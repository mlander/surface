<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\PatternTranslation;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A pattern is never stricter than PHP, `u` or not.
 *
 * The rows are shared with the React app's Vitest, which reads them with
 * an ECMA engine (src/test/patternEngines.test.ts): this side holds the
 * PHP column and the translation to PHP, that side holds the carried
 * pattern to every value PHP accepts. Under PHP's `u`, `\d`, `\w` and
 * `\b` read Unicode properties and ECMA's do not, `\s` holds U+0085 and
 * U+180E, which ECMA's lacks, and a negated class parts the engines with
 * `u` or without; each of those was once carried, so the schema refused
 * `٢٠٤٨`, `café`, `é` and a no-break space that the pipeline accepts.
 *
 * @see \Drupal\surface\Contract\PatternTranslation
 * @see docs/decisions.md#a-pattern-both-engines-read-alike
 */
#[Group('surface')]
class PatternUnderUnicodeTest extends UnitTestCase {

  /**
   * Tests that every row's PHP verdict and translation hold.
   *
   * Under the C locale PHP starts in and the C.UTF-8 one Drupal sets
   * (DrupalKernel::bootEnvironment()), since PCRE builds its character
   * tables from the locale when it is not C.
   */
  public function testUnicodeClassEscapesAndNegatedClassesAreNotCarried(): void {
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/pattern-engines.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertNotEmpty($fixture['rows']);
    $locale = setlocale(LC_CTYPE, '0');
    try {
      foreach (['C', 'C.UTF-8'] as $ctype) {
        if (setlocale(LC_CTYPE, $ctype) === FALSE) {
          continue;
        }
        foreach ($fixture['rows'] as $row) {
          $regex = $row['regex'];
          $this->assertSame($row['ecma'], PatternTranslation::ecma($regex), $regex);
          foreach ($row['values'] as $case) {
            $this->assertSame($case['php'], preg_match($regex, $case['value']) === 1, $ctype . ' ' . $regex . ' ' . json_encode($case['value']));
          }
        }
      }
    }
    finally {
      setlocale(LC_CTYPE, $locale === FALSE ? 'C' : $locale);
    }
  }

}
