<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins every file of the module that uses Typed Data, by role.
 *
 * The rule: the list only shrinks, and the constant is the proof. A file
 * uses Typed Data when it names one of TOKENS anywhere: the namespace
 * (which an alias, a `use` line, a fully qualified name and a class name
 * in a string all spell), the typed data manager's service id or its
 * `\Drupal` accessor, typed config's service id or manager, or one of
 * Surface's three definition helpers, its own declaration of one
 * included. The scan reads the `.php`, `.module`, `.install` and `.inc`
 * files at the module's root, under `src` and under `modules/<module>`
 * (any `tests` directory left out), and the `.services.yml` files of the
 * module and its submodules, where a services file counts when it names
 * one of the two service ids. Each such file has one line in
 * tests/fixtures/typed-data-footprint.txt, `<role> <path>`, sorted by
 * path, each path once.
 *
 * This test fails when a file uses Typed Data and is not listed, when a
 * listed file no longer does (so a file that left cannot come back
 * unnoticed), when a line has no role or repeats a path, when a file
 * whose role is `edge` is not one of EDGES, and when the lines whose role
 * is not `edge` are not exactly OUTSIDE_THE_EDGES, so a file that leaves
 * lowers the constant in the same change. It counts files, not uses, so
 * it does not see a listed file gaining uses, a method call on a
 * definition handed in (which names no token), a subclass of a listed
 * class (which inherits its use), one file taken off and another listed
 * in the same change (the count holds; the list's diff shows it), or the
 * Field API's own names (a field item list, an entity's `validate()`).
 *
 * The roles: `model` (holds or walks definitions as a compiled surface's
 * shape, and the services file that wires its builders), `constraints`
 * (reads or runs constraints), `emitter` (reads definitions to write the
 * document), `reader` (turns a document into definitions), `pipeline`
 * (coerces, computes, resolves options, conforms outputs), `storage`
 * (layouts and stored shapes), `door` (forms, tools, drush), `shape`
 * (shapes, metadata, secrets), `example` (the example modules), and
 * `edge`, a file EDGES names with the reason it stays on Typed Data.
 *
 * SURFACE_FOOTPRINT_WRITE=1 rewrites the list from the files as they are,
 * each keeping its role; a file new to it gets `unassigned`, which this
 * test refuses until a role is written by hand.
 *
 * Decision: see
 * docs/decisions.md#one-adapter-runs-a-constraint-the-typed-data-footprint-is-pinned.
 */
#[Group('surface')]
class TypedDataFootprintTest extends UnitTestCase {

  /**
   * The list, relative to the module.
   */
  public const LIST = 'tests/fixtures/typed-data-footprint.txt';

  /**
   * What a PHP file names to use Typed Data.
   *
   * The namespace has no trailing backslash, so an alias of the namespace
   * matches too; the second spelling is the namespace in an escaped
   * string.
   */
  public const TOKENS = [
    'Drupal\Core\TypedData',
    'Drupal\\\\Core\\\\TypedData',
    'typed_data_manager',
    'typedDataManager(',
    'config.typed',
    'TypedConfigManager',
    'DefinitionMetadata',
    'DefinitionTypedData',
    'DefinitionMap',
  ];

  /**
   * What a services file names to use Typed Data: the two service ids.
   */
  public const SERVICE_TOKENS = [
    'typed_data_manager',
    'config.typed',
  ];

  /**
   * The directories the scan leaves out.
   */
  public const SKIPPED = ['tests', 'node_modules'];

  /**
   * The roles a file may have.
   */
  public const ROLES = [
    'model',
    'constraints',
    'emitter',
    'reader',
    'pipeline',
    'storage',
    'door',
    'shape',
    'example',
    'edge',
  ];

  /**
   * The files that stay on Typed Data on purpose, each with its reason.
   */
  public const EDGES = [
    'modules/surface_examples_entity/src/FieldItemDerivation.php' => 'host reader: reads field item definitions',
    'src/Contract/DefinitionSchema.php' => 'the one read of a definition into a Schema; the host readers\' shared reader after the flip',
    'src/Contract/NodeDefinition.php' => 'the inverse of the read: makes a definition from a node, the one place Typed Data is made from the model',
    'src/Reader/ConfigSchemaReader.php' => 'host reader: reads config schema',
    'src/Reader/TypedDataReader.php' => 'host reader: reads data type definitions',
    'src/Storage/ConfigEntityStorage.php' => 'host storage validates its own typed values',
    'src/Storage/ConfigObjectStorage.php' => 'host storage validates its own typed values',
    'src/Storage/ConfigSchemaGenerator.php' => 'the config schema writer',
    'src/Storage/SchemaViolations.php' => 'host storage validates its own typed values',
    'src/Tool/InputDefinitions.php' => 'the Tool API takes input definitions',
    'src/Validation/ConstraintRunner.php' => 'core constraint plugins expect a typed value',
  ];

  /**
   * The number of lines whose role is not `edge`, exactly.
   *
   * Set on 2026-11-12 at 156: the 141 files outside the edges that the
   * namespace alone found, UniqueItemsConstraintValidator, which keeps
   * its unwrap of a typed list, 11 files that the service ids and typed
   * config found, and 3 services files. Lowered on 2026-11-12 to 153: a
   * shape is a Schema, so ShapeInterface and the two example shapes,
   * PhoneNumber and License, name no Typed Data. Lowered on 2026-11-13 to
   * 151: the value pipeline reads nodes, so Pipeline/Coercion.php (handed
   * a key's facts) and Pipeline/Outbound.php (it reads secrets and a
   * datetime's type from an entry's node) name no Typed Data. Lowered on
   * 2026-11-14 to 150: Form/SurfaceFormatterTrait.php reads a key's label
   * and properties from its entry's node. Lowered on 2026-11-15 to 131: an
   * options resolver and the class computing a key are handed the key's
   * node, so SurfaceOptionsResolverInterface, ComputesValueInterface, the
   * nine stock resolvers, MachineName, the five example resolvers and the
   * two example computing classes name no Typed Data. Lowered on
   * 2026-11-15 to 129: the options service takes a key's node, so the
   * accept hands a value's node alone and walks no definition beside it:
   * Pipeline/Accepting.php and Pipeline/SurfacePipeline.php name no Typed
   * Data. Lowered on 2026-11-16 to 128: the form's extraction reads a
   * refined key's node and hands it to the options service, so
   * Form/Extracting.php names no Typed Data. Lowered on 2026-11-16 to 110:
   * a widget is chosen by the key's schema, so the 15 stock widgets, the
   * extras' comma separated list widget and WidgetChoice name no Typed
   * Data, and DefinitionWidgetInterface is gone (SchemaWidgetInterface
   * names none). Lowered on 2026-11-16 to 109: the document is written
   * from schemas, so Contract/Choices.php reads whether a key is a map of
   * keys from the site from its schema (NodeFacts::each()) and names no
   * Typed Data. Raised on 2026-11-17 to 110: Contract/KeywordReader.php,
   * the reader of a key's keywords, reads a config schema type it is
   * handed (`drupal:config/<type>`) through typed config.
   */
  public const OUTSIDE_THE_EDGES = 110;

  /**
   * Finds the files that use Typed Data.
   *
   * @param string $module
   *   The module's directory.
   *
   * @return string[]
   *   Their paths relative to the module, sorted.
   */
  public static function scanned(string $module): array {
    $files = array_values(array_filter(glob($module . '/*') ?: [], 'is_file'));
    $roots = [$module . '/src', ...array_filter(glob($module . '/modules/*') ?: [], 'is_dir')];
    foreach ($roots as $root) {
      $tree = new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $file): bool => !$file->isDir() || !in_array($file->getFilename(), self::SKIPPED, TRUE),
      );
      foreach (new \RecursiveIteratorIterator($tree) as $file) {
        assert($file instanceof \SplFileInfo);
        $files[] = $file->getPathname();
      }
    }
    $found = [];
    foreach ($files as $path) {
      $tokens = match (TRUE) {
        str_ends_with($path, '.services.yml') => self::SERVICE_TOKENS,
        in_array(pathinfo($path, PATHINFO_EXTENSION), ['php', 'module', 'install', 'inc'], TRUE) => self::TOKENS,
        default => [],
      };
      $contents = $tokens === [] ? '' : (string) file_get_contents($path);
      foreach ($tokens as $token) {
        if (str_contains($contents, $token)) {
          $found[] = substr($path, strlen($module) + 1);
          break;
        }
      }
    }
    sort($found, SORT_STRING);
    return $found;
  }

  /**
   * Reads the list.
   *
   * @param string $file
   *   The list's file.
   *
   * @return list<array{0: string, 1: string}>
   *   Each line's role and path, in the list's order; the role is empty
   *   where the line has only one word, which is then its path.
   */
  public static function lines(string $file): array {
    $lines = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $parts = explode(' ', $line, 2);
      $lines[] = isset($parts[1]) ? [$parts[0], $parts[1]] : ['', $parts[0]];
    }
    return $lines;
  }

  /**
   * Says what is wrong with the list, against the files as they are.
   *
   * @param string $module
   *   The module's directory.
   *
   * @return string[]
   *   One sentence per fault; none when the list is right.
   */
  public static function problems(string $module): array {
    $file = $module . '/' . self::LIST;
    if (!is_file($file)) {
      return [sprintf('%s is missing. Run this test with SURFACE_FOOTPRINT_WRITE=1.', self::LIST)];
    }
    $problems = [];
    $listed = [];
    foreach (static::lines($file) as $index => [$role, $path]) {
      if ($role === '' || $path === '') {
        $problems[] = sprintf('Line %d, `%s`, is not a role and a path.', $index + 1, trim($role . ' ' . $path));
      }
      if (isset($listed[$path])) {
        $problems[] = sprintf('Line %d repeats %s.', $index + 1, $path);
      }
      $listed[$path] = $role;
    }
    $paths = array_map('strval', array_keys($listed));
    $sorted = $paths;
    sort($sorted, SORT_STRING);
    if ($sorted !== $paths) {
      $problems[] = 'The list is not sorted by path.';
    }
    $scanned = static::scanned($module);
    foreach (array_diff($scanned, $paths) as $path) {
      $problems[] = sprintf('%s uses Typed Data and is not listed. Run this test with SURFACE_FOOTPRINT_WRITE=1 and give its `unassigned` line a role.', $path);
    }
    foreach (array_diff($paths, $scanned) as $path) {
      $problems[] = sprintf('%s is listed and no longer uses Typed Data. Run this test with SURFACE_FOOTPRINT_WRITE=1 and lower OUTSIDE_THE_EDGES.', $path);
    }
    foreach ($listed as $path => $role) {
      if ($role !== '' && !in_array($role, self::ROLES, TRUE)) {
        $problems[] = sprintf('%s has the role `%s`, which is not one of the roles.', $path, $role);
      }
      if ($role === 'edge' && !isset(self::EDGES[$path])) {
        $problems[] = sprintf('%s has the role `edge` and is not one of EDGES.', $path);
      }
    }
    foreach (array_keys(self::EDGES) as $path) {
      if (isset($listed[$path]) && $listed[$path] !== 'edge') {
        $problems[] = sprintf('%s is one of EDGES and has the role `%s`.', $path, $listed[$path]);
      }
    }
    $outside = count(array_filter($listed, static fn (string $role): bool => $role !== 'edge'));
    if ($outside !== self::OUTSIDE_THE_EDGES) {
      $problems[] = sprintf('%d lines are outside the edges and OUTSIDE_THE_EDGES is %d. The list only shrinks: lower the constant when a file leaves, never raise it.', $outside, self::OUTSIDE_THE_EDGES);
    }
    return $problems;
  }

  /**
   * Tests the list names every file that uses Typed Data, and no other.
   */
  public function testFootprintIsPinned(): void {
    $module = dirname(__DIR__, 3);
    $file = $module . '/' . self::LIST;
    if (getenv('SURFACE_FOOTPRINT_WRITE')) {
      $listed = [];
      foreach (is_file($file) ? static::lines($file) : [] as [$role, $path]) {
        $listed[$path] = $role;
      }
      $lines = array_map(static fn (string $path): string => ($listed[$path] ?? 'unassigned') . ' ' . $path, static::scanned($module));
      file_put_contents($file, implode("\n", $lines) . "\n");
      $this->markTestSkipped(sprintf('%d files written to %s.', count($lines), self::LIST));
    }
    $this->assertSame([], static::problems($module));
  }

}
