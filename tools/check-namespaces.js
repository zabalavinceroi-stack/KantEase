/**
 * KantEase — namespace resolution checker (development tool, not shipped).
 *
 * PHP name resolution in a namespaced file:
 *
 *   - An unqualified CLASS name resolves to `current\namespace\ClassName`.
 *   - An unqualified FUNCTION call resolves to `current\namespace\fn()` FIRST,
 *     and only then to the global function.
 *
 * Both rules have bitten this codebase during Phase 2, each producing a fatal
 * error that only appeared on whichever code path happened to run first:
 *
 *   1. `client_ip()` inside `namespace KantEase\Repositories` resolved to
 *      KantEase\Repositories\client_ip(), which does not exist. Fix: one
 *      `use function` line.
 *
 *   2. `catch (InstallerLockedException)` in a file with NO namespace resolved
 *      to \InstallerLockedException, so a deliberate refusal never matched and
 *      the test reported "wrong exception type" while the message plainly said
 *      the installer had refused. Fix: fully qualify the catch.
 *
 * Rule 2 is invisible to a static reader because the file looks correct.
 * This script catches both classes of mistake, including unqualified class
 * names inside catch/throw/new that do not exist in the global namespace.
 *
 * Usage:  node tools/check-namespaces.js
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SKIP = new Set(['node_modules', '.git', 'docs']);

/** PHP's own functions, always resolvable without an import. */
const BUILTINS = new Set([
  'array_map','array_filter','array_merge','array_keys','array_values','array_slice','array_fill',
  'array_fill_keys','array_key_exists','array_column','array_unique','array_sum','array_search',
  'array_reverse','array_shift','array_pop','array_push','array_diff','array_diff_key','array_intersect',
  'array_pad','array_splice','range','compact','extract','count','in_array','implode','explode',
  'sprintf','vsprintf','printf','number_format','str_pad','str_repeat','strlen','mb_strlen','substr',
  'mb_substr','trim','ltrim','rtrim','strtolower','mb_strtolower','strtoupper','mb_strtoupper',
  'str_replace','str_contains','str_starts_with','str_ends_with','strrev','str_split','strval',
  'substr_count','wordwrap','chunk_split','preg_match','preg_replace','preg_replace_callback',
  'preg_split','preg_quote','preg_grep','json_encode','json_decode','serialize','unserialize',
  'is_array','is_string','is_int','is_float','is_bool','is_null','is_numeric','is_object','is_callable',
  'is_iterable','is_countable','isset','empty','gettype','get_debug_type','max','min','abs','round',
  'floor','ceil','intval','floatval','boolval','random_bytes','random_int','uniqid','hash',
  'hash_equals','hash_pbkdf2','password_hash','password_verify','password_needs_rehash','pack','unpack',
  'bin2hex','hex2bin','base64_encode','base64_decode','date','gmdate','time','mktime','checkdate',
  'strtotime','timezone_identifiers_list','filter_var','setcookie','header','headers_sent',
  'session_start','session_status','session_name','session_regenerate_id','session_destroy',
  'ini_set','ini_get','set_exception_handler','error_log','file_get_contents','file_put_contents',
  'is_file','is_dir','is_readable','is_writable','mkdir','unlink','fopen','fclose','intdiv','usleep',
  'microtime','memory_get_usage','php_sapi_name','phpversion','version_compare','defined','define',
  'constant','exit','die','var_dump','parse_url','http_build_query','parse_str','strtok','getenv',
  'putenv','class_exists','function_exists','method_exists','call_user_func','call_user_func_array',
  'func_get_args','array_walk','iterator_to_array','htmlspecialchars','mb_convert_encoding',
  'ucfirst','ucwords','lcfirst','strip_tags','nl2br','fwrite','get_class','array_is_list',
  'str_word_count','ctype_digit','ctype_alpha','mb_str_split','spl_object_hash','serialize',
]);

/** Language constructs that look like calls or class names. */
const KEYWORDS = new Set([
  'function','fn','if','for','foreach','while','switch','match','catch','return','new','and','or','not',
  'use','echo','print','isset','unset','empty','list','array','static','public','private','protected',
  'const','require','require_once','include','include_once','elseif','do','try','throw','clone','yield',
  'instanceof','int','float','string','bool','void','iterable','object','mixed','never','self','parent',
  'global','else','break','continue','declare','namespace','default','exit','die','enum','readonly',
  'callable','true','false','null',
]);

/** Classes that exist in PHP's own extension set. */
const GLOBAL_CLASSES = new Set([
  'PDO','PDOStatement','PDOException','Throwable','Exception','Error','ErrorException','TypeError',
  'ValueError','ArgumentCountError','ArithmeticError','RuntimeException','LogicException',
  'InvalidArgumentException','OutOfBoundsException','RangeException','LengthException',
  'DomainException','UnderflowException','BadFunctionCallException','BadMethodCallException',
  'JsonException','DateTime','DateTimeImmutable','DateTimeZone','DateInterval','DatePeriod',
  'DateMalformedStringException','InvalidArgumentException','ArrayObject','ArrayIterator',
  'SplFixedArray','SplObjectStorage','Generator','Closure','stdClass','Stringable','UnitEnum',
  'BackedEnum','RecursiveIteratorIterator','RecursiveDirectoryIterator','FilesystemIterator',
  'DirectoryIterator','Iterator','IteratorAggregate','Countable','ArrayAccess','JsonSerializable',
  'Traversable','IteratorAggregate','SensitiveParameter','Attribute','ReturnTypeWillChange',
  // PHP extension classes.
  //   finfo    — used by includes/uploads.php to sniff an uploaded file's real type
  //   CURLFile — used by tests/verify-phase4b.php to post a multipart upload
  //
  // Note the checker reports these unqualified because its regex captures the
  // name after an optional leading backslash, so `new \finfo` is
  // indistinguishable from `new finfo` to it. Both are genuine global classes.
  'finfo','CURLFile',
]);

/** self, static, parent and true/false/null in ::class position. */
const RESERVED_STATIC = new Set(['self', 'static', 'parent', 'true', 'false', 'null']);

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (path.extname(entry.name) === '.php') out.push(full);
  }
  return out;
}

/**
 * Blank out strings and comments so scanning does not produce false hits.
 *
 * This is a real character-by-character scanner, not a chain of regexes. The
 * naive version paired quotes greedily across line boundaries, so an apostrophe
 * inside a comment — "the school's NAT" — opened a string that swallowed the
 * rest of the file, and every real code after it became invisible. A checker
 * that silently stops looking is worse than no checker, so this walks the
 * source properly.
 */
function strip(src) {
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

    // // line comment, and # line comment
    if ((c === '/' && d === '/') || c === '#') {
      while (i < n && src[i] !== '\n') { out += ' '; i++; }
      continue;
    }

    // <<< heredoc
    if (src.slice(i, i + 3) === '<<<') {
      const label = /^<<<\s*'?([A-Za-z0-9_]+)'?[ \t]*\r?\n/.exec(src.slice(i));
      if (label) {
        const tag = label[1];
        const close = new RegExp(`\\n[ \\t]*${tag}\\b`);
        const rest = src.slice(i + label[0].length);
        const end = close.exec(rest);
        const stop = end ? i + label[0].length + end.index : n;

        while (i < stop) { out += src[i] === '\n' ? '\n' : ' '; i++; }
        continue;
      }
    }

    // 'string'
    if (c === "'" || c === '"') {
      const quote = c;
      out += quote;
      i++;
      while (i < n) {
        if (src[i] === '\\' && i + 1 < n) { out += quote; i += 2; continue; }
        if (src[i] === quote && src[i + 1] === quote) { out += quote + quote; i += 2; continue; }
        if (src[i] === quote) { out += quote; i++; break; }
        out += src[i] === '\n' ? '\n' : ' ';
        i++;
      }
      continue;
    }

    out += c;
    i++;
  }

  return out;
}

const files = walk(ROOT);

// ---------------------------------------------------------------------------
// Build the KantEase namespace inventory: every class and function the project
// declares, per namespace.
// ---------------------------------------------------------------------------

const inventory = { classes: new Map(), functions: new Map() };

for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const ns = (src.match(/^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;/m) || [])[1] || '';
  const code = strip(src);

  for (const m of code.matchAll(/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/gm)) {
    if (!inventory.classes.has(ns)) inventory.classes.set(ns, new Set());
    inventory.classes.get(ns).add(m[1]);
  }

  for (const m of code.matchAll(/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+([A-Za-z0-9_]+)\s*\(/gm)) {
    if (!inventory.functions.has(ns)) inventory.functions.set(ns, new Set());
    inventory.functions.get(ns).add(m[1]);
  }
}

/**
 * Does $name exist in EXACTLY the namespace PHP would look in?
 *
 * PHP does NOT walk parent namespaces. Inside `namespace KantEase\Repositories`
 * an unqualified `utc_now()` looks for KantEase\Repositories\utc_now and then
 * the global one. It never considers KantEase\utc_now, which is where the real
 * function lives — so the name must be imported explicitly.
 *
 * Returning true here for a parent namespace is exactly the false negative that
 * let the missing `use function` line through.
 */
function resolvesExactly(name, ns, kind) {
  if ((inventory[kind].get(ns) || new Set()).has(name)) return true;

  // Global fallback.
  return (inventory[kind].get('') || new Set()).has(name);
}

/** Every namespace the project declares. */
const namespaces = new Set([...inventory.classes.keys(), ...inventory.functions.keys()]);

/** Does a qualified reference name (as written, e.g. "KantEase\\Foo") exist? */
function qualifiedExists(qualified) {
  const parts = qualified.split('\\');
  const leaf = parts.pop();

  for (let i = 0; i < parts.length; i++) {
    const prefix = parts.slice(0, i + 1).join('\\');
    const known = namespaces.has(prefix)
      || (inventory.classes.get(prefix) || new Set()).has(parts[i])
      || GLOBAL_CLASSES.has(parts[i]);

    if (!known) return false;
  }

  const owner = parts.join('\\');
  return (inventory.classes.get(owner) || new Set()).has(leaf)
    || (inventory.functions.get(owner) || new Set()).has(leaf);
}

/** Names this file imports, so they never count as unqualified. */
function importedNames(code) {
  const classes = new Set();
  const functions = new Set();

  for (const m of code.matchAll(/^\s*use\s+(?!function\s)([A-Za-z0-9_\\]+)\s*(?:as\s+([A-Za-z0-9_]+))?\s*;/gm)) {
    classes.add((m[2] || m[1].split('\\').pop()));
  }
  for (const m of code.matchAll(/^\s*use\s+function\s+([A-Za-z0-9_\\]+)\s*(?:as\s+([A-Za-z0-9_]+))?\s*;/gm)) {
    functions.add((m[2] || m[1].split('\\').pop()));
  }

  return { classes, functions };
}

const problems = [];
let qualifiedClassHits = 0;
let unqualifiedCallHits = 0;

for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const ns = (src.match(/^\s*namespace\s+([A-Za-z0-9_\\]+)\s*;/m) || [])[1] || '';
  const code = strip(src);
  const lineOf = (index) => code.slice(0, index).split('\n').length;
  const imports = importedNames(code);

  // ---- 1. Class references: catch / throw / new / extends / instanceof ------
  //
  // These resolve to `current\namespace\ClassName`. A KantEase class used
  // unqualified from a file with no namespace of its own will NOT be found.
  const classRefRe = /\b(catch|throw\s+new|new|extends|implements|instanceof)\s+\\?([A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)*)\b/g;
  let m;

  while ((m = classRefRe.exec(code))) {
    const keyword = m[1];
    const written = m[2];
    const isQualified = written.includes('\\');
    const leaf = written.split('\\').pop();

    if (KEYWORDS.has(leaf) || GLOBAL_CLASSES.has(leaf)) continue;
    if (imports.classes.has(leaf)) continue;

    if (isQualified) {
      // A backslash means the author was explicit. Verify the target anyway,
      // because a typo in the middle segment is otherwise invisible.
      if (!qualifiedExists(written)) {
        qualifiedClassHits++;
        problems.push(
          `${rel}:${lineOf(m.index)}  ${keyword} ${written} — no such class or namespace segment.`
        );
      }
      continue;
    }

    if (resolvesExactly(leaf, ns, 'classes') || resolvesExactly(leaf, ns, 'functions')) continue;

    // Report every unresolvable capitalised class reference, including a name
    // that exists nowhere at all.
    //
    // The earlier version skipped names it had never seen, on the assumption
    // they were PHP built-ins. That is exactly how a typo like
    // `throw new InstallerLockedTypo(` sails through: the typo exists in no
    // namespace, so "known nowhere" was treated as "fine". A capitalised class
    // name that resolves to nothing is a defect unless it is a genuine PHP or
    // extension class, and those are enumerated above.
    qualifiedClassHits++;
    problems.push(
      `${rel}:${lineOf(m.index)}  ${keyword} ${leaf} — resolves to ${ns || '(global)'}\\${leaf}, `
      + `which does not exist. Fully qualify it, add:  use KantEase\\${leaf};, `
      + `or correct the spelling if it is a typo.`
    );
  }

  // ---- 2. Static access: Foo::bar() ------------------------------------
  //
  // A class reference is also made by a static call, which the keyword-based
  // scan above cannot see because there is no keyword in front of the name.
  // A typo here is as fatal as one in `new`, so it gets the same treatment.
  const staticRe = /(?<![\\\w])([A-Z][A-Za-z0-9_]*)\s*::/g;

  while ((m = staticRe.exec(code))) {
    const leaf = m[1];

    if (RESERVED_STATIC.has(leaf)) continue;
    if (GLOBAL_CLASSES.has(leaf) || KEYWORDS.has(leaf)) continue;
    if (imports.classes.has(leaf)) continue;

    if (resolvesExactly(leaf, ns, 'classes')) continue;

    // A bare :: at the start means the name was already fully qualified, e.g.
    // \KantEase\Config::load(). The lookbehind above lets the backslash
    // through, so check the character that actually precedes the match.
    qualifiedClassHits++;
    problems.push(
      `${rel}:${lineOf(m.index)}  ${leaf}:: — resolves to ${ns ? ns + '\\' : ''}${leaf}, which does not exist. `
      + `Add:  use KantEase\\${leaf}; or correct the spelling.`
    );
  }

  // ---- 2. Unqualified function calls --------------------------------------
  //
  // PHP resolves an unqualified function call by looking in the CURRENT
  // namespace first, then the global one. It never walks parent namespaces.
  //
  // Two shapes matter, and the second one is easy to forget:
  //
  //   namespace KantEase\Repositories
  //     utc_now()  ->  KantEase\Repositories\utc_now()   [missing]
  //                  \utc_now()                        [also missing]
  //     The real function is KantEase\utc_now, so only a `use function` line
  //     can reach it.
  //
  //   no namespace - a web-root entry point such as login.php
  //     e()       ->  \e()                             [missing]
  //     The real function is KantEase\e(). Same fix.
  //
  // A file with no namespace is NOT exempt. Those entry points are where the
  // mistake is easiest to make, because the file reads as though the helpers
  // are simply available.
  const callRe = /(?<![\w>:$\\])([a-z_][a-z0-9_]*)\s*\(/g;

  while ((m = callRe.exec(code))) {
    const name = m[1];

    if (KEYWORDS.has(name) || BUILTINS.has(name)) continue;
    if (imports.functions.has(name) || imports.classes.has(name)) continue;
    if (/^(?:get|set|is|has|to|from|try)[A-Z]/.test(name)) continue;

    if (resolvesExactly(name, ns, 'functions')) continue;

    const knownSomewhere = [...inventory.functions.values()].some(set => set.has(name));

    if (!knownSomewhere) continue;

    unqualifiedCallHits++;
    problems.push(
      `${rel}:${lineOf(m.index)}  ${name}() resolves to ${ns ? ns + '\\' : ''}${name}(), which does not exist. `
      + `Add:  use function KantEase\\${name};`
    );
  }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

console.log(`Scanned ${files.length} PHP files.`);
console.log(`  namespaces: ${[...inventory.classes.keys()].filter(Boolean).join(', ') || '(none)'}`);
console.log(`  checked ${qualifiedClassHits} class reference(s) and ${unqualifiedCallHits} unqualified call(s)\n`);

if (problems.length === 0) {
  console.log('OK - no unresolved class or function names.');
} else {
  console.log(`${problems.length} namespace problem(s):\n`);
  problems.forEach(p => console.log('  ' + p));
  process.exitCode = 1;
}
