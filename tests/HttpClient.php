<?php

declare(strict_types=1);

/**
 * KantEase — HTTP client used by the Phase 3 verification suite.
 *
 * Not shipped application code. It exists so the Phase 3 suite can drive the
 * real pages over real HTTP, with real cookies, exactly the way a browser
 * would, instead of calling functions and hoping the page wires them up
 * correctly.
 *
 * A cookie jar is the important part. Almost every property under test —
 * "signing in creates a session", "signing out destroys it", "a deactivated
 * account loses access on its NEXT request" — is only observable across
 * several requests sharing one session. A stateless client cannot see any of
 * it.
 */

final class HttpClient
{
    /**
     * The User-Agent every request from this client carries.
     *
     * Shared by send() and postMultipart() on purpose. Auth::fingerprint()
     * hashes the user agent together with the client address and drops any
     * session whose fingerprint no longer matches, so two methods sending
     * different agent strings would quietly sign the same client out halfway
     * through a run — which looks exactly like a server-side session bug.
     */
    private const USER_AGENT = 'KantEaseVerifyPhase3/1.0';

    private string $jar;

    public function __construct(private readonly string $baseUrl)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'kantease-cookies-');

        if ($this->jar === false) {
            throw new RuntimeException('Could not create a cookie jar.');
        }
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string, location: string}
     */
    public function get(string $path, array $extraHeaders = []): array
    {
        return $this->send('GET', $path, null, $extraHeaders);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string, location: string}
     */
    public function post(string $path, array $fields, array $extraHeaders = []): array
    {
        return $this->send('POST', $path, http_build_query($fields), $extraHeaders);
    }

    /**
     * POST a multipart/form-data body, so a file can be uploaded.
     *
     * http_build_query() cannot carry a file: it would serialise a CURLFile as
     * the string "Object of class CURLFile could not be converted to string",
     * which is not an upload and would quietly make every upload check pass for
     * the wrong reason.
     *
     * @param  array<string, mixed> $fields scalar values plus CURLFile entries
     * @return array{status: int, headers: array<string, string>, body: string, location: string}
     */
    public function postMultipart(string $path, array $fields, array $extraHeaders = []): array
    {
        $handle = curl_init(
            str_starts_with($path, 'http') ? $path : $this->baseUrl . $path
        );

        $headers = ['Accept: text/html'];

        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        // No Content-Type is set here on purpose: cURL adds the
        // multipart/form-data boundary itself. Setting it by hand without that
        // boundary produces a body the server cannot parse at all.
        $payload = [];

        foreach ($fields as $name => $value) {
            $payload[$name] = is_scalar($value) ? (string) $value : $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            // Must match self::send() exactly. Auth binds each session to a
            // fingerprint of the user agent and client address, so a multipart
            // POST carrying a different agent string logs the session out and
            // every request after it comes back signed out. That is the
            // application behaving correctly; the client was the thing at
            // fault.
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
        ]);

        $raw    = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $size   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($raw === '' && $status === 0) {
            throw new RuntimeException(sprintf('No response from %s: %s', $path, $error));
        }

        $head = substr($raw, 0, $size);
        $out  = substr($raw, $size);

        $blocks = preg_split("/\r?\n\r?\n(?=[A-Z]{3} )/", $head) ?: [$head];
        $last   = trim((string) end($blocks));

        $parsed = [];

        foreach (preg_split('/\r?\n/', $last) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        return [
            'status'   => $status,
            'headers'  => $parsed,
            'body'     => $out,
            'location' => $parsed['location'] ?? '',
        ];
    }

    /**
     * Send a POST with a deliberately malformed body, to prove the server
     * treats it as hostile rather than assuming it parses.
     *
     * @param array<string, string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string, location: string}
     */
    public function postRaw(string $path, string $rawBody, array $extraHeaders = []): array
    {
        return $this->send('POST', $path, $rawBody, $extraHeaders);
    }

    /**
     * The session cookie value currently held, or null.
     */
    public function sessionCookie(): ?string
    {
        foreach ($this->readJar() as $line) {
            $parts = explode("\t", $line);

            if (count($parts) >= 7 && $parts[5] === 'KANTEASE') {
                return $parts[6];
            }
        }

        return null;
    }

    /**
     * Every cookie name currently held.
     *
     * @return list<string>
     */
    public function cookieNames(): array
    {
        $names = [];

        foreach ($this->readJar() as $line) {
            $parts = explode("\t", $line);

            if (count($parts) >= 7 && $parts[0] !== '#') {
                $names[] = $parts[5];
            }
        }

        return $names;
    }

    public function forgetCookies(): void
    {
        file_put_contents($this->jar, '');
    }

    /**
     * @return list<string>
     */
    private function readJar(): array
    {
        $contents = @file_get_contents($this->jar);

        if ($contents === false || $contents === '') {
            return [];
        }

        return array_values(array_filter(explode("\n", $contents), static fn (string $l): bool => $l !== ''));
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, headers: array<string, string>, body: string, location: string}
     */
    private function send(string $method, string $path, ?string $body, array $extraHeaders): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;

        $handle = curl_init($url);

        $headers = ['Accept: text/html'];

        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            // No redirect following: the Location header IS the thing under test.
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($handle, CURLOPT_HTTPGET, true);
        }

        $raw    = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $size   = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($raw === '' && $status === 0) {
            throw new RuntimeException(sprintf('No response from %s: %s', $url, $error));
        }

        $head = substr($raw, 0, $size);
        $out  = substr($raw, $size);

        // A redirect chain leaves several header blocks in $raw. Only the last
        // one describes the page that was actually returned.
        $blocks = preg_split("/\r?\n\r?\n(?=[A-Z]{3} )/", $head) ?: [$head];
        $last   = trim((string) end($blocks));

        $parsed = [];

        foreach (preg_split('/\r?\n/', $last) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        return [
            'status'   => $status,
            'headers'  => $parsed,
            'body'     => $out,
            'location' => $parsed['location'] ?? '',
        ];
    }
}