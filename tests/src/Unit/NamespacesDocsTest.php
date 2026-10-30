<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds every snippet in the docs to saying where its classes live.
 *
 * A snippet that names one of the module's classes either imports it, a
 * `use` line inside the snippet, or finds it in the "Namespaces" table on
 * the concepts page, which the section the snippet sits in links: from
 * `docs/`, `concepts.md#namespaces`; from the examples,
 * `../concepts.md#namespaces`. The third fresh-eyes run found
 * `KeysFromSiteInterface` and `KeyedList` with no namespace in any doc
 * and had to search `src/` (its log, entry 16).
 *
 * The introduction is not held: its snippets show the code each page is
 * about, and the series does not point at the Namespaces table while the
 * module's classes are still being settled.
 *
 * The dated records, the fresh-eyes logs and the adopters' trial, keep
 * what was written then and are not held.
 */
#[Group('data_surface')]
class NamespacesDocsTest extends UnitTestCase {

  /**
   * The module root.
   */
  protected const ROOT = __DIR__ . '/../../..';

  /**
   * The page holding the table, and the link to it from beside it.
   */
  protected const TABLE = 'docs/concepts.md';
  protected const LINK = 'concepts.md#namespaces';

  /**
   * The directories whose pages are held, and the path from each to docs/.
   */
  protected const DIRECTORIES = [
    'docs' => '',
    'docs/examples' => '../',
  ];

  /**
   * The dated records, by file name.
   */
  protected const RECORDS = '/^(fresh-eyes|adopters)-\d{4}-\d{2}-\d{2}/';

  /**
   * Reads the table: each class's short name and the class it names.
   *
   * @return array<string, string>
   *   The fully qualified class, by short name, in the table's order.
   */
  protected static function table(): array {
    $page = (string) file_get_contents(self::ROOT . '/' . self::TABLE);
    $start = strpos($page, "\n## Namespaces\n");
    self::assertNotFalse($start, 'The concepts page has a Namespaces section.');
    $section = substr($page, (int) $start + 1);
    $next = strpos($section, "\n## ");
    $section = $next === FALSE ? $section : substr($section, 0, $next);
    preg_match_all('/^\| `(\w+)` \| `use ([\w\\\\]+);` \|/m', $section, $rows, PREG_SET_ORDER);
    $table = [];
    foreach ($rows as [, $short, $class]) {
      $table[$short] = $class;
    }
    return $table;
  }

  /**
   * Lists the module's own classes by short name.
   *
   * @return array<string, string[]>
   *   The fully qualified classes, by short name.
   */
  protected static function moduleClasses(): array {
    $classes = [];
    $src = self::ROOT . '/src';
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
      $path = substr($file->getPathname(), strlen($src) + 1);
      if (str_ends_with($path, '.php')) {
        $classes[basename($path, '.php')][] = 'Drupal\\data_surface\\' . str_replace('/', '\\', substr($path, 0, -4));
      }
    }
    return $classes;
  }

  /**
   * Tests the namespace says which storage a class is, as the table does.
   *
   * A class in Storage\ is one an author names on #[Resource(storage:)]
   * or extends, or one such a class works with; the storages the
   * pipeline writes through are in Pipeline\Storage\, beside the
   * interface they implement.
   */
  public function testStorageHoldsWhatAnAuthorNames(): void {
    foreach (['Storage' => FALSE, 'Pipeline/Storage' => TRUE] as $directory => $pipeline) {
      $files = glob(self::ROOT . '/src/' . $directory . '/*.php');
      $this->assertNotEmpty($files, $directory);
      foreach ($files as $file) {
        $class = 'Drupal\\data_surface\\' . str_replace('/', '\\', $directory) . '\\' . basename($file, '.php');
        $this->assertSame($pipeline, is_subclass_of($class, DataSurfaceStorageInterface::class), sprintf('%s %s the pipeline\'s storage interface.', $class, $pipeline ? 'implements' : 'does not implement'));
      }
    }
  }

  /**
   * Tests every row of the table names a class of the module, as it is.
   */
  public function testEveryRowNamesExistingClass(): void {
    $table = static::table();
    $this->assertNotEmpty($table);
    foreach ($table as $short => $class) {
      $this->assertStringStartsWith('Drupal\\data_surface\\', $class, $short);
      $this->assertStringEndsWith('\\' . $short, $class, $short);
      $file = self::ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen('Drupal\\data_surface\\'))) . '.php';
      $this->assertFileExists($file, sprintf('The table names %s, which is not there.', $class));
    }
    $sorted = array_keys($table);
    sort($sorted, SORT_STRING | SORT_FLAG_CASE);
    $this->assertSame($sorted, array_keys($table), 'The table is in alphabetical order, so a reader finds a class.');
  }

  /**
   * Tests every class a snippet names is imported there or in the table.
   */
  public function testEverySnippetSaysWhereItsClassesLive(): void {
    $table = static::table();
    $classes = static::moduleClasses();
    $unlisted = [];
    $unlinked = [];
    $pages = [];
    foreach (self::DIRECTORIES as $directory => $up) {
      $files = glob(self::ROOT . '/' . $directory . '/*.md');
      $this->assertNotEmpty($files, $directory);
      foreach ($files as $file) {
        $pages[$directory . '/' . basename($file)] = $up;
      }
    }
    foreach ($pages as $page => $up) {
      if (preg_match(self::RECORDS, basename($page))) {
        continue;
      }
      $file = self::ROOT . '/' . $page;
      $text = (string) file_get_contents($file);
      // Headings are read where no fence is: a `#[Resource]` line or a
      // YAML comment inside one is no heading.
      $prose = (string) preg_replace_callback('/^```.*?^```/ms', static fn (array $fence): string => str_repeat(' ', strlen($fence[0])), $text);
      preg_match_all('/^```php\n(.*?)\n```/ms', $text, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
      foreach ($blocks as $block) {
        [$code, $offset] = $block[1];
        preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;/m', $code, $uses, PREG_SET_ORDER);
        $imported = array_map(static fn (array $use): string => $use[2] ?? substr((string) strrchr('\\' . $use[1], '\\'), 1), $uses);
        // Comments and strings name no class, and a `use` line or a fully
        // qualified name says where its class lives.
        $bare = (string) preg_replace([
          '#//[^\n]*#',
          '#/\*.*?\*/#s',
          "/'(?:[^'\\\\]|\\\\.)*'/",
          '/"(?:[^"\\\\]|\\\\.)*"/',
          '/^(use|namespace) [^;]*;/m',
          '/\\\\?(?:\w+\\\\)+\w+/',
        ], ['', '', "''", '""', '', ''], $code);
        preg_match_all('/\b([A-Z]\w*)\b/', $bare, $words);
        $named = array_values(array_unique(array_filter($words[1], static fn (string $word): bool => isset($classes[$word]) && !in_array($word, $imported, TRUE))));
        if ($named === []) {
          continue;
        }
        foreach ($named as $short) {
          if (!isset($table[$short])) {
            $unlisted[] = sprintf('%s: %s', $page, $short);
          }
        }
        if ($page === self::TABLE) {
          continue;
        }
        // The section the snippet sits in: from the heading above it to
        // the next one.
        $before = substr($prose, 0, $offset);
        $from = max((int) strrpos($before, "\n#"), 0);
        $to = strpos($prose, "\n#", $offset);
        $section = substr($prose, $from, $to === FALSE ? NULL : $to - $from);
        // The link as a link, relative to the page: a subdirectory's page
        // linking `concepts.md#namespaces` leads nowhere.
        if (!str_contains($section, '](' . $up . self::LINK . ')')) {
          preg_match('/^#+ (.*)$/m', substr($prose, $from + 1), $heading);
          $unlinked[] = sprintf('%s, "%s" (%s)', $page, $heading[1] ?? '', implode(', ', $named));
        }
      }
    }
    $this->assertSame([], array_values(array_unique($unlisted)), 'Each class a snippet names and does not import is in the Namespaces table on the concepts page.');
    $this->assertSame([], array_values(array_unique($unlinked)), 'Each section whose snippet names a class it does not import links the Namespaces table.');
  }

}
