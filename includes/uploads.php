<?php

declare(strict_types=1);

namespace KantEase;

/**
 * Secure handling of product photographs.
 *
 * ===========================================================================
 * WHY THIS IS DELIBERATE
 * ===========================================================================
 *
 * The original Node.js build wrote the browser-supplied filename straight into
 * uploads/products. That single line is the whole attack:
 *
 *     fs.writeFileSync(`uploads/products/${req.file.originalname}`, data)
 *
 * A student or staff member posting a file called `shell.php` got a PHP file
 * inside the document root, and the canteen server executed it. Nothing about
 * it needed a vulnerability — it was a feature.
 *
 * Five independent controls apply here, and the file has to pass ALL of them.
 * One would usually be enough; they are layered because the cost of a gap is a
 * remote shell on the school's server.
 *
 *   1. PHP's own upload errors. A file that did not arrive intact is refused
 *      before any of its bytes are read.
 *   2. Size cap from config (`security.upload_max_bytes`).
 *   3. `is_uploaded_file()` and `move_uploaded_file()`. This is what makes the
 *      check meaningful: without it, a path in $_FILES could name any file on
 *      disk that the web server can read, and "upload" would be a file copy.
 *   4. The REAL type of the bytes, established twice:
 *        - a hand-written magic-number signature check, which cannot be fooled
 *          by a header a client chose to send; and
 *        - finfo, which inspects content rather than trusting a claim.
 *      Both must agree with each other, and both must agree with the
 *      extension derived from that type — never with the submitted name.
 *   5. A randomised filename. The name the client chose is never used, so it
 *      can never carry a second extension, a null byte or a traversal.
 *
 * images/zones aside, uploads/.htaccess is a further safety net: PHP execution
 * is switched off in that tree and script extensions are denied outright, so
 * even a file that somehow landed there cannot run. That rule protects the
 * Apache deployment. This class protects every other deployment.
 *
 * ===========================================================================
 * FALLBACKS
 * ===========================================================================
 *
 * A product with no photo is normal, not an error, so `placeholderUrl()` exists
 * and every view uses it. There is no broken-image icon anywhere in KantEase.
 */

/**
 * Result of one upload attempt.
 *
 * Deliberately not an exception: "no file was chosen" is a normal state on an
 * edit form, and an exception would force every caller into a try/catch.
 */
final class ImageUpload
{
    private function __construct(
        public readonly bool $uploaded,
        public readonly ?string $relativePath = null,
        public readonly ?string $error = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $bytes = null,
    ) {
    }

    /**
     * The longest edge a product photo may have, in pixels.
     *
     * A canteen photo taken on a modern phone is 4000px wide and several
     * megabytes. Displayed in a 220px card that is wasted bytes on every
     * single menu load, and on a school machine with limited disk it adds up.
     * Nothing is downscaled here — that would need GD, which is an optional
     * PHP extension — but an oversized image is refused with a message that
     * says what to do about it, rather than accepted and left to the browser.
     */
    public const MAX_DIMENSION = 3000;

    /** Smallest edge accepted. A 1x1 tracking pixel is not a food photo. */
    public const MIN_DIMENSION = 32;

    /**
     * Magic-number prefixes per supported type.
     *
     * Longest signatures first: WEBP's container is "RIFF....WEBP", so a naive
     * check on "RIFF" alone would also accept WAV and AVI files.
     *
     * @var array<string, list<string>>
     */
    private const SIGNATURES = [
        'jpeg' => [
            "\xFF\xD8\xFF\xDB",
            "\xFF\xD8\xFF\xC0",
            "\xFF\xD8\xFF\xC1",
            "\xFF\xD8\xFF\xC2",
            "\xFF\xD8\xFF\xC3",
            "\xFF\xD8\xFF\xE0",
        ],
        'png' => ["\x89PNG\r\n\x1A\n"],
        'webp' => ['RIFF????WEBP'],
    ];

    /**
     * The canonical extension for each type.
     *
     * Derived from what the bytes ARE. A file named "payload.php" that happens
     * to be a PNG is saved as ".png", which is the only extension that could
     * have been chosen here at all.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Extension -> the MIME type the config allows for it.
     *
     * Read from `security.upload_allowed_extensions` /
     * `security.upload_allowed_mimes` at call time rather than hard-coded, so
     * an operator can tighten or widen the allow-list without touching code.
     *
     * @return array<string, string> extension => mime
     */
    private static function allowedTypes(): array
    {
        $configured = Config::get('security.upload_allowed_mimes', []);
        $allowed    = [];

        if (is_array($configured)) {
            foreach ($configured as $extension => $mime) {
                if (is_string($extension) && is_string($mime)) {
                    $allowed[strtolower($extension)] = $mime;
                }
            }
        }

        return $allowed === [] ? ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'] : $allowed;
    }

    /**
     * Handle one file input.
     *
     * @param string $field the name of the <input type="file">, e.g. "photo"
     *
     * @return self "nothing chosen" is a success with uploaded = false
     */
    public static function receive(string $field = 'photo'): self
    {
        if (! isset($_FILES[$field]) || ! is_array($_FILES[$field])) {
            return new self(false);
        }

        /** @var array<string, mixed> $file */
        $file = $_FILES[$field];

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            // The edit form was submitted without touching the file input.
            return new self(false);
        }

        $message = match ($error) {
            UPLOAD_ERR_OK                     => null,
            UPLOAD_ERR_INI_SIZE              => 'That photo is larger than this server accepts.',
            UPLOAD_ERR_FORM_SIZE             => 'That photo is larger than this form accepts.',
            UPLOAD_ERR_PARTIAL               => 'The photo did not finish uploading. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR            => 'The server has no temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE            => 'The server could not save the photo. Check its disk space.',
            UPLOAD_ERR_EXTENSION             => 'A server setting stopped this upload.',
            default                          => 'The photo could not be uploaded. Please try again.',
        };

        if ($message !== null) {
            return new self(false, null, $message);
        }

        $size = (int) ($file['size'] ?? 0);
        $max  = Config::int('security.upload_max_bytes', 2_097_152);

        if ($size < 1) {
            return new self(false, null, 'That file is empty.');
        }

        if ($size > $max) {
            return new self(false, null, sprintf(
                'That photo is %s. The largest accepted is %s.',
                self::humanBytes($size),
                self::humanBytes($max)
            ));
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        // is_uploaded_file() is the control that makes everything below mean
        // something. It is only true for a file PHP itself received through an
        // HTTP upload, so a crafted tmp_name cannot point at /etc/passwd or at
        // includes/config.local.php.
        if ($temporary === '' || ! is_uploaded_file($temporary)) {
            return new self(false, null, 'That file did not arrive as an upload.');
        }

        // -- Read the bytes --------------------------------------------------
        //
        // strlen(), NOT (int) $contents.
        //
        // The cast looked harmless and is the reason NO image could ever be
        // uploaded: every one of these files begins with a byte that is not a
        // digit, so (int) on the binary content is 0 for every valid image. The
        // length was then compared against 1, every upload was refused with
        // "the server could not read that photo", and not one signature byte
        // was ever examined. A real 116-byte PNG produced 0.
        //
        // The content is read as a string and stays a string: the signature
        // check, finfo and getimagesize() all need the bytes, not a length.
        $contents = @file_get_contents($temporary);

        if ($contents === false || $contents === '') {
            return new self(false, null, 'The server could not read that photo.');
        }

        $bytes = strlen($contents);

        // -- Signature check ------------------------------------------------
        //
        // Read from the bytes, not from what the client claimed. A Content-Type
        // header and a filename are both attacker-controlled; the first twelve
        // bytes of a JPEG are not.

        $extension = self::extensionFromSignature($contents);

        if ($extension === null) {
            return new self(false, null, 'That file is not a photo. Use a JPG, PNG or WEBP image.');
        }

        // -- Second opinion from finfo ---------------------------------------

        $declared = self::sniffMime($contents);

        if ($declared === null) {
            return new self(false, null, 'That file is not a photo. Use a JPG, PNG or WEBP image.');
        }

        $allowed = self::allowedTypes();
        $expected = $allowed[$extension] ?? null;

        // All three must agree: the signature, finfo, and the operator's
        // allow-list for that extension. Anything less and a polyglot file
        // gets through.
        if ($expected === null || $declared !== $expected) {
            return new self(false, null, 'That file is not a photo. Use a JPG, PNG or WEBP image.');
        }

        // -- It really is an image -------------------------------------------
        //
        // getimagesize() decodes the header itself, so it fails on a file that
        // merely starts with the right four bytes. It also gives the dimensions
        // used for the size rules below.

        $info = @getimagesizefromstring($contents);

        if ($info === false || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return new self(false, null, 'That file is not a readable photo. Use a JPG, PNG or WEBP image.');
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            return new self(false, null, sprintf(
                'That photo is only %dx%d pixels. Use one at least %dx%d.',
                $width,
                $height,
                self::MIN_DIMENSION,
                self::MIN_DIMENSION
            ));
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            return new self(false, null, sprintf(
                'That photo is %dx%d pixels. The largest accepted is %dx%d. Please resize it before uploading.',
                $width,
                $height,
                self::MAX_DIMENSION,
                self::MAX_DIMENSION
            ));
        }

        // -- Move it into place ----------------------------------------------

        $directory = self::directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return new self(false, null, 'The server could not create its photo folder. Check its permissions.');
        }

        if (! is_writable($directory)) {
            return new self(false, null, 'The server cannot write to its photo folder. Check its permissions.');
        }

        // The name the client chose is discarded entirely. 16 random bytes make a
        // collision impossible in practice and mean the stored name can never
        // carry a second extension, a null byte or "../".
        $stored = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$declared];

        $destination = $directory . DIRECTORY_SEPARATOR . $stored;

        if (! @move_uploaded_file($temporary, $destination)) {
            return new self(false, null, 'The server could not save the photo. Check its permissions.');
        }

        // A world-writable upload in a school lab is a shared-machine risk.
        @chmod($destination, 0644);

        return new self(
            true,
            self::RELATIVE_DIR . '/' . $stored,
            null,
            $width,
            $height,
            $size
        );
    }

    /** The directory uploads live in, relative to the application root. */
    public const RELATIVE_DIR = '/uploads/products';

    /** Absolute path of the upload directory. */
    public static function directory(): string
    {
        return KANTEASE_ROOT . self::RELATIVE_DIR;
    }

    /**
     * Remove a previously uploaded file.
     *
     * Takes the path stored in the database, which is untrusted input in the
     * sense that it decides whether a file on disk is deleted. It is resolved
     * with realpath() and then checked to be inside the upload directory, so
     * a stored value of "../../includes/config.local.php" resolves to a path
     * outside that directory and deletes nothing.
     *
     * @param string|null $relativePath the value of food_items.image_path
     */
    public static function delete(?string $relativePath): bool
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return false;
        }

        // Only ever consider paths this class could have written.
        if (! str_starts_with($relativePath, self::RELATIVE_DIR . '/')) {
            return false;
        }

        $directory = realpath(self::directory());

        if ($directory === false) {
            return false;
        }

        $target = realpath(KANTEASE_ROOT . '/' . ltrim($relativePath, '/'));

        if ($target === false) {
            // Already gone, or a name that does not resolve. Nothing to do.
            return false;
        }

        // The containment test. realpath() has already collapsed any "..".
        if (! str_starts_with($target, $directory . DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (! is_file($target)) {
            return false;
        }

        return @unlink($target);
    }

    /**
     * Is a stored image path one this class produced?
     *
     * Used by the views before they build a URL, so a hand-edited or
     * migrated-away value can never turn into an href into the rest of the
     * document root.
     */
    public static function isManaged(?string $relativePath): bool
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return false;
        }

        // Reject anything that is not a bare filename directly inside the
        // directory. No traversal, no nested folders, no absolute paths.
        return preg_match('#^/uploads/products/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$#', $relativePath) === 1;
    }

    /**
     * The local image shown when a product has no photograph.
     *
     * A local SVG, so it costs nothing to load and works with no network.
     */
    public static function placeholderUrl(): string
    {
        return url('/assets/images/product-placeholder.svg');
    }

    /**
     * The URL to render for a product's stored image path, or the placeholder.
     */
    public static function urlFor(?string $relativePath): string
    {
        return self::isManaged($relativePath) ? url((string) $relativePath) : self::placeholderUrl();
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The extension implied by the leading bytes, or null.
     *
     * Order matters. PNG's signature is checked before the WebP one only
     * because neither can be a prefix of the other, but within WebP the "RIFF"
     * and "WEBP" halves are separated by four arbitrary bytes, which is why
     * that pattern uses "." wildcards.
     */
    private static function extensionFromSignature(string $bytes): ?string
    {
        $head = substr($bytes, 0, 16);

        foreach (self::SIGNATURES as $extension => $signatures) {
            foreach ($signatures as $signature) {
                if (preg_match('/\A' . $signature . '/', $head) === 1) {
                    return $extension;
                }
            }
        }

        return null;
    }

    /**
     * The MIME type finfo reports for these bytes, or null if finfo is
     * unavailable or the type is not one KantEase accepts.
     */
    private static function sniffMime(string $bytes): ?string
    {
        if (! class_exists(\finfo::class)) {
            // Without finfo the signature check is still in force. This is a
            // weaker position and is reported rather than silently ignored.
            error_log('KantEase: the fileinfo extension is missing; upload checks rely on signatures alone.');

            foreach (self::EXTENSIONS as $mime => $extension) {
                if (self::extensionFromSignature($bytes) === $extension) {
                    return $mime;
                }
            }

            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($bytes);

        return is_string($mime) && isset(self::EXTENSIONS[$mime]) ? $mime : null;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }

        return $bytes . ' bytes';
    }
}