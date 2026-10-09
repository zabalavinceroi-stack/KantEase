# KantEase — Phase 4A: panel UI repair

Repairs the navigation, sidebar, top bar and account controls for both panels.
No Phase 4 business feature is implemented here; this is the foundation they sit
on.

---

## 1. Root cause

The reported symptom was blank or unreadable navigation items and an empty
account button. There were **two independent causes**, and only one of them was
visible in the served HTML.

### 1a. `assets/js/core.js` did not parse — the whole front end was dead

```js
menu-bars: '<path d="M4 7h16M4 12h16M4 17h16"/>',
```

An unquoted object key in `ICON_PATHS`. JavaScript read `menu-bars` as an
expression, hit `-`, and threw:

```
SyntaxError: Unexpected token '-'
```

The entire file is one IIFE, so the parse error meant **none** of it ever ran.
Consequences:

| Broken | Symptom |
| --- | --- |
| `syncThemeButtons()` never ran | The top-right button stayed exactly as the server printed it: `<button class="icon-button" data-theme-toggle></button>` — **no icon, no text, no `aria-label`**. This is the empty button from the report. |
| `setupDrawer()` never ran | The mobile menu toggle did nothing. |
| Toasts, confirm dialog, fetch wrapper | All unavailable. |

The page still returned **HTTP 200**, every stylesheet still returned 200, and
every DOM string assertion still passed. The failure was in the browser, not in
the response, which is why it survived Phase 3.

Fixed by quoting the key, and guarded permanently — see §5.

### 1b. The navigation model was being hidden, not rendered

`Router::availableNavFor()` filtered `navFor()` down to entries whose PHP file
existed on disk. Only `admin/dashboard.php` and `student/dashboard.php` exist,
so **each sidebar rendered exactly one item**. The administrator got 1 of the 10
required entries; the student got 1 of 7. That is what reads as a blank menu.

### 1c. The active menu item was invisible in dark mode

Found only by measuring rendered pixels, after every structural check passed:

```
:active  color rgb(255,138,61)  background rgb(255,138,61)  contrast 1.00:1
```

`base.css` carried

```css
:root[data-theme="dark"] a { color: var(--brand-500); }
```

That scores `(0,2,1)` and beats `.nav-item[aria-current="page"]` at `(0,2,0)`,
so the orange active row was painted orange-on-orange. The same rule would have
done the same to `.skip-link` and to any `<a class="btn">`.

### 1d. Two smaller defects

- `.app-sidebar__close { display: none }` was declared *before* `.icon-button`
  in the same file at equal specificity, so `.icon-button`'s later
  `display: grid` won and the drawer close button sat permanently on the
  desktop sidebar.
- No `.icon` rule existed anywhere. `.icon--sm`, already used by the Sign out
  button, did nothing, and sizing depended entirely on SVG width/height
  attributes fighting `img, svg, video { height: auto }` in the reset.

### What was *not* wrong

Worth stating, because it redirected the investigation: the CSS loaded, the
colours were defined, the markup was escaped, and the labels were present in
the HTML all along. `--nav-text #d8e5f1` on `--nav-bg #12304e` is roughly 10:1.
No CDN, font or icon request was being made or failing.

---

## 2. Navigation model

`includes/routing.php` now defines the full Phase 4 structure. Availability is
resolved per entry by `pageExists()` rather than by deleting entries, so the
model is never edited twice.

**Administrator** — 10 entries, grouped:

| # | Label | Path | State |
| --- | --- | --- | --- |
| 1 | Dashboard | `/admin/dashboard.php` | live |
| 2 | Food Management | `/admin/food.php` | disabled |
| 3 | Categories | `/admin/categories.php` | disabled |
| 4 | Inventory | `/admin/inventory.php` | disabled |
| 5 | Orders | `/admin/orders.php` | disabled |
| 6 | Sales & Reports | `/admin/sales.php` | disabled |
| 7 | Student Accounts | `/admin/students.php` | disabled |
| 8 | Administrator Accounts | `/admin/administrators.php` | disabled |
| 9 | Settings | `/admin/settings.php` | disabled |
| 10 | Logout | POST `/logout.php` | live |

**Student** — 7 entries: Dashboard (live), Food Menu, My Cart, My Orders,
Order History, My Profile, Logout (live).

An entry whose page does not exist renders as

```html
<span class="nav-item nav-item--disabled" aria-disabled="true">
```

— no `href`, not focusable, tagged **SOON**, with a visually-hidden
"Not available yet". Disabled rows are spans rather than links so Tab never
stops on something that cannot be activated, and so nothing in the menu can
produce a 404.

`activeKey()` now compares the last two path segments, so it is unaffected by
`base_path` and stays correct now that both panels could have same-named pages.

---

## 3. Security consequence found while restructuring

`Router::pathBelongsTo()` is what `login.php` uses to validate `?next=`. It
consulted the full navigation list, so once placeholder entries existed, a
`?next=/admin/settings.php` would have been accepted and then 404. It now
requires **both** ownership and an existing file. The access control is
otherwise untouched: `requirePanel()`, CSRF, session handling, escaping and
`safe_path()` are unchanged, and logout remains POST-only so no prefetch can
trigger it.

---

## 4. What changed in the UI

- **Sidebar** — brand block with panel label, grouped nav, compact user footer
  (avatar, name, code, role badge) and Logout as the final row. Focus-visible
  rings on every row. The footer was 242px tall and pushed the menu into
  scrolling; it is now 124px and a 900px-tall laptop fits without scrolling.
- **Top bar** — page title, theme toggle, account dropdown.
- **Account dropdown** — avatar with initials, name and role; a panel with the
  profile and settings rows (disabled, labelled "Coming in Phase 4") and a
  Logout POST form. Opens on click, closes on Escape, outside click, focus
  leaving, or ArrowUp/ArrowDown cycling; Tab is kept inside the panel and focus
  returns to the trigger.
- **Theme toggle** — both glyphs are now printed server-side and the stylesheet
  picks one, so the button is never blank — not with scripts blocked, not
  mid-load, not before paint.
- **Drawer** — close button inside the drawer, focus moves in on open and back
  to the toggle on close, Escape and scrim still work, and it closes itself when
  the viewport grows past the breakpoint.
- **Icons** — six added (`utensils`, `tag`, `shield`, `sliders`, `clock`,
  `chevron-down`), all inline SVG using `currentColor`. Nothing is fetched.

---

## 5. Regression guard

`tools/run-all-checks.js` gained a **JavaScript syntax** stage. `php -l` only
inspects PHP, which is precisely why §1a went unnoticed for a whole phase.

Run the full suite with an explicit PHP path — `php` is not on PATH in this
XAMPP install, and the runner defaults to it:

```sh
node tools\run-all-checks.js D:\hatdog\php\php.exe
```

---

## 6. Verification

```sh
# Browser harness — real Chrome over the DevTools Protocol
node ke-browser-check.js                 # 57 checks

# Existing suites
php tests\verify-phase3.php              # 72 checks
php tests\verify-web.php                 # 42 checks
node tools\check-offline.js
node tools\run-all-checks.js D:\hatdog\php\php.exe
```

The browser harness signs in through the real form — no session is forged — and
fails on any console error, failed request or 4xx/5xx response.

### Test accounts

The harness needs two accounts. They are test fixtures, not real users, and they
carry known passwords, so they are removed after a run:

```sql
USE kantease_db;
DELETE FROM users WHERE user_code IN ('ADM-9001', 'STU-9001');
```

Recreate them with any password hash from `password_hash($pw, PASSWORD_DEFAULT)`.
No database credential was changed.

### A note on `tests/.live-fingerprint.txt`

`tools/run-all-checks.js` stages 6 and 8 compare a fingerprint of the live
database against a recorded baseline. Signing in writes to `activity_logs` and
`login_attempts`, so **performing the required login tests changes the live
database** and those two stages report CHANGED. That is the application working,
not a regression — the fingerprint was not rewritten to make the check pass.