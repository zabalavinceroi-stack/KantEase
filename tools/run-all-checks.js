/**
 * KantEase — full verification runner (development tool, not shipped).
 *
 * Runs every check in the right order and prints one summary. Exits non-zero if
 * any stage fails, so it can be wired into CI or a scheduled task later.
 *
 * Usage:
 *   node tools/run-all-checks.js [path-to-php.exe]
 *
 * Order matters:
 *   1. php -l                    no dependencies
 *   2. check-references          no dependencies
 *   3. check-namespaces          no dependencies
 *   4. check-sql                 no dependencies
 *   5. check-schema-live         needs MariaDB
 *   6. verify-isolation          needs MariaDB; fingerprints the live database
 *   7. verify-phase2             needs PHP CLI + MariaDB
 *   8. verify-isolation again    proves the live database did not change
 *
 * The isolation guard runs on BOTH sides of the suite on purpose: the first run
 * records a baseline fingerprint, and the second compares against it.
 */

const { execFileSync, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const PHP = process.argv[2] || 'php';

let failures = 0;
const summary = [];

/** Run a command, stream its output, and record whether it passed. */
function run(label, command, args, options = {}) {
  process.stdout.write(`\n${'='.repeat(78)}\n  ${label}\n${'='.repeat(78)}\n`);

  const result = spawnSync(command, args, {
    cwd: ROOT,
    stdio: 'inherit',
    shell: false,
    ...options,
  });

  const code = result.status === null ? 1 : result.status;
  const ok = code === 0;

  if (!ok) failures++;
  summary.push({ label, ok, code });

  return ok;
}

// ---------------------------------------------------------------------------
// 1. PHP syntax
// ---------------------------------------------------------------------------

process.stdout.write(`\n${'='.repeat(78)}\n  1. PHP syntax check (php -l)\n${'='.repeat(78)}\n`);

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'docs'].includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (path.extname(entry.name) === '.php') out.push(full);
  }
  return out;
}

const phpFiles = walk(ROOT);
let syntaxFailures = 0;

for (const file of phpFiles) {
  try {
    execFileSync(PHP, ['-l', file], { stdio: 'pipe' });
  } catch (error) {
    syntaxFailures++;
    console.log(`  FAIL ${path.relative(ROOT, file)}`);
    console.log(`       ${(error.stdout || '').toString().trim()}`);
  }
}

if (syntaxFailures > 0) failures++;
summary.push({ label: 'php -l', ok: syntaxFailures === 0 });
console.log(syntaxFailures === 0
  ? `  PASS — ${phpFiles.length} files clean`
  : `  FAIL — ${syntaxFailures} of ${phpFiles.length} files`);

// ---------------------------------------------------------------------------
// 1b. JavaScript syntax
// ---------------------------------------------------------------------------

// Added in Phase 4A, after the reason the navigation appeared blank turned out
// to be a syntax error in assets/js/core.js: an unquoted object key
// (`menu-bars:`) made the whole file fail to parse, so the entire front-end
// runtime silently never ran — no theme icon, no drawer, no keyboard support.
// The page still returned HTTP 200 and every DOM string assertion still passed,
// because the defect lived in the browser, not in the served markup.
//
// `php -l` only looks at PHP, so nothing caught it for an entire phase. This
// stage does.
process.stdout.write(`\n${'='.repeat(78)}\n  1b. JavaScript syntax check\n${'='.repeat(78)}\n`);

function walkJs(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'docs'].includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walkJs(full, out);
    else if (path.extname(entry.name) === '.js') out.push(full);
  }
  return out;
}

const jsFiles = walkJs(path.join(ROOT, 'assets'));
let jsFailures = 0;

for (const file of jsFiles) {
  try {
    // --check parses without executing, so nothing is sent anywhere.
    execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
  } catch (error) {
    jsFailures++;
    console.log(`  FAIL ${path.relative(ROOT, file)}`);
    console.log(`       ${(error.stderr || '').toString().trim().split('\n')[0]}`);
  }
}

if (jsFailures > 0) failures++;
summary.push({ label: 'JavaScript syntax', ok: jsFailures === 0 });
console.log(jsFailures === 0
  ? `  PASS — ${jsFiles.length} files clean (assets/ only; tools/ is developer tooling)`
  : `  FAIL — ${jsFailures} of ${jsFiles.length} files`);

// ---------------------------------------------------------------------------
// 2-4. Static analysis
// ---------------------------------------------------------------------------

run('2. Static cross-references', 'node', ['tools/check-references.js']);
run('3. Namespace resolution', 'node', ['tools/check-namespaces.js']);
run('4. SQL statement structure',
    'node', ['tools/check-sql.js', 'database/database.sql', 'database/migrate_from_node.sql']);
run('5. Offline and language compliance', 'node', ['tools/check-offline.js']);

// ---------------------------------------------------------------------------
// 5. Live database
// ---------------------------------------------------------------------------

run('5. Live MariaDB schema + migration', 'node', ['tools/check-schema-live.js']);

// ---------------------------------------------------------------------------
// 6. Isolation baseline
// ---------------------------------------------------------------------------

run('6. Database isolation guard (baseline)', PHP, ['tests/verify-isolation.php']);

// ---------------------------------------------------------------------------
// 7. The main suite
// ---------------------------------------------------------------------------

run('7. Phase 2 verification suite', PHP, ['tests/verify-phase2.php']);

// ---------------------------------------------------------------------------
// 8. Prove the live database is unchanged
// ---------------------------------------------------------------------------

run('8. Database isolation guard (after the suite)', PHP, ['tests/verify-isolation.php']);

// ---------------------------------------------------------------------------
// 9. Web access and headers (needs Apache serving the app)
// ---------------------------------------------------------------------------
//
// This one needs a running Apache with KantEase inside the DocumentRoot. It is
// skipped rather than failed when nothing answers, so the other eight stages
// still work on a machine with no web server.

const WEB_URL = process.argv[3] || null;

process.stdout.write(`\n${'='.repeat(78)}\n  9. Web access and security headers\n${'='.repeat(78)}\n`);

const webArgs = WEB_URL ? ['tests/verify-web.php', WEB_URL] : ['tests/verify-web.php'];
const web = spawnSync(PHP, webArgs, { cwd: ROOT, stdio: 'inherit', shell: false });
const webCode = web.status === null ? 1 : web.status;

if (webCode === 0) {
  summary.push({ label: '9. Web access and headers', ok: true });
} else {
  summary.push({ label: '9. Web access and headers', ok: false });
  failures++;
}

// ---------------------------------------------------------------------------
// 10. Phase 3: authentication and role-based access
// ---------------------------------------------------------------------------
//
// Needs PHP CLI + MariaDB. It builds its own throwaway database and its own
// temporary copy of the application, so it does not disturb the live database
// or your configuration.

process.stdout.write(`\n${'='.repeat(78)}\n  10. Phase 3 authentication suite\n${'='.repeat(78)}\n`);

const phase3 = spawnSync(PHP, ['tests/verify-phase3.php'], { cwd: ROOT, stdio: 'inherit', shell: false });
const phase3Code = phase3.status === null ? 1 : phase3.status;

summary.push({ label: '10. Phase 3 authentication', ok: phase3Code === 0 });
if (phase3Code !== 0) failures++;

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

console.log(`\n${'#'.repeat(78)}`);
console.log('  SUMMARY');
console.log('#'.repeat(78));

for (const stage of summary) {
  console.log(`  ${stage.ok ? 'PASS' : 'FAIL'}  ${stage.label}`);
}

console.log(`\n  ${summary.filter(s => s.ok).length} of ${summary.length} stages passed\n`);

process.exit(failures === 0 ? 0 : 1);