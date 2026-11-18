<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which code reads a definition into a schema, by file and count.
 *
 * An entry holds its key's schema (Entry::schema()), and a reader that
 * walks down from an entry walks the entry's schema in step. What is left
 * calling DefinitionSchema::of(), head(), around() or shell() holds a
 * definition no entry holds. The list is pinned (CALLERS): a new caller
 * changes it, and so does a caller that moves to an entry's schema. Each is
 * a reader a later slice moves. Comments are not read. What the scan
 * cannot see: a call from inside DefinitionSchema itself (a new public
 * method there that wraps of()), and a call through a callable string.
 *
 * Entry::schema() asks no service: Entry's own code names no `Drupal::`
 * and no container.
 *
 * Decision: see docs/decisions.md#an-entry-holds-its-schema-the-memo-is-gone.
 */
#[Group('surface')]
class DefinitionReadCallersTest extends UnitTestCase {

  /**
   * The calls of DefinitionSchema::of(), head(), around() and shell().
   *
   * In src and modules/*\/src.
   *
   * By file, relative to the module, then by function, the number of
   * calls. Why each caller holds a definition and no entry:
   * - src/Entry.php, of(): a definition-first entry reads its own
   *   definition, once (schema()), and once more per later ask inside an
   *   assertion; a shell reads its definition inside an assertion on its
   *   first ask too, against the schema it composed; a schema-first entry
   *   reads its derived definition per later ask of definition() inside
   *   an assertion.
   * - src/Entry.php, around(): a key that holds a child frame composes its
   *   schema of its own facts around its child's entries' schemas (composed()
   *   for a collection, around a collection's item and a mount's, and
   *   framed() for a map of a child's keys). The seal makes each entry
   *   schema-first (Entry::sealed(), from Entries::fromArrays() with
   *   schemaFirst): a shell is handed its own facts alone and composed by
   *   the same calls, its child's keys placed by name (since 2026-11-15);
   *   fromArrays() reads nothing itself.
   * - src/Contract/Conditionals.php reads none since 2026-11-16: a
   *   declared rule's answer is applied to the key's schema
   *   (Answer::apply()).
   * - src/ResourceBuild/Binding.php, of(): the build's once-run of a
   *   refiner that watches nothing reads the key the builder holds as a
   *   definition, to apply the refiner's answer to its schema (since
   *   2026-11-16; goes when the builder holds schemas).
   * - src/Contract/ContractEmitter.php, of(): describeKey() reads a
   *   definition its caller hands, which no entry holds (since
   *   2026-11-16; with none handed it reads the entry's schema).
   * - src/Contract/PropertySchema.php reads none since 2026-11-16:
   *   property() describes a shape from the schema its key's own holds,
   *   and listSchema() gives a collection's own keywords from the list's
   *   schema, without its items; it reads no definition.
   * - src/Tool/InputDefinitions.php, of(): the public converters,
   *   fromDefinition() and outputFromDefinition(), read the schema of a
   *   definition a caller hands with no schema; every caller in the module
   *   hands the entry's schema with it.
   * - src/Storage/ConfigSchemaGenerator.php reads none since 2026-11-14:
   *   a collection's declared constraints, and what every entry of a map
   *   of entries from the site is, are read from the key's own schema,
   *   which is composed of its child's entries' schemas (Entry::schema()).
   * - src/Pipeline/Validating.php reads none since 2026-11-16: a port's
   *   key is held as its schema (SurfaceInterface::getPortSchema()), and
   *   schemaAt() hands it on, to the options service too.
   * - src/Surface.php, of(): withPorts() reads a port's key handed as a
   *   definition into its schema, once, at the door (since 2026-11-16);
   *   refusedWatchedValues() reads the place of a watched key its source
   *   gave as a definition, which the watched-value check is handed as a
   *   schema (since slice 34).
   * - src/Attachment.php, of(): withReads() reads the definition of the
   *   key a port is wired to into its schema, once (since 2026-11-16):
   *   the build hands the builder's definition (Attaching::wiredKeys()),
   *   and a port of an entry of a map of keys from the site the sibling
   *   entry's own schema, which is held as it is.
   *
   * - src/Options/SurfaceOptions.php, of(): resolve() hands a resolver
   *   the schema of the definition it was handed, where its caller holds
   *   no schema, read when a resolver is about to be called (since
   *   2026-11-15).
   * - src/ResourceBuild/KeyPaths.php, of(): violation() reads a
   *   builder's definition before sealing, which no entry holds, into the
   *   schema a site's or a route's value is held to (since slice 34).
   * - src/Resolve.php reads none since 2026-11-16: a resolved key's class
   *   is handed its entry's schema, and a key a mount holds the schema its
   *   mount's schema holds.
   * - src/Surface.php, around(): a mount its own refiners refined, with a
   *   structure refined in it too, keeps the definition built as before
   *   (refineMounted()), and its schema is composed of the refiners'
   *   answer's own facts and the refined structures' entries' schemas, so
   *   no key of a structure is read again (since 2026-11-16).
   * - src/Variants.php, shell(): the shell of variants, which every chosen
   *   variant wears, is read once into the schema a chosen variant's schema is
   *   composed of (shellSchema()), and once more per later ask inside an
   *   assertion (since 2026-11-16).
   * property() takes its schema from its caller and reads nothing, so a
   * caller that stops handing an entry's schema on has to call of() itself,
   * which this list then shows.
   */
  protected const CALLERS = [
    'src/Attachment.php' => ['of' => 1],
    'src/Contract/ContractEmitter.php' => ['of' => 1],
    // A config schema type's own definition, read at the edge (part 32.9).
    'src/Contract/KeywordReader.php' => ['of' => 1],
    'src/Entry.php' => ['around' => 4, 'of' => 4],
    'src/Options/SurfaceOptions.php' => ['of' => 1],
    'src/ResourceBuild/Binding.php' => ['of' => 1],
    'src/ResourceBuild/KeyPaths.php' => ['of' => 1],
    'src/Surface.php' => ['around' => 2, 'of' => 2],
    // A key the builder holds as a definition, asked for as a schema.
    'src/SurfaceBuilder.php' => ['of' => 1],
    'src/Tool/InputDefinitions.php' => ['of' => 2],
    'src/Variants.php' => ['shell' => 2],
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
      if (preg_match_all('/\bDefinitionSchema::(of|head|around|shell)\s*\(/', $code, $matches) === 0) {
        continue;
      }
      $relative = substr($file, strlen($root) + 1);
      foreach ($matches[1] as $function) {
        $found[$relative][$function] = ($found[$relative][$function] ?? 0) + 1;
      }
      ksort($found[$relative]);
    }
    ksort($found);
    $this->assertSame(self::CALLERS, $found, 'The code that reads a definition into a schema, by file and count.');
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
