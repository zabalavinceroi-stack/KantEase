<?php

declare(strict_types=1);

namespace KantEase;

use InvalidArgumentException;

/**
 * Password hashing.
 *
 * Two formats are supported during the migration window:
 *
 *   MODERN   password_hash() output, verified with password_verify().
 *            Used by every account KantEase creates.
 *
 *   LEGACY   "<32 hex salt>:<128 hex key>", produced by the original Node.js
 *            build with crypto.scrypt(password, salt, 64) where the salt was
 *            passed as its own hex STRING, not decoded to bytes.
 *            PHP has no built-in scrypt, so LegacyHasher below implements it.
 *
 * A legacy hash is verified at most once per account. The moment it matches,
 * Passwords::verify() returns a flag telling the caller to replace it with a
 * modern hash, so nobody is locked out and no legacy hash survives long.
 */
final class Passwords
{
    /**
     * Hash a new password with PHP's current best algorithm.
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Check a password against whichever hash the account carries.
     *
     * @return array{
     *     valid: bool,
     *     needs_rehash: bool,
     *     legacy_verified: bool,
     *     rehash_hash: ?string
     * }
     */
    public static function verify(string $password, string $hash, ?string $legacyHash = null): array
    {
        // Legacy is checked first. Migrated accounts carry an empty modern
        // hash, so checking the other way round could authenticate against
        // an empty string.
        if (is_string($legacyHash) && $legacyHash !== '') {
            if (LegacyHasher::verify($password, $legacyHash)) {
                return [
                    'valid'          => true,
                    'needs_rehash'   => true,
                    'legacy_verified'=> true,
                    'rehash_hash'    => self::hash($password),
                ];
            }

            // A correct modern password still wins even when a stale legacy
            // hash lingers on the row.
            if ($hash !== '' && password_verify($password, $hash)) {
                return [
                    'valid'          => true,
                    'needs_rehash'   => password_needs_rehash($hash, PASSWORD_DEFAULT),
                    'legacy_verified'=> false,
                    'rehash_hash'    => password_needs_rehash($hash, PASSWORD_DEFAULT)
                        ? self::hash($password)
                        : null,
                ];
            }

            return ['valid' => false, 'needs_rehash' => false, 'legacy_verified' => false, 'rehash_hash' => null];
        }

        if ($hash === '') {
            return ['valid' => false, 'needs_rehash' => false, 'legacy_verified' => false, 'rehash_hash' => null];
        }

        $valid = password_verify($password, $hash);

        return [
            'valid'          => $valid,
            'needs_rehash'   => $valid && password_needs_rehash($hash, PASSWORD_DEFAULT),
            'legacy_verified'=> false,
            'rehash_hash'    => $valid && password_needs_rehash($hash, PASSWORD_DEFAULT)
                ? self::hash($password)
                : null,
        ];
    }

    /**
     * Policy check applied on both registration and password change.
     *
     * @return list<string> Human-readable problems; empty means valid.
     */
    public static function policyProblems(string $password): array
    {
        $min = Config::int('security.password_min_length', 8);
        $max = Config::int('security.password_max_length', 128);
        $problems = [];

        if (mb_strlen($password) < $min) {
            $problems[] = sprintf('Your password must be at least %d characters long.', $min);
        }

        // Measured in characters, not bytes: a 60-character password of
        // non-ASCII characters must not be rejected for "length".
        if (mb_strlen($password) > $max) {
            $problems[] = sprintf('Your password must be %d characters or fewer.', $max);
        }

        if (mb_strlen($password) > 2000) {
            // Hashing a megabyte-long password is a denial-of-service vector.
            $problems[] = 'Your password is too long.';
        }

        return $problems;
    }
}

/**
 * scrypt, in pure PHP.
 *
 * Needed only to read passwords created by the old Node.js application. It is
 * deliberately self-contained and never used to STORE anything — once an
 * account signs in successfully its legacy hash is replaced with
 * password_hash() output.
 *
 * Parameters are fixed to the original's defaults: N = 16384, r = 8, p = 1,
 * derived key length 64 bytes. 16 MiB of working memory, matching the Node
 * implementation so the output is bit-identical.
 */
final class LegacyHasher
{
    private const N       = 16384;
    private const R       = 8;
    private const P       = 1;
    private const DK_LEN  = 64;

    /** Matched exactly against the original's own format check. */
    private const HASH_PATTERN = '/^[a-f0-9]{32}:[a-f0-9]{128}$/';

    /**
     * Constant-time verification of a "salt:hash" credential.
     *
     * The format is validated before any expensive work, so a malformed value
     * costs nothing.
     */
    public static function verify(string $password, string $stored): bool
    {
        $parts = explode(':', $stored, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$salt, $expected] = $parts;

        if (preg_match('/^[a-f0-9]{32}$/', $salt) !== 1 || preg_match('/^[a-f0-9]{128}$/', $expected) !== 1) {
            return false;
        }

        $computed = self::scrypt($password, $salt);

        return hash_equals($expected, $computed);
    }

    /**
     * True when the value looks like a legacy credential.
     */
    public static function looksLegacy(string $stored): bool
    {
        return preg_match(self::HASH_PATTERN, $stored) === 1;
    }

    /**
     * Derive the 64-byte key, hex-encoded, exactly as Node's
     * crypto.scrypt(password, salt, 64) does.
     *
     * @throws InvalidArgumentException
     */
    public static function scrypt(string $password, string $saltHexString): string
    {
        if ($saltHexString === '') {
            throw new InvalidArgumentException('scrypt salt must not be empty.');
        }

        $password = self::normalise($password);
        $salt     = self::normalise($saltHexString);

        $blockSize = 128 * self::R;                       // 1024 bytes
        $combined  = hash_pbkdf2('sha256', $password, $salt, 1, self::P * $blockSize, true);

        $v = array_fill(0, self::N, '');

        for ($i = 0; $i < self::P; $i++) {
            $combined = self::romix(substr($combined, $i * $blockSize, $blockSize), $v);
        }

        $derived = hash_pbkdf2('sha256', $password, $combined, 1, self::DK_LEN, true);

        return bin2hex($derived);
    }

    /**
     * ROMix over one 128*r byte block.
     *
     * @param  list<string> $v Scratch buffer of N blocks, reused across calls.
     * @return string The mixed block.
     */
    private static function romix(string $block, array &$v): string
    {
        $x = $block;

        // First pass: fill V with the BlockMix chain.
        for ($i = 0; $i < self::N; $i++) {
            $v[$i] = $x;
            $x = self::blockMix($x);
        }

        // Second pass: index back into V using the block's own last words.
        for ($i = 0; $i < self::N; $i++) {
            // Integerify: the last 64-byte block read as a little-endian
            // integer. N is far below 2^32, so only the first 32 bits matter.
            $lastBlockOffset = (2 * self::R - 1) * 64;
            /** @var array{1: int} $unpacked */
            $unpacked = unpack('V', substr($x, $lastBlockOffset, 4));
            $j = $unpacked[1] % self::N;

            $x = self::blockMix(self::xorBlocks($x, $v[$j]));
        }

        return $x;
    }

    /**
     * BlockMix over one 128*r byte block.
     *
     * Two halves of r Salsa20/8 steps, where each step XORs the running value
     * with the next 64-byte input block. The results are then written back in
     * even-then-odd block order, which is what makes the whole construction
     * resistant to time-memory trade-off attacks.
     */
    private static function blockMix(string $block): string
    {
        $twoR   = 2 * self::R;
        $words  = 16;

        $input = unpack('V*', $block);
        if ($input === false || count($input) !== $twoR * $words) {
            throw new InvalidArgumentException('scrypt received a malformed block.');
        }

        /** @var list<int> $input */
        $input = array_values($input);

        // X starts as the last 64-byte block.
        $x = array_slice($input, ($twoR - 1) * $words, $words);

        /** @var list<list<int>> $y */
        $y = [];

        for ($i = 0; $i < $twoR; $i++) {
            // T = X xor B[i]
            $t = [];
            for ($k = 0; $k < $words; $k++) {
                $t[$k] = ($x[$k] ^ $input[$i * $words + $k]) & 0xFFFFFFFF;
            }

            $x = self::salsa20_8($t);
            $y[$i] = $x;
        }

        // B' = Y[0], Y[2], ..., Y[2r-2], then Y[1], Y[3], ..., Y[2r-1]
        $out = [];
        for ($i = 0; $i < $twoR; $i += 2) {
            for ($k = 0; $k < $words; $k++) {
                $out[] = $y[$i][$k];
            }
        }
        for ($i = 1; $i < $twoR; $i += 2) {
            for ($k = 0; $k < $words; $k++) {
                $out[] = $y[$i][$k];
            }
        }

        $packed = pack('V*', ...$out);
        if ($packed === false) {
            throw new InvalidArgumentException('scrypt failed to pack a block.');
        }

        return $packed;
    }

    /**
     * Salsa20/8 core: four double rounds, then add the input back.
     *
     * scrypt uses the bare core — the sigma constants belong to the Salsa20
     * cipher's key schedule and are deliberately not mixed in here.
     *
     * @param  array<int, int> $b 16 uint32 words.
     * @return array<int, int> 16 uint32 words.
     */
    private static function salsa20_8(array $b): array
    {
        $x = $b;

        for ($round = 0; $round < 4; $round++) {
            // Column round
            $x[4]  = $x[4]  ^ self::rotl(($x[0]  + $x[12]) & 0xFFFFFFFF, 7);
            $x[8]  = $x[8]  ^ self::rotl(($x[4]  + $x[0])  & 0xFFFFFFFF, 9);
            $x[12] = $x[12] ^ self::rotl(($x[8]  + $x[4])  & 0xFFFFFFFF, 13);
            $x[0]  = $x[0]  ^ self::rotl(($x[12] + $x[8])  & 0xFFFFFFFF, 18);

            $x[9]  = $x[9]  ^ self::rotl(($x[5]  + $x[1])  & 0xFFFFFFFF, 7);
            $x[13] = $x[13] ^ self::rotl(($x[9]  + $x[5])  & 0xFFFFFFFF, 9);
            $x[1]  = $x[1]  ^ self::rotl(($x[13] + $x[9])  & 0xFFFFFFFF, 13);
            $x[5]  = $x[5]  ^ self::rotl(($x[1]  + $x[13]) & 0xFFFFFFFF, 18);

            $x[14] = $x[14] ^ self::rotl(($x[10] + $x[6])  & 0xFFFFFFFF, 7);
            $x[2]  = $x[2]  ^ self::rotl(($x[14] + $x[10]) & 0xFFFFFFFF, 9);
            $x[6]  = $x[6]  ^ self::rotl(($x[2]  + $x[14]) & 0xFFFFFFFF, 13);
            $x[10] = $x[10] ^ self::rotl(($x[6]  + $x[2])  & 0xFFFFFFFF, 18);

            $x[3]  = $x[3]  ^ self::rotl(($x[15] + $x[11]) & 0xFFFFFFFF, 7);
            $x[7]  = $x[7]  ^ self::rotl(($x[3]  + $x[15]) & 0xFFFFFFFF, 9);
            $x[11] = $x[11] ^ self::rotl(($x[7]  + $x[3])  & 0xFFFFFFFF, 13);
            $x[15] = $x[15] ^ self::rotl(($x[11] + $x[7])  & 0xFFFFFFFF, 18);

            // Row round
            $x[1]  = $x[1]  ^ self::rotl(($x[0]  + $x[3])  & 0xFFFFFFFF, 7);
            $x[2]  = $x[2]  ^ self::rotl(($x[1]  + $x[0])  & 0xFFFFFFFF, 9);
            $x[3]  = $x[3]  ^ self::rotl(($x[2]  + $x[1])  & 0xFFFFFFFF, 13);
            $x[0]  = $x[0]  ^ self::rotl(($x[3]  + $x[2])  & 0xFFFFFFFF, 18);

            $x[6]  = $x[6]  ^ self::rotl(($x[5]  + $x[4])  & 0xFFFFFFFF, 7);
            $x[7]  = $x[7]  ^ self::rotl(($x[6]  + $x[5])  & 0xFFFFFFFF, 9);
            $x[4]  = $x[4]  ^ self::rotl(($x[7]  + $x[6])  & 0xFFFFFFFF, 13);
            $x[5]  = $x[5]  ^ self::rotl(($x[4]  + $x[7])  & 0xFFFFFFFF, 18);

            $x[11] = $x[11] ^ self::rotl(($x[10] + $x[9])  & 0xFFFFFFFF, 7);
            $x[8]  = $x[8]  ^ self::rotl(($x[11] + $x[10]) & 0xFFFFFFFF, 9);
            $x[9]  = $x[9]  ^ self::rotl(($x[8]  + $x[11]) & 0xFFFFFFFF, 13);
            $x[10] = $x[10] ^ self::rotl(($x[9]  + $x[8])  & 0xFFFFFFFF, 18);

            $x[12] = $x[12] ^ self::rotl(($x[15] + $x[14]) & 0xFFFFFFFF, 7);
            $x[13] = $x[13] ^ self::rotl(($x[12] + $x[15]) & 0xFFFFFFFF, 9);
            $x[14] = $x[14] ^ self::rotl(($x[13] + $x[12]) & 0xFFFFFFFF, 13);
            $x[15] = $x[15] ^ self::rotl(($x[14] + $x[13]) & 0xFFFFFFFF, 18);
        }

        // Output = input + state, modulo 2^32.
        for ($i = 0; $i < 16; $i++) {
            $x[$i] = ($x[$i] + $b[$i]) & 0xFFFFFFFF;
        }

        return $x;
    }

    /**
     * 32-bit rotate left.
     */
    private static function rotl(int $value, int $bits): int
    {
        $value &= 0xFFFFFFFF;

        return (($value << $bits) | ($value >> (32 - $bits))) & 0xFFFFFFFF;
    }

    /**
     * XOR two equal-length byte strings.
     *
     * PHP's ^ operator works byte-for-byte on binary strings, so no loop is
     * needed.
     */
    private static function xorBlocks(string $a, string $b): string
    {
        if (strlen($a) !== strlen($b)) {
            throw new InvalidArgumentException('scrypt received mismatched blocks.');
        }

        return $a ^ $b;
    }

    /**
     * Hash a password the way Node's crypto.scrypt hashed it.
     *
     * Node passed the password and the salt as JavaScript strings, which
     * crypto.scrypt encoded as UTF-8 with no normalisation of any kind. PHP
     * strings are already byte strings, so the values are used exactly as
     * they are: normalising here would produce a different key and lock every
     * migrated account out.
     */
    private static function normalise(string $value): string
    {
        return $value;
    }
}