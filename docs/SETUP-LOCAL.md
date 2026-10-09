# Setting up KantEase locally

Everything below runs on one Windows computer, or on a server computer for a
school LAN. **No internet connection is needed** after XAMPP is installed.

> **Do not type your database password into chat, an issue tracker, a
> screenshot, or any file in this repository.** It goes in
> `includes/config.local.php` and nowhere else. That file is gitignored and
> blocked from the web server.

---

## 1. Install XAMPP

Download XAMPP for Windows from the Apache Friends site, install it, then open
the **XAMPP Control Panel**.

Make sure these components are present:

| Component | Needed |
|---|---|
| Apache | yes |
| MySQL (or MariaDB) | yes |
| PHP | yes, **version 8.2 or newer** |
| phpMyAdmin | yes, for inspecting the database |

> If your XAMPP bundles PHP 8.1 or older, KantEase will not run. Update XAMPP
> first, or install a standalone PHP 8.2+ and point Apache at it.

---

## 2. Start Apache and MySQL

Press **Start** next to Apache and next to MySQL in the Control Panel. Both
should turn green.

* Apache serves <http://localhost/>
* phpMyAdmin is at <http://localhost/phpmyadmin/>

---

## 3. Copy the application into htdocs

Copy the whole `KantEase` folder into XAMPP's web root:

```
C:\xampp\htdocs\KantEase\
```

The folder name matters: the default configuration expects the app at
`/KantEase`, which is what `http://localhost/KantEase/` refers to.

---

## 4. Create your local configuration file

Copy the template:

```
includes\config.sample.php   ->   includes\config.local.php
```

Open `includes/config.local.php` in Notepad and set **only** these:

```php
'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'name'     => 'kantease_db',
    'user'     => 'root',                 // your MySQL user
    'password' => 'YOUR-PASSWORD-HERE',    // your MySQL password
    'charset'  => 'utf8mb4',
],
```

| Setting | What to enter |
|---|---|
| `host` | `127.0.0.1` for the same machine. The LAN server's IP if KantEase runs on a separate server. |
| `name` | `kantease_db` for a fresh install. The **old** database name if you are migrating existing data. |
| `user` | A MySQL account you control. `root` with an empty password is the XAMPP default. |
| `password` | That account's password. Leave `''` if it has none. |

Three useful facts:

* `config.local.php` is listed in `.gitignore`, so it can never be committed
  by accident.
* `includes/.htaccess` blocks it from the web server, and the root `.htaccess`
  blocks it a second time.
* Leaving `password` empty is fine for a default XAMPP install. It is not fine
  for a machine students can reach — give the account a real password.

**If you would rather not use `root`**, create a dedicated account in phpMyAdmin
→ SQL tab:

```sql
CREATE USER 'kantease_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON kantease_db.* TO 'kantease_app'@'localhost';
FLUSH PRIVILEGES;
```

Then put that username and password in `config.local.php`. The `kantease_app`
account cannot create or drop tables, so even a compromised web request cannot
destroy the canteen's data.

---

## 5. Run the installer

Open:

```
http://localhost/KantEase/database/setup.php
```

It walks through three steps:

1. **Create the database** — runs `CREATE DATABASE IF NOT EXISTS`.
2. **Install the tables** — applies `database/database.sql`.
3. **Create the first administrator** — you choose the name, email and password.

Each step is a button, so nothing runs until you click it.

### When it finishes

The installer writes `database/.installed`, and refuses to run again. **Delete
`database/setup.php` now** — it is not needed, and a file that does not exist
cannot be reached.

> The installer only runs from `localhost`. That is deliberate: it is the one
> page that can create an administrator, so it must not be open to the school
> network.

---

## 6. Migrating an existing canteen database

Skip this if you are starting fresh.

The old Node.js application kept its data in a database whose name you set in
`server.js`. To carry it over:

1. **Back it up first**, in phpMyAdmin: select the old database → **Export** →
   **Save**. Do not skip this.
2. Create a *copy* to experiment on. Keep the original until KantEase works.
3. In phpMyAdmin, select the copy and **Import** `database/migrate_from_node.sql`.
4. Point `config.local.php` `db.name` at that database.
5. Sign in with an **existing** account. Its old password is verified once and
   immediately upgraded to the new format. Nobody has to reset anything.

The migration:

* keeps every student, administrator, order and product
* gives each legacy order a `KE-LEGACY-####` reference
* preserves orders that had no owner (they are invisible in the old admin
  report, but the data is kept)
* renames the original tables to `legacy_*` so nothing is destroyed
* writes eight verification queries at the end — **each must return an empty
  result set**

See the header of `database/migrate_from_node.sql` for the full list of what
cannot be recovered (historical payment confirmations and stock movements were
never recorded by the old build).

---

## 7. Verify the installation

With Apache and MySQL running:

```
C:\xampp\php\php.exe tests\verify-phase2.php
C:\xampp\php\php.exe tests\verify-web.php
```

| Command | Proves |
|---|---|
| `verify-phase2.php` | Syntax, credentials hygiene, database, migration, sessions, CSRF, permissions, order and stock integrity |
| `verify-web.php` | No sensitive file can be fetched through a browser |

Both must report `RESULT: PASS`. If either fails, **do not continue to the next
phase** — the message will say what went wrong.

---

## 8. Run the app

```
http://localhost/KantEase/
```

---

## Using KantEase from other devices on the school LAN

The same Apache that serves `localhost` serves the network. One setting makes
the server visible to other machines.

1. Find the server computer's IPv4 address:
   * open Command Prompt
   * type `ipconfig`
   * read **IPv4 Address**, e.g. `192.168.1.50`

2. Allow Apache through Windows Firewall. XAMPP normally prompts for this on
   first start. If you dismissed it, open **Windows Defender Firewall with
   Advanced Security** → **Inbound Rules** → **New Rule** → **Port** → **TCP 80**
   → **Allow the connection**, restricted to **Private** networks.

3. On another device on the same network, open:

   ```
   http://192.168.1.50/KantEase/
   ```

4. If the folder is served from the document root instead of a subfolder, set
   `'base_path' => ''` in `config.local.php`.

### Notes for the LAN

* Every device reaches the **one** database on the server. There is no copy per
  device, so order and stock stay consistent everywhere.
* Nothing is published to the internet. A device outside the school network
  cannot reach the server unless someone explicitly forwards a port.
* If the server is switched off, other devices show a connection error. They
  never see a false "order submitted" message — orders are only reported as
  placed once the server has written them.
* Consider giving the MySQL account a real password before students connect,
  and keeping the machine behind a login.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "KantEase needs to be configured" | `config.local.php` missing | Copy `config.sample.php` to `config.local.php` |
| "MySQL is not running" | MySQL stopped | Start it in the XAMPP Control Panel |
| "The database does not exist" | Schema not imported | Run `database/setup.php` |
| "MySQL rejected the username or password" | Wrong credentials | Check `db.user` / `db.password` in `config.local.php` |
| Setup says "Already installed" | `.installed` exists | Expected. Delete `database/setup.php` and use the app |
| Login loops back to the sign-in page | Session not persisting | Check `app.base_path` matches the folder name |
| `page not found` on sub-pages | Wrong `base_path` | Set it to `/KantEase` or `''` |
| Styles look unstyled | Apache `AllowOverride None` | Set `AllowOverride All` for `htdocs` in `httpd.conf` |
| White page with no message | PHP error hidden by design | Read `C:\xampp\php\logs\error.log` |
| Port 80 already in use | IIS or another server | Stop it, or change the XAMPP Apache port |

### Seeing errors while you work

Set `'dev_mode' => true` in `config.local.php` to show detailed error pages.
**Set it back to `false` before any student uses the machine.**