<?php

declare(strict_types=1);

/**
 * KantEase — sign in.
 *
 *     POST identifier, password  -> verify, then send them to their own panel
 *     GET  ?next=/student/...    -> return there afterwards, if allowed
 *
 * One form serves both roles. That is deliberate: the original build had a
 * single /api/login, and splitting it into "student login" and "admin login"
 * would only add a second way to be blocked.
 *
 * Security notes
 *   - The form carries a CSRF token, verified on POST before anything is read.
 *   - Auth::attempt() applies the login throttle, so repeated guessing locks
 *     the account rather than merely failing.
 *   - "No such account" and "wrong password" produce the identical message, so
 *     the form cannot be used to enumerate registered User IDs.
 *   - The submitted password is never echoed back, never logged, and never
 *     stored in old input.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// Every KantEase helper is called explicitly. This file has no `namespace`
// declaration, so an unqualified call would be looked up in the GLOBAL
// namespace and fail — `e()` and `url()` live in KantEase, not in global. The
// imports are not decoration; they are what makes the calls resolve.
use KantEase\AuthenticationException;
use KantEase\Auth;
use KantEase\Csrf;
use KantEase\Layout;
use KantEase\RateLimitException;
use KantEase\Router;
use KantEase\UserRole;

use function KantEase\e;
use function KantEase\flash_set;
use function KantEase\forget_old_input;
use function KantEase\input_string;
use function KantEase\is_post;
use function KantEase\query_string;
use function KantEase\redirect;
use function KantEase\remember_old_input;
use function KantEase\safe_path;
use function KantEase\url;
use function KantEase\with_query;

// ---------------------------------------------------------------------------
// Already signed in? Go straight to the right panel.
// ---------------------------------------------------------------------------

if (Auth::check()) {
    redirect(Router::homeFor(Auth::role() ?? UserRole::Student));
}

// ---------------------------------------------------------------------------
// Where to go afterwards.
// ---------------------------------------------------------------------------

$next = query_string('next');

if (is_post()) {
    // Token first. Nothing below runs on a request that should not exist.
    Csrf::verifyRequest();

    $identifier = input_string('identifier');
    $password   = input_string('password');

    // Keep the identifier so the form is not empty on a failed attempt. The
    // password is stripped by remember_old_input(), deliberately.
    remember_old_input(['identifier' => $identifier]);

    try {
        $result = Auth::attempt($identifier, $password);
    } catch (RateLimitException | AuthenticationException $exception) {
        // Both are user-facing, non-technical conditions. ValidationException is
        // deliberately not caught here: it can only come from a malformed
        // field, and its handler gives a precise per-field message.
        flash_set('error', $exception->getMessage());
        redirect('/login.php' . ($next === '' ? '' : with_query(['next' => $next])));
    }

    $role = UserRole::tryFrom((string) $result['user']['role']) ?? UserRole::Student;

    // Send them home unless ?next names a page their role is actually allowed
    // to open.
    //
    // safe_path() already removes any attempt to leave the application, but a
    // student could otherwise be handed ?next=/admin/dashboard.php and bounced
    // by the guard on arrival — a confusing outcome that looks like a bug. The
    // redirect honours a next only when the role owns that page.
    $destination = Router::homeFor($role);

    if ($next !== '' && Router::pathBelongsTo($role, $next)) {
        $destination = safe_path($next, $destination);
    }

    if ($result['upgraded']) {
        // The old Node.js build stored scrypt hashes. Saying so explains why a
        // first sign-in is slower than the ones after it.
        flash_set(
            'info',
            'Your password was upgraded to the newer format. This only happens once.'
        );
    }

    flash_set('success', 'Welcome back, ' . (string) $result['user']['full_name'] . '.');

    redirect($destination);
}

forget_old_input();

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

Layout::authStart([
    'title'    => 'Sign in',
    'subtitle' => 'Sign in to order from the canteen.',
    // Loaded only here and on register.php. core.js always loads as well.
    'scripts'  => ['/assets/js/auth.js'],
]);

$errors = $_SESSION['_field_errors'] ?? [];
unset($_SESSION['_field_errors']);

echo '<h2 class="auth-heading">Sign in</h2>' . "\n";

echo '<form method="post" action="' . e(url('/login.php'))
    . ($next === '' ? '' : '?next=' . e(urlencode($next))) . '" novalidate data-form>' . "\n";

echo '  ' . Csrf::field() . "\n";
echo '  <div class="stack">' . "\n";

Layout::field('identifier', 'User ID or email', $errors, [
    'type'         => 'text',
    'autocomplete' => 'username',
    'autofocus'    => true,
    'inputmode'    => 'email',
    'maxlength'    => 254,
    'spellcheck'   => false,
    'hint'         => 'For example STU-0001.',
]);

Layout::field('password', 'Password', $errors, [
    'type'         => 'password',
    'autocomplete' => 'current-password',
]);

echo "  </div>\n";
echo '  <button class="btn btn--lg btn--block mt-6" type="submit" data-submit>Sign in</button>' . "\n";
echo "</form>\n";

echo '<p class="auth-footer">New student? <a href="' . e(url('/register.php')) . '">Create an account</a></p>' . "\n";

Layout::authEnd();