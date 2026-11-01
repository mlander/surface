<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;

/**
 * Draws a map of two strings side by side, when a structure asks for it.
 *
 * It claims a shape no key has, so it draws only what asks for it by its
 * word: the pair structure, #[Structure(widget: 'widget_test_pair')].
 */
#[SurfaceWidget(
  id: 'widget_test_pair',
  label: new TranslatableMarkup('Pair'),
  claims: [['shape' => 'widget_test.nothing']],
)]
final class PairWidget extends SurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ComplexDataDefinitionInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    assert($definition instanceof ComplexDataDefinitionInterface);
    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['widget-test-pair']],
      '#element_validate' => [],
    ];
    foreach ($definition->getPropertyDefinitions() as $name => $property) {
      $element[$name] = [
        '#type' => 'textfield',
        '#title' => $property->getLabel(),
        '#default_value' => is_array($value) ? ($value[$name] ?? NULL) : NULL,
      ];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    assert($definition instanceof ComplexDataDefinitionInterface);
    $values = [];
    foreach (array_keys($definition->getPropertyDefinitions()) as $name) {
      $values[$name] = $form_state->getValue([...$fallback_parents, $name]);
    }
    return $values;
  }

}
