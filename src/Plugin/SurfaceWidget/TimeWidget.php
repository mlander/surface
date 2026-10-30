<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;

/**
 * A time input for a string that asks for one.
 *
 * Core has no data type for a time of day, so a time is a string with a
 * pattern, `HH:MM`, and the pattern is the rule. A definition asks for
 * the browser's time picker with `setSetting('widget', 'time')`; nothing
 * is read off the pattern, since a pattern is for machines and many
 * patterns allow a time. The element is core's own spelling of it, the
 * one its datetime element draws a time with: a `date` element whose
 * input type is `time`. A browser posts `HH:MM` from it, the string the
 * pattern checks, so what a person picks, what an agent sends and what
 * is stored are the same.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'time',
  label: new TranslatableMarkup('Time'),
  weight: 9,
)]
final class TimeWidget extends SurfaceWidgetBase {

  /**
   * Answers whether a definition is drawn as a time input.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return bool
   *   TRUE for a string that asks for one.
   */
  public static function draws(DataDefinitionInterface $definition): bool {
    return $definition->getDataType() === 'string' && $definition->getSetting('widget') === 'time';
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
      '#attributes' => ['type' => 'time'],
      '#default_value' => $value,
    ];
  }

}
