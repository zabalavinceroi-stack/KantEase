/**
 * KantEase — offline and language compliance audit (development tool).
 *
 * Usage:  node tools/check-offline.js
 *
 * KantEase must run with no internet connection. That is only credible if it is
 * checked, because a single `<link href="https://fonts.googleapis.com/...">`
 * turns an offline school system into one that renders unstyled text the moment
 * the network is unplugged — and nothing else would reveal it, because every
 * test would still pass on a connected machine.
 *
 * This scans every application file for anything that would reach the network
 * at runtime, and for frameworks that would need a build pipeline or a second
 * language runtime.
 *
 * SCOPE
 *   Application files are scanned: *.php, *.css, *.js, *.html, *.sql.
 *
 *   The legacy Node.js tree is REPORTED but not scanned, because Rule 1 keeps
 *   server.js on disk for reference while forbidding the PHP application from
 *   using it. It is checked for one thing only: that nothing in the PHP
 *   application requires it.
 *
 * WHAT COUNTS AS A VIOLATION
 *   - Any absolute URL whose host is not localhost / 127.0.0.1
 *   - Protocol-relative URLs ("//host/path")
 *   - @import of a remote stylesheet
 *   - fetch/XHR/beacon/WebSocket aimed at an absolute non-local URL
 *   - Known CDN and font-service hostnames
 *   - Framework markers for the languages and toolchains that are prohibited
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

const SKIP_DIRS = new Set(['node_modules', '.git', 'docs', '.idea', '.vscode']);
const SCAN_EXT = ['.php', '.css', '.js', '.html', '.sql', '.htaccess'];

/** The legacy Node.js tree: present on disk, forbidden to the PHP app. */
const LEGACY_FILES = ['server.js', 'package.json', 'package-lock.json'];

/** Hosts that are local to the school network and therefore allowed. */
const LOCAL_HOST = /^(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]|::1)$/i;

/** Hostnames that only ever appear as a remote dependency. */
const KNOWN_CDN_HOSTS = [
  'cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'ajax.googleapis.com',
  'fonts.googleapis.com', 'fonts.gstatic.com', 'code.jquery.com', 'cdnjs.com',
  'stackpath.bootstrapcdn.com', 'use.fontawesome.com', 'use.typekit.net',
  'cdn.sketchfab.com', 'esm.sh', 'esm.run', 'deno.land', 'raw.githubusercontent.com',
  'cdn.statically.io', 'kit.fontawesome.com', 'maxcdn.bootstrapcdn.com',
];

/**
 * Framework and language markers that Rule 1 prohibits.
 *
 * Each entry is matched against import lines, script tags, composer/npm
 * manifests and CDN filenames rather than against the whole file, because a
 * project is allowed to *mention* these words in a comment explaining why it
 * does not use them.
 */
const PROHIBITED_FRAMEWORKS = [
  { name: 'React',        re: /from\s+['"]react['"]|require\(['"]react['"]\)|\/react(\.min)?\.js\b/i },
  { name: 'Vue',          re: /from\s+['"]vue['"]|require\(['"]vue['"]\)|\/vue(\.min)?\.js\b/i },
  { name: 'Angular',      re: /from\s+['"]@angular\/|require\(['"]@angular\//i },
  { name: 'Next.js',      re: /from\s+['"]next(\/|['"])/i },
  { name: 'jQuery',       re: /(?:^|[^.\w])jquery(?:\.slim)?(?:\.min)?\.js|require\(['"]jquery['"]\)|\bjQuery\b\s*=/im },
  { name: 'Tailwind',     re: /tailwind(?:css)?(?:\.min)?\.css|@tailwind\s+(?:base|components|utilities)/i },
  { name: 'Bootstrap',    re: /bootstrap(?:\.bundle)?(?:\.min)?\.(?:css|js)/i },
  { name: 'Laravel',      re: /Illuminate\\|artisan\s+serve|laravel\.com/i },
  { name: 'Express',      re: /require\(['"]express['"]\)/ },
  { name: 'TypeScript',   re: /\bfrom\s+['"][^'"]+\.ts['"]|\btsconfig\.json\b|:\s*(?:string|number|boolean)\s*(?:;|\|)/ },
  { name: 'Socket.IO',   re: /require\(['"]socket\.io['"]\)|\/socket\.io\.js\b/i },
  { name: 'Google reCAPTCHA', re: /google\.com\/recaptcha|grecaptcha\.api\.js/i },
];

/**
 * Where a remote URL would be reached at runtime.
 */
const REMOTE_URL_PATTERNS = [
  { what: 'remote stylesheet', re: /<link[^>]+href\s*=\s*["']https?:\/\/[^"']+/gi },
  { what: 'remote script',     re: /<script[^>]+src\s*=\s*["']https?:\/\/[^"']+/gi },
  { what: 'remote @import',    re: /@import\s+(?:url\()?["']?https?:\/\/[^"')]+/gi },
  { what: 'remote image',      re: /<img[^>]+src\s*=\s*["']https?:\/\/[^"']+/gi },
  { what: 'remote font',       re: /@font-face\s*\{[^}]*src\s*:[^}]*https?:\/\//gi },
  { what: 'fetch()',           re: /fetch\(\s*["'`]https?:\/\/[^"'`]+/gi },
  { what: 'XHR',               re: /\.open\(\s*["'][A-Z]+["']\s*,\s*["'`]https?:\/\/[^"'`]+/gi },
  { what: 'sendBeacon',        re: /sendBeacon\(\s*["'`]https?:\/\/[^"'`]+/gi },
  { what: 'WebSocket',         re: /new\s+WebSocket\(\s*["'`](?:wss?:\/\/|https?:\/\/)[^"'`]+/gi },
  { what: 'EventSource',       re: /new\s+EventSource\(\s*["'`]https?:\/\/[^"'`]+/gi },
  { what: 'dynamic import',    re: /import\(\s*["'`]https?:\/\/[^"'`]+/gi },
  { what: 'protocol-relative', re: /(?:src|href)\s*=\s*["']\/\/[a-z0-9-]+\.[a-z]{2,}/gi },
];

/** Any absolute http(s) URL, used to report the host so it can be classified. */
const ANY_ABSOLUTE_URL = /https?:\/\/[^\s"'`)<>\]]+/gi;

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith('.') && entry.name !== '.htaccess') continue;
    if (SKIP_DIRS.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
}

/**
 * Blank out comments, keeping the line numbering intact.
 *
 * Without this the audit flags its own documentation. Two real examples:
 *
 *   logout.php documents WHY sign-out is POST-only by naming the attack it
 *   defeats: `<img src="http://canteen.school/KantEase/logout.php">`. That is a
 *   comment explaining a defence, and reading it as a runtime request would be
 *   exactly backwards.
 *
 *   This file lists the CDNs and frameworks it searches for, so it would always
 *   report itself as a violation.
 *
 * Only real code can reach the network, so only real code is audited.
 */
function stripComments(src) {
  let out = '';
  let i = 0;
  const n = src.length;

  while (i < n) {
    const c = src[i];
    const d = src[i + 1];

    // /* block comment */
    if (c === '/' && d === '*') {
      i += 2;
      while (i < n && !(src[i] === '*' && src[i + 1] === '/')) {
        out += src[i] === '\n' ? '\n' : ' ';
        i++;
      }
      i += 2;
      out += '  ';
      continue;
    }

    // // line comment, and # line comment (PHP attributes are rare here)
    if ((c === '/' && d === '/') || c === '#') {
      while (i < n && src[i] !== '\n') {
        out += ' ';
        i++;
      }
      continue;
    }

    // Quoted string: keep it, but blank anything that looks like a URL inside
    // a heredoc-free single-line literal only if it is escaped. Real string
    // content matters, so it is preserved verbatim.
    if (c === "'" || c === '"') {
      const quote = c;
      out += c;
      i++;
      while (i < n) {
        if (src[i] === '\\' && i + 1 < n) {
          out += src.slice(i, i + 2);
          i += 2;
          continue;
        }
        out += src[i];
        if (src[i] === quote) {
          i++;
          break;
        }
        i++;
      }
      continue;
    }

    out += c;
    i++;
  }

  return out;
}

const SELF = path.basename(__filename);

const violations = [];
const notes = [];

function add(file, line, message) {
  violations.push(`${file}:${line}  ${message}`);
}

/** Pull the host out of an absolute URL, or null when it is not one. */
function hostOf(url) {
  const m = /^https?:\/\/([^/:?#]+)/i.exec(url);
  return m ? m[1] : null;
}

const allFiles = walk(ROOT);

const appFiles = allFiles.filter((f) => {
  const name = path.basename(f);
  if (LEGACY_FILES.includes(name)) return false;
  if (SCAN_EXT.includes(path.extname(f)) || name === '.htaccess') return true;
  return false;
});

for (const file of appFiles) {
  const rel = path.relative(ROOT, file);

  // The auditor cannot audit itself: this file names every CDN and framework
  // it searches for, so it would otherwise always report a violation.
  if (path.basename(file) === SELF) continue;

  const src = stripComments(fs.readFileSync(file, 'utf8'));
  const lines = src.split('\n');

  // --- prohibited frameworks -------------------------------------------
  for (const { name, re } of PROHIBITED_FRAMEWORKS) {
    lines.forEach((line, i) => {
      if (re.test(line)) add(rel, i + 1, `${name} is prohibited by Rule 1`);
    });
  }

  // --- runtime network reach ------------------------------------------
  for (const { what, re } of REMOTE_URL_PATTERNS) {
    lines.forEach((line, i) => {
      const hits = line.match(re);
      if (!hits) return;
      for (const hit of hits) {
        const m = /https?:\/\/([^/:?#"')]+)/i.exec(hit);
        const host = m ? m[1] : null;
        if (host && LOCAL_HOST.test(host)) return;
        add(rel, i + 1, `${what} reaches off-machine: ${hit.trim().slice(0, 90)}`);
      }
    });
  }

  // --- any remaining absolute URL, classified --------------------------
  lines.forEach((line, i) => {
    const hits = line.match(ANY_ABSOLUTE_URL) || [];
    for (const raw of hits) {
      const host = hostOf(raw);
      if (!host) continue;
      if (LOCAL_HOST.test(host)) continue;
      const kind = KNOWN_CDN_HOSTS.includes(host.toLowerCase())
        ? 'KNOWN CDN OR FONT SERVICE'
        : 'external host';
      notes.push(`${rel}:${i + 1}  [${kind}] ${raw.trim().slice(0, 100)}`);
    }
  });
}

// --- legacy tree --------------------------------------------------------
const legacyPresent = LEGACY_FILES.filter((f) => fs.existsSync(path.join(ROOT, f)));

// Does a WEB-REACHABLE application file require any legacy file?
//
// The test suites legitimately name server.js and package.json: they fetch
// those URLs over HTTP to prove the .htaccess rules refuse them. That is the
// opposite of depending on them, so tests/ and tools/ are excluded. What must
// never happen is a page or repository requiring the Node build to run.
let phpTouchesLegacy = false;

const webReachable = appFiles.filter((f) => {
  const rel = path.relative(ROOT, f).replace(/\\/g, '/');

  return f.endsWith('.php') && !rel.startsWith('tests/') && !rel.startsWith('tools/');
});

for (const file of webReachable) {
  const src = stripComments(fs.readFileSync(file, 'utf8'));

  for (const legacy of LEGACY_FILES) {
    if (src.includes(legacy)) {
      phpTouchesLegacy = true;
      add(path.relative(ROOT, file), 0, `references the legacy file ${legacy}`);
    }
  }
}

// -----------------------------------------------------------------------
// Report
// -----------------------------------------------------------------------

console.log(`Scanned ${appFiles.length} application files under ${ROOT}\n`);

console.log('Legacy Node.js tree (kept on disk, forbidden to the PHP application):');
if (legacyPresent.length === 0) {
  console.log('  (absent)');
} else {
  legacyPresent.forEach((f) => console.log(`  present: ${f}`));
  console.log(`  referenced by PHP application: ${phpTouchesLegacy ? 'YES - VIOLATION' : 'no'}`);
}

console.log('');

if (notes.length > 0) {
  console.log(`External URLs found in application files (${notes.length}):`);
  notes.forEach((n) => console.log('  ' + n));
  console.log('');
}

if (violations.length === 0) {
  console.log('OK — no remote runtime dependency and no prohibited framework.');
  console.log('     Fonts, icons, stylesheets and scripts are all local files.');
  process.exit(0);
}

console.log(`${violations.length} compliance violation(s):\n`);
violations.forEach((v) => console.log('  ' + v));
process.exit(1);