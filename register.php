<?php

declare(strict_types=1);

/**
 * KantEase — student registration.
 *
 *     POST full_name, email, password, password_confirmation
 *
 * STUDENTS ONLY.
 *
 * There is no public administrator registration, and that is a security
 * decision rather than an omission. The original Node.js build had no
 * restriction: anyone who could reach /register.php and post role=admin was
 * handed an administrator account (audit finding VULN-1). Here the role is not
 * a form field at all — it is fixed to UserRole::Student below — so there is
 * nothing in the request to tamper with. The first administrator can only be
 * created through database/setup.php.
 *
 * A newly registered student is active immediately, because a canteen needs
 * students ordering at break time rather than a moderation queue. Deactivation
 * remains an administrator's decision and takes effect on the account's very
 * next request: Auth::user() re-reads is_active on every request instead of
 * trusting whatever the session happens to hold.
 *
 * After registering, the student is NOT signed in. They are sent to the
 * sign-in page carrying their new User ID, so every account enters the
 * application through one audited authentication path rather than two.
 */

require_once __DIR__ . '/includes/bootstrap.php';

// See login.php: this file has no `namespace` declaration, so every KantEase
// helper must be imported or the unqualified call resolves against the GLOBAL
// namespace and fails.
use KantEase\Auth;
use KantEase\Config;
use KantEase\Csrf;
use KantEase\Layout;
use KantEase\Passwords;
use KantEase\Repositories\UserRepository;
use KantEase\Router;
use KantEase\UserRole;
use KantEase\ValidationException;
use KantEase\Validator;

use function KantEase\e;
use function KantEase\field_errors_set;
use function KantEase\field_errors_take;
use function KantEase\flash_set;
use function KantEase\forget_old_input;
use function KantEase\input_string;
use function KantEase\is_post;
use function KantEase\redirect;
use function KantEase\remember_old_input;
use function KantEase\url;

// ---------------------------------------------------------------------------
// Nobody registers while already signed in.
// ---------------------------------------------------------------------------

if (Auth::check()) {
    redirect(Router::homeFor(Auth::role() ?? UserRole::Student));
}

// ---------------------------------------------------------------------------
// Submit
// ---------------------------------------------------------------------------

if (is_post()) {
    // The token is checked before any field is read, so a cross-site post is
    // rejected before it can influence anything.
    Csrf::verifyRequest();

    // Repopulate the harmless fields on a failure. remember_old_input() strips
    // passwords and the token, so the confirmation box can never be echoed.
    remember_old_input([
        'full_name' => input_string('full_name'),
        'email'     => input_string('email'),
    ]);

    $password     = input_string('password');
    $confirmation = input_string('password_confirmation');

    $validator = Validator::for($_POST)
        ->text('full_name', 'Full name', 2, 120)
        ->email('email')
        ->password('password', 'Password')
        ->password('password_confirmation', 'Password confirmation');

    // "The two boxes do not match" is a relationship between two fields, not a
    // property of either one, so it is checked here rather than by a rule.
    //
    // When a field already has a message — an empty confirmation box, say —
    // Validator::reject() keeps the first one, so the more specific
    // "is required" wins and the student is not told both.
    if ($password !== '' && $password !== $confirmation) {
        $validator->reject('password_confirmation', 'The two passwords do not match.');
    }

    try {
        $clean = $validator->validate();

        // The role is FIXED HERE. It is never read from the request.
        //
        // create() writes its own `account.create` audit entry, so this page
        // does not log again -- two rows for one registration would misreport
        // what happened.
        //
        // This call is INSIDE the try for a reason. create() raises
        // ValidationException when the email is already taken, and that is the
        // single most common thing a student gets wrong. With the call outside
        // the try, that exception escaped to the global handler and the student
        // got the styled 'Something went wrong' page instead of a message under
        // the email field. The Phase 3 suite caught it: the duplicate WAS
        // correctly refused, but the page said nothing about why.
        $user = UserRepository::create(
            (string) $clean['full_name'],
            (string) $clean['email'],
            Passwords::hash((string) $clean['password']),
            UserRole::Student,
            true
        );
    } catch (ValidationException $exception) {
        // Every complaint travels back at once so the student fixes them in a
        // single pass instead of discovering one per submission.
        field_errors_set($exception->errors());
        flash_set('error', $exception->getMessage());

        redirect('/register.php');
    }
    forget_old_input();

    // Hand the new User ID to the sign-in page so it can prefill the field.
    remember_old_input(['identifier' => (string) $user['user_code']]);

    flash_set(
        'success',
        'Your account is ready. Your User ID is ' . (string) $user['user_code'] . '.'
    );

    redirect('/login.php');
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------

$minLength = Config::int('security.password_min_length', 8);

$errors = field_errors_take();

Layout::authStart([
    'title'    => 'Create a student account',
    'subtitle' => 'Register to order from the canteen.',
    'scripts'  => ['/assets/js/auth.js'],
]);

echo '<h2 class="auth-heading">Create your account</h2>' . "\n";

echo '<form method="post" action="' . e(url('/register.php')) . '" novalidate data-form>' . "\n";

echo '  ' . Csrf::field() . "\n";
echo '  <div class="stack">' . "\n";

Layout::field('full_name', 'Full name', $errors, [
    'autocomplete' => 'name',
    'autofocus'    => true,
    'maxlength'    => 120,
    'hint'         => 'As it appears on your school record.',
]);

Layout::field('email', 'Email address', $errors, [
    'type'         => 'email',
    'autocomplete' => 'email',
    'inputmode'    => 'email',
    'maxlength'    => 254,
    'spellcheck'   => false,
]);

Layout::field('password', 'Password', $errors, [
    'type'         => 'password',
    'autocomplete' => 'new-password',
    'data'         => ['strength' => 'password-strength'],
    'hint'         => sprintf('At least %d characters.', $minLength),
]);

Layout::field('password_confirmation', 'Confirm password', $errors, [
    'type'         => 'password',
    'autocomplete' => 'new-password',
]);

echo "  </div>\n";

// Strength meter, filled in by assets/js/auth.js. It starts hidden and stays
// hidden without JavaScript, rather than showing an empty bar that looks like
// a verdict the page has not actually reached.
echo '  <div class="password-meter" data-strength-meter hidden>' . "\n";
echo '    <div class="password-meter__track">'
    . '<span class="password-meter__fill" data-strength-fill></span></div>' . "\n";
echo '    <p class="password-meter__label" data-strength-label>Password strength</p>' . "\n";
echo "  </div>\n";

echo '  <button class="btn btn--lg btn--block mt-6" type="submit" data-submit>Create account</button>' . "\n";
echo "</form>\n";

echo '<details class="auth-legal">' . "\n";
echo '  <summary class="field__hint">Why do we ask for an email address?</summary>' . "\n";
echo '  <p>Your canteen needs a way to reach you if your account is deactivated or you forget your '
    . 'password. Nothing is sent to it automatically, and the address is never shared outside this '
    . 'server.</p>' . "\n";
echo "</details>\n";

echo '<p class="auth-footer">Already registered? <a href="' . e(url('/login.php'))
    . '">Sign in</a></p>' . "\n";

Layout::authEnd();