<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which code reads a definition into a node, by file and count.
 *
 * An entry holds its key's node (Entry::schema()), and a reader that
 * walks down from an entry walks the entry's node in step. What is left
 * calling DefinitionSchema::of(), head() or around() holds a definition
 * no entry holds. The list is pinned (CALLERS): a new caller
 * changes it, and so does a caller that moves to an entry's node. Each is
 * a reader a later slice moves. Comments are not read. What the scan
 * cannot see: a call from inside DefinitionSchema itself (a new public
 * method there that wraps of()), and a call through a callable string.
 *
 * Entry::schema() asks no service: Entry's own code names no `Drupal::`
 * and no container.
 *
 * Decision: see docs/decisions.md#an-entry-holds-its-node-the-memo-is-gone.
 */
#[Group('surface')]
class DefinitionReadCallersTest extends UnitTestCase {

  /**
   * The calls of DefinitionSchema::of(), head() and around().
   *
   * In src and modules/*\/src.
   *
   * By file, relative to the module, then by function, the number of
   * calls. Why each caller holds a definition and no entry:
   * - src/Entry.php, of(): a definition-first entry reads its own
   *   definition, once (schema()), and once more per later ask inside an
   *   assertion; a shell reads its definition inside an assertion on its
   *   first ask too, against the node it composed; a node-first entry
   *   reads its derived definition per later ask of definition() inside
   *   an assertion.
   * - src/Entry.php, around(): a key that holds a child frame composes its
   *   node of its own facts around its child's entries' nodes (composed()
   *   for a collection, around a collection's item and a mount's, and
   *   framed() for a map of a child's keys). With the node-first switch
   *   on, DefinitionMap::fromArrays() makes each entry node-first from the
   *   node it reads (Entry::nodeFirst()), and reads nothing itself (since
   *   2026-11-14).
   * - src/Contract/Conditionals.php, of(): stated() describes a definition
   *   no entry holds: a declared rule's answer, made on a copy (rules()),
   *   and a key a caller describes by its definition alone (describeKey()
   *   handed one).
   * - src/Contract/PropertySchema.php, of(): property() reads a shape's
   *   definition where its key's node does not hold the shape's node.
   * - src/Contract/PropertySchema.php, head(): listNode() reads a
   *   collection's own node, without its items, which no entry's node is
   *   (a list's node holds its items, the child's whole tree), for its
   *   keywords, its default, its arrangement and its signature.
   * - src/Tool/InputDefinitions.php, of(): the public converters,
   *   fromDefinition() and outputFromDefinition(), read the node of a
   *   definition a caller hands with no node; every caller in the module
   *   hands the entry's node with it.
   * - src/Storage/ConfigSchemaGenerator.php reads none since 2026-11-14:
   *   a collection's declared constraints, and what every entry of a map
   *   of entries from the site is, are read from the key's own node,
   *   which is composed of its child's entries' nodes (Entry::schema()).
   * - src/Pipeline/Validating.php, of(): nodeAt() reads a port's node. A
   *   port has no entry: the container hands the child a bare definition
   *   (SurfaceInterface::getPortDefinition()), whose label a refusal
   *   names.
   *
   * - src/Options/SurfaceOptions.php, of(): resolve() hands a resolver
   *   the node of the definition it was handed, where its caller holds
   *   no node, read when a resolver is about to be called (since
   *   2026-11-15).
   * - src/Resolve.php, of(): a resolved key's class is handed the node of
   *   the key's definition; a key a mount holds has no entry of its own
   *   (since 2026-11-15).
   * property() takes its node from its caller and reads nothing, so a
   * caller that stops handing an entry's node on has to call of() itself,
   * which this list then shows.
   */
  protected const CALLERS = [
    'src/Contract/Conditionals.php' => ['of' => 1],
    'src/Contract/PropertySchema.php' => ['head' => 1, 'of' => 1],
    'src/Entry.php' => ['around' => 4, 'of' => 4],
    'src/Options/SurfaceOptions.php' => ['of' => 1],
    'src/Pipeline/Validating.php' => ['of' => 1],
    'src/Resolve.php' => ['of' => 1],
    'src/Tool/InputDefinitions.php' => ['of' => 2],
  ];

  /**
   * Tests the callers are the pinned list.
   */
  public function testTheCallersAreThePinnedList(): void {
    $root = dirname(__DIR__, 3);
    $found = [];
    $files = [];
    foreach ([$root . '/src', ...(glob($root . '/modules/*/src') ?: [])] as $directory) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
          $files[] = $file->getPathname();
        }
      }
    }
    $this->assertGreaterThan(500, count($files), 'Every source file is read.');
    foreach ($files as $file) {
      $code = static::code((string) file_get_contents($file));
      // The class is called by its own name: under another name, or
      // through a variable, a call would not be counted below.
      $this->assertDoesNotMatchRegularExpression('/\bDefinitionSchema\s+as\s+\w|\bDefinitionSchema::class\b/', $code, substr($file, strlen($root) + 1) . ' names DefinitionSchema by an alias or as a class string.');
      if (preg_match_all('/\bDefinitionSchema::(of|head|around)\s*\(/', $code, $matches) === 0) {
        continue;
      }
      $relative = substr($file, strlen($root) + 1);
      foreach ($matches[1] as $function) {
        $found[$relative][$function] = ($found[$relative][$function] ?? 0) + 1;
      }
      ksort($found[$relative]);
    }
    ksort($found);
    $this->assertSame(self::CALLERS, $found, 'The code that reads a definition into a node, by file and count.');
  }

  /**
   * Tests Entry::schema() asks no service.
   */
  public function testTheEntryAsksNoService(): void {
    $code = static::code((string) file_get_contents(dirname(__DIR__, 3) . '/src/Entry.php'));
    $this->assertStringContainsString('function schema(', $code);
    $this->assertDoesNotMatchRegularExpression('/\bDrupal::|getContainer\s*\(|ContainerInterface/', $code);
  }

  /**
   * Reads a PHP file's code, its comments left out.
   *
   * @param string $source
   *   The file's source.
   *
   * @return string
   *   The code.
   */
  protected static function code(string $source): string {
    $code = '';
    foreach (token_get_all($source) as $token) {
      if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
  }

}
