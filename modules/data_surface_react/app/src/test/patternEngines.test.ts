import engines from '../../../../../tests/fixtures/pattern-engines.json';

// The rows PatternUnderUnicodeTest holds to PHP's preg_match and to
// PatternTranslation::ecma(), read here by an ECMA engine with the `u`
// flag, as ajv reads a `pattern`. The suite's own validator, opis, reads
// a pattern with PCRE, so this is the one run that sees the other side.

interface Row {
  regex: string;
  ecma: string | null;
  values: { value: string; php: boolean }[];
}

const rows: Row[] = engines.rows;

/** The body between the delimiters, as PHP wrote it. */
const body = (regex: string): string => regex.slice(1, regex.lastIndexOf(regex[0]));

describe('a pattern is never stricter than PHP', () => {
  it.each(rows.filter((row) => row.ecma !== null).map((row) => [row.regex, row] as const))(
    '%s, carried, accepts every value PHP accepts',
    (_regex, row) => {
      const pattern = new RegExp(row.ecma!, 'u');
      for (const { value } of row.values.filter((value) => value.php)) {
        expect(pattern.test(value), JSON.stringify(value)).toBe(true);
      }
    },
  );

  it.each(rows.filter((row) => row.ecma === null).map((row) => [row.regex, row] as const))(
    '%s is left out: read by ECMA, it refuses a value PHP accepts',
    (_regex, row) => {
      const pattern = new RegExp(body(row.regex), 'u');
      const refused = row.values.filter((value) => value.php && !pattern.test(value.value));
      expect(refused.length).toBeGreaterThan(0);
    },
  );
});
