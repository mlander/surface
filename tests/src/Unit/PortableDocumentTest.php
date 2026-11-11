<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\Schemas;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Holds every pinned document to the Portable Schema dialect.
 *
 * Every object carrying `$schema` in the fixtures that pin a served
 * document, the module's and the React app's recorded answers, is a
 * Portable Schema document: the registry takes it, and the validator loads
 * it against the dialect's meta-schema and profile, which refuse a
 * `$schema` other than the dialect's, a format outside the profile (such as
 * `idn-email`) or a keyword the dialect does not know. And the dialect's
 * own value, read with Schema::from(), writes it back byte for byte, as
 * does its array form (Schemas::arrayed()), which is what a served
 * document holds.
 *
 * Decision: see
 * docs/decisions.md#the-served-document-is-a-portable-schema-document.
 */
#[Group('surface')]
final class PortableDocumentTest extends UnitTestCase {

  /**
   * The module root.
   */
  private const ROOT = __DIR__ . '/../../..';

  /**
   * The fixture families, under tests/fixtures/, with their documents.
   */
  private const FAMILIES = [
    'contracts' => 48,
    'venues' => 10,
    'references' => 4,
    'retitle' => 4,
    'block-placement' => 2,
    'component-block' => 1,
    'keys-from-site' => 2,
    'variants-from-site' => 2,
  ];

  /**
   * The React app's recorded answers, and the documents they hold.
   */
  private const REACT = ['modules/surface_examples_react/app/src/test/fixtures' => 59];

  /**
   * The flags a fixture is written with.
   */
  private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

  /**
   * Tests every document loads in the dialect and reads back byte for byte.
   */
  public function testEveryPinnedDocumentIsPortable(): void {
    $directories = [];
    foreach (self::FAMILIES as $family => $count) {
      $directories['tests/fixtures/' . $family] = $count;
    }
    $total = 0;
    foreach ($directories + self::REACT as $directory => $count) {
      $found = 0;
      foreach (glob(self::ROOT . '/' . $directory . '/*.json') ?: [] as $file) {
        $raw = (string) file_get_contents($file);
        // The baselines are written with no zero fraction kept; the rest
        // keep it.
        $flags = self::FLAGS | (str_contains($directory, '/contracts') ? 0 : JSON_PRESERVE_ZERO_FRACTION);
        $name = basename($directory) . '/' . basename($file);
        foreach (static::documents(json_decode($raw, flags: JSON_THROW_ON_ERROR)) as $index => $document) {
          $at = sprintf('%s, document %d', $name, $index);
          // Registered by where it is pinned: a recorded answer's `$id` is
          // the URL it was served at, relative in some, which the registry
          // refuses, and the same in several.
          $registered = clone $document;
          unset($registered->{'$id'});
          $registry = Registry::withTypes()->add($registered, 'surface:' . $name . '/' . $index);
          $this->assertInstanceOf(Validator::class, new Validator($registry), $at . ' loads in the dialect.');
          $this->assertSame(json_encode($document, $flags), json_encode(Schema::from($document)->toDocument(), $flags), $at . ' reads into a Schema and writes back the same.');
          $this->assertSame(json_encode($document, $flags), json_encode(Schemas::arrayed(Schema::from($document)), $flags), $at . ' in the array form writes the same.');
          $found++;
        }
      }
      $this->assertSame($count, $found, $directory . ' holds that many documents.');
      $total += $found;
    }
    $this->assertSame(132, $total);
  }

  /**
   * Finds the documents in a decoded fixture: each object with `$schema`.
   *
   * @param mixed $value
   *   The fixture, objects decoded as objects.
   *
   * @return list<\stdClass>
   *   The documents, in the order they stand.
   */
  private static function documents(mixed $value): array {
    if ($value instanceof \stdClass && is_string($value->{'$schema'} ?? NULL)) {
      return [$value];
    }
    if (!$value instanceof \stdClass && !is_array($value)) {
      return [];
    }
    $found = [];
    foreach ((array) $value as $item) {
      array_push($found, ...static::documents($item));
    }
    return $found;
  }

}
