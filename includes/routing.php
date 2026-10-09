<?php

declare(strict_types=1);

namespace KantEase;

use KantEase\Repositories\UserRepository;

/**
 * Navigation model and page guards.
 *
 * Two jobs:
 *
 *   1. Decide WHERE each role belongs. A student who types /admin/dashboard.php
 *      is redirected to their own dashboard rather than shown an error, and an
 *      administrator typing /student/dashboard.php is sent to theirs. This
 *      mirrors the original build, which redirected rather than blocked, and
 *      is friendlier without weakening anything — the guard below is still
 *      enforced on every request.
 *
 *   2. Describe the sidebar. One definition per role, used to render the menu,
 *      to mark the active item, and to check that a requested page is one the
 *      role is allowed to open.
 */
final class Router
{
    /**
     * The sidebar, per role.
     *
     * Each entry maps a page key to a file path, a label and an icon. Icons are
     * inline SVG names resolved by the layout partial, so nothing is fetched
     * from the internet.
     *
     * This is the FULL navigation: the contract for what the finished
     * application contains, whether or not the page exists yet. It drives
     * ownership checks (pathBelongsTo) and the sidebar itself. Availability is
     * worked out per entry by sidebarNavFor() rather than by deleting entries
     * here, so the model never has to be edited twice.
     *
     * Logout is deliberately absent from these lists. It is a POST-only action
     * rendered by the sidebar footer as a form, so it can never be triggered by
     * a prefetch, a crawler or a link preview following a GET.
     *
     * @return list<array{key: string, path: string, label: string, icon: string, group: string}>
     */
    public static function navFor(UserRole $role): array
    {
        return match ($role) {
            UserRole::Student => [
                [
                    'key'   => 'dashboard',
                    'path'  => '/student/dashboard.php',
                    'label' => 'Dashboard',
                    'icon'  => 'home',
                    'group' => 'Overview',
                ],
                [
                    'key'   => 'food-menu',
                    'path'  => '/student/menu.php',
                    'label' => 'Food Menu',
                    'icon'  => 'utensils',
                    'group' => 'Ordering',
                ],
                [
                    'key'   => 'cart',
                    'path'  => '/student/cart.php',
                    'label' => 'My Cart',
                    'icon'  => 'cart',
                    'group' => 'Ordering',
                ],
                [
                    'key'   => 'orders',
                    'path'  => '/student/orders.php',
                    'label' => 'My Orders',
                    'icon'  => 'orders',
                    'group' => 'Ordering',
                ],
                [
                    'key'   => 'history',
                    'path'  => '/student/history.php',
                    'label' => 'Order History',
                    'icon'  => 'clock',
                    'group' => 'Ordering',
                ],
                [
                    'key'   => 'profile',
                    'path'  => '/student/profile.php',
                    'label' => 'My Profile',
                    'icon'  => 'user',
                    'group' => 'Account',
                ],
            ],
            UserRole::Admin => [
                [
                    'key'   => 'dashboard',
                    'path'  => '/admin/dashboard.php',
                    'label' => 'Dashboard',
                    'icon'  => 'home',
                    'group' => 'Overview',
                ],
                [
                    'key'   => 'food',
                    'path'  => '/admin/food.php',
                    'label' => 'Food Management',
                    'icon'  => 'utensils',
                    'group' => 'Canteen',
                ],
                [
                    'key'   => 'categories',
                    'path'  => '/admin/categories.php',
                    'label' => 'Categories',
                    'icon'  => 'tag',
                    'group' => 'Canteen',
                ],
                [
                    'key'   => 'inventory',
                    'path'  => '/admin/inventory.php',
                    'label' => 'Inventory',
                    'icon'  => 'box',
                    'group' => 'Canteen',
                ],
                [
                    'key'   => 'orders',
                    'path'  => '/admin/orders.php',
                    'label' => 'Orders',
                    'icon'  => 'orders',
                    'group' => 'Service',
                ],
                [
                    'key'   => 'sales',
                    'path'  => '/admin/sales.php',
                    'label' => 'Sales & Reports',
                    'icon'  => 'chart',
                    'group' => 'Service',
                ],
                [
                    'key'   => 'students',
                    'path'  => '/admin/students.php',
                    'label' => 'Student Accounts',
                    'icon'  => 'users',
                    'group' => 'People',
                ],
                [
                    'key'   => 'administrators',
                    'path'  => '/admin/administrators.php',
                    'label' => 'Administrator Accounts',
                    'icon'  => 'shield',
                    'group' => 'People',
                ],
                [
                    'key'   => 'settings',
                    'path'  => '/admin/settings.php',
                    'label' => 'Settings',
                    'icon'  => 'sliders',
                    'group' => 'Account',
                ],
            ],
        };
    }

    /**
     * Is the PHP file behind a navigation entry actually on disk?
     *
     * Single source of truth for "can this be linked to yet". Used by the
     * sidebar (link vs disabled item) and by pathBelongsTo(), so a page that is
     * only a placeholder can never become a redirect target.
     */
    public static function pageExists(string $path): bool
    {
        $relative = ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        return $relative !== '' && is_file(KANTEASE_ROOT . '/' . $relative);
    }

    /**
     * The one page each role belongs on.
     */
    public static function homeFor(UserRole $role): string
    {
        return $role->homePath();
    }

    /**
     * Where a person with this role should land right now.
     *
     * Guests go to the sign-in page. Signed-in users go to their own panel,
     * never to the other one.
     */
    public static function landingPath(): string
    {
        $role = Auth::role();

        return $role === null ? '/login.php' : self::homeFor($role);
    }

    /**
     * Send the visitor to the right place for who they are.
     */
    public static function redirectToLanding(): never
    {
        redirect(self::landingPath());
    }

    /**
     * The nav key for the page currently being served.
     *
     * Compares the last two path segments, so it is unaffected by the
     * configured base path: SCRIPT_NAME '/KantEase/admin/dashboard.php' and
     * the entry '/admin/dashboard.php' both reduce to 'admin/dashboard.php'.
     * Matching on the folder as well as the filename matters now that both
     * panels can have a same-named page.
     */
    public static function activeKey(): string
    {
        $script = self::routeTail((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($script === '') {
            return '';
        }

        foreach ([UserRole::Student, UserRole::Admin] as $role) {
            foreach (self::navFor($role) as $item) {
                if (self::routeTail($item['path']) === $script) {
                    return $item['key'];
                }
            }
        }

        return '';
    }

    /**
     * The last two segments of a path, or the last one if there is only one.
     */
    private static function routeTail(string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('\\', '/', (string) parse_url($path, PHP_URL_PATH))),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            return '';
        }

        return implode('/', array_slice($segments, -2));
    }

    /**
     * Does this path belong to the panel for $role AND exist?
     *
     * Used by requirePanel() to tell "wrong role" apart from "no such page",
     * and by login.php to validate the ?next= return path. The file-existence
     * half matters: the navigation lists pages that are still being built, and
     * honouring ?next= for one of those would hand the visitor a bare 404.
     */
    public static function pathBelongsTo(UserRole $role, string $path): bool
    {
        $needle = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');

        foreach (self::navFor($role) as $item) {
            if ($needle === $item['path'] && self::pageExists($item['path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guard every page in one of the two panels.
     *
     * The whole of the access control lives here, so a new page cannot
     * accidentally ship without it: requiring this function is the only way a
     * page gets past the front door.
     *
     *   - Not signed in  -> sign-in page, with a safe return path
     *   - Wrong role     -> that role's own home page, with a message
     *   - Right role     -> the page loads
     *
     * @return array<string, mixed> the signed-in user
     */
    public static function requirePanel(UserRole $role): array
    {
        $user = Auth::user();

        if ($user === null) {
            flash_set('error', 'Please sign in to continue.');

            $current = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $next    = $current === '' ? '' : with_query(['next' => safe_path($current, '')], []);

            redirect('/login.php' . $next);
        }

        $actual = UserRole::tryFrom((string) $user['role']);

        if ($actual !== $role) {
            flash_set(
                'error',
                $actual === UserRole::Admin
                    ? 'You are signed in as an administrator. That area is for students.'
                    : 'You are signed in as a student. That area is for canteen staff.'
            );

            redirect(self::homeFor($actual ?? UserRole::Student));
        }

        return $user;
    }

    /**
     * The panels are grouped in the sidebar; this returns them grouped, in
     * order, for the layout partial.
     *
     * @return array<string, list<array{key: string, path: string, label: string, icon: string, group: string}>>
     */
    public static function groupedNavFor(UserRole $role): array
    {
        $grouped = [];

        foreach (self::navFor($role) as $item) {
            $grouped[$item['group']][] = $item;
        }

        return $grouped;
    }

    /**
     * The navigation entries with their availability resolved.
     *
     * navFor() is the full, intended navigation — the contract for what the
     * finished application contains. Rendering it verbatim mid-build would put
     * dead links in the sidebar: a student clicking "My Orders" before that
     * phase lands gets a bare Apache 404 with no styling and no way back.
     *
     * Dropping the unfinished entries instead was worse. During Phase 4A that
     * reduced each sidebar to a single "Dashboard" row, because no other page
     * exists yet, and the panel looked broken rather than unfinished.
     *
     * So every entry is returned, flagged with whether its page is on disk, and
     * the sidebar renders a flagged entry as a disabled, labelled row with no
     * href. Nothing links to a page that does not exist, the structure is
     * visible, and nothing is weakened: pathBelongsTo() still requires both
     * ownership and an existing file, and a page that does not exist cannot be
     * reached by guessing its URL because there is no file to reach.
     *
     * @return list<array{key: string, path: string, label: string, icon: string, group: string, available: bool}>
     */
    public static function sidebarNavFor(UserRole $role): array
    {
        $items = [];

        foreach (self::navFor($role) as $item) {
            $item['available'] = self::pageExists($item['path']);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * sidebarNavFor(), grouped, in order, for the layout partial.
     *
     * @return array<string, list<array{key: string, path: string, label: string, icon: string, group: string, available: bool}>>
     */
    public static function sidebarNavGrouped(UserRole $role): array
    {
        $grouped = [];

        foreach (self::sidebarNavFor($role) as $item) {
            $grouped[$item['group']][] = $item;
        }

        return $grouped;
    }

    /**
     * The signed-in user's own profile row.
     *
     * @return array<string, mixed>
     */
    public static function currentUser(): array
    {
        return Auth::requireLogin();
    }

    /**
     * Look up a user by their public code, for admin pages.
     *
     * @return array<string, mixed>|null
     */
    public static function findUser(string $userCode): ?array
    {
        return UserRepository::findByCode($userCode);
    }

    /**
     * The application root URL, for the logo link and the "back to" link.
     */
    public static function rootUrl(): string
    {
        return url('/');
    }
}