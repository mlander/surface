<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Pipeline\Coercion;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Surface;
use PortableSchema\Builder\Schema;

/**
 * The module's watched value check: the key's schema, by the validator.
 *
 * The value is held to the key's schema as one output
 * (Surface::holding(), SurfacePipelineInterface::conformOutput()): its
 * keywords by the dialect's validator, each `drupal:` check by the check
 * provider, as the pipeline validates a value it is sent, so a value the
 * pipeline refuses is a value no refiner is handed. The value, as
 * accepted, is written as the document holds it first (written()). Only
 * the yes or no is
 * kept; what the refusal says is the pipeline's to report, under the key
 * that holds the value. A check that throws is not a refusal: it is
 * thrown.
 *
 * Decision: see
 * docs/decisions.md#outputs-conform-through-the-dialects-validator.
 */
final class WatchedValueCheck implements WatchedValueCheckInterface {

  use DependencySerializationTrait;

  /**
   * Constructs a WatchedValueCheck.
   *
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, whose conform verb holds the value.
   */
  public function __construct(
    protected SurfacePipelineInterface $pipeline,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function admits(Schema $schema, mixed $value): bool {
    $written = static::written($schema, $value);
    return static::holdsIntegers($schema, $written) && $this->pipeline->conformOutput(Surface::holding($schema), ['value' => $written])->isEmpty();
  }

  /**
   * Answers whether every integer place holds a PHP integer.
   *
   * The dialect reads a whole float as an integer, and a refiner typed for
   * an integer is handed it as PHP holds it: a float the coercion table
   * left a float (one past PHP's integers) is not handed over.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The value's built schema.
   * @param mixed $value
   *   The value, as accepted.
   *
   * @return bool
   *   FALSE where a key of an integer type holds a float, at any depth.
   */
  protected static function holdsIntegers(Schema $schema, mixed $value): bool {
    if (is_float($value)) {
      return !in_array(NodeFacts::dataType($schema), ['integer', 'timestamp'], TRUE);
    }
    if (!is_array($value)) {
      return TRUE;
    }
    $item = DefinitionSchema::isList($schema) ? DefinitionSchema::itemNode($schema) : NULL;
    $properties = NodeFacts::properties($schema);
    foreach ($value as $name => $one) {
      $below = $item ?? $properties[$name] ?? NULL;
      if ($below !== NULL && !static::holdsIntegers($below, $one)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Writes an accepted value as the document holds it.
   *
   * Each value that is not a list or a map is read through the coercion
   * table, as accept() reads a value a form posts (Coercion::scalar()),
   * then written as SurfacePipelineInterface::document() writes it: a date
   * and time with its offset (Coercion::served()), at every depth of a
   * list or a map. A site's or a route's value is written the same way
   * before it is held (KeyPaths).
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The value's built schema.
   * @param mixed $value
   *   The value, as accepted.
   *
   * @return mixed
   *   The value, as the document holds it.
   *
   * @internal
   */
  public static function written(Schema $schema, mixed $value): mixed {
    if (is_array($value)) {
      if (DefinitionSchema::isList($schema)) {
        $item = DefinitionSchema::itemNode($schema);
        return $item === NULL ? $value : array_map(static fn (mixed $one): mixed => static::written($item, $one), $value);
      }
      $properties = NodeFacts::properties($schema);
      foreach ($value as $name => $one) {
        if (isset($properties[$name])) {
          $value[$name] = static::written($properties[$name], $one);
        }
      }
      return $value;
    }
    if (!ValueState::isConfigured($value) || !DefinitionSchema::isLeaf($schema)) {
      return $value;
    }
    // Read through the coercion table first, as accept() reads what a
    // form posts: a form's "1" is a boolean's TRUE.
    $type = NodeFacts::dataType($schema);
    $date_only = DefinitionSchema::declares($schema, 'DateOnly');
    $value = Coercion::scalar($type, $date_only, $value);
    if ($type === 'string' && is_scalar($value) && !is_string($value)) {
      // A scalar at a string key, which the watched-value check has always
      // handed over, as Typed Data's string takes any scalar; the value
      // baseline pins it. Open: see
      // docs/decisions.md#outputs-conform-through-the-dialects-validator.
      return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
    return $type === 'datetime_iso8601' ? Coercion::served($value, $date_only) : $value;
  }

}
