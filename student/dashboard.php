<?php

declare(strict_types=1);

/**
 * KantEase — student dashboard.
 *
 * The landing page a student reaches after signing in, and the first page of
 * the student panel.
 *
 * Guarded by Router::requirePanel(), which enforces three things on every
 * request, not just this one:
 *
 *   1. Not signed in      -> redirected to /login.php with a safe return path
 *   2. Wrong role         -> redirected to that role's own home
 *   3. Signed out          -> a deactivated account is treated as signed out
 *      (Auth::user() re-reads is_active every request, so an administrator
 *       switching an account off takes effect immediately rather than when the
 *       session cookie happens to expire)
 *
 * Scope: this is the navigation shell and the account summary. Ordering is a
 * later phase, so nothing here pretends to be the ordering panel.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

use KantEase\Layout;
use KantEase\Router;
use KantEase\UserRole;

use function KantEase\e;
use function KantEase\format_datetime;
use function KantEase\url;

$user = Router::requirePanel(UserRole::Student);

Layout::appStart([
    'title'    => 'Dashboard',
    'heading'  => 'Dashboard',
    'subtitle' => 'Your canteen account at a glance.',
]);

?>
    <div class="stat-grid">
      <div class="stat">
        <span class="stat__label">User ID</span>
        <span class="stat__value mono"><?= e((string) $user['user_code']) ?></span>
        <span class="stat__hint">Sign in with this or your email address.</span>
      </div>

      <div class="stat">
        <span class="stat__label">Status</span>
        <span class="stat__value">
          <span class="badge badge--student">Active</span>
        </span>
        <span class="stat__hint">
          <?= (int) $user['is_active'] === 1
              ? 'You can sign in and order.'
              : 'Ask the canteen staff to reactivate your account.' ?>
        </span>
      </div>

      <div class="stat">
        <span class="stat__label">Member since</span>
        <span class="stat__value"><?= e(format_datetime((string) ($user['created_at'] ?? null), 'M j, Y')) ?></span>
        <span class="stat__hint">Your registration date.</span>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card__header">
        <h3 class="card__title">What happens next</h3>
      </div>
      <div class="card__body">
        <p>
          Your account is ready and your sign-in works. Ordering is being added
          next: once the canteen switches it on you will be able to browse today's
          menu, place an order and follow its status from this page.
        </p>
        <p class="text-muted">
          Nothing here is a placeholder for something you should be able to use
          today. When ordering arrives, it will appear in the menu on the left.
        </p>
      </div>
    </div>
<?php

Layout::appEnd();