# KantEase

**Offline school canteen ordering and management system.**
Runs entirely on a local computer or school LAN. No internet connection is
required at runtime.

---

## ⚠️ Security notice — read this first

An earlier version of this repository committed database credentials to
source control. Those credentials must be treated as **compromised**.

* The MySQL account used by the old application has been **exposed** and must
  be **rotated** before KantEase is used on any machine that students can reach.
* Do **not** reuse that password, or any part of it, for the new application.
* Credentials must never be written into any file that is committed to git.

**Rotating the exposed credential**

1. Open the XAMPP Control Panel → **Shell**, or run from a command prompt:
   ```
   mysql -u root -p
   ```
2. At the `mysql>` prompt, dropping the old application account and creating a
   new one for KantEase (replace `<old-user>` and `<old-db>` with the names the
   legacy app used, then choose your own long random password):
   ```sql
   DROP USER '<old-user>'@'localhost';
   CREATE USER 'kantease_app'@'localhost' IDENTIFIED BY 'choose-a-long-random-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON <old-db>.* TO 'kantease_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
3. Put the new password in your **local, untracked** config file — never here,
   never in any file in this repository.

**Note on git history:** the password remains in the commit history of this
repository even after it is removed from the working files. Anyone with a copy
of the repository can still read it. That history should be rewritten or the
repository re-initialised once the migration is complete.

---

## Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, vanilla JavaScript (ES6+), no frameworks, no CDN |
| Backend | Native PHP 8.2+ — no framework, no Composer packages |
| Database | MySQL 8.0 / MariaDB 10.4+ |
| Server | Apache via XAMPP |
| Auth | PHP sessions, `password_hash()`, PDO prepared statements, CSRF tokens |

Every asset is served from this folder. Nothing is fetched from the internet.

---

## Project layout

```
KantEase/
├── index.php  login.php  register.php  logout.php
├── .htaccess                     web-server hardening + security headers
├── .gitignore
│
├── student/                      Student panel pages
├── admin/                        Administrator panel pages
├── api/                          JSON endpoints (Fetch API consumers)
│
├── includes/                     ← never web-accessible
│   ├── bootstrap.php             the single entry point for every script
│   ├── config.php                loads and validates configuration
│   ├── config.sample.php         COMMITTED TEMPLATE — contains no secrets
│   ├── config.local.php          YOUR credentials — gitignored, never commit
│   ├── database.php  auth.php  csrf.php  passwords.php
│   ├── functions.php  validation.php  search.php  pagination.php
│   ├── enums.php  exceptions.php  error_handler.php  schema_installer.php
│   ├── routing.php
│   ├── repositories/             ← every SQL statement lives here
│   └── layout/                   ← shared page shells
│
├── assets/
│   ├── css/                      base, layout, components
│   ├── js/                       core, student, admin
│   └── images/                   local logo, icons, placeholder
│
├── uploads/products/             ← product photos, PHP execution disabled
│
├── database/
│   ├── database.sql              fresh-install schema
│   ├── migrate_from_node.sql     legacy data migration
│   └── setup.php                 one-time installer (delete after use)
│
├── tests/                        verification suites (run from the CLI)
├── tools/                        development-only static checkers
└── docs/                         audit and design documents
```

---

## Installation

Full instructions are in [`docs/SETUP-LOCAL.md`](docs/SETUP-LOCAL.md).
In short:

1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy this folder into `C:\xampp\htdocs\KantEase`.
3. Copy `includes/config.sample.php` to **`includes/config.local.php`**
   and enter your MySQL username and password **there**.
   This file is gitignored and blocked from the web server.
4. Open <http://localhost/KantEase/database/setup.php> and follow the three steps.
5. **Delete `database/setup.php`** when it finishes.
6. Visit <http://localhost/KantEase/>.

### Where the database password goes

**Only** in `includes/config.local.php`. Never in:

* any file committed to git
* any file inside `assets/` (browser-readable)
* `includes/config.sample.php` (committed template, must stay empty)
* chat messages, screenshots, or issue trackers

If the password is ever pasted into a file that is under version control, treat
it as leaked and rotate it.

---

## Running the verification suites

Once XAMPP is running:

```
php tests/verify-phase2.php      full Phase 2 verification
php tests/legacy-password-test.php   legacy password migration check
```

Both exit with code 0 on success and 1 on failure.

---

## Legacy application

The original single-file Node.js application is kept at the repository root as
`server.js` for reference during the migration. **Do not run it.** It contains
hardcoded credentials and a public administrator sign-up, and it is superseded
by the PHP application in this repository.

It can be deleted once the migration has been signed off.

---

© KantEase — canteen ordering made easy.