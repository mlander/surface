<?php

declare(strict_types=1);

namespace Drupal\surface_widget_test\Plugin\SurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

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
final class PairWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return $definition instanceof ComplexDataDefinitionInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::type($node) === 'object';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $element = [
      '#type' => 'container',
      '#attributes' => ['class' => ['widget-test-pair']],
      '#element_validate' => [],
    ];
    foreach ((array) ($node->get('properties') ?? []) as $name => $property) {
      $element[$name] = [
        '#type' => 'textfield',
        '#title' => $property instanceof Schema ? static::text($property->get('title')) : NULL,
        '#default_value' => is_array($value) ? ($value[$name] ?? NULL) : NULL,
        SurfaceFormBuilderInterface::PATH_KEY => $context->at($name)->pointer,
      ];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed {
    $values = [];
    foreach (array_keys((array) ($node->get('properties') ?? [])) as $name) {
      $values[$name] = is_array($element[$name] ?? NULL) ? $this->rawValue($element[$name], $form_state) : NULL;
    }
    return $values;
  }

}
