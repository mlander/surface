<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * A date input for a string that asks for one, the time input's twin.
 *
 * A date with no time is a string with a pattern, `YYYY-MM-DD`, and the
 * pattern is the rule. A definition asks for the browser's date picker
 * with `setSetting('widget', 'date')`; nothing is read off the pattern.
 * The element is core's own date element, whose input type is `date` and
 * whose format is `Y-m-d` by default. A browser posts the RFC 3339
 * full-date from it, the string the pattern checks, so what a person
 * picks, what an agent sends and what is stored are the same.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[DataSurfaceWidget(
  id: 'date',
  label: new TranslatableMarkup('Date'),
  weight: 9,
)]
final class DateWidget extends DataSurfaceWidgetBase {

  /**
   * Answers whether a definition is drawn as a date input.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a string that asks for one.
   */
  public static function draws(DataDefinitionInterface $definition): bool {
    return $definition->getDataType() === 'string' && $definition->getSetting('widget') === 'date';
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return static::draws($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'date',
      '#default_value' => $value,
    ];
  }

}
