<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Recursive container for complex definitions with declared properties.
 *
 * Children are keyed by property name directly — no wrapper nesting —
 * and each property's current value reaches its child widget, so stored
 * nested values populate at every depth (the adapter-era MapAdapter
 * discarded them, which read as "the value did not save").
 */
#[DataSurfaceWidget(
  id: 'map',
  label: new TranslatableMarkup('Map'),
  weight: 5,
)]
final class MapWidget extends DataSurfaceWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a MapWidget.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DataSurfaceWidgetManager $widgetManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.data_surface_widget'));
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ComplexDataDefinitionInterface
      && $definition->getPropertyDefinitions() !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    assert($definition instanceof ComplexDataDefinitionInterface);
    $element = [
      '#type' => 'details',
      '#title' => $definition->getLabel(),
      '#open' => TRUE,
    ];
    if ($definition->getDescription() !== NULL) {
      $element['#description'] = $definition->getDescription();
    }
    if ($definition->isRequired()) {
      // A details holds no value of its own, so this paints the required
      // marker on the summary and nothing else: what makes the map
      // required is the surface, checked in the pipeline's validate().
      // Without it a required map is the one required key on a generated
      // form with no marker at all.
      $element['#required'] = TRUE;
    }
    foreach ($definition->getPropertyDefinitions() as $property_name => $property_definition) {
      $element[$property_name] = $this->widgetManager
        ->getWidgetFor($property_definition)
        ->buildElement($property_definition, is_array($value) ? ($value[$property_name] ?? NULL) : NULL);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    assert($definition instanceof ComplexDataDefinitionInterface);
    $values = [];
    foreach ($definition->getPropertyDefinitions() as $property_name => $property_definition) {
      // Only properties that were rendered are collected. A property
      // with no element was never offered — access denied, removed by a
      // host, or simply not built — so it has nothing to say, and the
      // pipeline's accept() keeps whatever the level already holds for
      // it. Collecting it anyway would read NULL out of form state and
      // write that NULL over a stored value, which is the same rule the
      // top level already applies to whole keys.
      if (!isset($element[$property_name]) || !is_array($element[$property_name])) {
        continue;
      }
      // A rendered property is collected empty or not: what an empty
      // submission means is the pipeline's rule, not the widget's.
      $values[$property_name] = $this->widgetManager
        ->getWidgetFor($property_definition)
        ->extractValue($property_definition, $element[$property_name], $form_state, array_merge($fallback_parents, [$property_name]));
    }
    return $values;
  }

  /**
   * Names the child element keys, for consumers walking the structure.
   *
   * @return string[]
   *   The property names present as children.
   */
  public static function childKeys(array $element): array {
    return Element::children($element);
  }

}
