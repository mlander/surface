<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * A value no element types in: an item saying it is not drawn on this form.
 *
 * Core's data types whose value is an object rather than a value a person
 * types: a language, and a reference to typed data (`language_reference`,
 * `entity_reference`; a key that refers to an entity is an id with the
 * SurfaceValidReference constraint, drawn as a select or an
 * autocomplete). No blueprint declares one (the type table refuses them),
 * so only a definition handed to the engine by hand reaches it. The item
 * posts nothing, so the key keeps what it holds. A list of them is not
 * drawn either.
 *
 * Decision: see
 * docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key.
 */
#[SurfaceWidget(
  id: 'not_drawn',
  label: new TranslatableMarkup('Not drawn'),
  weight: 10,
)]
final class NotDrawnWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * The data types it draws.
   */
  public const TYPES = ['language', 'language_reference', 'entity_reference'];

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    $value = DefinitionSchema::isList($schema) ? DefinitionSchema::itemOf($schema) : $schema;
    return $value !== NULL && in_array(SchemaFacts::dataType($value), self::TYPES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $schema, WidgetContext $context): bool {
    // Named by its word alone: a schema says no data type the form does
    // not draw.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $schema, mixed $value, WidgetContext $context): array {
    return static::notDrawn($schema);
  }

}
