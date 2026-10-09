/**
 * KantEase — core front-end runtime
 *
 * Loaded on every page. Deliberately small and dependency-free: there is no
 * build step, no package manager and nothing fetched from the internet.
 *
 * Responsibilities
 *   - Theme switching (light / dark / follow the system)
 *   - A fetch wrapper that carries the CSRF token, speaks JSON, and turns a
 *     dead server into a readable message instead of a raw browser error
 *   - Toasts and a focus-trapped confirmation dialog
 *   - Peso and date formatting, matching the PHP side exactly
 *
 * Everything is exposed on a single global, `KantEase`, and is written as
 * ES2020 modules-free plain script so it works with `script-src 'self'`.
 */

(function () {
    'use strict';

    /* ======================================================================
       Constants
       ====================================================================== */

    var THEME_KEY = 'kantease-theme';
    var THEMES = ['light', 'dark'];

    /** Milliseconds a toast stays on screen. */
    var TOAST_MS = 4000;

    /* ======================================================================
       Theme
       ====================================================================== */

    var Theme = {
        /** The theme currently applied to <html>. */
        current: function () {
            return document.documentElement.getAttribute('data-theme') || 'light';
        },

        /**
         * What the visitor has chosen.
         * 'system' means "follow the operating system".
         */
        preference: function () {
            try {
                var stored = window.localStorage.getItem(THEME_KEY);
                if (THEMES.indexOf(stored) !== -1) {
                    return stored;
                }
            } catch (error) {
                /* Private browsing can refuse storage. Not a problem. */
            }
            return 'system';
        },

        /**
         * The theme to actually paint: the stored choice, or what the
         * operating system is asking for.
         */
        resolved: function () {
            var preference = Theme.preference();

            if (preference !== 'system') {
                return preference;
            }

            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light';
        },

        apply: function (theme) {
            document.documentElement.setAttribute('data-theme', theme);
            syncThemeButtons(theme);
        },

        setPreference: function (theme) {
            try {
                if (theme === 'system') {
                    window.localStorage.removeItem(THEME_KEY);
                } else {
                    window.localStorage.setItem(THEME_KEY, theme);
                }
            } catch (error) {
                /* Storage unavailable; the theme still applies for this page. */
            }

            Theme.apply(Theme.resolved());
        },

        toggle: function () {
            Theme.setPreference(Theme.current() === 'dark' ? 'light' : 'dark');
        }
    };

    /** Keep every [data-theme-toggle] button showing the right icon. */
    function syncThemeButtons(theme) {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        var toDark = theme !== 'dark';

        Array.prototype.forEach.call(buttons, function (button) {
            button.setAttribute('aria-label', toDark ? 'Switch to dark mode' : 'Switch to light mode');
            button.setAttribute('title', toDark ? 'Switch to dark mode' : 'Switch to light mode');

            // The glyph itself is swapped by the stylesheet, which keys off
            // [data-theme] on <html>.
            //
            // This used to rewrite innerHTML. Because it did, the button was
            // completely empty in the server's HTML and only ever gained a
            // glyph once this file had loaded and run — blank with scripts
            // blocked, blank mid-load, and unlabelled to a screen reader
            // until then. Both glyphs are now printed by the layout instead.
        });
    }

    /* ======================================================================
       Icons
       Inline SVG so nothing is fetched over the network. `currentColor`
       means an icon always matches the text beside it.
       ====================================================================== */

    var ICON_PATHS = {
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
        cart: '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.6 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 7H6"/>',
        orders: '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5M9 12h7M9 16h7"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        box: '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 21c0-3.9 3.1-6.5 7-6.5s7 2.6 7 6.5"/><path d="M17 4.6a3.5 3.5 0 0 1 0 6.8M18.5 21c0-2.6-.9-4.6-2.4-6"/>',
        chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        check: '<path d="M4 12.5 9 17.5 20 6.5"/>',
        close: '<path d="M6 6l12 12M18 6L6 18"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        minus: '<path d="M5 12h14"/>',
        warning: '<path d="M12 3 2 20h20z"/><path d="M12 9v5M12 17.2v.1"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.8v.1"/>',
        bag: '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        logout: '<path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 3H4v18h8"/>',
        'menu-bars': '<path d="M4 7h16M4 12h16M4 17h16"/>',

        // Added in Phase 4A for the canteen navigation. Kept in step with the
        // identical table in includes/layout/Layout.php.
        utensils: '<path d="M6 3v6a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V3"/><path d="M6 6h4"/><path d="M8 11v10"/><path d="M17 3c-1.7 1-2.6 2.6-2.6 4.6 0 1.7 1 2.9 2.6 3.2V3z"/><path d="M17 10.8V21"/>',
        tag: '<path d="M3 11.5V4a1 1 0 0 1 1-1h7.4a2 2 0 0 1 1.4.6l7.6 7.6a2 2 0 0 1 0 2.8l-7.6 7.6a2 2 0 0 1-2.8 0l-7.6-7.6a2 2 0 0 1-.6-1.4z"/><circle cx="7.4" cy="7.4" r="1.4"/>',
        shield: '<path d="M12 3l8 3v6c0 4.4-3.3 7.9-8 9-4.7-1.1-8-4.6-8-9V6z"/><path d="M8.8 12l2.2 2.2 4.2-4.4"/>',
        sliders: '<path d="M4 7h9M19 7h1M4 17h3M13 17h7"/><circle cx="16" cy="7" r="3"/><circle cx="10" cy="17" r="3"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 6.8V12l3.4 2.1"/>',
        'chevron-down': '<path d="M6 9.5l6 6 6-6"/>'
    };

    /** Build an inline <svg> string for one of the names above. */
    function icon(name, extraClass) {
        var path = ICON_PATHS[name];

        if (!path) {
            return '';
        }

        return '<svg class="icon ' + (extraClass || '') + '" viewBox="0 0 24 24" width="20" height="20" ' +
            'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" ' +
            'stroke-linejoin="round" aria-hidden="true" focusable="false">' + path + '</svg>';
    }

    /* ======================================================================
       Formatting
       Mirrors includes/functions.php exactly, so a peso shown by the server
       and one formatted here are byte-identical.
       ====================================================================== */

    /**
     * Format a number of centavos as ₱1,234.56
     *
     * @param {number} cents
     * @returns {string}
     */
    function peso(cents) {
        var value = Number(cents);

        if (!isFinite(value)) {
            return '₱0.00';
        }

        var negative = value < 0;
        var absolute = Math.abs(Math.round(value));
        var whole = Math.floor(absolute / 100);
        var fraction = absolute % 100;

        return (negative ? '-₱' : '₱') +
            whole.toLocaleString('en-PH') + '.' +
            (fraction < 10 ? '0' : '') + fraction;
    }

    /**
     * Format a MySQL DATETIME for display, in the configured timezone.
     *
     * MySQL values arrive as 'YYYY-MM-DD HH:MM:SS' in UTC. The space is
     * swapped for a 'T' because Safari refuses to parse it otherwise.
     *
     * @param {string|null} value
     * @returns {string}
     */
    function dateTime(value) {
        if (!value) {
            return '—';
        }

        var parsed = new Date(String(value).replace(' ', 'T') + 'Z');

        if (isNaN(parsed.getTime())) {
            return '—';
        }

        return parsed.toLocaleString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function date(value) {
        if (!value) {
            return '—';
        }

        var parsed = new Date(String(value).replace(' ', 'T') + 'Z');

        if (isNaN(parsed.getTime())) {
            return '—';
        }

        return parsed.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    /* ======================================================================
       Toasts
       ====================================================================== */

    function toastRegion() {
        var region = document.getElementById('toast-region');

        if (!region) {
            region = document.createElement('div');
            region.id = 'toast-region';
            region.className = 'toast-region';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }

        return region;
    }

    /**
     * Show a transient message.
     *
     * @param {string} message
     * @param {'success'|'error'|'info'} [type]
     */
    function toast(message, type) {
        var kind = type || 'success';
        var node = document.createElement('div');

        node.className = 'toast toast--' + kind;
        node.append(
            textSpan(icon(kind === 'error' ? 'warning' : kind === 'info' ? 'info' : 'check')),
            textSpan(document.createTextNode(message))
        );

        toastRegion().appendChild(node);

        window.setTimeout(function () {
            node.classList.add('is-leaving');
            window.setTimeout(function () {
                node.remove();
            }, 220);
        }, TOAST_MS);
    }

    function textSpan(content) {
        var span = document.createElement('span');
        span.className = 'toast__text';

        if (typeof content === 'string') {
            span.innerHTML = content;
        } else {
            span.appendChild(content);
        }

        return span;
    }

    /* ======================================================================
       Confirmation dialog
       Replaces window.confirm(), which cannot be styled and blocks the whole
       page. Keeps keyboard focus inside the dialog while it is open.
       ====================================================================== */

    var dialog = null;
    var lastFocused = null;

    /**
     * @param {object} options
     * @param {string} options.title
     * @param {string} [options.message]
     * @param {string} [options.confirmLabel]
     * @param {boolean} [options.danger]
     * @returns {Promise<boolean>}
     */
    function confirmDialog(options) {
        if (dialog) {
            dialog.remove();
        }

        lastFocused = document.activeElement;

        dialog = document.createElement('div');
        dialog.className = 'modal';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'kantease-dialog-title');

        var headingId = 'kantease-dialog-title';

        dialog.innerHTML =
            '<div class="modal__panel modal__panel--narrow">' +
                '<div class="modal__header">' +
                    '<h2 id="' + headingId + '"></h2>' +
                '</div>' +
                '<div class="modal__body"><p></p></div>' +
                '<div class="modal__footer">' +
                    '<button type="button" class="btn btn--ghost" data-role="cancel"></button>' +
                    '<button type="button" class="btn" data-role="confirm"></button>' +
                '</div>' +
            '</div>';

        dialog.querySelector('h2').textContent = options.title || 'Are you sure?';

        var body = dialog.querySelector('.modal__body p');
        body.textContent = options.message || '';

        if (!options.message) {
            body.remove();
        }

        var confirmButton = dialog.querySelector('[data-role="confirm"]');
        confirmButton.textContent = options.confirmLabel || 'Confirm';

        if (options.danger) {
            confirmButton.classList.add('btn--danger');
        }

        var cancelButton = dialog.querySelector('[data-role="cancel"]');
        cancelButton.textContent = 'Cancel';

        return new Promise(function (resolve) {
            function close(answer) {
                document.removeEventListener('keydown', onKeydown, true);
                dialog.remove();
                dialog = null;

                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus();
                }

                resolve(answer);
            }

            function onKeydown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    close(false);
                    return;
                }

                // Keep Tab inside the dialog.
                if (event.key === 'Tab') {
                    var focusable = dialog.querySelectorAll('button, [href], input, select, textarea');
                    if (!focusable.length) {
                        return;
                    }

                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];

                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            }

            confirmButton.addEventListener('click', function () { close(true); });
            cancelButton.addEventListener('click', function () { close(false); });

            dialog.addEventListener('mousedown', function (event) {
                if (event.target === dialog) {
                    close(false);
                }
            });

            document.addEventListener('keydown', onKeydown, true);
            document.body.appendChild(dialog);
            confirmButton.focus();
        });
    }

    /* ======================================================================
       HTTP
       ====================================================================== */

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /** Raised when the server could not be reached at all. */
    function ConnectionError(message) {
        this.name = 'ConnectionError';
        this.message = message;
    }
    ConnectionError.prototype = Object.create(Error.prototype);

    var CONNECTION_MESSAGE =
        'Cannot reach the KantEase server. Check that it is switched on and that you are on the same network, then try again.';

    /**
     * Fetch with JSON in and JSON out.
     *
     *   - attaches the CSRF token to every state-changing request
     *   - sends a session-ending request to the sign-in page on 401
     *   - turns a dead server into a ConnectionError with a readable message,
     *     rather than the browser's bare "Failed to fetch"
     *
     * @param {string} url
     * @param {object} [options]
     * @returns {Promise<object>}
     */
    function request(url, options) {
        var settings = options || {};
        var method = (settings.method || 'GET').toUpperCase();
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var init = { method: method, credentials: 'same-origin', headers: headers };

        if (settings.body !== undefined && settings.body !== null) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(settings.body);
        }

        if (method !== 'GET' && method !== 'HEAD') {
            headers['X-CSRF-Token'] = csrfToken();
        }

        return fetch(url, init).then(function (response) {
            // A 401 from any endpoint means the session ended. Send the
            // visitor to sign in rather than leaving a dead page on screen.
            if (response.status === 401 && typeof settings.onUnauthorised !== 'function') {
                window.location.href = url_path('login.php');
                throw new ConnectionError('Your session has ended.');
            }

            var type = response.headers.get('Content-Type') || '';

            if (type.indexOf('application/json') === -1) {
                if (!response.ok) {
                    throw new Error('The server returned an unexpected response (' + response.status + ').');
                }
                return {};
            }

            return response.json().then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload && payload.error ? payload.error : 'Request failed.');
                    error.status = response.status;
                    error.payload = payload;
                    throw error;
                }

                return payload;
            });
        }, function (networkError) {
            // A rejected fetch means the request never reached a server.
            throw new ConnectionError(CONNECTION_MESSAGE);
        });
    }

    /** Build an application URL with the configured base path. */
    function url_path(path) {
        var base = document.documentElement.getAttribute('data-base-path') || '';
        return base + '/' + String(path).replace(/^\/+/, '');
    }

    /**
     * POST JSON to a URL-encoded form endpoint (no JSON accept header).
     * Used by forms that post normally and then follow a redirect.
     *
     * @param {string} url
     * @param {object} body
     */
    function postForm(url, body) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = url;
        form.hidden = true;

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_csrf';
        token.value = csrfToken();
        form.appendChild(token);

        Object.keys(body || {}).forEach(function (key) {
            var field = document.createElement('input');
            field.type = 'hidden';
            field.name = key;
            field.value = body[key];
            form.appendChild(field);
        });

        document.body.appendChild(form);
        form.submit();
    }

    /* ======================================================================
       Sidebar drawer
       ====================================================================== */

    function setupDrawer() {
        var shell = document.querySelector('.app-shell');
        var toggle = document.querySelector('[data-sidebar-toggle]');
        var scrim = document.querySelector('[data-scrim]');
        var close = document.querySelector('[data-sidebar-close]');

        if (!shell || !toggle) {
            return;
        }

        function setOpen(open) {
            shell.classList.toggle('is-nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');

            if (scrim) {
                scrim.hidden = !open;
            }

            // Moving focus into the drawer when it opens, and back to the
            // button that opened it when it closes, is what makes it usable
            // from the keyboard rather than only with a mouse.
            if (open) {
                var first = shell.querySelector('.app-sidebar__close, .nav-item, .nav-item--logout');

                if (first) {
                    first.focus();
                }
            } else if (shell.contains(document.activeElement)) {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!shell.classList.contains('is-nav-open'));
        });

        if (scrim) {
            scrim.addEventListener('click', function () {
                setOpen(false);
            });
        }

        // The close button lives inside the drawer, on small screens only.
        if (close) {
            close.addEventListener('click', function () {
                setOpen(false);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && shell.classList.contains('is-nav-open')) {
                setOpen(false);
                toggle.focus();
            }
        });

        // Following a link inside the drawer navigates anyway, but closing
        // first keeps the state honest for in-page anchors and for browsers
        // that restore the page from bfcache.
        Array.prototype.forEach.call(
            shell.querySelectorAll('.app-sidebar a[href]'),
            function (link) {
                link.addEventListener('click', function () {
                    shell.classList.remove('is-nav-open');

                    if (scrim) {
                        scrim.hidden = true;
                    }
                });
            }
        );

        // Close the drawer when the viewport grows past the breakpoint, so it
        // is not left hidden behind a sticky scrim on a tablet.
        window.addEventListener('resize', function () {
            if (window.innerWidth > 900 && shell.classList.contains('is-nav-open')) {
                setOpen(false);
            }
        });
    }

    /* ======================================================================
       Account dropdown

       A disclosure button controlling a panel. Implemented by hand rather than
       with <details> so that it closes on Escape, on an outside click and on
       ArrowDown, and returns focus to the trigger when it closes.
       ====================================================================== */

    function setupAccountMenu() {
        var menus = document.querySelectorAll('[data-account-menu]');

        Array.prototype.forEach.call(menus, function (menu) {
            var trigger = menu.querySelector('[data-account-trigger]');
            var panel = menu.querySelector('[data-account-panel]');

            if (!trigger || !panel) {
                return;
            }

            function isOpen() {
                return trigger.getAttribute('aria-expanded') === 'true';
            }

            function setOpen(open) {
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.hidden = !open;
            }

            function focusables() {
                // Disabled rows are spans, so they are naturally absent here.
                return Array.prototype.filter.call(
                    panel.querySelectorAll('a[href], button:not([disabled])'),
                    function (node) {
                        return node.offsetParent !== null;
                    }
                );
            }

            trigger.addEventListener('click', function () {
                var next = !isOpen();

                setOpen(next);

                if (next) {
                    var items = focusables();

                    if (items.length) {
                        items[0].focus();
                    }
                }
            });

            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown' && !isOpen()) {
                    event.preventDefault();
                    setOpen(true);

                    var items = focusables();

                    if (items.length) {
                        items[0].focus();
                    }
                }
            });

            panel.addEventListener('keydown', function (event) {
                var items = focusables();

                if (items.length === 0) {
                    return;
                }

                var first = items[0];
                var last = items[items.length - 1];

                // Escape closes from anywhere inside and hands focus back.
                if (event.key === 'Escape') {
                    event.preventDefault();
                    setOpen(false);
                    trigger.focus();
                    return;
                }

                // Keep Tab inside the panel while it is open.
                if (event.key === 'Tab') {
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                    return;
                }

                // Up and Down walk the rows, wrapping at both ends.
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();

                    var index = items.indexOf(document.activeElement);

                    if (index === -1) {
                        return;
                    }

                    var step = event.key === 'ArrowDown' ? 1 : -1;
                    var nextIndex = (index + step + items.length) % items.length;

                    items[nextIndex].focus();
                }
            });

            document.addEventListener('click', function (event) {
                if (isOpen() && !menu.contains(event.target)) {
                    setOpen(false);
                }
            });

            document.addEventListener('focusin', function (event) {
                if (isOpen() && !menu.contains(event.target)) {
                    setOpen(false);
                }
            });
        });
    }

    /* ======================================================================
       Boot
       ====================================================================== */

    function boot() {
        Theme.apply(Theme.resolved());

        Array.prototype.forEach.call(
            document.querySelectorAll('[data-theme-toggle]'),
            function (button) {
                button.addEventListener('click', function () {
                    Theme.toggle();
                });
            }
        );

        // Follow the operating system until the visitor picks a theme.
        if (window.matchMedia) {
            var query = window.matchMedia('(prefers-color-scheme: dark)');
            var listener = function () {
                if (Theme.preference() === 'system') {
                    Theme.apply(Theme.resolved());
                }
            };

            if (typeof query.addEventListener === 'function') {
                query.addEventListener('change', listener);
            } else if (typeof query.addListener === 'function') {
                query.addListener(listener);
            }
        }

        setupDrawer();
        setupAccountMenu();

        // Surface an unexpected disconnection rather than letting a click do
        // nothing at all. Any element with data-connection-guard gets this.
        window.addEventListener('offline', function () {
            toast('You appear to be offline. KantEase needs a connection to the school server.', 'error');
        });
    }

    /* ======================================================================
       Public API
       ====================================================================== */

    window.KantEase = {
        Theme: Theme,
        icon: icon,
        peso: peso,
        dateTime: dateTime,
        date: date,
        toast: toast,
        confirm: confirmDialog,
        request: request,
        postForm: postForm,
        csrfToken: csrfToken,
        url: url_path,
        ConnectionError: ConnectionError
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();