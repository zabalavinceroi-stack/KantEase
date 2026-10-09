# KantEase — Phase 1: Existing Project Audit

**Date:** 2026-10-09
**Auditor:** Senior Full-Stack / PHP / DB Architecture review
**Scope:** Complete inspection of the supplied KantEase source code, schema, endpoints, UI, and workflows.
**Status:** Phase 1 complete. No files were modified or deleted.

---

## 1. Project Inventory

The original project is **not** a multi-file web app. It is a **single self-contained Node.js file**.

| Path | Size | Purpose |
|---|---|---|
| `server.js` | 178 KB / 3,303 lines | Everything: HTML+CSS+JS frontend, HTTP router, SQL schema bootstrap |
| `package.json` | 57 B | One dependency: `mariadb ^3.5.4` |
| `package-lock.json` | 3.2 KB | Lockfile |
| `node_modules/` | ~400 tracked files | Committed to git |
| `README.md` | 288 B | DB credentials, a `users_view` snippet, kill command |
| `.git/` | — | 2 commits (`Initial commit`, `mycasptone`) |
| `.gitignore` | **MISSING** | — |

**Breakdown of `server.js`:**

| Lines | Block |
|---|---|
| 1–15 | Config constants + module requires |
| 17–2140 | `page` — one template literal holding the ENTIRE frontend (1,360 lines CSS + HTML + 1,358 lines JS) |
| 2142–2365 | Helpers: JSON, CSV, body parsing, scrypt hashing, session store, role guard, search-whitelist builder |
| 2367–3167 | `handleRequest()` — all 24 API routes |
| 3169–3303 | `start()` — DB bootstrap, migrations, seed data, `http.createServer().listen()` |

### Files that DO NOT exist
`database.sql` · `uploads/` · `assets/` · any `.html`/`.css`/`.js` file · any `.htaccess` · any PHP file · any test file · any migration file · `.env`/`.env.example` · `LICENSE` · `docs/`

---

## 2. Environment Findings (BLOCKER for Phase 8 testing)

Verified on this machine:

| Item | Status |
|---|---|
| `php` on PATH | **NOT INSTALLED** |
| XAMPP (`C:\xampp`) | **NOT INSTALLED** |
| MySQL / MariaDB service | **NOT INSTALLED / NOT RUNNING** |
| `mysql` client | **NOT INSTALLED** |
| TCP port 3306 | **CLOSED** |
| Node.js | v24.13.1 (available) |

**Consequence:** the live `canteen_db` database referenced in `server.js` **cannot be introspected from this machine**, and **no PHP or SQL code can be executed or tested here** until XAMPP is installed.

The schema analysis in §4 is therefore derived from **100% of the `CREATE TABLE` / `ALTER TABLE` statements in `server.js`**, which is the authoritative and complete source for the live schema (the app creates its own schema on boot). No schema file exists to inspect.

**Action required before Phase 8:** install XAMPP, or grant access to a machine that has it. I will not claim any test as "passed" until it has actually been executed.

---

## 3. Credential & Repository Hygiene

| Finding | Severity | Detail |
|---|---|---|
| DB password in source | **CRITICAL** | `server.js:5-8` — credentials defined as plaintext literals. **Value redacted from this document; it has been rotated and must never be reused.** |
| DB password in docs | **CRITICAL** | `README.md` repeats host / user / password in plaintext |
| Hardcoded admin code | **CRITICAL** | `server.js:9` → `ADMIN_CODE = "canteen2026"` |
| `node_modules` committed | HIGH | ~400 files tracked in git; bloats repo, pollutes history |
| No `.gitignore` | HIGH | Nothing excluded |
| Secrets already in git history | HIGH | `git log` shows the credentials in committed blobs |

---

## 4. Database Schema (live, derived from `start()`)

### 4.1 Tables that exist

```
users(id AI PK, user_code VARCHAR(20) UNIQUE, full_name VARCHAR(120),
      email VARCHAR(254) UNIQUE, password VARCHAR(200),
      role ENUM('student','admin'), created_at DATETIME)

users_view  -- VIEW(user_code, full_name, email, role, created_at)

food_items(id AI PK, name VARCHAR(120), price DECIMAL(10,2),
           stock INT DEFAULT 0, low_stock_level INT DEFAULT 5,
           category VARCHAR(30) DEFAULT 'Meals')

student_orders(id, user_id FK→users CASCADE, item_name, quantity,
               food_id NULL, total_amount, created_at)   -- LEGACY

orders(id AI PK, user_id FK→users CASCADE NULL, total_amount,
       order_date DATETIME, status ENUM('Pending','Preparing','Ready',
       'Completed','Cancelled'), payment_status ENUM('Unpaid','Paid'),
       note VARCHAR(200) NULL, updated_at DATETIME NULL)

order_items(id AI PK, order_id FK→orders CASCADE, food_id FK→food_items SET NULL,
            item_name, quantity, unit_price DECIMAL(10,2), subtotal DECIMAL(10,2))

id_sequences(role ENUM PK, next_number INT)
```

### 4.2 Seed data
Auto-inserted only if `food_items` is empty:
`Chicken Rice ₱75/20`, `Pancit ₱50/15`, `Siomai ₱35/25`, `Cheese Sandwich ₱40/12`, `Bottled Water ₱20/30`.

### 4.3 Boot-time migrations (run on EVERY startup)
`ALTER TABLE ... ADD COLUMN IF NOT EXISTS` on `food_items`, `orders`, `order_items`, `student_orders`; a `users_view` replace; a back-fill of blank `item_name`/`unit_price`; a one-time migration of `student_orders` → `orders` + `order_items`; and sequence resync.

### 4.4 Index inventory
Only the implicit indexes from `PRIMARY KEY`, two `UNIQUE`s on `users`, and the FK indexes on `order_items.food_id`, `student_orders.user_id`.
**No indexes on:** `orders.status`, `orders.order_date`, `orders.payment_status`, `food_items.category`, `food_items.stock`. Every dashboard/report query is a full table scan.

### 4.5 Schema gaps vs. the required design (§8 of the brief)

| Required | Status |
|---|---|
| `users` | Partial — no `is_active`, no `updated_at`, no `last_login_at` |
| `food_categories` | **MISSING** — category is a bare `VARCHAR(30)` string |
| `food_items` | Partial — no `description`, no `image_path`, no `is_available`, no `is_archived`, no `category_id` FK |
| `orders` | Partial — **no `order_number`** (auto-increment `id` is used as the order number), no `cancel_reason`, no `cancelled_at`/`cancelled_by`, no per-status timestamps, `user_id` is NULLABLE |
| `order_items` | **GOOD** — `item_name` + `unit_price` are true historical price snapshots |
| `payments` | **MISSING** — payment is a bare ENUM column with no timestamp, no amount, no responsible admin |
| `inventory_movements` | **MISSING** — no audit trail for any stock change |
| `activity_logs` | **MISSING** — no audit trail for any admin action |
| Unique order number | **MISSING** |
| Referential integrity | Partial — `order_items.food_id ON DELETE SET NULL` silently orphans history |

---

## 5. API Endpoint Inventory & Role Matrix

`S` = student, `A` = admin, `P` = public.

| # | Method | Path | Role | Purpose |
|---|---|---|---|---|
| 1 | GET | `/` | P | Login page (HTML) |
| 2 | GET | `/signup` | P | Registration page (HTML) |
| 3 | GET | `/student` | S | Student panel (HTML) |
| 4 | GET | `/admin` | A | Admin panel (HTML) |
| 5 | POST | `/api/signup` | P | Register (student **or admin**) |
| 6 | POST | `/api/login` | P | Login by `user_code` OR `email` |
| 7 | POST | `/api/logout` | any | Logout |
| 8 | POST | `/api/change-password` | any | Change own password |
| 9 | GET | `/api/me` | any | Own profile |
| 10 | PUT | `/api/me` | any | Update own name/email |
| 11 | GET | `/api/menu` | S | Available food items |
| 12 | GET | `/api/student-orders` | S | Own order history |
| 13 | POST | `/api/student-orders` | S | **Place order** |
| 14 | GET | `/api/admin/dashboard` | A | All dashboard stats |
| 15 | GET | `/api/admin-orders` | A | All orders + filters |
| 16 | GET | `/api/admin-orders/:id` | A | Order detail + lines |
| 17 | PUT | `/api/admin-orders/:id` | A | **Edit status / payment / items / note** |
| 18 | GET | `/api/inventory` | A | Inventory list |
| 19 | POST | `/api/inventory` | A | Create item |
| 20 | PUT | `/api/inventory/:id` | A | Update item |
| 21 | DELETE | `/api/inventory/:id` | A | Delete item (blocked if used by an order) |
| 22 | GET | `/api/sales` | A | Daily sales totals |
| 23 | GET | `/api/admin/export/orders` | A | Orders CSV |
| 24 | GET | `/api/admin/export/sales` | A | Sales CSV |

Every protected route calls `requireRole()` **server-side**. ✅ No route relies on a hidden button or a JS check.

---

## 6. Complete Ordering Workflow Trace

**Student places an order**
1. `GET /student` → HTML shell; JS calls `GET /api/me`, renders sidebar name/ID.
2. Menu section → `GET /api/menu?field=…&q=…` → cards rendered with category chips, price, stock badge.
3. Client validates quantity `1..100`, adds to an in-memory `studentCart[]`, re-checks against the menu snapshot (max 20 distinct lines).
4. `POST /api/student-orders {items:[{food_id, quantity}]}`
5. Server: role check → array `1..20` → each `food_id ≥ 1`, `quantity 1..100`, no duplicate ids → **sort ids ascending**
6. `BEGIN` → `SELECT id,name,price,stock FROM food_items WHERE id IN (…) ORDER BY id FOR UPDATE`
7. Re-validate stock per line; compute the total in **integer cents**
8. `INSERT INTO orders (user_id, total_amount)`
9. Per line: `INSERT INTO order_items (...)` then `UPDATE food_items SET stock = stock - ? WHERE id = ? AND stock >= ?` — `affectedRows = 0` ⇒ `409` and rollback
10. `COMMIT` → `201 {message, total_amount}`
11. Client clears the cart, re-fetches the menu, shows a toast.

**Canteen staff processes it**
12. `GET /api/admin-orders` → admin list, `has_unlinked_items` flag computed.
13. `GET /api/admin-orders/:id` → detail → modal editor.
14. `PUT /api/admin-orders/:id {status, payment_status, note, items[]}`
15. Server: `BEGIN` → `SELECT … FROM orders WHERE id=? FOR UPDATE`
16. **Terminal locks:** `Completed` ⇒ only `Unpaid→Paid` allowed; `Cancelled` ⇒ fully locked
17. `Cancelled` branch: aggregate quantities per `food_id`, `SELECT … FOR UPDATE`, `UPDATE food_items SET stock = stock + ?`, set status `Cancelled` + payment `Unpaid`
18. Otherwise: diff old vs new quantities, apply stock deltas, `DELETE` + re-`INSERT` `order_items`, recompute total, `UPDATE orders`
19. `COMMIT`

**Student sees it**
20. Student navigates to "My Orders" → `GET /api/student-orders` re-runs.

### What is CORRECT and MUST be preserved
- ✅ **`FOR UPDATE` row locking on `food_items` + `WHERE stock >= ?` guard.** This is genuinely oversell-proof and, crucially, is enforced by the *database*, not by application state. It will remain correct under Apache's multi-process model. **This is the single most important algorithm to port verbatim.**
- ✅ Integer-cents arithmetic for all totals (no float drift).
- ✅ Server-side re-pricing and re-stock-check at checkout (client values are never trusted).
- ✅ Historical price snapshots: existing lines keep their original `unit_price` when an admin edits quantities; only newly added lines use the current price.
- ✅ Cancel restores stock exactly once — the row is `FOR UPDATE`-locked and `status='Cancelled'` is re-checked inside the transaction, so a double-cancel is impossible.
- ✅ Completed orders are immutable except `Unpaid → Paid`.
- ✅ Delete-protection: a food item referenced by any order cannot be deleted; a student with order history cannot be deleted.
- ✅ Last-admin protection and self-role-change protection.
- ✅ Search fields use a **server-side whitelist** mapping user-facing field names to fixed SQL column expressions. Request values never become SQL identifiers. **This design is excellent and must be carried over.**
- ✅ CSV injection guard (values starting `= + - @` are prefixed with `'`).
- ✅ XSS-safe rendering — the client uses `textContent`/`createTextNode` everywhere; no `innerHTML` with user data.
- ✅ FOUC-free theme boot script; `prefers-color-scheme` default + `localStorage` persistence; `prefers-reduced-motion` honoured.
- ✅ Fully responsive: sidebar drawer ≤700 px, admin table → card layout ≤1100 px, single-column ≤520 px.
- ✅ Export CSV respects the active search filters.
- ✅ Other-user order isolation is enforced in SQL (`WHERE o.user_id = ?` from the session).

---

## 7. Feature Audit — Student Panel

| # | Feature | Classification | Notes |
|---|---|---|---|
| S1 | Registration form (name, email, password, role, T&C) | **Existing but defective** | Role selector offers **Admin**; gated only by the hardcoded `canteen2026`. See VULN-1. |
| S2 | Login by User ID **or** email | **Existing and functional** | Preserves the "student ID or email" requirement. |
| S3 | Logout | **Existing and functional** | — |
| S4 | Profile view | **Existing and functional** | Displays ID, name, email, role, date joined. |
| S5 | Profile edit (name, email) | **Existing and functional** | Uniqueness enforced by DB + `ER_DUP_ENTRY` → 409. |
| S6 | Change password | **Existing and functional** | Verifies current password; invalidates the user's other sessions. |
| S7 | Dashboard welcome + Student ID | **Existing and functional** | — |
| S8 | Dashboard stat: total orders | **Existing and functional** | Computed client-side from real data. |
| S9 | Dashboard stat: total spent | **Existing but defective** | Sums **cancelled and unpaid** orders. Misleading. |
| S10 | Dashboard stat: last order | **Existing and functional** | — |
| S11 | Dashboard: Pending / Preparing / Ready / Completed counts | **Missing and required** | Brief §5.A. |
| S12 | Dashboard: food recommendations | **Missing and required** | Brief §5.A. |
| S13 | Menu browsing with name + price + stock | **Existing and functional** | — |
| S14 | Category filter chips | **Existing and functional** | Hardcoded category list; not DB-driven. |
| S15 | Menu search (name / price) | **Existing and functional** | Debounced 300 ms. |
| S16 | Product image | **Missing and required** | Only a static 🍽️ emoji placeholder. |
| S17 | Product description | **Missing and required** | No column, no UI. |
| S18 | Price in ₱ | **Existing and functional** | — |
| S19 | Stock availability badge (Available / Low / Sold out) | **Existing and functional** | — |
| S20 | Sold-out items not orderable | **Existing and functional** | Input + button disabled; re-checked server-side. |
| S21 | Quantity selection + add to cart | **Existing and functional** | — |
| S22 | Cart: add | **Existing and functional** | — |
| S23 | Cart: remove | **Existing and functional** | — |
| S24 | Cart: **increase / decrease quantity** | **Existing but incomplete** | Only "remove whole line". Must remove a line to change quantity. Brief §5.C. |
| S25 | Cart: item subtotal + grand total | **Existing and functional** | — |
| S26 | Cart: checkout confirmation | **Existing and functional** | — |
| S27 | Cart persistence across page loads | **Missing and required** | Cart is a JS array; a refresh loses it. Brief §5.C + §11 `cart.php`. |
| S28 | Unique human-readable order number | **Missing and required** | Auto-increment `id` is used. Brief §5.D.5. |
| S29 | Order placement w/ transaction + server pricing | **Existing and functional** | See §6. |
| S30 | Stock deduction policy | **Existing and functional** | Immediate deduction at placement. Consistent; will be documented as the official policy. |
| S31 | Order history list | **Existing and functional** | Shows number, items+qty, total, payment, status, date. |
| S32 | Order history search + date range | **Existing and functional** | — |
| S33 | Order detail view | **Missing and required** | Brief §5.E. |
| S34 | Pickup information | **Missing and required** | Only a static banner on the menu page. Brief §5.E. |
| S35 | **Live order-status polling** | **Missing and required** | Brief §3 — "refresh order statuses without refreshing the entire page". Currently the student must re-navigate. |
| S36 | Student cancellation of own order | **Missing and required** | Brief §5.E lists Cancelled as a student-visible terminal status; there is no student cancel path at all. |
| S37 | Guarantee: students cannot self-advance status/payment | **Existing and functional** | Server enforces `requireRole('student')`; no endpoint exists. ✅ |

---

## 8. Feature Audit — Administrator Panel

| # | Feature | Classification | Notes |
|---|---|---|---|
| A1 | Dashboard: total students | **Existing and functional** | — |
| A2 | Dashboard: total admins | **Existing and functional** | — |
| A3 | Dashboard: today's orders | **Existing and functional** | — |
| A4 | Dashboard: total orders (all time) | **Missing and required** | Brief §6.A. |
| A5 | Dashboard: today's sales | **Existing but defective** | Counts `Unpaid` orders as revenue. See DEF-1. |
| A6 | Dashboard: total recorded sales (all time) | **Missing and required** | Brief §6.A. |
| A7 | Dashboard: status counts (P/Prep/Ready/Comp) | **Existing and functional** | — |
| A8 | Dashboard: 7-day sales chart | **Existing and functional** | Pure CSS bars, zero dependencies. Acceptable per brief. |
| A9 | Dashboard: low-stock list | **Existing and functional** | — |
| A10 | Dashboard: best sellers | **Existing and functional** | Top 5 over 7 days. |
| A11 | Dashboard: recent orders | **Existing and functional** | — |
| A12 | Dashboard: animated number counters | **Existing and functional** | Reduced-motion aware. |
| A13 | Orders: list all student orders | **Existing and functional** | — |
| A14 | Orders: search order no. / student ID / student name / items / total / status / date | **Existing and functional** | — |
| A15 | Orders: filter by date range | **Existing and functional** | — |
| A16 | Orders: filter by status | **Existing and functional** | Also reachable by clicking a dashboard status card. |
| A17 | Orders: **filter by payment status** | **Missing and required** | Brief §6.B. |
| A18 | Orders: pagination | **Missing and required** | Brief has no explicit clause, but unbounded `GROUP_CONCAT` + full scan will degrade. |
| A19 | Order detail view | **Existing and functional** | — |
| A20 | Order edit (status, payment, items, note) | **Existing and functional** | Transaction-safe; snapshots preserved. |
| A21 | Order edit: invalid transition prevention | **Existing but incomplete** | Terminal states are locked, but **backwards and skipped transitions are allowed** (Completed→Pending, Pending→Completed). Brief §6.C. |
| A22 | Order cancel with recorded reason | **Existing but incomplete** | Reason is written into the shared `note` field and is destroyed if the order is later re-saved. No dedicated `cancel_reason`, `cancelled_at`, `cancelled_by`. |
| A23 | Order workflow Pending→Preparing→Ready→Completed | **Existing and functional** | — |
| A24 | Stock restored exactly once on cancel | **Existing and functional** | Row-locked + re-checked. ✅ |
| A25 | Duplicate-completion prevention | **Existing and functional** | Completed is locked. |
| A26 | Inventory: add item | **Existing and functional** | — |
| A27 | Inventory: edit item | **Existing and functional** | — |
| A28 | Inventory: delete item | **Existing and functional** | Refuses when referenced by an order. |
| A29 | Inventory: change price / category / stock / low-stock level | **Existing and functional** | — |
| A30 | Inventory: **image upload** | **Missing and required** | Brief §6.D. No `uploads/` dir, no upload code. |
| A31 | Inventory: **mark unavailable** | **Missing and required** | Only achievable indirectly via `stock = 0`. Brief §6.D. |
| A32 | Inventory: **archive** product | **Missing and required** | Brief §6.D. Deletion only, and only when unreferenced. |
| A33 | Inventory: low-stock warnings | **Existing and functional** | Per-item threshold + dashboard list. |
| A34 | Inventory: historical orders intact on edit/archive | **Existing and functional** | `ON DELETE SET NULL` + `item_name`/`unit_price` snapshots. ✅ |
| A35 | Inventory: **stock movement audit trail** | **Missing and required** | Brief §8. |
| A36 | Sales: daily totals + custom date range | **Existing and functional** | — |
| A37 | Sales: weekly / monthly aggregation | **Missing and required** | Brief §6.E. |
| A38 | Sales: total **paid** sales + transaction count | **Missing and required** | Brief §6.E. |
| A39 | Sales: best-selling products + quantities | **Missing and required** | Only on the dashboard; not in the Sales report. Brief §6.E. |
| A40 | Sales: CSV export | **Existing but defective** | Contains **unpaid + non-cancelled** totals presented as "Total Amount". See DEF-1. |
| A41 | Sales figures exclude cancelled | **Existing and functional** | ✅ |
| A42 | Accounts: list + role filter + search + date range | **Existing and functional** | — |
| A43 | Accounts: edit name / email / role | **Existing and functional** | — |
| A44 | Accounts: reset student password | **Existing and functional** | — |
| A45 | Accounts: delete student (only if no orders) | **Existing and functional** | ✅ |
| A46 | Accounts: **activate / deactivate** | **Missing and required** | Brief §6.F. |
| A47 | Accounts: **create a student account** | **Missing and required** | Admin can only edit existing accounts. |
| A48 | Accounts: date created shown | **Existing and functional** | — |
| A49 | Accounts: privilege-escalation guards | **Existing and functional** | Self-role-change blocked; last-admin demotion blocked. ✅ |
| A50 | Admin profile view / edit / change password | **Existing and functional** | — |
| A51 | Orders CSV export | **Existing and functional** | Respects filters; injection-guarded. |
| A52 | Dashboard quick actions | **Existing and functional** | — |
| A53 | Sidebar navigation (6 sections) | **Existing and functional** | — |
| A54 | Light / dark theme | **Existing and functional** | ✅ |
| A55 | Responsive layout | **Existing and functional** | ✅ |

---

## 9. Security Vulnerabilities (ranked)

| ID | Severity | Vulnerability | Location | Required fix |
|---|---|---|---|---|
| **VULN-1** | **CRITICAL** | **Privilege escalation by design.** `/signup` is public and offers an `Admin` role. Admin signup is gated solely by the string `canteen2026` hardcoded at `server.js:9`. Anyone with the source (it is in git history) can mint unlimited admin accounts. | `server.js:9`, `:469`, `:2400` | Admin accounts are **created only by the installer** or by an existing admin. Delete the public admin option; delete `ADMIN_CODE`. |
| **VULN-2** | **CRITICAL** | **Plaintext DB credentials in source and in git history.** | `server.js:5-8`, `README.md` | Move to `includes/config.php` (never served as source by Apache) + `.htaccess` deny + `.gitignore` + ship `config.sample.php`. |
| **VULN-3** | **HIGH** | **No CSRF tokens.** Only implicit protection via `SameSite=Strict` + the JSON content-type preflight. `/api/logout` accepts a bare cross-site form POST. Brief §9 mandates explicit CSRF. | all mutating routes | Per-session token + hidden field + `X-CSRF-Token` header, `hash_equals()`. |
| **VULN-4** | **HIGH** | **No login rate limiting or lockout.** Unlimited password guessing against every account. | `/api/login` | Attempt counter in session + exponential backoff + temporary lockout. |
| **VULN-5** | **HIGH** | **Custom password hashing** (`crypto.scrypt` + manual salt:hash) instead of the mandated `password_hash()`/`password_verify()`. | `:2225-2245` | `password_hash(PASSWORD_DEFAULT)`; keep a legacy verifier + rehash-on-login. |
| **VULN-6** | **MEDIUM** | **Stale authorization state.** The session stores `role` and `id` in memory; `requireRole()` trusts the cached role and never re-reads the DB. If an admin demotes or deletes an account, the target's live sessions survive (deletion is only purged on a *role change*). | `:2260-2271`, `:3114-3118` | Re-validate `is_active` + `role` from the DB on every request (PHP sessions make this easy). |
| **VULN-7** | **MEDIUM** | **No session invalidation server-side after logout-by-admin, no idle timeout**, and **sessions vanish on restart** (in-memory `Map`). | `:15`, `:2247` | Native PHP file sessions; idle timeout; `session_regenerate_id(true)` on login. |
| **VULN-8** | **MEDIUM** | **No `.htaccess` / web-server hardening.** `server.js`, `node_modules/`, and future `includes/` are web-servable if the folder is placed in a web root. | repo root | Ship `.htaccess` denying `includes/`, `database/`, and PHP execution inside `uploads/`. |
| **VULN-9** | **LOW** | **No signup throttling** — account spam. | `/api/signup` | Rate limit. |
| **VULN-10** | **LOW** | Error messages are inconsistent (mixed English / Tagalog); 500s leak a hint to the terminal. Minor info disclosure via `console.error`. | throughout | Single consistent error layer; generic 500 body; log details server-side only. |

---

## 10. Defects & Data-Integrity Issues (ranked)

| ID | Severity | Defect | Location |
|---|---|---|---|
| **DEF-1** | **HIGH** | **Unpaid orders are counted as revenue.** `WHERE status <> 'Cancelled'` with no `payment_status` filter in `/api/admin/dashboard` (today's sales), `/api/sales`, and `/api/admin/export/sales`. Directly violates brief §6.E: *"Do not count unpaid orders as collected revenue."* | `:2643`, `:3011`, `:2969` |
| **DEF-2** | **HIGH** | **No payment audit trail.** `payment_status` is a mutable column. No `payments` table ⇒ no amount, no timestamp, no responsible admin. Brief §7 mandates all three. | schema |
| **DEF-3** | **MEDIUM** | **No duplicate-order-submission guard.** Only a client-side `button.disabled`. A retry, double-tap race, or browser resend creates a second order. Brief §13 explicitly tests this. | `:2103-2127` |
| **DEF-4** | **MEDIUM** | **Invalid status transitions are permitted.** Completed→Pending and Pending→Completed are both accepted. Only terminal states are guarded. Brief §6.C. | `:2745-2900` |
| **DEF-5** | **MEDIUM** | **Cancel reason is not preserved.** It is written to the shared `note` column and is overwritten by the next edit. | `:2810` |
| **DEF-6** | **MEDIUM** | **Orders with `user_id IS NULL` are invisible to admins.** The `ALTER TABLE orders ADD COLUMN IF NOT EXISTS user_id INT NULL` plus the legacy migration path allows NULL; `GET /api/admin-orders` uses an inner `JOIN users`, silently dropping those rows. | `:3228`, `:2709` |
| **DEF-7** | **MEDIUM** | **No admin pagination.** Every order is returned with a correlated `GROUP_CONCAT` subquery and a full-table scan. | `:2704` |
| **DEF-8** | **LOW** | **`GROUP_CONCAT` truncation risk.** `group_concat_max_len` defaults to 1024 on MySQL; large orders would silently show truncated item lists. | `:2540`, `:2706` |
| **DEF-9** | **LOW** | **Student "Total spent" includes cancelled and unpaid orders.** | `:1344` |
| **DEF-10** | **LOW** | **Direct stock edits are untracked.** `PUT /api/inventory` sets `stock` to an arbitrary value with no movement record, so the ledger and `food_items.stock` can silently diverge. | `:2953` |
| **DEF-11** | **LOW** | **No confirmation for status change or cancellation.** Only `confirm()` on delete. A mis-click cancels an order and silently returns stock. | `:1810` |
| **DEF-12** | **LOW** | **Order-editor item picker is an unfiltered `<select>` of every product.** Unusable at real menu size. | `:1775-1782` |
| **DEF-13** | **LOW** | **`users_view` is a pointless indirection** that the accounts list depends on; if the view is missing the Accounts page breaks entirely. | `:3030` |
| **DEF-14** | **LOW** | **Universal `transition:` on `*`** forces style recalculation on every element. Performance smell. | `:119` |
| **DEF-15** | **LOW** | **Dead legacy code runs on every boot** — `student_orders` migration queries and `ALTER TABLE … IF NOT EXISTS` batch. Dev-only pattern. | `:3177-3293` |
| **DEF-16** | **LOW** | **No friendly offline/connection error.** `fetch` rejection surfaces the raw browser string ("Failed to fetch"). Brief §3 requires an understandable connection error. | `:848-854` |

---

## 11. UI/UX & Accessibility Audit

**Good, preserve:** FOUC-free theme init · `prefers-reduced-motion` support · `aria-live` regions on message boxes · visible focus rings · responsive breakpoints at 700/1100/520 px · mobile sidebar drawer · `data-label` card fallback for the admin table · CSV button in both filtered lists.

| ID | Severity | Issue |
|---|---|---|
| UX-1 | HIGH | **Everything is one 3,303-line file.** 1,360 lines of inline CSS and 1,358 of inline JS. Impossible to maintain or review. Must be split into `assets/css/*` and `assets/js/*`. |
| UX-2 | MEDIUM | **Student has no dedicated cart page.** Brief §11 requires `student/cart.php`; the cart is a sticky sidebar panel. |
| UX-3 | MEDIUM | **No per-page URLs.** All navigation is `location.hash`, so no page is linkable, bookmarkable, or reloadable — and no page works as a real browser Back button target. |
| UX-4 | MEDIUM | **Modals have no focus trap, no Escape-to-close, and no focus restoration.** Keyboard/SR users can tab into the page behind the overlay. |
| UX-5 | MEDIUM | **Emoji used as the entire icon set** (🏠🍽️🧾👤📦👥📊). Renders inconsistently across Windows/Android/macOS. Brief allows local icons — these should become a local inline-SVG set. |
| UX-6 | LOW | `Arial, sans-serif` only. Brief §10 asks for consistent typography. |
| UX-7 | LOW | Sidebar fixed at 205 px — cramped for "Sales Report" / "My Profile" labels. |
| UX-8 | LOW | No `aria-current="page"` on the active nav item; no `aria-expanded` on the mobile menu button. |
| UX-9 | LOW | No `<main>` landmark scoped per panel view. |
| UX-10 | LOW | Universal `transition` on `*` (see DEF-14). |
| UX-11 | LOW | No favicon, no logo mark (a 🍴 emoji stands in). |

---

## 12. Node.js → PHP Conversion Map

| Node artefact | PHP replacement | Notes |
|---|---|---|
| `require("mariadb")` + pool | `PDO` + `new PDO(..., [ATTR_EMULATE_PREPARES=>false])` | Native prepared statements — required by brief §2. |
| `connection.query(sql, params)` | `$stmt = $pdo->prepare($sql); $stmt->execute($params);` | 1:1 mapping. |
| `pool.getConnection()` | `new PDO(...)` per transaction, or a single lazy singleton | `beginTransaction` / `commit` / `rollBack` map directly. |
| `SELECT … FOR UPDATE` | **Identical SQL.** | The oversell-protection core ports verbatim. |
| `error.status` + `fail()` | `throw new AppException($msg, $httpStatus)` caught by one front controller | |
| `crypto.scrypt` hashing | `password_hash()` / `password_verify()` | Plus a legacy verifier for migration. |
| In-memory `sessions` Map | Native `$_SESSION` | Built-in persistence, idle timeout, `session_regenerate_id(true)`. |
| Hand-rolled cookie parsing | `session.cookie_httponly`, `session.cookie_samesite` | |
| `http.createServer().listen()` | Apache via XAMPP | **This is the core offline-deployment change.** |
| JSON request/response | `json_decode(file_get_contents('php://input'), true)` / `json_encode()` | |
| `page` template literal (1 HTML page) | Real `.php` pages + `includes/layout/*.php` partials | |
| Inline `<style>` (1,360 lines) | `assets/css/base.css`, `layout.css`, `components.css`, `charts.css` | |
| Inline `<script>` (1,358 lines) | `assets/js/core.js`, `student.js`, `admin.js` | |
| `buildSearch()` whitelist | Same whitelist, in PHP | Design preserved verbatim. |
| `sendCsv()` + `toCsv()` | `fputcsv()` + BOM + `Content-Disposition` | Injection guard preserved. |
| Client-side `location.hash` routing | Real page URLs per brief §11 | |
| Bootstrap schema in `start()` | `database/database.sql` | Plus `database/migrate_from_node.sql`. |

**Nothing needs a behavioural rewrite.** Every algorithm is portable; this is a careful transliteration, not a redesign.

---

## 13. Proposed Target Architecture

### 13.1 File structure

```
KantEase/
├── index.php                     login.php        register.php      logout.php
├── .htaccess                     ← deny includes/, database/, uploads/*.php
├── includes/
│   ├── config.php                credentials + constants (never web-readable)
│   ├── config.sample.php         committed template
│   ├── database.php              PDO factory
│   ├── auth.php                  login / logout / require_login / require_role
│   ├── csrf.php                  token issue + verify
│   ├── functions.php             e() · peso() · status labels · flash · pagination · CSV
│   ├── validation.php            server-side validators
│   ├── repositories/             ← all SQL lives here, never in page files
│   │   ├── UserRepository.php  OrderRepository.php  FoodRepository.php
│   │   ├── SalesRepository.php  InventoryRepository.php  AuditRepository.php
│   └── layout/  student_header.php · student_footer.php · admin_header.php · admin_footer.php
├── student/   dashboard.php · menu.php · cart.php · orders.php · order.php · profile.php
├── admin/     dashboard.php · orders.php · order.php · inventory.php · sales.php · accounts.php · profile.php
├── api/
│   ├── _bootstrap.php            ← api_require_role(), api_json(), api_input()
│   ├── auth/       login · register · logout · me
│   ├── student/    menu · cart · orders · order-status (polling) · recommendations
│   └── admin/      dashboard · orders · order-status · inventory · sales · accounts · export · upload
├── assets/
│   ├── css/    base.css · layout.css · components.css · charts.css
│   ├── js/     core.js · student.js · admin.js
│   └── images/ logo.svg · placeholder-product.svg · icons.svg (local inline sprite)
├── uploads/products/  +  .htaccess  (Deny from all, no PHP execution)
└── database/  database.sql · migrate_from_node.sql
```

### 13.2 Database schema (target)

```sql
users            (id, user_code UQ, full_name, email UQ, password VARCHAR(255),
                  password_legacy NULL, role ENUM('student','admin'), is_active TINYINT(1),
                  last_login_at NULL, created_at, updated_at)
food_categories  (id, name UQ, sort_order, is_active, created_at)
food_items       (id, category_id FK RESTRICT, name, description NULL,
                  price DECIMAL(10,2), stock INT UNSIGNED, low_stock_level INT UNSIGNED,
                  image_path NULL, is_available TINYINT(1), is_archived TINYINT(1),
                  created_at, updated_at)
orders           (id, order_number UQ, user_id FK RESTRICT, total_amount DECIMAL(10,2),
                  status ENUM('Pending','Preparing','Ready','Completed','Cancelled'),
                  payment_status ENUM('Unpaid','Paid'), note NULL,
                  cancel_reason NULL, cancelled_at NULL, cancelled_by FK SET NULL,
                  prepared_at NULL, ready_at NULL, completed_at NULL,
                  order_date, updated_at,
                  KEY(status), KEY(order_date), KEY(payment_status), KEY(user_id, order_date))
order_items      (id, order_id FK CASCADE, food_id FK SET NULL,
                  item_name, unit_price DECIMAL(10,2), quantity INT UNSIGNED, subtotal DECIMAL(10,2))
order_status_history (id, order_id FK CASCADE, from_status NULL, to_status,
                      changed_by FK SET NULL, note NULL, created_at)
payments         (id, order_id FK RESTRICT, amount DECIMAL(10,2),
                  method ENUM('Cash'), received_by FK RESTRICT, received_at,
                  reference VARCHAR(64) UQ, note NULL, created_at)
inventory_movements (id, food_id FK RESTRICT, movement_type ENUM(...),
                     quantity_change INT, stock_before INT, stock_after INT,
                     reference_type NULL, reference_id NULL, user_id FK SET NULL,
                     note NULL, created_at)
activity_logs    (id, user_id FK SET NULL, action VARCHAR(64), entity_type NULL,
                  entity_id NULL, details TEXT NULL, ip_address VARCHAR(45), created_at)
id_sequences     (role ENUM PK, next_number)
```

`order_number` format: **`KE-YYYYMMDD-0001`**, generated from a `FOR UPDATE`-locked `id_sequences` row, mirroring the existing `STU-0001` mechanism.

### 13.3 Business rules to be enforced

**Order status state machine**
```
Pending ──▶ Preparing ──▶ Ready ──▶ Completed   (Completed = terminal)
   │           │           │
   └───────────┴───────────┴──▶ Cancelled       (Cancelled = terminal)
Completed + Unpaid ──▶ Paid                    (only transition out of Completed)
```
- No backwards moves, no skipping, no terminal-state edits.
- `Cancelled` allowed only from Pending / Preparing / Ready.
- Every transition writes `order_status_history` + `activity_logs`.

**Inventory policy (unchanged from the original, now documented)**
- Stock is **deducted at order placement** inside the same transaction as the order insert.
- Cancellation restores stock **exactly once**, guarded by `FOR UPDATE` + a status re-check, and always writes an `inventory_movements` row.
- `UPDATE food_items SET stock = stock - ? WHERE id = ? AND stock >= ?` — `rowCount() === 0` ⇒ rollback + 409. **Oversell is impossible.**
- Direct stock edits from the Inventory page write an adjustment movement.

**Sales rules (fixes DEF-1)**
- **Collected revenue** = `payment_status = 'Paid' AND status <> 'Cancelled'`.
- **Order value** = all non-cancelled orders — shown as a *separate, clearly labelled* figure.
- Cancelled orders are excluded from both, and from best-seller ranking.
- `payments.reference = order_number` is UNIQUE ⇒ **double payment recording is impossible**.

**Permissions matrix (server-side, every request)**
| Capability | Student | Admin |
|---|:--:|:--:|
| Browse menu, search, filter | ✅ | — |
| Cart, place own order | ✅ | — |
| Cancel **own** order (Pending only) | ✅ | ✅ |
| View orders | Own only | All |
| Advance status / mark paid | ❌ | ✅ |
| Edit order items | ❌ | ✅ |
| Inventory, categories, accounts | ❌ | ✅ |
| Sales reports, CSV export | ❌ | ✅ |
| Own profile + password | ✅ | ✅ |

---

## 14. Data Migration Plan

`database/migrate_from_node.sql` will:
1. Create `food_categories` and seed the five original categories (`Meals, Snacks, Drinks, Desserts, Others`).
2. Add `category_id` to `food_items`, back-filled from the existing `category` string, then drop the string column.
3. Migrate `student_orders` → `orders` + `order_items` (idempotent, matching the original logic).
4. Back-fill `orders.order_number` for legacy rows (`KE-LEGACY-<id>`), then add the UNIQUE index and `NOT NULL`.
5. Clean up any `orders.user_id IS NULL` rows (DEF-6) before adding `NOT NULL`.
6. Hash legacy passwords into `password` + `password_legacy`; **rehash to `password_hash()` on first successful login**, then drop `password_legacy`.
7. Add `is_active = 1` for all users.
8. Rebuild `id_sequences` from existing max codes.

`database/database.sql` ships a clean, self-contained schema + a **securely generated** first administrator (password supplied by the installer, hashed with `password_hash()`, never a hardcoded default).

---

## 15. Test Plan (Phase 8) — and an honest status statement

I can and will write the full test matrix, but **I must state plainly:**

> **Nothing has been tested yet, because PHP, MySQL/MariaDB, and XAMPP are not installed on this machine.** No feature in this audit is claimed as "verified". Every classification above is based on **static code reading**, which is sufficient to prove the existence of the defects and gaps but cannot prove runtime behaviour.

To execute Phase 8 I need one of:
1. **XAMPP installed on this machine** (Apache + PHP 8.2 + MariaDB + phpMyAdmin), or
2. Access to a machine that has it, or
3. Permission to install it.

### Planned verification matrix

| Group | Test | Expected |
|---|---|---|
| Auth | Valid student login; valid admin login; wrong password; unknown user; inactive account; session regeneration on login; forced logout on deactivation | Pass/deny as appropriate |
| Auth | Direct navigation to `/admin` as a student, `/student` as admin, unauthenticated to a protected page | 403 / redirect |
| Auth | CSRF token missing or tampered on every mutating endpoint | 419/403, no state change |
| Auth | 10 wrong passwords then a correct one | Locked out until the backoff elapses |
| Student | Register → login → place order → view status progression → cancel | Full lifecycle |
| Student | Order the last remaining unit of an item from two concurrent sessions | Exactly one succeeds |
| Student | Order more units than stock | 409, **no partial order, no negative stock** |
| Student | Double-click / resend the checkout request | **One** order only (idempotency key) |
| Student | Student A cannot read Student B's order (ID enumeration) | 404/403 |
| Student | Student calls the admin status endpoint | 403, status unchanged |
| Admin | Pending→Preparing→Ready→Completed; then a backwards attempt | Forward works, backwards rejected |
| Admin | Completed + Unpaid → mark Paid, twice | One payment row only |
| Admin | Cancel → check stock; re-cancel | Stock restored **exactly once** |
| Admin | Edit quantities up/down | Stock diff exact; total exact |
| Admin | Revenue with 1 paid + 1 unpaid + 1 cancelled order | Only the paid order counted |
| Admin | Delete a product used by an order | Blocked; history intact |
| Admin | Deactivate a student with an active session | Session invalidated |
| Data | Run SQL invariant checks after the suite | No negative stock; no order total ≠ SUM(subtotal); no duplicate `order_number`; no orphan rows |
| UI | Light/dark persistence, mobile drawer, keyboard nav, modal focus trap | Pass |
| UI | Server unreachable mid-session | Understandable connection error; **never a false "order submitted"** |

---

## 16. Phase 1 Report

**Completed:** Full inspection of all 4 tracked project files, all 3,303 lines of `server.js`, all 24 API routes, the complete live schema, every UI section, and the complete ordering workflow. 92 discrete features classified. 10 vulnerabilities and 16 defects identified and ranked.

**Modified files:** None.
**Deleted files:** None.
**Database changes:** None.
**Tests performed:** Static code review only. **No runtime testing** — the required stack is not installed on this machine.
**Test results:** N/A (see §15).

**Known limitations of this audit**
- The live `canteen_db` contents (row counts, real production data volume, actual index statistics) could not be inspected — no MySQL access on this machine.
- Runtime behaviour is inferred from source, not observed.
- UX defects were identified by code review; no visual/browser review was possible.

**Next phase:** Phase 2 — PHP architecture and database. Blocked on the environment decision in §17.

---

## 17. Decisions Locked (2026-10-09)

Phase 1 findings reviewed by the project owner. The following are now **fixed requirements**, not proposals.

### D-1 — Test environment
**XAMPP will be installed on this machine** (Apache + PHP 8.2+ + MariaDB + phpMyAdmin).
Until it is installed and reported ready, **no code may be described as tested**. Phase 2–7 may be authored; **Phase 8 verification is blocked** until then.

### D-2 — Admin account creation (closes VULN-1)
- The public signup page **no longer offers an `Admin` role**. `register.php` is student-only.
- The `ADMIN_CODE` constant is **deleted entirely**. It is not moved, not rotated — it is removed.
- The **first** administrator is created by the local installer (`database/setup.php`, run once, from `localhost` only).
- Every **subsequent** administrator is created by an existing administrator from **Admin → Accounts → Add Account**.
- The setup script self-disables (refuses to run once an administrator exists) and can be deleted after use.

### D-3 — Database delivery (both paths ship)
- `database/database.sql` — clean, self-contained schema for a **fresh install**.
- `database/migrate_from_node.sql` — **opt-in** conversion of the live legacy `canteen_db`, idempotent, preserving every user, order, and food item.
- The installer detects which one is in use and refuses to run twice.

### D-4 — Legacy password migration
- The old `salt:hex` scrypt hash moves to `users.password_legacy`.
- On successful login: verify against `password_legacy` → immediately re-hash with `password_hash()` → write `users.password` → `SET password_legacy = NULL`.
- **Nobody is locked out.** No mass password reset.
- `password_legacy` is dropped in a later migration once the column is fully NULL.

### Consequences accepted by these decisions
| Decision | Closes | Cost |
|---|---|---|
| D-2 | VULN-1 (critical) | Existing admins must be re-created once; the legacy admin rows are migrated to `is_active = 1` but must be able to log in — they can, via D-4. |
| D-3 | — | Two schema scripts to maintain and keep in sync. |
| D-4 | VULN-5 | One extra nullable column, plus a legacy verifier in `auth.php`. |
| D-1 | — | Phase 8 cannot start until the environment exists. |