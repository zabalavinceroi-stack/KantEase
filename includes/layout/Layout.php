<?php

declare(strict_types=1);

namespace KantEase;

use function KantEase\e;
use function KantEase\flash_take;
use function KantEase\send_page_headers;

/**
 * Renders the two page shells.
 *
 * Both panels share one set of markup; only the navigation and the accent
 * differ. Keeping the shell in one class means a change to the sidebar, the
 * topbar or the document head happens once rather than in six page files.
 *
 * Nothing here contains business logic. Pages pass in what they already have
 * and this decides how it looks.
 *
 * Content-Security-Policy note: the only inline script is the theme bootstrap,
 * which has to run before the first paint or the page flashes white for a
 * dark-theme user. It is allowed by a per-request nonce rather than by
 * 'unsafe-inline', so the policy stays strict.
 */
final class Layout
{
    /**
     * Page-specific scripts, carried from appStart()/authStart() to documentFoot().
     *
     * The footer is a separate call from the header, so the list cannot travel
     * as an argument. Before this existed, `appStart(['scripts' => [...]])` and
     * `authStart(['scripts' => [...]])` accepted the option and silently threw
     * it away: documentHead() received the array and never used it, and
     * appEnd()/authEnd() called documentFoot() with no arguments. A page asking
     * for its own script got a page with no script and no warning.
     *
     * @var list<string>
     */
    private static array $pendingScripts = [];

    /**
     * Open the document, print the shared <head>, and start the app shell.
     *
     * @param array{
     *     title?: string,
     *     user?: array<string, mixed>|null,
     *     heading?: string,
     *     subtitle?: string,
     *     actions?: string,
     *     scripts?: list<string>,
     *     stylesheets?: list<string>
     * } $options
     */
    public static function appStart(array $options = []): void
    {
        $user      = $options['user'] ?? Auth::user();
        $role      = $user === null ? null : UserRole::tryFrom((string) $user['role']);
        $title     = (string) ($options['title'] ?? 'KantEase');
        $heading   = (string) ($options['heading'] ?? $title);
        $subtitle  = (string) ($options['subtitle'] ?? '');
        $actions   = (string) ($options['actions'] ?? '');
        $nonce     = csp_nonce();

        if ($role === null) {
            redirect(Router::landingPath());
        }

        self::$pendingScripts = self::scriptList($options['scripts'] ?? []);

        self::documentHead($title, $nonce, $options['scripts'] ?? [], $options['stylesheets'] ?? []);

        $userName = (string) ($user['full_name'] ?? '');
        $userCode = (string) ($user['user_code'] ?? '');

        echo '<a class="skip-link" href="#main-content">Skip to content</a>' . "\n";

        echo '<div class="app-shell">' . "\n";

        self::sidebar($role, $userName, $userCode);

        echo '<button class="scrim" type="button" data-scrim hidden aria-label="Close navigation"></button>' . "\n";

        echo '<div class="app-main">' . "\n";
        echo '  <header class="app-topbar">' . "\n";
        echo '    <button class="icon-button sidebar-toggle" type="button" data-sidebar-toggle ' .
             'aria-expanded="false" aria-label="Open navigation" aria-controls="app-navigation">' .
             self::icon('menu-bars') . '</button>' . "\n";
        echo '    <h1 class="app-topbar__title">' . e($heading) . "</h1>\n";
        echo '    <span class="app-topbar__spacer"></span>' . "\n";

        // Both glyphs are printed server-side and the stylesheet shows whichever
        // one matches the active theme.
        //
        // This button used to be emitted completely empty — no icon, no text and
        // no aria-label — and only acquired a glyph once core.js ran. It was an
        // unlabelled mystery box to a screen reader until scripts loaded, and it
        // stayed blank for good if the script never ran at all.
        echo '    <button class="icon-button theme-toggle" type="button" data-theme-toggle ' .
             'aria-label="Switch colour theme" title="Switch colour theme">' . "\n";
        echo '      ' . self::icon('moon', 'icon--theme-moon') . "\n";
        echo '      ' . self::icon('sun', 'icon--theme-sun') . "\n";
        echo "    </button>\n";

        self::accountMenu($role, $userName, $userCode);

        echo '  </header>' . "\n";

        echo '  <main class="app-content" id="main-content" tabindex="-1">' . "\n";

        if ($subtitle !== '' || $actions !== '') {
            echo '    <div class="page-header">' . "\n";
            echo '      <div class="page-header__text">' . "\n";
            echo '        <h2>' . e($title) . "</h2>\n";

            if ($subtitle !== '') {
                echo '        <p>' . e($subtitle) . "</p>\n";
            }

            echo "      </div>\n";

            if ($actions !== '') {
                echo '      <div class="page-header__actions">' . $actions . "</div>\n";
            }

            echo "    </div>\n";
        }

        self::alerts();
    }

    /**
     * Close the app shell and print the scripts.
     */
    public static function appEnd(): void
    {
        echo "  </main>\n";
        echo "</div>\n";
        echo "</div>\n";

        self::documentFoot();
    }

    /**
     * Open the shell used by sign-in, registration and other public pages.
     *
     * @param array{title?: string, subtitle?: string, scripts?: list<string>, stylesheets?: list<string>} $options
     */
    public static function authStart(array $options = []): void
    {
        $title = (string) ($options['title'] ?? 'KantEase');
        $nonce = csp_nonce();

        self::$pendingScripts = self::scriptList($options['scripts'] ?? []);

        self::documentHead($title, $nonce, $options['scripts'] ?? [], $options['stylesheets'] ?? []);

        echo '<div class="auth-page">' . "\n";
        echo '  <div class="auth-shell">' . "\n";
        echo '    <div class="auth-card">' . "\n";

        echo '      <a class="auth-brand" href="' . e(url('/')) . '">' . "\n";
        echo '        <span class="auth-brand__mark">K</span>' . "\n";
        echo '        <span>KantEase</span>' . "\n";
        echo "      </a>\n";

        self::alerts();

        if (isset($options['subtitle']) && $options['subtitle'] !== '') {
            echo '      <p class="auth-subtitle">' . e($options['subtitle']) . "</p>\n";
        }
    }

    public static function authEnd(): void
    {
        echo '      <button class="icon-button auth-theme-toggle" type="button" data-theme-toggle ' .
             'aria-label="Switch colour theme" title="Switch colour theme">' . "\n";
        echo '        ' . self::icon('moon', 'icon--theme-moon') . "\n";
        echo '        ' . self::icon('sun', 'icon--theme-sun') . "\n";
        echo "      </button>\n";
        echo "    </div>\n";
        echo "  </div>\n";
        echo '  <footer class="auth-footer">' . "\n";
        echo '    <p class="text-faint text-small">KantEase runs on your school\'s own server. '
             . 'No internet connection is required.</p>' . "\n";
        echo "  </footer>\n";
        echo "</div>\n";

        self::documentFoot();
    }

    /**
     * The sidebar navigation for one role.
     *
     * Every entry in the navigation model is rendered. Entries whose page does
     * not exist yet become a disabled row with no href, so the structure is
     * visible and honest at the same time: nothing in the menu is a dead link,
     * and nothing pretends to work.
     */
    private static function sidebar(UserRole $role, string $userName, string $userCode): void
    {
        $active = Router::activeKey();

        echo '<aside class="app-sidebar" id="app-navigation">' . "\n";

        echo '  <div class="app-sidebar__head">' . "\n";
        echo '    <a class="app-sidebar__brand" href="' . e(url(Router::homeFor($role))) . '">' . "\n";
        echo '      <span class="app-sidebar__mark" aria-hidden="true">K</span>' . "\n";
        echo '      <span class="app-sidebar__identity">' . "\n";
        echo '        <span class="app-sidebar__wordmark">KantEase</span>' . "\n";
        echo '        <span class="app-sidebar__panel">' . e($role->label()) . ' panel</span>' . "\n";
        echo "      </span>\n";
        echo "    </a>\n";
        echo '    <button class="icon-button app-sidebar__close" type="button" data-sidebar-close ' .
             'aria-label="Close navigation" title="Close navigation">' . self::icon('close') . "</button>\n";
        echo "  </div>\n";

        echo '  <nav class="app-sidebar__nav" aria-label="' . e($role->label()) . ' panel">' . "\n";

        foreach (Router::sidebarNavGrouped($role) as $groupLabel => $items) {
            echo '    <div class="nav-group">' . "\n";
            echo '      <p class="nav-group__label">' . e($groupLabel) . "</p>\n";

            foreach ($items as $item) {
                $isActive = $item['available'] && $item['key'] === $active;

                if ($item['available']) {
                    echo '      <a class="nav-item" href="' . e(url($item['path'])) . '"';

                    if ($isActive) {
                        echo ' aria-current="page"';
                    }

                    echo ">\n";
                } else {
                    // No href, and not focusable: a disabled row must not be
                    // reachable by keyboard either, or Tab would stop on
                    // something that cannot be activated.
                    echo '      <span class="nav-item nav-item--disabled" aria-disabled="true">' . "\n";
                }

                echo '        <span class="nav-item__icon">' . self::icon($item['icon']) . "</span>\n";
                echo '        <span class="nav-item__label">' . e($item['label']) . "</span>\n";

                if (! $item['available']) {
                    echo '        <span class="nav-item__tag" aria-hidden="true">Soon</span>' . "\n";
                    echo '        <span class="visually-hidden">Not available yet</span>' . "\n";
                }

                echo $item['available'] ? "      </a>\n" : "      </span>\n";
            }

            echo "    </div>\n";
        }

        echo "  </nav>\n";

        echo '  <div class="app-sidebar__footer">' . "\n";
        echo '    <div class="app-sidebar__user">' . "\n";
        echo '      <span class="app-sidebar__avatar" aria-hidden="true">' . e(self::initials($userName)) . "</span>\n";
        echo '      <span class="app-sidebar__user-text">' . "\n";
        echo '        <span class="app-sidebar__name">' . e($userName) . "</span>\n";
        echo '        <span class="app-sidebar__user-meta">' . "\n";
        echo '          <span class="app-sidebar__code">' . e($userCode) . "</span>\n";
        echo '          <span class="badge ' . e($role->badgeClass()) . '">' . e($role->label()) . "</span>\n";
        echo "        </span>\n";
        echo "      </span>\n";
        echo "    </div>\n";

        // Last item of the navigation, and a plain POST form so signing out
        // works with JavaScript disabled and can never be triggered by a
        // prefetch following a link.
        echo '    <form method="post" action="' . e(url('/logout.php')) . '">' . "\n";
        echo '      ' . Csrf::field() . "\n";
        echo '      <button class="nav-item nav-item--logout" type="submit">' . "\n";
        echo '        <span class="nav-item__icon">' . self::icon('logout') . "</span>\n";
        echo '        <span class="nav-item__label">Logout</span>' . "\n";
        echo "      </button>\n";
        echo "    </form>\n";

        echo "  </div>\n";
        echo "</aside>\n";
    }

    /**
     * The account control in the top-right of the panel.
     *
     * The trigger is a real <button> with aria-expanded and aria-haspopup, and
     * the panel is a labelled region rather than a list of bare links, so the
     * whole thing is reachable and operable from the keyboard. core.js adds the
     * open/close behaviour; without it the button is still a focusable,
     * labelled control and nothing on the page is broken.
     *
     * Profile and Settings are shown as disabled rows while their pages are
     * being built, for the same reason as the sidebar: visible structure, no
     * dead links.
     */
    private static function accountMenu(UserRole $role, string $userName, string $userCode): void
    {
        $profilePath = $role === UserRole::Admin
            ? '/admin/profile.php'
            : '/student/profile.php';

        echo '    <div class="account-menu" data-account-menu>' . "\n";
        echo '      <button class="account-trigger" type="button" data-account-trigger ' .
             'aria-expanded="false" aria-haspopup="true" aria-controls="account-panel">' . "\n";
        echo '        <span class="account-trigger__avatar" aria-hidden="true">' . e(self::initials($userName)) . "</span>\n";
        echo '        <span class="account-trigger__text">' . "\n";
        echo '          <span class="account-trigger__name">' . e($userName) . "</span>\n";
        echo '          <span class="account-trigger__role">' . e($role->label()) . "</span>\n";
        echo "        </span>\n";
        echo '        <span class="account-trigger__caret" aria-hidden="true">' .
             self::icon('chevron-down', 'icon--sm') . "</span>\n";
        echo "      </button>\n";

        echo '      <div class="account-panel" id="account-panel" data-account-panel hidden ' .
             'aria-label="Account">' . "\n";
        echo '        <p class="account-panel__name">' . e($userName) . "</p>\n";
        echo '        <p class="account-panel__meta">' . e($userCode) . " · " . e($role->label()) . "</p>\n";
        echo '        <div class="account-panel__rule" role="presentation"></div>' . "\n";

        self::accountRow($profilePath, 'user', 'Profile', 'Coming in Phase 4');

        self::accountRow('', 'sliders', 'Account Settings', 'Coming in Phase 4');

        echo '        <div class="account-panel__rule" role="presentation"></div>' . "\n";

        echo '        <form method="post" action="' . e(url('/logout.php')) . '">' . "\n";
        echo '          ' . Csrf::field() . "\n";
        echo '          <button class="account-row account-row--danger" type="submit">' . "\n";
        echo '            <span class="account-row__icon">' . self::icon('logout') . "</span>\n";
        echo "            <span>Logout</span>\n";
        echo "          </button>\n";
        echo "        </form>\n";

        echo "      </div>\n";
        echo "    </div>\n";
    }

    /**
     * One row of the account dropdown.
     *
     * A row is a link only when its page exists; otherwise it is a disabled
     * <span> with the reason beside it, so nobody clicks through to a 404.
     *
     * @param string $note Optional muted explanation, e.g. "Coming in Phase 4".
     */
    private static function accountRow(string $path, string $icon, string $label, string $note = ''): void
    {
        $available = $path !== '' && Router::pageExists($path);

        echo '        ';

        if ($available) {
            echo '<a class="account-row" href="' . e(url($path)) . '">' . "\n";
        } else {
            echo '<span class="account-row account-row--disabled" aria-disabled="true">' . "\n";
        }

        echo '          <span class="account-row__icon">' . self::icon($icon) . "</span>\n";
        echo '          <span class="account-row__label">' . e($label) . "</span>\n";

        if ($note !== '') {
            echo '          <span class="account-row__note">' . e($note) . "</span>\n";
        }

        echo $available ? "        </a>\n" : "        </span>\n";
    }

    /**
     * Up to two initials for an avatar, taken from the first and last word.
     *
     * Falls back to the brand letter for a name that has no letters at all, so
     * the avatar is never an empty circle.
     */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));

        $first = $words === [] ? '' : (string) mb_substr($words[0], 0, 1);
        $last  = count($words) > 1 ? (string) mb_substr($words[count($words) - 1], 0, 1) : '';

        $letters = mb_strtoupper($first . $last);

        return $letters !== '' ? $letters : 'K';
    }

    /**
     * One labelled form field, with its hint and its validation message.
     *
     * Every value that came from the user is escaped here, so no page has to
     * remember to. `$errors` is keyed by field name, which is exactly the shape
     * Validator::errors() returns, so a page passes the validator's own output
     * straight through.
     *
     * Re-population deliberately reads old() rather than $_POST: the old-input
     * helper strips passwords and CSRF tokens before storing anything, so a
     * failed sign-in cannot echo the submitted password back into the page.
     *
     * @param array<string, string>              $errors
     * @param array{
     *     type?: string,
     *     value?: string,
     *     hint?: string,
     *     autocomplete?: string,
     *     required?: bool,
     *     autofocus?: bool,
     *     inputmode?: string,
     *     maxlength?: int,
     *     spellcheck?: bool,
     *     placeholder?: string,
     *     reveal?: bool,
     *     data?: array<string, string>
     * } $options
     */
    public static function field(string $name, string $label, array $errors = [], array $options = []): void
    {
        $type    = (string) ($options['type'] ?? 'text');
        $error   = $errors[$name] ?? '';
        $id      = 'field-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        $hint    = (string) ($options['hint'] ?? '');
        $hasHint = $hint !== '';

        // Explicit $options['value'] wins, so a page can seed a field itself.
        $value = array_key_exists('value', $options)
            ? (string) $options['value']
            : (string) old($name, '');

        // A password field gets a Show/Hide button beside it. The button is
        // type="button", carries no name and sits outside the input, so using
        // it can neither submit the form nor change what the server receives.
        $revealable = $type === 'password' && ($options['reveal'] ?? true) === true;

        echo '<div class="field' . ($error !== '' ? ' field--invalid' : '') . '">' . "\n";

        echo '  <label class="field__label" for="' . e($id) . '">' . e($label);

        if ($options['required'] ?? true) {
            echo ' <span aria-hidden="true">*</span>';
        }

        echo "</label>\n";

        if ($revealable) {
            echo '  <div class="password-field">' . "\n";
        }

        echo '  <input type="' . e($type) . '"' . "\n";
        echo '         id="' . e($id) . '"' . "\n";
        echo '         name="' . e($name) . '"' . "\n";

        if ($type !== 'password') {
            echo '         value="' . e($value) . '"' . "\n";
        }

        echo '         autocomplete="' . e((string) ($options['autocomplete'] ?? 'off')) . '"' . "\n";

        if (($options['required'] ?? true) === true) {
            echo "         required\n";
        }

        if (($options['autofocus'] ?? false) === true) {
            echo "         autofocus\n";
        }

        foreach (['inputmode', 'placeholder'] as $attribute) {
            if (isset($options[$attribute]) && $options[$attribute] !== '') {
                echo '         ' . $attribute . '="' . e((string) $options[$attribute]) . '"' . "\n";
            }
        }

        if (isset($options['maxlength']) && $options['maxlength'] > 0) {
            echo '         maxlength="' . (int) $options['maxlength'] . '"' . "\n";
        }

        if (array_key_exists('spellcheck', $options)) {
            echo '         spellcheck="' . ($options['spellcheck'] ? 'true' : 'false') . '"' . "\n";
        }

        // Wiring the label, hint and error to the input means a screen reader
        // announces the problem with the field rather than as loose text.
        echo '         aria-describedby="' . e($id . '-describe') . '"';

        if ($error !== '') {
            echo ' aria-invalid="true"';
        }

        foreach (($options['data'] ?? []) as $key => $item) {
            echo ' data-' . e((string) $key) . '="' . e((string) $item) . '"';
        }

        echo ">\n";

        if ($revealable) {
            echo '  <button class="password-field__toggle" type="button"' . "\n";
            echo '          data-password-toggle="#' . e($id) . '"' . "\n";
            echo '          aria-pressed="false" aria-controls="' . e($id) . '">Show</button>' . "\n";
            echo "  </div>\n";
        }

        echo '  <div id="' . e($id . '-describe') . '">';

        if ($hasHint) {
            echo '    <p class="field__hint">' . e($hint) . "</p>\n";
        }

        if ($error !== '') {
            echo '    <p class="field__error">' . e($error) . "</p>\n";
        }

        echo "  </div>\n";
        echo "</div>\n";
    }

    /**
     * Flash messages queued by the previous request.
     */
    public static function alerts(): void
    {
        $messages = flash_take();

        if ($messages === []) {
            return;
        }

        echo '      <div class="stack-sm mb-6" role="status" aria-live="polite">' . "\n";

        foreach ($messages as $message) {
            $iconName = match ($message['type']) {
                'error'   => 'warning',
                'info'    => 'info',
                'warning' => 'warning',
                default   => 'check',
            };

            echo '        <div class="alert alert--' . e($message['type']) . '">' . "\n";
            echo '          <span class="alert__icon">' . self::icon($iconName) . "</span>\n";
            echo '          <span>' . e($message['message']) . "</span>\n";
            echo "        </div>\n";
        }

        echo "      </div>\n";
    }

    /**
     * <head>, the theme bootstrap, and the opening <body>.
     *
     * @param list<string> $scripts
     * @param list<string> $stylesheets
     */
    private static function documentHead(string $title, string $nonce, array $scripts, array $stylesheets): void
    {
        // Send the security headers before a single byte of HTML is written.
        //
        // This was missing, and the Phase 3 suite caught it. Only
        // error_handler.php and database/setup.php called send_page_headers(),
        // so every ordinary application page — sign in, registration, both
        // dashboards — went out with NO Content-Security-Policy at all and with
        // X-Powered-By naming the exact PHP version.
        //
        // The earlier web suite missed it because it measured headers on
        // database/setup.php, the one page that did send them. That is the cost
        // of checking headers on one page and assuming the rest match.
        send_page_headers();

        $basePath = Config::basePath();

        // Runs before the stylesheets are parsed, so a dark-theme user never
        // sees a white flash. Kept tiny on purpose.
        $themeBootstrap = <<<'JS'
        (function () {
            var theme = '';
            try { theme = window.localStorage.getItem('kantease-theme') || ''; } catch (e) {}
            if (theme !== 'light' && theme !== 'dark') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            document.documentElement.setAttribute('data-theme', theme);
        })();
        JS;

        echo '<!doctype html>' . "\n";
        echo '<html lang="en">' . "\n";
        echo "<head>\n";
        echo '  <meta charset="utf-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '  <meta name="robots" content="noindex, nofollow">' . "\n";
        echo '  <meta name="csrf-token" content="' . e(Csrf::token()) . '">' . "\n";
        echo '  <meta name="base-path" content="' . e($basePath) . '">' . "\n";
        echo '  <title>' . e($title) . ' — KantEase</title>' . "\n";

        foreach (self::stylesheetList($stylesheets) as $href) {
            echo '  <link rel="stylesheet" href="' . e(url($href)) . '">' . "\n";
        }

        echo '  <script nonce="' . e($nonce) . '">' . "\n";
        echo $themeBootstrap;
        echo "\n  </script>\n";

        echo "</head>\n";
        echo "<body>\n";
    }

    /**
     * The closing scripts and the live region for toasts.
     *
     * @param list<string> $scripts
     */
    private static function documentFoot(array $scripts = []): void
    {
        // core.js first: with `defer` the browser runs these in document
        // order, so a page script can rely on the helpers core.js defines.
        $scripts = array_values(array_unique([
            '/assets/js/core.js',
            ...self::scriptList($scripts),
            ...self::$pendingScripts,
        ]));

        foreach ($scripts as $src) {
            echo '<script src="' . e(url($src)) . '" defer></script>' . "\n";
        }

        echo '<div class="toast-region" id="toast-region" role="status" aria-live="polite"></div>' . "\n";
        echo "</body>\n";
        echo "</html>\n";
    }

    /**
     * Stylesheets every page loads, in cascade order.
     *
     * @param  list<string> $extra
     * @return list<string>
     */
    private static function stylesheetList(array $extra): array
    {
        return array_values(array_unique([
            '/assets/css/tokens.css',
            '/assets/css/base.css',
            '/assets/css/components.css',
            '/assets/css/layout.css',
            ...$extra,
        ]));
    }

    /**
     * Scripts every page loads, in dependency order.
     *
     * @param  list<string> $extra
     * @return list<string>
     */
    private static function scriptList(array $extra): array
    {
        return array_values(array_unique($extra));
    }

    /**
     * An inline SVG icon, matching the set in assets/js/core.js.
     *
     * Duplicated on purpose: PHP needs the markup server-side and JavaScript
     * needs it for dynamically built nodes. Both lists are short and stable.
     */
    public static function icon(string $name, string $extraClass = ''): string
    {
        $paths = [
            'sun'          => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon'         => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
            'home'         => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
            'menu'         => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'cart'         => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 7H6"/>',
            'orders'       => '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5M9 12h7M9 16h7"/>',
            'user'         => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
            'box'          => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
            'users'        => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/><path d="M17 4.6a3.5 3.5 0 0 1 0 6.8M18.5 21c0-2.6-.9-4.6-2.4-6"/>',
            'chart'        => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            'search'       => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
            'check'        => '<path d="M4 12.5 9 17.5 20 6.5"/>',
            'close'        => '<path d="M6 6l12 12M18 6L6 18"/>',
            'plus'         => '<path d="M12 5v14M5 12h14"/>',
            'minus'        => '<path d="M5 12h14"/>',
            'warning'      => '<path d="M12 3 2 20h20z"/><path d="M12 9v5M12 17.2v.1"/>',
            'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.8v.1"/>',
            'bag'          => '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
            'logout'       => '<path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 3H4v18h8"/>',
            'menu-bars'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',

            // -- Added in Phase 4A for the canteen navigation ----------------
            'utensils'     => '<path d="M6 3v6a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V3"/><path d="M6 6h4"/><path d="M8 11v10"/><path d="M17 3c-1.7 1-2.6 2.6-2.6 4.6 0 1.7 1 2.9 2.6 3.2V3z"/><path d="M17 10.8V21"/>',
            'tag'          => '<path d="M3 11.5V4a1 1 0 0 1 1-1h7.4a2 2 0 0 1 1.4.6l7.6 7.6a2 2 0 0 1 0 2.8l-7.6 7.6a2 2 0 0 1-2.8 0l-7.6-7.6a2 2 0 0 1-.6-1.4z"/><circle cx="7.4" cy="7.4" r="1.4"/>',
            'shield'       => '<path d="M12 3l8 3v6c0 4.4-3.3 7.9-8 9-4.7-1.1-8-4.6-8-9V6z"/><path d="M8.8 12l2.2 2.2 4.2-4.4"/>',
            'sliders'      => '<path d="M4 7h9M19 7h1M4 17h3M13 17h7"/><circle cx="16" cy="7" r="3"/><circle cx="10" cy="17" r="3"/>',
            'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 6.8V12l3.4 2.1"/>',
            'chevron-down' => '<path d="M6 9.5l6 6 6-6"/>',
        ];

        $path = $paths[$name] ?? null;

        if ($path === null) {
            return '';
        }

        return '<svg class="icon ' . e($extraClass) . '" viewBox="0 0 24 24" width="20" height="20" '
            . 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }
}