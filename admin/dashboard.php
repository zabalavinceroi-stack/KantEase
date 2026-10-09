<?php

declare(strict_types=1);

/**
 * KantEase — administrator dashboard.
 *
 * The landing page canteen staff reach after signing in, and the first page of
 * the administrator panel.
 *
 * Guarded by Router::requirePanel(UserRole::Admin), which redirects a signed-out
 * visitor to the sign-in page and a signed-in student to their own dashboard.
 * The check is server-side and unconditional; the sidebar being different for
 * each role is a convenience, never the control.
 *
 * Scope: navigation shell and account summary. Canteen management — orders,
 * inventory, sales, accounts — is a later phase and is not faked here.
 */

require_once __DIR__ . '/../includes/bootstrap.php';

use KantEase\Layout;
use KantEase\Repositories\UserRepository;
use KantEase\Router;
use KantEase\UserRole;

use function KantEase\e;
use function KantEase\format_datetime;
use function KantEase\peso;
use function KantEase\url;

$user = Router::requirePanel(UserRole::Admin);

$studentCount = UserRepository::countActiveStudents();

Layout::appStart([
    'title'    => 'Dashboard',
    'heading'  => 'Dashboard',
    'subtitle' => 'Canteen administration.',
]);

?>
    <div class="stat-grid">
      <div class="stat">
        <span class="stat__label">Signed in as</span>
        <span class="stat__value"><?= e((string) $user['full_name']) ?></span>
        <span class="stat__hint mono"><?= e((string) $user['user_code']) ?></span>
      </div>

      <div class="stat">
        <span class="stat__label">Active students</span>
        <span class="stat__value tnum"><?= e((string) $studentCount) ?></span>
        <span class="stat__hint">Accounts that may currently sign in.</span>
      </div>

      <div class="stat">
        <span class="stat__label">Administrator since</span>
        <span class="stat__value"><?= e(format_datetime((string) ($user['created_at'] ?? null), 'M j, Y')) ?></span>
        <span class="stat__hint">
          Last signed in
          <?= e(format_datetime((string) ($user['last_login_at'] ?? null), 'M j, Y g:i A')) ?>.
        </span>
      </div>
    </div>

    <div class="card mt-6">
      <div class="card__header">
        <h3 class="card__title">What happens next</h3>
      </div>
      <div class="card__body">
        <p>
          Sign-in, registration and the two panels are working, and every action
          on them is enforced on the server. Canteen management is being added
          next: serving today's orders, adjusting stock, reading sales figures
          and managing accounts.
        </p>
        <p class="text-muted">
          Those sections are deliberately absent from the menu rather than shown
          as broken links. They will appear here as each one is built.
        </p>
      </div>
    </div>
<?php

Layout::appEnd();