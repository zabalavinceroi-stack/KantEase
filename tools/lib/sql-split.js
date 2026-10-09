/**
 * KantEase — shared SQL statement splitter (development tool, not shipped).
 *
 * This is the JavaScript twin of `splitStatements()` in
 * includes/schema_installer.php. Both are used by the same workflow: one splits
 * the file for the PHP installer, the other for the static checkers. They live
 * here so they cannot drift apart again.
 *
 * A previous version of the checkers had a subtle but serious defect: while
 * inside a string or a backtick-quoted identifier it skipped the character
 * WITHOUT copying it into the output. That silently truncated
 *
 *     SET time_zone = '+00:00';
 *     USE `kantease_db`;
 *
 * down to
 *
 *     SET time_zone =
 *     USE
 *
 * so the checker reported "all statements well formed" while validating
 * statements that were not the ones in the file. Every character inside a
 * quoted region is now preserved.
 */

/**
 * Split a SQL script into individual statements.
 *
 * Handles: single-quoted strings (with backslash escapes and doubled quotes),
 * double-quoted strings, backtick identifiers, `--` and `#` line comments, and
 * `/* *\/` block comments. It does NOT understand stored routines or DELIMITER
 * blocks; the KantEase schema uses neither.
 *
 * @param {string} sql
 * @returns {string[]}
 */
function splitStatements(sql) {
  const out = [];
  let current = '';
  let i = 0;
  const n = sql.length;

  while (i < n) {
    const c = sql[i];
    const d = sql[i + 1];

    // -- line comment: "--" followed by whitespace or end of input
    if (c === '-' && d === '-' && (i + 2 >= n || /[\s]/.test(sql[i + 2]))) {
      while (i < n && sql[i] !== '\n') i++;
      continue;
    }

    // # line comment
    if (c === '#') {
      while (i < n && sql[i] !== '\n') i++;
      continue;
    }

    // /* block comment */
    if (c === '/' && d === '*') {
      i += 2;
      while (i < n && !(sql[i] === '*' && sql[i + 1] === '/')) i++;
      i += 2;
      continue;
    }

    // 'string'
    if (c === "'" || c === '"') {
      const quote = c;
      current += c;
      i++;

      while (i < n) {
        // Backslash escape consumes the next character verbatim.
        if (sql[i] === '\\' && i + 1 < n) {
          current += sql[i] + sql[i + 1];
          i += 2;
          continue;
        }

        // A doubled quote is an escaped quote, not a terminator.
        if (sql[i] === quote && sql[i + 1] === quote) {
          current += quote + quote;
          i += 2;
          continue;
        }

        if (sql[i] === quote) {
          current += quote;
          i++;
          break;
        }

        current += sql[i];
        i++;
      }

      continue;
    }

    // `identifier`
    if (c === '`') {
      const end = sql.indexOf('`', i + 1);

      if (end === -1) {
        // Unterminated: keep the remainder so the error surfaces in the SQL,
        // not as a silently truncated statement.
        current += sql.slice(i);
        i = n;
        continue;
      }

      current += sql.slice(i, end + 1);
      i = end + 1;
      continue;
    }

    // Statement terminator
    if (c === ';') {
      if (current.trim() !== '') {
        out.push(current.trim());
      }
      current = '';
      i++;
      continue;
    }

    current += c;
    i++;
  }

  if (current.trim() !== '') {
    out.push(current.trim());
  }

  return out;
}

/**
 * Strip literals and comments so structural checks are not confused by a
 * semicolon inside a string.
 *
 * @param {string} statement
 * @returns {string}
 */
function stripLiterals(statement) {
  return statement
    .replace(/'(?:[^'\\]|\\.|'')*'/g, "''")
    .replace(/`(?:[^`]|``)*`/g, '``')
    .replace(/"(?:[^"\\]|\\.)*"/g, '""')
    .replace(/--[^\n]*/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');
}

module.exports = { splitStatements, stripLiterals };