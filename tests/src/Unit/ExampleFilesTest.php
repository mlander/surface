<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\data_surface_examples\CodeLines;
use Drupal\data_surface_examples\Series;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Holds the examples' files, as Series lists them, to the module.
 *
 * Each example's page lists the files it is made of, the landing page
 * counts them and the README has them as a table under each example, all
 * from one list. This fails when the list names a file that is not there,
 * when the examples have a part no example lists (a class, a component, a
 * route, a config schema entry, a shipped settings file), when a listed
 * class names a part of the examples no example up to it lists, when a
 * docs page or a decision it points at is gone, and when a README table
 * is not the list's.
 */
#[Group('data_surface')]
class ExampleFilesTest extends UnitTestCase {

  /**
   * The repository's root.
   */
  protected const ROOT = __DIR__ . '/../../..';

  /**
   * The modules the examples are made of.
   */
  protected const MODULES = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_workflow',
    'data_surface_component_block',
    'data_surface_examples_keys',
  ];

  /**
   * The directories of a module's src/ that hold an example's parts.
   */
  protected const PARTS = [
    'Resource',
    'Structure',
    'Shape',
    'Storage',
    'Access',
    'Keys',
    'Variants',
    'Alter',
    'Plugin',
    'Hook',
  ];

  /**
   * The main module's namespace whose classes an example lists as its own.
   */
  protected const PROVIDERS = 'Drupal\\data_surface\\Variants\\';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Each role is a t() string, read here in English.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Tests every listed file is there, and is read as the docs tests read it.
   */
  public function testEveryListedFileIsThere(): void {
    $this->assertSame(range(1, 15), array_keys(Series::examples()));
    foreach (Series::examples() as $number => $example) {
      $this->assertNotSame('', (string) $example['title']);
      $this->assertNotSame('', (string) $example['concept']);
      if ($example['files'] === []) {
        $this->assertLessThan($number, $example['from'] ?? $number, sprintf('Example %d has no file, so it names the earlier example it is made from.', $number));
      }
      foreach ($example['files'] as $file => $role) {
        [$path, $key] = Series::split($file);
        $this->assertFileExists(self::ROOT . '/' . $path, sprintf('Example %d lists %s, which is not there.', $number, $path));
        $this->assertNotSame('', (string) $role);
        $expected = $key === NULL
          ? trim((string) file_get_contents(self::ROOT . '/' . $path), "\n")
          : FirstResourceDocsTest::entry(self::ROOT . '/' . $path, $key);
        $this->assertNotNull($expected, sprintf('Example %d lists %s, which has no entry %s.', $number, $path, $key));
        $this->assertSame($expected, Series::text($file), sprintf('Example %d shows %s otherwise than it is.', $number, $file));
        $this->assertGreaterThan(0, Series::lines($file), $file);
      }
    }
    foreach (Series::examples() as $number => $example) {
      foreach ($example['routes'] as $route) {
        $this->assertNotNull(FirstResourceDocsTest::entry(self::ROOT . '/' . Series::MODULE . 'data_surface_examples.routing.yml', $route), sprintf('Example %d names a route, %s, the module does not have.', $number, $route));
      }
    }
  }

  /**
   * Tests the line counts are the series', for PHP the landing page's.
   */
  public function testTheLineCountsAreTheSeries(): void {
    foreach (self::listed() as $file => $number) {
      [$path] = Series::split($file);
      $expected = str_ends_with($path, '.php') || str_ends_with($path, '.install')
        ? CodeLines::count(self::ROOT . '/' . $path)
        : count(array_filter(explode("\n", Series::text($file)), static fn (string $line): bool => trim($line) !== '' && !str_starts_with(trim($line), '#')));
      $this->assertSame($expected, Series::lines($file), sprintf('Example %d counts %s otherwise.', $number, $file));
      $this->assertSame($expected < Series::WHOLE_BELOW, Series::whole($file));
    }
  }

  /**
   * Tests every part of the examples is listed by one of them.
   */
  public function testEveryPartOfTheExamplesIsListed(): void {
    $parts = [];
    foreach (self::MODULES as $module) {
      $base = 'modules/' . $module . '/';
      foreach (self::PARTS as $directory) {
        foreach (self::php(self::ROOT . '/' . $base . 'src/' . $directory) as $file) {
          $parts[] = Series::relative(realpath($file) ?: $file);
        }
      }
      foreach (glob(self::ROOT . '/' . $base . 'components/*/*') ?: [] as $file) {
        $parts[] = $base . substr($file, strlen(self::ROOT . '/' . $base));
      }
      foreach (glob(self::ROOT . '/' . $base . 'config/install/*.yml') ?: [] as $file) {
        $parts[] = $base . 'config/install/' . basename($file);
      }
      foreach (glob(self::ROOT . '/' . $base . 'config/schema/*.yml') ?: [] as $file) {
        foreach (self::keys($file) as $key) {
          $parts[] = $base . 'config/schema/' . basename($file) . '#' . $key;
        }
      }
      // A route an operation form serves; the others are this page's.
      $routing = self::ROOT . '/' . $base . $module . '.routing.yml';
      foreach (is_file($routing) ? self::keys($routing) : [] as $key) {
        if (str_contains((string) FirstResourceDocsTest::entry($routing, $key), '_data_surface_resource:')) {
          $parts[] = $base . $module . '.routing.yml#' . $key;
        }
      }
      // A module of its own is an example's: its info and permissions,
      // its services and its install file.
      if ($module !== 'data_surface_examples') {
        foreach (glob(self::ROOT . '/' . $base . $module . '.{info.yml,permissions.yml,services.yml,install}', GLOB_BRACE) ?: [] as $file) {
          $parts[] = $base . basename($file);
        }
      }
    }
    $this->assertNotEmpty($parts);
    $unlisted = array_values(array_diff($parts, array_keys(self::listed())));
    $this->assertSame([], $unlisted, 'A part of the examples that no example lists in Series::examples().');
    $this->assertArrayHasKey(Series::MODULE . 'src/Venues.php', self::listed());
  }

  /**
   * Tests what a listed class names is listed with it, or before it.
   *
   * A class it imports from the examples' parts or the main module's
   * providers by an earlier example or the same one; its storage, the
   * config object the storage names, both its settings and its schema,
   * and a variant of it by the same one; and a route's resource with the
   * route.
   */
  public function testWhatEachFileNamesIsListedWithIt(): void {
    $listed = self::listed();
    $variants = [];
    foreach (array_keys($listed) as $file) {
      if (preg_match('/#\[Variant\(of: (\w+)::class/', self::source($file), $match)) {
        $variants[self::resolve($file, $match[1])][] = $file;
      }
    }
    foreach (Series::examples() as $number => $example) {
      foreach (array_keys($example['files']) as $file) {
        [$path, $key] = Series::split($file);
        $source = self::source($file);
        if (str_ends_with($path, '.php')) {
          preg_match_all('/^use (Drupal\\\\[\w\\\\]+);$/m', $source, $uses);
          foreach ($uses[1] as $class) {
            $named = self::path($class);
            if ($named !== NULL) {
              $this->assertArrayHasKey($named, $listed, sprintf('%s names %s, which no example lists.', $path, $named));
              $this->assertLessThanOrEqual($number, $listed[$named], sprintf('%s, of example %d, names %s, which example %d lists after it.', $path, $number, $named, $listed[$named]));
            }
          }
          if (preg_match('/#\[Resource\([^\n]*storage: (\w+)::class/', $source, $match)) {
            $storage = (string) self::resolve($file, $match[1]);
            $this->assertArrayHasKey($storage, $example['files'], sprintf('Example %d lists %s without its storage.', $number, $path));
            $this->assertMatchesRegularExpression('/const CONFIG = \'([\w.]+)\';/', self::source($storage));
            preg_match('/const CONFIG = \'([\w.]+)\';/', self::source($storage), $config);
            $this->assertArrayHasKey(Series::INSTALL . $config[1] . '.yml', $example['files'], sprintf('Example %d lists %s without the settings it ships.', $number, $path));
            $this->assertArrayHasKey(Series::SCHEMA . $config[1], $example['files'], sprintf('Example %d lists %s without its config schema.', $number, $path));
          }
          foreach ($variants[$path] ?? [] as $variant) {
            $this->assertArrayHasKey($variant, $example['files'], sprintf('Example %d lists %s without its variant %s.', $number, $path, $variant));
          }
        }
        if ($key !== NULL && str_ends_with($path, '.routing.yml')) {
          $this->assertMatchesRegularExpression('/_data_surface_resource: \'([\w\\\\]+)\'/', $source);
          preg_match('/_data_surface_resource: \'([\w\\\\]+)\'/', $source, $resource);
          $this->assertArrayHasKey((string) self::path($resource[1]), $example['files'], sprintf('Example %d lists the route %s without its resource.', $number, $key));
          $this->assertContains($key, $example['routes'], sprintf('Example %d lists the route %s, which is not one of its pages.', $number, $key));
        }
      }
    }
  }

  /**
   * Tests each docs page and decision the reading order links is there.
   */
  public function testEachDocsPageAndDecisionIsThere(): void {
    foreach (Series::examples() as $number => $example) {
      [$page, $anchor] = Series::split($example['docs']);
      $this->assertFileExists(self::ROOT . '/' . $page, sprintf('Example %d points at %s, which is not there.', $number, $page));
      if ($anchor !== NULL) {
        $this->assertContains($anchor, self::anchors($page), sprintf('Example %d points at %s, which has no heading %s.', $number, $page, $anchor));
      }
      $this->assertContains($example['decision'], self::anchors('docs/decisions.md'), sprintf('Example %d points at a decision, %s, that docs/decisions.md does not have.', $number, $example['decision']));
    }
  }

  /**
   * Tests each Files table of the README is the example's list.
   */
  public function testTheReadmeListsEachExamplesFiles(): void {
    $readme = ExamplesReadmeTest::readme();
    preg_match_all('/<!-- files: (\d+) -->/', $readme, $markers);
    $with = array_keys(array_filter(Series::examples(), static fn (array $example): bool => $example['files'] !== []));
    $this->assertSame($with, array_map('intval', $markers[1]), 'One Files table per example with files, in order.');
    foreach ($with as $number) {
      $this->assertStringContainsString(sprintf("<!-- files: %d -->\n\n%s\n", $number, Series::markdown($number)), $readme, sprintf("The README's Files table for example %d is not the list's; paste Series::markdown(%d) under its marker.", $number, $number));
    }
  }

  /**
   * Lists every file the examples list, with the first example to list it.
   *
   * @return array<string, int>
   *   The example's number, keyed by the file.
   */
  protected static function listed(): array {
    $listed = [];
    foreach (Series::examples() as $number => $example) {
      foreach (array_keys($example['files']) as $file) {
        $listed[$file] ??= $number;
      }
    }
    return $listed;
  }

  /**
   * Reads a listed file's source: the file, or its entry.
   *
   * @param string $file
   *   The file, from the root, perhaps with an entry.
   *
   * @return string
   *   What Series shows of it.
   */
  protected static function source(string $file): string {
    return Series::text($file);
  }

  /**
   * Finds the file of an examples class or a provider.
   *
   * @param string $class
   *   The class's full name.
   *
   * @return string|null
   *   Its file, from the root, for a class of the examples' parts or of the
   *   main module's providers; NULL for any other.
   */
  protected static function path(string $class): ?string {
    if (str_starts_with($class, self::PROVIDERS)) {
      return 'src/' . str_replace('\\', '/', substr($class, strlen('Drupal\\data_surface\\'))) . '.php';
    }
    $parts = explode('\\', $class);
    if (count($parts) < 4 || !in_array($parts[1], self::MODULES, TRUE) || !in_array($parts[2], self::PARTS, TRUE)) {
      return NULL;
    }
    return 'modules/' . $parts[1] . '/src/' . implode('/', array_slice($parts, 2)) . '.php';
  }

  /**
   * Resolves a short class name a listed file names.
   *
   * @param string $file
   *   The PHP file, from the root.
   * @param string $short
   *   The short name.
   *
   * @return string|null
   *   The named class's file, from the root, or NULL.
   */
  protected static function resolve(string $file, string $short): ?string {
    $source = self::source($file);
    if (preg_match('/^use (Drupal\\\\[\w\\\\]+\\\\' . preg_quote($short, '/') . ');$/m', $source, $match)) {
      return self::path($match[1]);
    }
    // The same namespace: beside the file.
    return dirname($file) . '/' . $short . '.php';
  }

  /**
   * Lists a directory's PHP files, at any depth.
   *
   * @param string $directory
   *   The directory.
   *
   * @return string[]
   *   The files.
   */
  protected static function php(string $directory): array {
    if (!is_dir($directory)) {
      return [];
    }
    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
      if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Lists a YAML file's top-level keys.
   *
   * @param string $file
   *   The file.
   *
   * @return string[]
   *   The keys, in order.
   */
  protected static function keys(string $file): array {
    preg_match_all('/^([\w.]+):\s*$/m', (string) file_get_contents($file), $matches);
    return $matches[1];
  }

  /**
   * Lists the anchors GitHub gives a Markdown page's headings.
   *
   * @param string $page
   *   The page, from the root.
   *
   * @return string[]
   *   Each heading lowercased, with what is neither a letter, a digit, a
   *   space, a hyphen nor an underscore dropped, and each space a hyphen.
   */
  protected static function anchors(string $page): array {
    preg_match_all('/^#{1,6} (.+)$/m', (string) file_get_contents(self::ROOT . '/' . $page), $headings);
    return array_map(static fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($heading)))), $headings[1]);
  }

}
