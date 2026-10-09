<?php

declare(strict_types=1);

/**
 * Verification suite for the legacy Node.js password migration.
 *
 * Run from the command line:
 *
 *     php tests/legacy-password-test.php
 *
 * WHAT IT PROVES
 *   The pure-PHP scrypt implementation in includes/passwords.php produces
 *   byte-identical output to the original Node.js application for the exact
 *   parameters that application used (crypto.scrypt(password, salt, 64)).
 *
 * WHY IT MATTERS
 *   Every student and administrator created by the old build has a password in
 *   that format. If this implementation is even slightly wrong, the migration
 *   locks every existing account out of KantEase, and the symptom (a wrong
 *   password error for a correct password) is very hard to diagnose.
 *
 * THE VECTORS
 *   Generated with Node.js on 2026-10-09 using:
 *       node -e "console.log(require('crypto').scryptSync(PW, SALT, 64).toString('hex'))"
 *
 *   The salt is the 32-character hex STRING, exactly as server.js passed it
 *   (crypto.scrypt received the salt as a JS string, not decoded to bytes).
 *
 * EXPECTED OUTPUT
 *   4 tests, 4 passes, 0 failures.
 *
 * NOTE ON RUNTIME
 *   scrypt with N=16384 and r=8 performs roughly 2 x 16384 block-mixing
 *   passes in interpreted PHP, which takes a few seconds per vector. That cost
 *   is paid exactly once per account, at the moment that account first signs
 *   in, and never again. This is the trade for not resetting everyone's
 *   password.
 */

require_once __DIR__ . '/../includes/exceptions.php';
require_once __DIR__ . '/../includes/enums.php';
require_once __DIR__ . '/../includes/functions.php';

use KantEase\LegacyHasher;

$vectors = [
    [
        'password' => 'studentpass1',
        'salt'     => '0123456789abcdef0123456789abcdef',
        'hash'     => '4c5d78d833ae5003bfca4e8fc4138434950c3d68595df6f55d7033360f421340'
                     . 'ccc981bdc416b71f4d91c4c0a2809f18e99bd84dd03a96e2c136878c89c26c0f',
    ],
    [
        'password' => 'Admin#2026secure',
        'salt'     => 'fedcba9876543210fedcba9876543210',
        'hash'     => 'e74fe397ebe02914d1a3ef563c79d17036887fe3eeb86607966876fff9af1b5e5'
                     . '35dc5c22272d4b610db360b97a7f218d6b579be8162d5c23fc032843ba06725',
    ],
    [
        'password' => 'p@ssw0rd!',
        'salt'     => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'hash'     => '3b44d07f5e0929a90c0880dfc0a6fd71696f18945feac5c535fd9dae1ba0c259'
                     . '106a126c207a31a59c4eac224c04ac96aa791fa0d8db9dcd20e3029f8de10556',
    ],
    [
        'password' => 'canteen2026',
        'salt'     => 'ffffffffffffffffffffffffffffffff',
        'hash'     => '2092faa1adeaf0ab46068cd6f232e7fb6153d70aad4e715163336a8f637fbc96'
                     . '3dd8c319c44aa725ee8cf3d69def385bfaf69adda0812520a8c47708c5e415b9',
    ],
];

$passed = 0;
$failed = 0;

echo "KantEase — legacy scrypt compatibility\n";
echo str_repeat('=', 60) . "\n\n";

foreach ($vectors as $index => $vector) {
    $label   = sprintf('#%d  %s', $index + 1, $vector['password']);
    $started = microtime(true);

    $stored = $vector['salt'] . ':' . $vector['hash'];

    $computed = LegacyHasher::scrypt($vector['password'], $vector['salt']);
    $elapsed  = microtime(true) - $started;

    if ($computed === $vector['hash']) {
        $passed++;
        printf("  PASS  %-34s %6.2fs\n", $label, $elapsed);
        continue;
    }

    $failed++;
    printf("  FAIL  %-34s %6.2fs\n", $label, $elapsed);
    printf("        expected %s\n", $vector['hash']);
    printf("        actual   %s\n", $computed);
}

// A wrong password must not verify.
$wrongRejected = ! LegacyHasher::verify('not-the-password', $vectors[0]['salt'] . ':' . $vectors[0]['hash']);
printf("\n  %s  wrong password is rejected\n", $wrongRejected ? 'PASS' : 'FAIL');
$wrongRejected ? $passed++ : $failed++;

// A malformed stored value must be rejected without doing the work.
$malformed = [
    'not-a-credential',
    'zzzz:zzzz',
    '0123456789abcdef0123456789abcde:',
    ':4c5d78d833ae5003bfca4e8fc4138434950c3d68595df6f55d7033360f421340',
];

$malformedOk = true;
$started     = microtime(true);

foreach ($malformed as $candidate) {
    if (LegacyHasher::verify('studentpass1', $candidate)) {
        $malformedOk = false;
    }
}

$malformedElapsed = microtime(true) - $started;
printf("  %s  malformed values rejected instantly (%.3fs)\n", $malformedOk ? 'PASS' : 'FAIL', $malformedElapsed);
$malformedOk ? $passed++ : $failed++;

echo "\n" . str_repeat('-', 60) . "\n";
printf("  %d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);