<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;

/**
 * The document a data surface's outputs are held to.
 *
 * Built from each output entry's schema (Entry::schema()), each key written
 * by the one writer of a key (PropertySchema::property()), as the served
 * outputs document is. Two things differ from the served one: the checks
 * stay (`portable-check`, `x-portable-messages`), so a check that no
 * keyword states runs; and every object that declares its keys is closed
 * (`additionalProperties: false`), at the top and at every depth. What says
 * nothing about a value goes: `x-surface`, the shape mark, `x-portable-ui`
 * and `x-portable-links`.
 *
 * An output key holds plain keys alone (lists, maps and values): no child,
 * no variant, no collection, never a secret.
 *
 * Decision: see design-schema-as-model.md, slice 34, point (1).
 *
 * @internal
 */
final class OutputsDocument {

  /**
   * What says nothing about what a value must hold.
   */
  private const PRESENTATION = [
    ContractEmitter::EXTENSION,
    PropertySchema::SHAPED,
    PortableKeywords::UI,
    PortableKeywords::LINKS,
  ];

  /**
   * The documents built, by the output entries they were built from.
   *
   * @var \WeakMap<object, \PortableSchema\Builder\Schema|false>
   */
  private \WeakMap $built;

  /**
   * Constructs an OutputsDocument.
   *
   * @param \Drupal\surface\Contract\PropertySchema $propertySchema
   *   The writer of one key, the served document's.
   */
  public function __construct(
    private readonly PropertySchema $propertySchema,
  ) {
    $this->built = new \WeakMap();
  }

  /**
   * Builds the document a data surface's outputs are held to.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, whose output entries are read
   *   (SurfaceInterface::getOutputDefinitions()).
   * @param \Drupal\surface\Contract\Emission|null $emission
   *   The walk whose cacheability collects what the document depends on;
   *   NULL for one of its own, and then the document is kept for the
   *   output entries.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The document; NULL for a data surface that declares no outputs.
   */
  public function of(SurfaceInterface $surface, ?Emission $emission = NULL): ?Schema {
    $outputs = $surface->getOutputDefinitions();
    if ($emission === NULL && $this->built->offsetExists($outputs)) {
      return $this->built[$outputs] ?: NULL;
    }
    $properties = [];
    $required = [];
    $walk = ($emission ?? Emission::detached())->withoutStale();
    foreach ($outputs->entries() as $name => $entry) {
      $schema = $entry->schema();
      [$written] = $this->propertySchema->property($schema, NULL, NULL, (string) $name, FALSE, [], $walk);
      $properties[(string) $name] = self::held($written);
      if (NodeFacts::isRequired($schema)) {
        $required[] = (string) $name;
      }
    }
    $document = NULL;
    if ($properties !== []) {
      $document = Schema::keywords([
        '$schema' => ContractEmitter::DIALECT,
        'type' => 'object',
        'properties' => $properties,
        'additionalProperties' => FALSE,
      ]);
      $document = $required === [] ? $document : $document->with('required', $required);
    }
    if ($emission === NULL) {
      $this->built[$outputs] = $document ?? FALSE;
    }
    return $document;
  }

  /**
   * Keeps what a value is held to, and closes every object that names keys.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   One key's schema, as the writer of a key wrote it.
   *
   * @return \PortableSchema\Builder\Schema
   *   The same schema, its checks and messages kept, its presentation gone,
   *   and `additionalProperties: false` beside every `properties` that has
   *   no word on other keys.
   */
  public static function held(Schema $schema): Schema {
    return Schemas::map($schema, static function (Schema $part): Schema {
      foreach (self::PRESENTATION as $keyword) {
        if ($part->has($keyword)) {
          $part = $part->without($keyword);
        }
      }
      if ($part->has('properties') && !$part->has('additionalProperties') && !$part->has('unevaluatedProperties') && !$part->has('patternProperties')) {
        $part = $part->with('additionalProperties', FALSE);
      }
      return $part;
    }, FALSE);
  }

}
