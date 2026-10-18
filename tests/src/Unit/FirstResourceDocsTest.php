<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds "Your first resource" and "Your second resource" to the module.
 *
 * The two pages are the module's concept budget: the first builds a
 * working settings page from a resource, an operation, a storage and
 * add(), and nothing else, and every later feature has to leave it
 * unchanged. So the code on them is data_surface_first's, and this fails
 * when either drifts:
 *
 * - `<!-- code: <path> -->` before a block: the block is that file, whole.
 * - `<!-- yaml: <path>#<key> -->`: the block is that file's top-level
 *   entry for the key, whole.
 * - `<!-- lines: <path> -->`: every line of the block is a line of the
 *   file, for an info file whose bookkeeping lines the page leaves out.
 *
 * Every file of the module but its README is shown on one of the pages,
 * every routing and schema entry too, and the first page names none of
 * the ideas it is the budget against. FirstResourceTest runs the Drush
 * calls the pages quote.
 */
#[Group('data_surface')]
class FirstResourceDocsTest extends UnitTestCase {

  /**
   * The module root.
   */
  protected const ROOT = __DIR__ . '/../../..';

  /**
   * The two pages, relative to the module root.
   */
  public const PAGES = ['docs/first-resource.md', 'docs/second-resource.md'];

  /**
   * Reads one of the pages.
   *
   * @param string $page
   *   The page, relative to the module root.
   *
   * @return string
   *   Its contents.
   */
  public static function page(string $page): string {
    return (string) file_get_contents(self::ROOT . '/' . $page);
  }

  /**
   * Lists the marked blocks of both pages.
   *
   * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
   *   Each block: its page, its marker kind, what the marker names, and
   *   the block's text.
   */
  public static function blocks(): array {
    $blocks = [];
    foreach (self::PAGES as $page) {
      preg_match_all('/<!-- (code|yaml|lines): (\S+) -->\n\n```[a-z]*\n(.*?)\n```/s', self::page($page), $matches, PREG_SET_ORDER);
      foreach ($matches as [, $kind, $target, $text]) {
        $blocks[] = [$page, $kind, $target, $text];
      }
    }
    return $blocks;
  }

  /**
   * Provides each marked block.
   *
   * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
   *   The blocks, keyed by page and target.
   */
  public static function providerBlocks(): array {
    $provided = [];
    foreach (self::blocks() as $block) {
      $provided[$block[0] . ' ' . $block[2]] = $block;
    }
    return $provided;
  }

  /**
   * Gets a YAML file's top-level entry, as written.
   *
   * @param string $file
   *   The file.
   * @param string $key
   *   The top-level key.
   *
   * @return string|null
   *   From the key's line to the line before the next top-level line,
   *   trailing blank lines and comments trimmed; NULL when there is none.
   */
  public static function entry(string $file, string $key): ?string {
    $lines = explode("\n", (string) file_get_contents($file));
    $start = array_search($key . ':', $lines, TRUE);
    if ($start === FALSE) {
      return NULL;
    }
    $entry = [$lines[$start]];
    for ($i = $start + 1; $i < count($lines); $i++) {
      if ($lines[$i] !== '' && !str_starts_with($lines[$i], ' ')) {
        break;
      }
      $entry[] = $lines[$i];
    }
    return rtrim(implode("\n", $entry));
  }

  /**
   * Tests each marked block is the module's code as it is.
   */
  #[DataProvider('providerBlocks')]
  public function testTheBlockIsTheModulesCode(string $page, string $kind, string $target, string $text): void {
    [$path, $key] = array_pad(explode('#', $target, 2), 2, NULL);
    $file = self::ROOT . '/' . $path;
    $this->assertFileExists($file, sprintf('%s quotes %s, which is not there.', $page, $path));
    $hint = sprintf('%s quotes %s as it no longer is; copy it into the page again.', $page, $target);
    $expected = match ($kind) {
      'code' => trim((string) file_get_contents($file)),
      'yaml' => self::entry($file, (string) $key),
      // Every line of the block, in the order the file has them.
      default => implode("\n", array_values(array_intersect(explode("\n", (string) file_get_contents($file)), explode("\n", $text)))),
    };
    $this->assertSame($expected, $text, $hint);
  }

  /**
   * Tests every file and entry of the module is on one of the pages.
   */
  public function testEveryFileOfTheModuleIsShown(): void {
    $module = self::ROOT . '/modules/data_surface_first';
    $shown = [];
    foreach (self::blocks() as [, , $target]) {
      $shown[] = explode('#', $target, 2)[0];
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($module, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $path = 'modules/data_surface_first/' . substr($file->getPathname(), strlen($module) + 1);
      if ($path === 'modules/data_surface_first/README.md') {
        continue;
      }
      $this->assertContains($path, $shown, sprintf('%s is not on either page.', $path));
    }
    foreach (['data_surface_first.routing.yml', 'config/schema/data_surface_first.schema.yml'] as $yaml) {
      preg_match_all('/^([a-z][\w.]*):$/m', (string) file_get_contents($module . '/' . $yaml), $keys);
      foreach ($keys[1] as $key) {
        $this->assertContains('modules/data_surface_first/' . $yaml . '#' . $key, array_column(self::blocks(), 2), sprintf('The %s entry of %s is not on either page.', $key, $yaml));
      }
    }
  }

  /**
   * Tests the first page stays inside its budget.
   *
   * A resource, an operation, a storage and add(): the page that shows a
   * newcomer a working settings page names nothing else. A feature that
   * cannot stay off it has to justify itself (ROADMAP item 13).
   */
  public function testTheFirstPageStaysInsideItsBudget(): void {
    $first = self::page('docs/first-resource.md');
    $this->assertSame([], array_unique(preg_match_all('/\b(refiners?|RefinesInput|alters?|Alters|structures?|attach\w*|slots?|variants?|collections?|secrets?)\b/i', $first, $found) ? $found[0] : []));
    $this->assertSame(1, substr_count((string) preg_replace('/\s+/', ' ', $first), 'the form, the tool and the schema came for free'), 'One sentence says what was derived.');
  }

}
