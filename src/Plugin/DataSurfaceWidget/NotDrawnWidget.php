<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A value no element types in: an item saying it is not drawn on this form.
 *
 * Bytes (`binary`), and core's data types whose value is an object rather
 * than a value a person types: a language, and a reference to typed data
 * (`language_reference`, `entity_reference`; a key that refers to an
 * entity is `entity:<type>`, drawn as a select or an autocomplete). The
 * item posts nothing, so the key keeps what it holds, and the tool, HTTP
 * and the React page set it. A list of them is not drawn either.
 *
 * Decision: see
 * docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key.
 */
#[DataSurfaceWidget(
  id: 'not_drawn',
  label: new TranslatableMarkup('Not drawn'),
  weight: 10,
)]
final class NotDrawnWidget extends DataSurfaceWidgetBase {

  /**
   * The data types it draws.
   */
  public const TYPES = ['binary', 'language', 'language_reference', 'entity_reference'];

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    $value = $definition instanceof ListDataDefinitionInterface ? $definition->getItemDefinition() : $definition;
    return in_array($value->getDataType(), self::TYPES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return static::notDrawn($definition);
  }

}
