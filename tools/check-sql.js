/**
 * KantEase SQL structure checker (development tool, not shipped).
 *
 * Applies the shared, quote-aware splitter to a SQL script and checks that
 * every statement is well formed enough to be sent to the server: a recognised
 * leading keyword, balanced parentheses, no trailing semicolon, no DELIMITER.
 *
 * It also guards against the failure mode that a broken splitter can cause:
 * statements that are silently truncated because quoted content was dropped.
 * Every statement is checked for having content after its last operator, and
 * the first few statements are echoed so the split can be eyeballed.
 *
 * Usage:  node tools/check-sql.js database/database.sql database/migrate_from_node.sql
 */

const fs = require('fs');
const { splitStatements, stripLiterals } = require('./lib/sql-split.js');

const KEYWORDS = new Set([
  'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'RENAME',
  'SET', 'USE', 'TRUNCATE', 'REPLACE', 'START', 'COMMIT', 'ROLLBACK',
]);

/**
 * Looks like a statement whose trailing literal or identifier vanished.
 * e.g. "USE" or "SET time_zone =" instead of the real thing.
 */
function looksTruncated(bare) {
  if (/\bUSE\s*$/i.test(bare)) return true;
  if (/\bTABLE\s+(IF\s+EXISTS\s+)?$/i.test(bare)) return true;
  if (/=\s*$/i.test(bare)) return true;
  if (/\bFROM\s*$/i.test(bare)) return true;
  if (/\bON\s*$/i.test(bare)) return true;
  return false;
}

const files = process.argv.slice(2);
let failed = false;

for (const file of files) {
  const sql = fs.readFileSync(file, 'utf8');
  const statements = splitStatements(sql);

  console.log('\n' + file);
  console.log(`  ${sql.split('\n').length} lines -> ${statements.length} statements`);

  const byKind = {};
  const problems = [];

  statements.forEach((stmt, index) => {
    const bare = stripLiterals(stmt).trim();
    if (bare === '') return;

    const first = (bare.match(/^[A-Za-z]+/) || [''])[0].toUpperCase();
    byKind[first] = (byKind[first] || 0) + 1;

    if (!KEYWORDS.has(first)) {
      problems.push(`  statement ${index + 1}: unrecognised keyword "${first}"\n    ${bare.slice(0, 90)}`);
    }

    let depth = 0;
    for (const ch of bare) {
      if (ch === '(') depth++;
      else if (ch === ')') { depth--; if (depth < 0) break; }
    }
    if (depth !== 0) {
      problems.push(`  statement ${index + 1}: unbalanced parentheses (${depth})\n    ${bare.slice(0, 90)}`);
    }

    if (/;\s*$/.test(bare)) {
      problems.push(`  statement ${index + 1}: trailing semicolon\n    ${bare.slice(0, 90)}`);
    }

    if (/\bDELIMITER\b/i.test(bare)) {
      problems.push(`  statement ${index + 1}: DELIMITER is not supported by the splitter`);
    }

    if (looksTruncated(bare)) {
      problems.push(`  statement ${index + 1}: looks truncated, a quoted literal may have been dropped\n    ${stmt.slice(0, 90)}`);
    }
  });

  console.log('  ' + Object.entries(byKind).map(([k, v]) => `${k}×${v}`).join('  '));

  console.log('\n  First five statements, verbatim:');
  statements.slice(0, 5).forEach((s, i) => {
    console.log(`    [${i}] ${JSON.stringify(s.length > 100 ? s.slice(0, 100) + '…' : s)}`);
  });

  if (problems.length === 0) {
    console.log('\n  OK — all statements well formed');
  } else {
    failed = true;
    console.log(`\n  ${problems.length} problem(s):`);
    problems.forEach(p => console.log(p));
  }
}

process.exitCode = failed ? 1 : 0;