<?php

declare(strict_types=1);

/**
 * KantEase — the front door.
 *
 *     http://localhost/KantEase/
 *
 * There is nothing to show an anonymous visitor here, so the only sensible
 * behaviour is to send them onward:
 *
 *   - signed out  -> /login.php
 *   - student     -> /student/dashboard.php
 *   - admin       -> /admin/dashboard.php
 *
 * Auth::role() re-reads the account row on every request, so an account that was
 * deactivated a second ago is treated as signed out here, not served a stale
 * session. That is the same guarantee every guarded page relies on.
 */

require_once __DIR__ . '/includes/bootstrap.php';

use KantEase\Router;

// Never cache this: it is a redirect whose target depends on the session, and a
// cached "Location: /login.php" would strand a signed-in user on the sign-in
// page after their browser or a proxy cached the response.
if (! headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

Router::redirectToLanding();