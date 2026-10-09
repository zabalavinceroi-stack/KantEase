/**
 * KantEase — panel UI verification in a real browser.
 *
 *   node tools\check-panel-ui.js
 *
 * WHY THIS EXISTS
 *
 * Phase 4A opened with a report of blank navigation items. The markup was fine,
 * the CSS was fine, and every string assertion passed, because the actual
 * defect was a JavaScript syntax error in assets/js/core.js that stopped the
 * whole front-end runtime from ever executing. A test that only inspects
 * served HTML cannot see that class of fault at all: the response is a perfect
 * HTTP 200 with every label in it.
 *
 * So this drives a locally installed Chrome over the DevTools Protocol and
 * checks what a person actually gets — computed colours and contrast ratios,
 * whether a click opens the drawer, whether focus moves, whether the console
 * is clean.
 *
 * WHAT IT IS NOT
 *
 * Developer tooling. The application is HTML + CSS + vanilla JS + PHP and never
 * runs Node; this script is in tools/ alongside the existing Node checkers and
 * is not served. It needs a local Chrome, so it is deliberately not wired into
 * tools/run-all-checks.js.
 *
 * SIGNING IN
 *
 * It submits the real sign-in form, so no session is ever forged. Credentials
 * come from the environment and have no defaults; see the note on `credential`
 * below for why. Point them at a disposable account — see
 * docs/PHASE-4A-UI.md for how to seed one.
 *
 * It fails the run on any console error, failed request, or 4xx/5xx response.
 */

'use strict';

const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const BASE = process.env.KE_BASE || 'http://localhost/KantEase';
const CHROME = process.env.KE_CHROME
    || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const OUT = process.env.KE_OUT || path.join(os.tmpdir(), 'kantease-panel-ui');
const PORT = Number(process.env.KE_CDP_PORT || 9333);

// Credentials come from the environment and have no defaults on purpose.
// tests/verify-phase2.php fails any file containing a literal password
// assignment, and it is right to: a test password committed to a repository is
// still a password-shaped string. See docs/PHASE-4A-UI.md for how to seed a
// disposable account.
function credential(role) {
    const user = process.env[`KE_${role.toUpperCase()}_USER`] || '';
    const pass = process.env[`KE_${role.toUpperCase()}_PASS`] || '';
    if (!user || !pass) {
        throw new Error(
            `Set KE_${role.toUpperCase()}_USER and KE_${role.toUpperCase()}_PASS to an ` +
            'administrator and a student you are willing to sign in with.'
        );
    }
    return { identifier: user, password: pass };
}

const ACCOUNTS = { admin: credential('admin'), student: credential('student') };

const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900, mobile: false },
    { name: 'laptop', width: 1280, height: 800, mobile: false },
    { name: 'tablet', width: 820, height: 1180, mobile: true },
    { name: 'mobile', width: 390, height: 844, mobile: true }
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// Minimal CDP client
// ---------------------------------------------------------------------------

class Cdp {
    constructor(url) {
        this.ws = new WebSocket(url);
        this.nextId = 1;
        this.pending = new Map();
        this.listeners = new Map();
        this.ready = new Promise((resolve, reject) => {
            this.ws.addEventListener('open', () => resolve());
            this.ws.addEventListener('error', (e) => reject(new Error('CDP socket: ' + e.message)));
        });
        this.ws.addEventListener('message', (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && this.pending.has(msg.id)) {
                const { resolve, reject } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
                return;
            }
            if (msg.method && this.listeners.has(msg.method)) {
                for (const fn of this.listeners.get(msg.method)) fn(msg.params);
            }
        });
    }

    on(method, fn) {
        if (!this.listeners.has(method)) this.listeners.set(method, []);
        this.listeners.get(method).push(fn);
    }

    async send(method, params = {}, sessionId) {
        await this.ready;
        const id = this.nextId++;
        const payload = { id, method, params };
        if (sessionId) payload.sessionId = sessionId;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.ws.send(JSON.stringify(payload));
            setTimeout(() => {
                if (this.pending.has(id)) {
                    this.pending.delete(id);
                    reject(new Error('CDP timeout: ' + method));
                }
            }, 30000);
        });
    }

    close() { try { this.ws.close(); } catch { /* already gone */ } }
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

const results = { passed: 0, failed: 0, failures: [] };

function check(ok, label, detail = '') {
    if (ok) {
        results.passed++;
        console.log(`  \x1b[32mPASS\x1b[0m  ${label}${detail ? '  (' + detail + ')' : ''}`);
    } else {
        results.failed++;
        results.failures.push(label + (detail ? ' â€” ' + detail : ''));
        console.log(`  \x1b[31mFAIL\x1b[0m  ${label}${detail ? '  (' + detail + ')' : ''}`);
    }
    return ok;
}

function section(title) {
    console.log('\n' + '='.repeat(74) + '\n  ' + title + '\n' + '='.repeat(74));
}

async function evaluate(cdp, sessionId, expression) {
    const r = await cdp.send('Runtime.evaluate', {
        expression,
        returnByValue: true,
        awaitPromise: true
    }, sessionId);
    if (r.exceptionDetails) {
        throw new Error(r.exceptionDetails.exception?.description || 'JS threw');
    }
    return r.result.value;
}

async function main() {
    fs.mkdirSync(OUT, { recursive: true });

    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'ke-panel-ui-'));
    const chrome = spawn(CHROME, [
        '--headless=new',
        `--remote-debugging-port=${PORT}`,
        `--user-data-dir=${profile}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-gpu',
        '--hide-scrollbars',
        '--force-color-profile=srgb',
        'about:blank'
    ], { stdio: 'ignore' });

    let version = null;
    for (let i = 0; i < 60 && !version; i++) {
        await sleep(250);
        try {
            const r = await fetch(`http://127.0.0.1:${PORT}/json/version`);
            if (r.ok) version = await r.json();
        } catch { /* not up yet */ }
    }
    if (!version) {
        chrome.kill();
        throw new Error('Chrome did not expose a DevTools endpoint');
    }

    const cdp = new Cdp(version.webSocketDebuggerUrl);

    // One flat session driving the whole run.
    const { targetId } = await cdp.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });

    await cdp.send('Page.enable', {}, sessionId);
    await cdp.send('Runtime.enable', {}, sessionId);
    await cdp.send('Log.enable', {}, sessionId);
    await cdp.send('Network.enable', {}, sessionId);

    // Pin the viewport before anything is measured. Without this the window
    // falls back to the headless default, which is narrow enough to trip the
    // 900px media queries and short enough to force the sidebar to scroll â€”
    // so the desktop checks below would have been reading the mobile layout.
    await cdp.send('Emulation.setDeviceMetricsOverride', {
        width: 1440, height: 900, deviceScaleFactor: 1, mobile: false
    }, sessionId);

    const consoleErrors = [];
    const failedRequests = [];
    const badResponses = [];

    cdp.on('Runtime.consoleAPICalled', (p) => {
        if (p.type === 'error' || p.type === 'warning') {
            consoleErrors.push(p.type + ': ' + p.args.map((a) => a.value ?? a.description ?? '').join(' '));
        }
    });
    cdp.on('Runtime.exceptionThrown', (p) => {
        consoleErrors.push('uncaught: ' + (p.exceptionDetails?.exception?.description || p.exceptionDetails?.text));
    });
    cdp.on('Log.entryAdded', (p) => {
        if (p.entry.level === 'error') consoleErrors.push('log: ' + p.entry.text);
    });
    cdp.on('Network.loadingFailed', (p) => {
        failedRequests.push(p.errorText + (p.type ? ' (' + p.type + ')' : ''));
    });
    cdp.on('Network.responseReceived', (p) => {
        if (p.response.status >= 400) {
            badResponses.push(p.response.status + ' ' + p.response.url);
        }
    });

    async function goto(url) {
        await cdp.send('Page.navigate', { url }, sessionId);
        await sleep(1400);
    }

    /**
     * Poll an expression until it is truthy.
     *
     * Sleeping a fixed interval after a navigation is a race: `defer` scripts
     * may not have run yet, and Runtime.evaluate can land on the outgoing
     * document's context. Both produced a false failure here.
     */
    async function waitFor(expression, label, timeoutMs = 12000) {
        const deadline = Date.now() + timeoutMs;
        let last = null;
        while (Date.now() < deadline) {
            try {
                last = await evaluate(cdp, sessionId, expression);
                if (last) return last;
            } catch (error) {
                last = 'threw: ' + error.message;
            }
            await sleep(150);
        }
        throw new Error('timed out waiting for ' + label + ' (last value: ' + JSON.stringify(last) + ')');
    }

    async function signIn(who) {
        await goto(BASE + '/login.php');
        const account = ACCOUNTS[who];
        await evaluate(cdp, sessionId, `
            (function () {
                var form = document.querySelector('form');
                var set = function (n, v) {
                    var el = form.querySelector('[name="' + n + '"]');
                    if (el) el.value = v;
                };
                set('identifier', ${JSON.stringify(account.identifier)});
                set('password', ${JSON.stringify(account.password)});
                form.submit();
                return true;
            })()
        `);
        // Wait for the destination document, not for a guessed delay.
        await waitFor(
            'document.readyState === "complete" && location.pathname.indexOf("/login.php") === -1',
            'the signed-in page'
        );
        await waitFor('typeof window.KantEase === "object"', 'core.js to execute');
        return evaluate(cdp, sessionId, 'location.pathname');
    }

    async function signOut() {
        await evaluate(cdp, sessionId, `
            (function () {
                var form = document.querySelector('.app-sidebar__footer form');
                if (form) { form.submit(); return true; }
                return false;
            })()
        `);
        await sleep(1200);
        return evaluate(cdp, sessionId, 'location.pathname');
    }

    // =====================================================================
    section('ADMIN PANEL â€” sign in and shell');
    // =====================================================================

    const adminPath = await signIn('admin');
    check(adminPath === '/KantEase/admin/dashboard.php', 'A1 administrator signs in and lands on their dashboard', adminPath);

    check(
        await evaluate(cdp, sessionId, 'typeof window.KantEase === "object"'),
        'A2 core.js executed (this was the original defect)'
    );

    check(
        await evaluate(cdp, sessionId, '!!document.documentElement.getAttribute("data-theme")'),
        'A3 the theme bootstrap set data-theme before paint'
    );

    const adminNav = await evaluate(cdp, sessionId, `
        Array.prototype.map.call(
            document.querySelectorAll('.app-sidebar .nav-item'),
            function (n) {
                return {
                    label: (n.querySelector('.nav-item__label') || {}).textContent || '',
                    tag: n.tagName,
                    href: n.getAttribute('href'),
                    disabled: n.classList.contains('nav-item--disabled'),
                    active: n.getAttribute('aria-current') === 'page',
                    hasIcon: !!n.querySelector('.nav-item__icon svg'),
                    visible: n.offsetParent !== null || n.closest('.app-sidebar') !== null,
                    textColor: getComputedStyle(n).color
                };
            }
        )
    `);

    const ADMIN_REQUIRED = [
        'Dashboard', 'Food Management', 'Categories', 'Inventory', 'Orders',
        'Sales & Reports', 'Student Accounts', 'Administrator Accounts', 'Settings', 'Logout'
    ];

    check(adminNav.length === 10, 'A4 the administrator sidebar has all 10 required items',
        adminNav.length + ' rendered');

    const adminLabels = adminNav.map((i) => i.label);
    const missingAdmin = ADMIN_REQUIRED.filter((l) => !adminLabels.includes(l));
    check(missingAdmin.length === 0, 'A5 every required administrator label is present',
        missingAdmin.length ? 'missing: ' + missingAdmin.join(', ') : 'all ' + ADMIN_REQUIRED.length + ' found');

    const blankLabels = adminNav.filter((i) => i.label.trim() === '');
    check(blankLabels.length === 0, 'A6 no administrator menu label is blank', blankLabels.length + ' blank');

    const noIcon = adminNav.filter((i) => !i.hasIcon);
    check(noIcon.length === 0, 'A7 every administrator menu item has an inline SVG icon',
        noIcon.length ? noIcon.map((i) => i.label).join(', ') : '10/10');

    const activeAdmin = adminNav.filter((i) => i.active);
    check(activeAdmin.length === 1 && activeAdmin[0].label === 'Dashboard',
        'A8 exactly one item is marked as the current page',
        activeAdmin.map((i) => i.label).join(', ') || 'none');

    const linkedButMissing = adminNav.filter((i) => i.href && !i.disabled);
    check(linkedButMissing.length === 1 && linkedButMissing[0].href.endsWith('/admin/dashboard.php'),
        'A9 the only clickable administrator item is Dashboard',
        linkedButMissing.map((i) => i.label).join(', ') || 'none');

    const wronglyDisabled = adminNav.filter((i) => i.disabled && i.tag !== 'SPAN');
    check(wronglyDisabled.length === 0, 'A10 every disabled item is a <span>, never an <a href>',
        'no href on ' + (adminNav.length - linkedButMissing.length) + ' items');

    // Contrast is measured, not assumed. The active row used to compute to a
    // literal 1.00 â€” orange text on the orange accent â€” and every DOM assertion
    // above still passed, because the label was present, it had an icon, and it
    // carried aria-current. Only the rendered pixels gave it away.
    const contrastReport = await evaluate(cdp, sessionId, `
        (function () {
            var parse = function (c) {
                var m = c.match(/[\\d.]+/g);
                return m ? m.slice(0, 3).map(Number).map(function (n) { return n / 255; }) : null;
            };
            var lum = function (c) {
                var v = parse(c).map(function (n) {
                    return n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4);
                });
                return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
            };
            var ratio = function (fg, bg) {
                var a = lum(fg), b = lum(bg);
                if (a < b) { var t = a; a = b; b = t; }
                return Math.round(((a + 0.05) / (b + 0.05)) * 100) / 100;
            };
            var bgOf = function (node) {
                while (node) {
                    var c = getComputedStyle(node).backgroundColor;
                    if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') return c;
                    node = node.parentElement;
                }
                return 'rgb(255, 255, 255)';
            };
            var pick = function (sel) {
                var n = document.querySelector(sel);
                if (!n) return null;
                return { ratio: ratio(getComputedStyle(n).color, bgOf(n)) };
            };
            return {
                theme: document.documentElement.getAttribute('data-theme'),
                rows: Array.prototype.map.call(
                    document.querySelectorAll('.app-sidebar .nav-item'),
                    function (n) {
                        var label = n.querySelector('.nav-item__label');
                        if (!label) return null;
                        return {
                            label: label.textContent.trim(),
                            ratio: ratio(getComputedStyle(label).color, bgOf(n))
                        };
                    }
                ).filter(Boolean),
                skipLink: pick('.skip-link'),
                trigger: pick('.account-trigger__name')
            };
        })()
    `);

    const lowContrast = contrastReport.rows.filter((r) => r.ratio < 4.5);
    check(lowContrast.length === 0,
        'A12 every sidebar label meets WCAG AA contrast (>= 4.5:1) in the ' + contrastReport.theme + ' theme',
        lowContrast.length
            ? lowContrast.map((r) => r.label + ' ' + r.ratio + ':1').join(', ')
            : 'lowest ' + Math.min(...contrastReport.rows.map((r) => r.ratio)) + ':1 over '
              + contrastReport.rows.length + ' rows');

    check(contrastReport.skipLink === null || contrastReport.skipLink.ratio >= 4.5,
        'A13 the skip link is legible',
        contrastReport.skipLink ? contrastReport.skipLink.ratio + ':1' : 'not present');

    check(contrastReport.trigger === null || contrastReport.trigger.ratio >= 4.5,
        'A14 the account name in the top bar is legible',
        contrastReport.trigger ? contrastReport.trigger.ratio + ':1' : 'not present');

    // The sidebar must fit a 900px-tall laptop without its footer being pushed
    // out of sight, and the desktop close button must actually be hidden.
    const chromeUi = await evaluate(cdp, sessionId, `
        (function () {
            var sb = document.querySelector('.app-sidebar');
            var close = document.querySelector('.app-sidebar__close');
            var foot = document.querySelector('.app-sidebar__footer');
            return {
                scrolls: sb.scrollHeight > sb.clientHeight + 1,
                clientH: sb.clientHeight,
                scrollH: sb.scrollHeight,
                closeDisplay: close ? getComputedStyle(close).display : 'MISSING',
                footBottom: foot ? Math.round(foot.getBoundingClientRect().bottom) : 0
            };
        })()
    `);
    check(!chromeUi.scrolls, 'A15 the whole sidebar fits a 900px viewport without clipping',
        chromeUi.scrollH + 'px content in ' + chromeUi.clientH + 'px');
    check(chromeUi.closeDisplay === 'none', 'A16 the drawer close button is hidden on desktop',
        'display: ' + chromeUi.closeDisplay);
    check(chromeUi.footBottom <= chromeUi.clientH + 1,
        'A17 the user footer is fully visible, not pushed off the bottom',
        'footer bottom at ' + chromeUi.footBottom + 'px of ' + chromeUi.clientH + 'px');

    // =====================================================================
    section('ADMIN PANEL â€” top bar and account dropdown');
    // =====================================================================

    const topbar = await evaluate(cdp, sessionId, `
        (function () {
            var t = document.querySelector('.app-topbar__title');
            var theme = document.querySelector('[data-theme-toggle]');
            var trig = document.querySelector('[data-account-trigger]');
            var panel = document.querySelector('[data-account-panel]');
            var visibleIcons = theme
                ? Array.prototype.filter.call(theme.querySelectorAll('svg'), function (s) {
                      return getComputedStyle(s).display !== 'none';
                  }).length
                : 0;
            return {
                title: t ? t.textContent.trim() : '',
                themeToggleExists: !!theme,
                themeToggleIcons: theme ? theme.querySelectorAll('svg').length : 0,
                themeToggleVisibleIcons: visibleIcons,
                themeToggleLabel: theme ? (theme.getAttribute('aria-label') || '') : '',
                triggerExists: !!trig,
                triggerLabel: trig ? trig.textContent.replace(/\\s+/g, ' ').trim() : '',
                triggerExpanded: trig ? trig.getAttribute('aria-expanded') : null,
                triggerHasPopup: trig ? trig.getAttribute('aria-haspopup') : null,
                panelExists: !!panel,
                panelHiddenAtLoad: panel ? panel.hasAttribute('hidden') : null,
                logoutForms: document.querySelectorAll('form[action*="logout.php"]').length
            };
        })()
    `);

    check(topbar.title === 'Dashboard', 'A12 the top bar shows the current page title', topbar.title);

    check(topbar.themeToggleExists && topbar.themeToggleIcons === 2,
        'A13 the previously empty top-right button now ships its icons in the HTML',
        topbar.themeToggleIcons + ' svg rendered server-side');

    check(topbar.themeToggleVisibleIcons === 1,
        'A14 exactly one theme glyph is visible for the active theme',
        topbar.themeToggleVisibleIcons + ' visible');

    check(topbar.themeToggleLabel.length > 0, 'A15 the theme button is labelled for assistive tech',
        topbar.themeToggleLabel);

    check(topbar.triggerExists && topbar.triggerHasPopup === 'true',
        'A16 the account trigger exists and declares a popup');

    check(topbar.triggerExpanded === 'false' && topbar.panelHiddenAtLoad === true,
        'A17 the account dropdown starts closed');

    check(/Administrator/.test(topbar.triggerLabel),
        'A18 the account trigger shows the signed-in administrator and their role',
        topbar.triggerLabel);

    // Drive the dropdown for real.
    await evaluate(cdp, sessionId, 'document.querySelector("[data-account-trigger]").click()');
    await sleep(400);
    const opened = await evaluate(cdp, sessionId, `
        (function () {
            var t = document.querySelector('[data-account-trigger]');
            var p = document.querySelector('[data-account-panel]');
            return {
                expanded: t.getAttribute('aria-expanded'),
                hidden: p.hasAttribute('hidden'),
                rows: Array.prototype.map.call(p.querySelectorAll('.account-row'), function (r) {
                    return { text: r.textContent.replace(/\\s+/g,' ').trim(), tag: r.tagName, href: r.getAttribute('href') };
                })
            };
        })()
    `);
    check(opened.expanded === 'true' && opened.hidden === false,
        'A19 clicking the trigger opens the dropdown');
    check(opened.rows.some((r) => r.text.startsWith('Logout')),
        'A20 the dropdown offers a Logout action', opened.rows.length + ' rows');

    await cdp.send('Input.dispatchKeyEvent', {
        type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27
    }, sessionId);
    await sleep(300);
    const escaped = await evaluate(cdp, sessionId,
        'document.querySelector("[data-account-panel]").hasAttribute("hidden")');
    check(escaped, 'A21 Escape closes the dropdown');

    // Keyboard reachability: the trigger must be in the tab order.
    const tabbable = await evaluate(cdp, sessionId, `
        (function () {
            var t = document.querySelector('[data-account-trigger]');
            t.focus();
            return document.activeElement === t;
        })()
    `);
    check(tabbable, 'A22 the account trigger is focusable');

    const logoutForms = topbar.logoutForms;
    check(logoutForms >= 2, 'A23 logout is reachable from the sidebar and the dropdown',
        logoutForms + ' POST forms');

    // =====================================================================
    section('RESPONSIVE â€” drawer, breakpoints, overflow');
    // =====================================================================

    const responsive = [];

    for (const vp of VIEWPORTS) {
        await cdp.send('Emulation.setDeviceMetricsOverride', {
            width: vp.width,
            height: vp.height,
            deviceScaleFactor: 1,
            mobile: vp.mobile
        }, sessionId);
        await sleep(500);

        const probe = await evaluate(cdp, sessionId, `
            (function () {
                var shell = document.querySelector('.app-shell');
                var sidebar = document.querySelector('.app-sidebar');
                var main = document.querySelector('.app-main');
                var toggle = document.querySelector('[data-sidebar-toggle]');
                var close = document.querySelector('[data-sidebar-close]');
                var sb = sidebar.getBoundingClientRect();
                var mb = main.getBoundingClientRect();
                return {
                    docWidth: document.documentElement.scrollWidth,
                    winWidth: window.innerWidth,
                    sidebarOnScreen: sb.right > 1 && sb.left < window.innerWidth - 1,
                    mainLeft: Math.round(mb.left),
                    toggleVisible: toggle ? getComputedStyle(toggle).display !== 'none' : false,
                    closeVisible: close ? getComputedStyle(close).display !== 'none' : false,
                    navTextColor: getComputedStyle(document.querySelector('.nav-item')).color,
                    navWidth: Math.round(sb.width)
                };
            })()
        `);
        responsive.push({ vp, probe });
    }

    const desktop = responsive[0].probe;
    const mobile = responsive[responsive.length - 1].probe;

    check(desktop.sidebarOnScreen && desktop.mainLeft >= 240,
        'R1 on desktop the sidebar is visible and content is offset by it',
        'sidebar pinned, main starts at ' + desktop.mainLeft + 'px');

    check(!desktop.toggleVisible, 'R2 the drawer toggle is hidden on desktop');

    check(mobile.toggleVisible, 'R3 on mobile the menu toggle appears');

    // Drive the drawer for real on mobile.
    await evaluate(cdp, sessionId, 'document.querySelector("[data-sidebar-toggle]").click()');
    await sleep(500);
    const drawerOpen = await evaluate(cdp, sessionId, `
        (function () {
            var shell = document.querySelector('.app-shell');
            var sb = document.querySelector('.app-sidebar').getBoundingClientRect();
            var scrim = document.querySelector('[data-scrim]');
            return {
                open: shell.classList.contains('is-nav-open'),
                expanded: document.querySelector('[data-sidebar-toggle]').getAttribute('aria-expanded'),
                onScreen: sb.left > -1,
                scrimVisible: !scrim.hasAttribute('hidden'),
                focusInside: shell.querySelector('.app-sidebar').contains(document.activeElement)
            };
        })()
    `);
    check(drawerOpen.open && drawerOpen.onScreen, 'R4 the toggle opens the drawer on mobile');
    check(drawerOpen.expanded === 'true', 'R5 the toggle reports its state to assistive tech',
        'aria-expanded=' + drawerOpen.expanded);
    check(drawerOpen.scrimVisible, 'R6 a scrim appears behind the open drawer');
    check(drawerOpen.focusInside, 'R7 focus moves into the drawer when it opens');

    await evaluate(cdp, sessionId, 'document.querySelector("[data-sidebar-close]").click()');
    await sleep(500);
    const drawerClosed = await evaluate(cdp, sessionId, `
        (function () {
            var sb = document.querySelector('.app-sidebar').getBoundingClientRect();
            return {
                open: document.querySelector('.app-shell').classList.contains('is-nav-open'),
                offScreen: sb.right <= 1,
                focusBack: document.activeElement === document.querySelector('[data-sidebar-toggle]')
            };
        })()
    `);
    check(!drawerClosed.open && drawerClosed.offScreen, 'R8 the close button closes the drawer');
    check(drawerClosed.focusBack, 'R9 focus returns to the toggle when the drawer closes');

    const overflow = responsive.filter((r) => r.probe.docWidth > r.probe.winWidth + 1);
    check(overflow.length === 0, 'R10 no horizontal overflow at any tested width',
        overflow.length
            ? overflow.map((r) => r.vp.name + ': ' + r.probe.docWidth + '>' + r.probe.winWidth).join(', ')
            : responsive.map((r) => r.vp.name).join(', ') + ' all clean');

    const unreadable = responsive.filter((r) => !r.probe.navTextColor || r.probe.navTextColor === 'rgba(0, 0, 0, 0)');
    check(unreadable.length === 0, 'R11 navigation text keeps a colour at every width');

    // Screenshots at each width.
    for (const { vp, probe } of responsive) {
        await cdp.send('Emulation.setDeviceMetricsOverride', {
            width: vp.width, height: vp.height, deviceScaleFactor: 1, mobile: vp.mobile
        }, sessionId);
        await sleep(450);
        const shot = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
        fs.writeFileSync(path.join(OUT, `admin-${vp.name}.png`), Buffer.from(shot.data, 'base64'));
    }
    console.log(`  ----  screenshots written to ${OUT}`);

    // Dark theme capture, desktop.
    //
    // Asserted as a change rather than as a specific value: headless Chrome
    // reports prefers-color-scheme: dark, so the page may already be dark and
    // the click is what takes it to light.
    await cdp.send('Emulation.setDeviceMetricsOverride', {
        width: 1440, height: 900, deviceScaleFactor: 1, mobile: false
    }, sessionId);
    const themeBefore = await evaluate(cdp, sessionId,
        'document.documentElement.getAttribute("data-theme")');
    await evaluate(cdp, sessionId, 'document.querySelector("[data-theme-toggle]").click()');
    await sleep(600);
    const themeAfter = await evaluate(cdp, sessionId,
        'document.documentElement.getAttribute("data-theme")');
    const darkShot = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    fs.writeFileSync(path.join(OUT, 'panel-desktop-theme-' + themeAfter + '.png'),
        Buffer.from(darkShot.data, 'base64'));
    check(
        themeBefore !== themeAfter && ['light', 'dark'].includes(themeAfter),
        'R12 the theme toggle actually switches the theme',
        themeBefore + ' -> ' + themeAfter
    );

    // Both themes have to be legible, so measure again on the other one.
    for (const theme of ['light', 'dark']) {
        await evaluate(cdp, sessionId, `window.KantEase.Theme.setPreference('${theme}'); 1`, sessionId);
        await sleep(450);
        const r = await evaluate(cdp, sessionId, `
            (function () {
                var parse = function (c) { var m = c.match(/[\\d.]+/g); return m ? m.slice(0,3).map(Number).map(function(n){return n/255;}) : null; };
                var lum = function (c) { var v = parse(c).map(function(n){return n<=0.03928?n/12.92:Math.pow((n+0.055)/1.055,2.4);}); return 0.2126*v[0]+0.7152*v[1]+0.0722*v[2]; };
                var ratio = function (f, b) { var a = lum(f), c = lum(b); if (a < c) { var t=a; a=c; c=t; } return Math.round(((a+0.05)/(c+0.05))*100)/100; };
                var bgOf = function (n) { while (n) { var c = getComputedStyle(n).backgroundColor; if (c && c !== 'rgba(0, 0, 0, 0)') return c; n = n.parentElement; } return 'rgb(255,255,255)'; };
                var rows = Array.prototype.map.call(document.querySelectorAll('.app-sidebar .nav-item'), function (n) {
                    var l = n.querySelector('.nav-item__label');
                    return l ? { label: l.textContent.trim(), ratio: ratio(getComputedStyle(l).color, bgOf(n)) } : null;
                }).filter(Boolean);
                var skip = document.querySelector('.skip-link');
                return {
                    worst: rows.length ? Math.min.apply(null, rows.map(function (r) { return r.ratio; })) : 99,
                    offenders: rows.filter(function (r) { return r.ratio < 4.5; }).map(function (r) { return r.label + ' ' + r.ratio; }),
                    skip: skip ? ratio(getComputedStyle(skip).color, bgOf(skip)) : null
                };
            })()
        `);
        check(r.offenders.length === 0 && r.skip !== null && r.skip >= 4.5,
            'R13 every label and the skip link pass AA contrast in the ' + theme + ' theme',
            'worst label ' + r.worst + ':1, skip link ' + r.skip + ':1'
                + (r.offenders.length ? ' â€” ' + r.offenders.join(', ') : ''));
    }

    // Account dropdown open, for the record.
    await evaluate(cdp, sessionId, 'document.querySelector("[data-account-trigger]").click()');
    await sleep(400);
    const menuShot = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    fs.writeFileSync(path.join(OUT, 'panel-account-menu.png'), Buffer.from(menuShot.data, 'base64'));
    await cdp.send('Input.dispatchKeyEvent', {
        type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27
    }, sessionId);
    await sleep(300);

    // =====================================================================
    section('STUDENT PANEL â€” isolation and navigation');
    // =====================================================================

    check(await signOut() === '/KantEase/login.php', 'S1 the sidebar Logout signs the administrator out');

    const studentPath = await signIn('student');
    check(studentPath === '/KantEase/student/dashboard.php',
        'S2 the student signs in and lands on their dashboard', studentPath);

    const studentNav = await evaluate(cdp, sessionId, `
        Array.prototype.map.call(
            document.querySelectorAll('.app-sidebar .nav-item'),
            function (n) {
                return {
                    label: (n.querySelector('.nav-item__label') || {}).textContent || '',
                    tag: n.tagName,
                    href: n.getAttribute('href'),
                    disabled: n.classList.contains('nav-item--disabled'),
                    hasIcon: !!n.querySelector('.nav-item__icon svg')
                };
            }
        )
    `);

    const STUDENT_REQUIRED = [
        'Dashboard', 'Food Menu', 'My Cart', 'My Orders', 'Order History', 'My Profile', 'Logout'
    ];

    const studentLabels = studentNav.map((i) => i.label);
    const missingStudent = STUDENT_REQUIRED.filter((l) => !studentLabels.includes(l));
    check(missingStudent.length === 0, 'S3 all 7 required student items are present',
        missingStudent.length ? 'missing: ' + missingStudent.join(', ') : studentLabels.join(' | '));

    check(studentNav.length === 7, 'S4 the student sidebar has exactly the 7 required items',
        studentNav.length + ' rendered');

    check(studentNav.every((i) => i.hasIcon), 'S5 every student item has an icon');

    check(studentNav.filter((i) => !i.disabled && i.href).length === 1,
        'S6 the only clickable student item is Dashboard');

    const adminLeaks = await evaluate(cdp, sessionId, `
        (function () {
            var html = document.documentElement.outerHTML;
            return {
                adminPath: html.indexOf('/admin/') !== -1,
                adminWord: /Administrator|Food Management|Student Accounts|Inventory/.test(
                    document.querySelector('.app-sidebar').textContent
                )
            };
        })()
    `);
    check(!adminLeaks.adminPath, 'S7 no administrator path appears anywhere on the student page');
    check(!adminLeaks.adminWord, 'S8 no administrator label appears in the student sidebar');

    // Role isolation: a student typing an admin URL is redirected.
    await goto(BASE + '/admin/dashboard.php');
    check(await evaluate(cdp, sessionId, 'location.pathname') === '/KantEase/student/dashboard.php',
        'S9 a student who types /admin/dashboard.php is sent to their own dashboard',
        await evaluate(cdp, sessionId, 'location.pathname'));

    await goto(BASE + '/student/dashboard.php');
    const studentTopbar = await evaluate(cdp, sessionId,
        'document.querySelector(".account-trigger").textContent.replace(/\\s+/g," ").trim()');
    check(/Student/.test(studentTopbar) && !/Administrator/.test(studentTopbar),
        'S10 the account control shows the student role', studentTopbar);

    const shot = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    fs.writeFileSync(path.join(OUT, 'student-panel-desktop.png'), Buffer.from(shot.data, 'base64'));

    await cdp.send('Emulation.setDeviceMetricsOverride', {
        width: 390, height: 844, deviceScaleFactor: 1, mobile: true
    }, sessionId);
    await sleep(500);
    await evaluate(cdp, sessionId, 'document.querySelector("[data-sidebar-toggle]").click()');
    await sleep(600);
    const mShot = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    fs.writeFileSync(path.join(OUT, 'student-panel-mobile-drawer.png'), Buffer.from(mShot.data, 'base64'));

    await evaluate(cdp, sessionId, 'document.querySelector("[data-sidebar-close]").click()');
    await sleep(400);
    const mShot2 = await cdp.send('Page.captureScreenshot', { format: 'png' }, sessionId);
    fs.writeFileSync(path.join(OUT, 'student-panel-mobile.png'), Buffer.from(mShot2.data, 'base64'));

    // =====================================================================
    section('OFFLINE + CONSOLE HEALTH');
    // =====================================================================

    check(consoleErrors.length === 0, 'C1 no console errors or warnings',
        consoleErrors.length ? consoleErrors.slice(0, 6).join(' | ') : 'clean');

    check(failedRequests.length === 0, 'C2 no failed network requests',
        failedRequests.length ? failedRequests.join(', ') : 'clean');

    const realBad = badResponses.filter((b) => !b.includes('favicon'));
    check(realBad.length === 0, 'C3 no 4xx/5xx responses (no missing assets)',
        realBad.length ? realBad.join(', ') : 'clean');

    const offline = await evaluate(cdp, sessionId, `
        (function () {
            var html = document.documentElement.outerHTML;
            var ext = html.match(/(?:href|src)="(?:https?:)?\\/\\/[^"]+"/g) || [];
            return { count: ext.length, sample: ext.slice(0, 5) };
        })()
    `);
    check(offline.count === 0, 'C4 the page references no remote asset',
        offline.count ? offline.sample.join(', ') : 'fully local');

    const entries = await evaluate(cdp, sessionId, `
        (function () {
            return performance.getEntriesByType('resource')
                .map(function (e) { return e.name; })
                .filter(function (n) { return !n.startsWith('data:'); });
        })()
    `);
    const remote = entries.filter((u) => !u.startsWith(BASE));
    check(remote.length === 0, 'C5 every sub-resource came from the local server',
        entries.length + ' resources, all local');

    // =====================================================================
    section('RESULT');
    // =====================================================================

    console.log(`  ${results.passed} passed   ${results.failed} failed`);
    if (results.failures.length) {
        console.log('\n  Failures:');
        results.failures.forEach((f) => console.log('    - ' + f));
    }

    cdp.close();
    chrome.kill();

    // Chrome releases its profile directory asynchronously on Windows, so an
    // immediate rmSync races the shutdown and throws EPERM. Give it a moment,
    // then treat a leftover temp profile as harmless rather than a test
    // failure.
    await sleep(1500);
    try {
        fs.rmSync(profile, { recursive: true, force: true });
    } catch {
        console.log(`  ----  left the temporary Chrome profile at ${profile}`);
    }

    process.exit(results.failed === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error('\nHARNESS ERROR: ' + error.message);
    console.error(error.stack);
    process.exit(2);
});