/**
 * KantEase static reference checker (development tool, not shipped).
 *
 * PHP is not installed on this machine yet, so `php -l` is unavailable and the
 * code has to be reviewed statically. This script builds an index of every
 * declared class, method and function, then reports any static call, type hint
 * or `new` that does not resolve.
 *
 * It is deliberately conservative: it only reports references to names that
 * are declared NOWHERE in the project. Anything it stays quiet about still
 * needs a real `php -l` run before shipping.
 *
 * Usage:  node tools/check-references.js
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SKIP = new Set(['node_modules', '.git', 'docs', 'tools']);
const EXT = ['.php'];

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, out);
    else if (EXT.includes(path.extname(entry.name))) out.push(full);
  }
  return out;
}

const files = walk(ROOT);
const sources = new Map();
for (const file of files) sources.set(file, fs.readFileSync(file, 'utf8'));

/** name -> {kind, file} */
const declaredClasses = new Map();
const declaredMethods = new Map(); // "Class::method"
const declaredFunctions = new Map(); // namespaced or global

for (const [file, src] of sources) {
  const namespaceMatch = src.match(/^namespace\s+([A-Za-z0-9_\\]+)\s*;/m);
  const ns = namespaceMatch ? namespaceMatch[1] : '';

  // Anchored at a statement start so prose such as "enum in the original" or
// "class rather than" is not mistaken for a declaration.
const classRe = /^\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+([A-Za-z0-9_]+)/gm;
  let m;
  while ((m = classRe.exec(src))) {
    const name = m[2];
    const fq = ns ? `${ns}\\${name}` : name;
    declaredClasses.set(name, { kind: m[1], file, ns });
    declaredClasses.set(fq, { kind: m[1], file, ns });
  }

  const fnRe = /^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+([A-Za-z0-9_]+)\s*\(/gm;
  while ((m = fnRe.exec(src))) {
    // Which class are we inside? Track the nearest preceding class declaration.
    const before = src.slice(0, m.index);
    const classesBefore = [...before.matchAll(/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/gm)];
    if (classesBefore.length > 0) {
      const className = classesBefore[classesBefore.length - 1][1];
      declaredMethods.set(className, declaredMethods.get(className) || new Set());
      declaredMethods.get(className).add(m[1]);
      declaredMethods.set(`${className}::${m[1]}`, { file });
    } else {
      declaredFunctions.set(ns ? `${ns}\\${m[1]}` : m[1], { file });
      declaredFunctions.set(m[1], { file });
    }
  }
}

// PHP's own functions that are obviously allowed.
const PHP_BUILTINS = new Set([
  'array_map','array_filter','array_merge','array_keys','array_values','array_slice','array_fill','array_fill_keys',
  'array_key_exists','array_column','array_unique','array_sum','array_search','array_reverse','array_shift',
  'array_pop','array_push','array_diff','array_diff_key','array_intersect','array_pad','range','compact','extract',
  'count','in_array','implode','explode','sprintf','vsprintf','printf','number_format','str_pad','str_repeat',
  'strlen','mb_strlen','substr','mb_substr','trim','ltrim','rtrim','strtolower','mb_strtolower','strtoupper',
  'mb_strtoupper','str_replace','str_contains','str_starts_with','str_ends_with','str_repeat','strrev','str_split',
  'str_word_count','ucfirst','ucwords','lcfirst','strval','sprintf','substr_count','wordwrap','chunk_split',
  'preg_match','preg_replace','preg_replace_callback','preg_split','preg_quote','preg_grep',
  'json_encode','json_decode','serialize','unserialize',
  'is_array','is_string','is_int','is_float','is_bool','is_null','is_numeric','is_object','is_callable','is_iterable','is_countable',
  'isset','empty','unset','gettype','get_debug_type',
  'max','min','abs','round','floor','ceil','intval','floatval','boolval','strval',
  'array_sum','array_product','random_bytes','random_int','uniqid',
  'hash','hash_equals','hash_pbkdf2','password_hash','password_verify','password_needs_rehash',
  'pack','unpack','bin2hex','hex2bin','base64_encode','base64_decode',
  'date','gmdate','time','mktime','checkdate','strtotime','timezone_identifiers_list','DateTime','DateTimeImmutable','DateTimeZone',
  'filter_var','filter_input','setcookie','header','headers_sent','session_start','session_status','session_name',
  'session_regenerate_id','session_destroy','session_write_close','ini_set','ini_get','set_exception_handler',
  'error_log','trigger_error','set_error_handler','register_shutdown_function',
  'file_get_contents','file_put_contents','is_file','is_dir','is_readable','is_writable','mkdir','unlink','glob',
  'fopen','fwrite','fclose','fputcsv','opendir','scandir',
  'str_contains_all','intdiv','abs',
  'usleep','microtime','memory_get_usage','php_sapi_name','phpversion','version_compare',
  'defined','define','constant','exit','die','print','echo','var_dump','printf',
  'parse_url','http_build_query','parse_str','strtok','array_pad','array_splice',
  'mb_convert_encoding','htmlspecialchars','htmlentities','strip_tags','nl2br','wordwrap',
  'checkdnsrr','getenv','putenv','exec','shell_exec','system','escapeshellarg',
  'class_exists','function_exists','method_exists','property_exists','get_class','get_object_vars',
  'call_user_func','call_user_func_array','func_get_args','array_walk','array_walk_recursive',
  'iterator_to_array','range','compact',
]);

const problems = [];

for (const [file, src] of sources) {
  const rel = path.relative(ROOT, file);

  // 1. Static calls: Foo::bar( where Foo is one of our own classes.
  //    from()/tryFrom()/value are built into PHP enums, never declared here.
  const ENUM_BUILTINS = new Set(['from', 'tryFrom', 'cases', 'value', 'name']);
  const staticRe = /\b([A-Z][A-Za-z0-9_]*)::([A-Za-z0-9_]+)\s*\(/g;
  let m;
  while ((m = staticRe.exec(src))) {
    const [, cls, method] = m;
    if (!declaredClasses.has(cls)) continue; // could be a PDO/DateTime built-in
    if (declaredClasses.get(cls).kind === 'enum' && ENUM_BUILTINS.has(method)) continue;
    if (!declaredMethods.has(`${cls}::${method}`)) {
      problems.push(`${rel}: ${cls}::${method}() — method not declared`);
    }
  }

  // 2. Qualified references to KantEase classes or helper functions.
  const fnCallRe = /\\?KantEase\\([A-Za-z0-9_]+)\s*\(/g;
  while ((m = fnCallRe.exec(src))) {
    const name = `KantEase\\${m[1]}`;
    const next = src[m.index + m[0].length - 1];
    // `new KantEase\Foo(` is a class; everything else must be a function.
    const isNew = /new\s+$/.test(src.slice(Math.max(0, m.index - 8), m.index));
    if (isNew) {
      if (!declaredClasses.has(name)) problems.push(`${rel}: new ${name} — class not declared`);
      else if (next === '(') { /* fine */ }
    } else if (!declaredFunctions.has(name)) {
      problems.push(`${rel}: ${name}() — function not declared`);
    }
  }

  // 2b. Bare KantEase exception names referenced from a sub-namespace.
  if (/^namespace\s+KantEase\\Repositories;/m.test(src)) {
    const bareRe = /(?<![\w$\\])(KantEaseException|[A-Z][A-Za-z0-9_]*Exception)\s*\(/g;
    while ((m = bareRe.exec(src))) {
      if (!declaredClasses.has(m[1])) {
        problems.push(`${rel}: ${m[1]} — exception class not declared`);
      }
    }
  }

  // 3. Unqualified calls to our helper functions from a SUB-namespace.
  //    Files in `namespace KantEase` itself need no import, so they are skipped.
  if (/^namespace\s+KantEase\\[A-Za-z]/m.test(src)) {
    const helperNames = [...declaredFunctions.keys()]
      .filter((k) => !k.includes('\\'))
      .filter((k) => !/^[a-z]/.test(k));
    const helpersWithNs = new Set(
      [...sources.entries()]
        .flatMap(([, s]) => [...s.matchAll(/use function KantEase\\([A-Za-z0-9_]+);/g)].map((x) => x[1]))
    );
    const localRe = /(?<![\$>:\\\w])([a-z_][a-z0-9_]*)\s*\(/g;
    while ((m = localRe.exec(src))) {
      const name = m[1];
      if (PHP_BUILTINS.has(name)) continue;
      if (['function','fn','if','for','foreach','while','switch','match','catch','return','new','and','or','not','use','echo','print','isset','unset','empty','list','array','static','public','private','protected','const','require','require_once','include','include_once','elseif','do','try','throw','clone','yield','instanceof','int','float','string','bool','void','iterable','object','mixed','never','self','parent','global','else','break','continue','declare','namespace','default','exit','die'].includes(name)) continue;
      if (/^(?:get|set|is|has|to|from|try)[A-Z]/.test(name)) continue;
      // Report only if this file neither imports nor declares it, and the
      // name is one of OUR helpers (camelCase, which PHP builtins never are).
      if (!/^[a-z][a-z0-9]*[A-Z]/.test(name)) continue;
      if (helpersWithNs.has(name)) continue;
      if (new RegExp(`(?:function\\s+${name}\\s*\\(|use function [^;]*\\\\${name}\\s*;)`).test(src)) continue;
      problems.push(`${rel}: ${name}() used without a use-function import or local definition`);
    }
  }

  // 4. new KantEase\Foo references.
  const newRe = /new\s+\\?KantEase\\([A-Za-z0-9_]+)/g;
  while ((m = newRe.exec(src))) {
    if (!declaredClasses.has(`KantEase\\${m[1]}`)) {
      problems.push(`${rel}: new KantEase\\${m[1]} — class not declared`);
    }
  }

  // 5. catch blocks naming a class that does not exist.
  const catchRe = /catch\s*\((\\?[A-Za-z0-9_\\]+)\s/g;
  while ((m = catchRe.exec(src))) {
    const name = m[1].replace(/^\\/, '');
    if (!name.includes('\\') && !declaredClasses.has(name) && !PHP_BUILTINS.has(name)) {
      // Only report if it looks like one of ours (starts uppercase, not a builtin PDO class).
      if (/^[A-Z]/.test(name) && !['PDOException','PDO','Throwable','Exception','Error','TypeError','ValueError','InvalidArgumentException','RuntimeException','JsonException','LogicException','ErrorException','DateMalformedStringException','ArrayObject','SplFixedArray','Generator'].includes(name)) {
        problems.push(`${rel}: catch (${name}) — class not declared`);
      }
    }
  }

  // 6. Balance check for braces/parens/brackets outside strings and comments.
  const stripped = stripPhp(src);
  const pairs = { '{': '}', '(': ')', '[': ']' };
  const closers = { '}': '{', ')': '(', ']': '[' };
  const stack = [];
  for (const ch of stripped) {
    if (pairs[ch]) stack.push(ch);
    else if (closers[ch]) {
      const open = stack.pop();
      if (open !== closers[ch]) {
        problems.push(`${rel}: unbalanced "${ch}"`);
        break;
      }
    }
  }
  if (stack.length) problems.push(`${rel}: ${stack.length} unclosed "${stack[stack.length - 1]}"`);
}

/** Remove strings and comments so bracket counting is meaningful. */
function stripPhp(src) {
  let out = '';
  let i = 0;
  const n = src.length;
  while (i < n) {
    const c = src[i];
    const d = src[i + 1];

    if (c === '/' && d === '/') { while (i < n && src[i] !== '\n') i++; continue; }
    if (c === '#') { while (i < n && src[i] !== '\n') i++; continue; }
    if (c === '/' && d === '*') { i += 2; while (i < n && !(src[i] === '*' && src[i + 1] === '/')) i++; i += 2; continue; }
    if (c === "'") { i++; while (i < n) { if (src[i] === '\\') { i += 2; continue; } if (src[i] === "'") { i++; break; } i++; } out += '""'; continue; }
    if (c === '"') { i++; while (i < n) { if (src[i] === '\\') { i += 2; continue; } if (src[i] === '"') { i++; break; } i++; } out += '""'; continue; }
    if (c === '<' && src.slice(i, i + 3) === '<<<') {
      const labelMatch = /^<<<\s*'?([A-Za-z0-9_]+)'?[ \t]*\r?\n/.exec(src.slice(i));
      if (labelMatch) {
        const label = labelMatch[1];
        // PHP 7.3+ allows the closing marker to be indented; skip to it.
        const closeRe = new RegExp(`\\n[ \\t]*${label}\\b`);
        const rest = src.slice(i + labelMatch[0].length);
        const endMatch = closeRe.exec(rest);
        // em[0] already contains the label, so stop right after it.
        i = endMatch
          ? i + labelMatch[0].length + endMatch.index + endMatch[0].length
          : n;
        out += '""';
        continue;
      }
    }
    out += c;
    i++;
  }
  return out;
}

console.log(`Scanned ${files.length} PHP files.`);
console.log(`Declared: ${declaredClasses.size} classes/interfaces/enums, ${declaredMethods.size} methods, ${declaredFunctions.size} functions.\n`);

if (problems.length === 0) {
  console.log('No unresolved references or unbalanced brackets found.');
} else {
  const unique = [...new Set(problems)];
  console.log(`${unique.length} problem(s):\n`);
  unique.sort().forEach((p) => console.log('  ' + p));
  process.exitCode = 1;
}