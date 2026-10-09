# KantEase — Phase 3 design

Prepared while Phase 2 runtime verification is outstanding.
Phase 2 is **UNVERIFIED** until `tests/verify-phase2.php` reports PASS.

---

## 1. Status summary

| Area | State |
|---|---|
| Schema, migration, config, CSRF, auth engine, repositories, routing, layout, design system | **Written, not executed** |
| Static cross-reference + bracket check | **PASS** (28 PHP files) |
| SQL statement structure | **PASS** (105 statements across both scripts) |
| `php -l`, database, migration, CSRF, RBAC, order integrity | **BLOCKED — no PHP or MySQL on this machine** |

---

## 2. Page inventory

Every panel page calls exactly one guard before anything else, so a new page
cannot ship unprotected.

| Page | Guard | Purpose |
|---|---|---|
| `/index.php` | — | Sends visitors to their correct destination |
| `/login.php` | redirect if signed in | Sign in |
| `/register.php` | redirect if signed in | Student sign-up **only** |
| `/logout.php` | POST + CSRF | Ends the session |
| `/student/dashboard.php` | `Router::requirePanel(Student)` | Personal figures, recent orders |
| `/student/menu.php` | `Router::requirePanel(Student)` | Browse, filter, add to cart |
| `/student/cart.php` | `Router::requirePanel(Student)` | Review and confirm |
| `/student/orders.php` | `Router::requirePanel(Student)` | Own orders only, with polling |
| `/student/order.php` | `Router::requirePanel(Student)` + ownership | One order in full |
| `/student/profile.php` | `Router::requirePanel(Student)` | Details, change password |
| `/admin/dashboard.php` | `Router::requirePanel(Admin)` | Real statistics |
| `/admin/orders.php` | `Router::requirePanel(Admin)` | All orders, filters, process |
| `/admin/order.php` | `Router::requirePanel(Admin)` | One order, edit, cancel, payment |
| `/admin/inventory.php` | `Router::requirePanel(Admin)` | Products, stock, upload |
| `/admin/sales.php` | `Router::requirePanel(Admin)` | Reports and CSV |
| `/admin/accounts.php` | `Router::requirePanel(Admin)` | Students and administrators |
| `/admin/profile.php` | `Router::requirePanel(Admin)` | Own details |

### What `requirePanel()` does

| Situation | Result |
|---|---|
| Not signed in | Redirect to `/login.php` with a safe `next` |
| Student on an `/admin/` page | Redirect to the student dashboard, with a message |
| Administrator on a `/student/` page | Redirect to the admin dashboard, with a message |
| Correct role | Page loads |

The redirect is a courtesy. The actual security boundary is
`Auth::requireRole()` inside the session layer, which re-reads the account from
the database on every request — so a role changed in Admin → Accounts takes
effect on the next click, not when the cookie expires.

---

## 3. Sign-in flow

```
1. GET  /login.php
   - no session        -> show the form
   - session exists    -> redirect to that role's own home page

2. POST /login.php
   - CSRF token checked                    -> 419 if missing or wrong
   - identifier and password present       -> 422 otherwise
   - account not locked out               -> 429 with the minutes remaining
   - account found, password_hash or
     password_legacy verifies             -> session created
   - otherwise                            -> 401, "not correct" (never says which part was wrong)

3. On success
   - session_regenerate_id(true)          -> defeats session fixation
   - CSRF token rotated                   -> a token minted before sign-in is dead
   - last_login_at written
   - activity_logs row written
   - if the password came from the legacy scrypt format it is replaced
     immediately with password_hash() output, and password_legacy is cleared

4. redirect to Router::landingPath()
```

Deliberate choices:

* **One message for both failures.** "That User ID or email, or the password,
  is not correct." Naming which half was wrong would turn the form into an
  account-enumeration oracle, and Student IDs are sequential.
* **Throttling is per identifier, not per IP.** A classroom shares one address
  behind the school's router; locking the IP would lock out every student at
  once. Locking the account only inconveniences whoever is being attacked.
* **`next` is validated.** `safe_path()` rejects absolute URLs, protocol-relative
  URLs and backslash tricks, so the sign-in page cannot be turned into an open
  redirect.

---

## 4. Registration — students only

The public form has **no role selector at all**. There is no hidden `role`
field to tamper with and no admin code to guess, because the concept of public
administrator registration no longer exists in the application.

```
POST /register.php
  1. CSRF
  2. name 1-120 chars, control characters and zero-width characters stripped
  3. valid email, max 254, stored lower-cased
  4. password 8-128 chars, confirmation matches
  5. terms accepted
  6. UserRepository::create(..., UserRole::Student)   <- role is fixed in code
  7. STU-#### allocated inside a transaction with the counter row locked
  8. audit row written
  9. sign in automatically, then to the dashboard
```

Administrators are created only by an existing administrator, or by
`database/setup.php` for the very first account.

---

## 5. Permissions

| Capability | Student | Administrator |
|---|:--:|:--:|
| Browse, search and filter the menu | yes | — |
| Cart and place own order | yes | — |
| Cancel own order (Pending only) | yes | yes |
| See orders | own only | all |
| Change status or record payment | no | yes |
| Edit order items | no | yes |
| Inventory, categories, accounts | no | yes |
| Sales reports and CSV | no | yes |
| Own profile and password | yes | yes |

Enforced in three layers, any one of which is sufficient:

1. `Router::requirePanel()` at the top of every page
2. `Auth::requireRole()` inside the session layer
3. Ownership checks in the query (`WHERE o.user_id = ?`) rather than after
   fetching — so probing order numbers cannot distinguish "not yours" from
   "not real"

---

## 6. Design system

Four stylesheets, no framework, no build step.

| File | Contents |
|---|---|
| `assets/css/tokens.css` | Every colour, radius, shadow, spacing step, font size. Light and dark themes defined as the same variable set. |
| `assets/css/base.css` | Reset, typography, form primitives, accessibility helpers, print rules |
| `assets/css/components.css` | Buttons, cards, stat tiles, badges, tables, alerts, forms, modal, toast, pagination, chart, product cards, stepper, chips |
| `assets/css/layout.css` | App shell, sidebar, topbar, drawer, auth pages, split layouts, responsive breakpoints at 900px and 560px |

Principles:

* **No component hard-codes a colour.** Both themes come from tokens, so a
  component never branches on theme.
* **Accessible by construction.** Focus rings on every interactive element,
  visible `:focus-visible`, `aria-current` on the active nav item,
  `aria-expanded` on the drawer button, `aria-live` regions for alerts and
  toasts, and a skip link.
* **Responsive without a framework.** Tables become labelled cards below
  720px using the `data-label` attribute the server already renders. The
  sidebar becomes a drawer below 900px.
* **Motion is optional.** `prefers-reduced-motion` collapses every animation
  to 0.01ms.

### Charts

Pure CSS bars, no library. Revenue and order value are drawn as two bars per
period so the difference between *promised* and *collected* is visible rather
than buried in a single number.

---

## 7. Front-end runtime

`assets/js/core.js` exposes one global, `KantEase`:

| Member | Purpose |
|---|---|
| `Theme` | light / dark / follow system, persisted in `localStorage` |
| `request(url, options)` | fetch with CSRF header, JSON in and out, `401` → sign-in page |
| `postForm(url, body)` | POST to a form endpoint with the token attached |
| `toast(message, type)` | transient notification |
| `confirm(options)` | focus-trapped dialog, returns a Promise |
| `peso(cents)` / `dateTime(v)` / `date(v)` | formatting identical to the PHP side |
| `icon(name)` | inline SVG |
| `ConnectionError` | raised when the server cannot be reached |

The connection behaviour is the important one. When `fetch` rejects — the
server is off, or the device is not on the school network — the wrapper raises
a `ConnectionError` carrying:

> Cannot reach the KantEase server. Check that it is switched on and that you
> are on the same network, then try again.

A dead server can therefore never be mistaken for a successful order: nothing
is reported as placed until the server has answered.

---

## 8. Content-Security-Policy

`script-src 'self' 'nonce-…'` and `style-src 'self' 'nonce-…'`, with **no
`unsafe-inline`**.

The single inline script — the theme bootstrap that prevents a white flash for
dark-theme users — carries a per-request nonce from `csp_nonce()`. Everything
else is a local file. Nothing is fetched from a CDN, so `'self'` is the whole
allowlist.

JavaScript sets element dimensions through CSSOM (`element.style.width`), which
the policy does not restrict, so the chart needs no inline style either.

---

## 9. What Phase 3 still has to build

| Item | State |
|---|---|
| `Router`, `Layout`, design system, `core.js` | **done** |
| `index.php`, `login.php`, `register.php`, `logout.php` | to build |
| `student/` pages (6) | to build |
| `admin/` pages (7) | to build |
| `api/` JSON endpoints | to build |
| Cart persistence in the session | to build |
| Student status polling | to build |

---

## 10. Phase 3 test plan

Authentication

| Test | Expected |
|---|---|
| Student sign-up | Account created, `STU-####` issued, lands on the dashboard |
| Duplicate email | Rejected with a readable message |
| No role field in the sign-up POST | Ignored; the account is always a student |
| Terms not accepted | Rejected |
| Password under 8 characters | Rejected |
| Sign in with `stu-0001` and with the email | Both work, case-insensitively |
| Wrong password | One uniform message, no hint about which field was wrong |
| Six wrong attempts | Account locks; message states the wait |
| Correct password after lockout | Still refused until the window passes |
| Deactivated account | Refused, with a message asking for staff |
| Session id before vs after sign-in | Different |
| Sign out, replay old cookie | Refused |

Authorisation

| Test | Expected |
|---|---|
| Student opens `/admin/dashboard.php` | Redirected to their own dashboard |
| Administrator opens `/student/menu.php` | Redirected to their own dashboard |
| Anonymous opens any panel page | Redirected to sign-in |
| Student requests `?next=https://evil.example` | Sign-in page ignores it |
| Student reads another student's order id | 404, identical to a missing order |
| Student calls an admin API endpoint | 403, nothing changes |

Layout and accessibility

| Test | Expected |
|---|---|
| Theme toggles and persists across pages | Yes |
| Theme on first ever visit | Follows the operating system, no flash |
| Keyboard-only navigation | Visible focus, skip link, modal traps focus |
| Escape closes a modal and the drawer | Yes |
| Screen reader on an error | Message announced |
| 360px, 768px, 1440px | No horizontal scrolling, no overlapping |
| `prefers-reduced-motion` | Animations effectively off |