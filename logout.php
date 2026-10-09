<?php

declare(strict_types=1);

/**
 * KantEase — sign out.
 *
 *     POST only, with a CSRF token
 *
 * Signing out is done by POST rather than by GET, and that is the whole point
 * of this file.
 *
 * A GET logout is trivially triggerable by a third party: any web page may
 * embed <img src="http://canteen.school/KantEase/logout.php">, and the browser
 * would happily send the request with the student's session cookie attached.
 * The student gets signed out without touching anything — an annoyance on its
 * own, but the same primitive, pointed at a destructive action, is a real
 * problem. Making the action require a POST and a token means a cross-site
 * request cannot carry it out.
 *
 * It also means the Back button cannot undo a sign-out, which a GET would.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// See login.php: no `namespace` declaration here, so every KantEase helper is
// imported explicitly or the unqualified call fails against the global scope.
use KantEase\Auth;
use KantEase\Csrf;

use function KantEase\flash_set;
use function KantEase\is_post;
use function KantEase\redirect;
use function KantEase\url;

if (! is_post()) {
    // Refuse rather than sign out. Say why, so a bookmarked link or a
    // mistyped URL explains itself instead of appearing to do nothing.
    flash_set('info', 'Use the Sign out button to end your session.');
    redirect('/');
}

Csrf::verifyRequest();

$name = (string) (Auth::user()['full_name'] ?? '');

Auth::logout();

// A fresh token belongs to the destroyed session; issuing one here means the
// sign-in form the visitor is about to see is already protected rather than
// minting one on its first render.
Csrf::rotate();

flash_set(
    'success',
    $name === '' ? 'You have been signed out.' : 'You have been signed out. See you at lunch, ' . $name . '.'
);

redirect('/login.php');