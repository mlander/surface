<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;
use Drupal\data_surface\Widget\DataSurfaceWidgetManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Recursive container for complex definitions.
 *
 * Children are keyed by property name directly — no wrapper nesting —
 * and each property's current value reaches its child widget, so stored
 * nested values populate at every depth (the adapter-era MapAdapter
 * discarded them, which read as "the value did not save").
 *
 * A map that declares no properties, such as a variant for a choice
 * with nothing more to say (a daily digest has no send day), holds
 * nothing: it renders as an empty container, with no title around
 * nothing, and answers an empty map.
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
    return $definition instanceof ComplexDataDefinitionInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    assert($definition instanceof ComplexDataDefinitionInterface);
    if ($definition->getPropertyDefinitions() === []) {
      // Nothing to fill in, so nothing to draw; the container is still
      // there for a rebuild to replace when another variant is chosen.
      return ['#type' => 'container'];
    }
    // A map that only groups the maps inside it, each its own details,
    // has nothing of its own to title, so nothing is drawn around them.
    $element = DefinitionMetadata::isGrouping($definition) ? ['#type' => 'container'] : [
      '#type' => 'details',
      '#title' => $definition->getLabel(),
      '#open' => TRUE,
    ];
    if ($element['#type'] === 'details' && $definition->getDescription() !== NULL) {
      $element['#description'] = $definition->getDescription();
    }
    $after = DefinitionMetadata::getPlacedAfter($definition);
    if ($after !== NULL) {
      // A module's fieldset its alter placed. It is built here, where its
      // values are posted; the container draws it after that key.
      $element[DataSurfaceFormBuilderInterface::PLACED_AFTER_KEY] = $after;
    }
    if ($definition->isRequired()) {
      // A details holds no value of its own, so this paints the required
      // marker on the summary and nothing else: what makes the map
      // required is the data surface, checked in the pipeline's validate().
      // Without it a required map is the one required key on a generated
      // form with no marker at all.
      $element['#required'] = TRUE;
    }
    foreach ($definition->getPropertyDefinitions() as $property_name => $property_definition) {
      if (DoesNotApply::marks($property_definition)) {
        // A key an alter mounted, or a child's key, that does not apply:
        // its wrapper alone.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        $ruled_out = [
          '#markup' => '',
          DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
          DataSurfaceFormBuilderInterface::RULED_OUT_KEY => TRUE,
        ];
        $element[$property_name] = static::arranged($ruled_out, $property_definition);
        continue;
      }
      if ($property_definition->getDataType() === 'any' && DefinitionMetadata::variantsBy($property_definition) !== NULL) {
        // Variants inside a child whose deciding key chose nothing yet:
        // nothing to draw until it does, as at the top of a form.
        $placeholder = ['#markup' => '', DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE];
        $element[$property_name] = static::arranged($placeholder, $property_definition);
        continue;
      }
      if ($property_definition instanceof ListDataDefinitionInterface && $property_definition->getItemDefinition() instanceof ComplexDataDefinitionInterface) {
        // A collection inside a child's map: the form draws one at the top
        // of a frame or inside an item, not here, so what it holds is kept
        // and set through the other doors.
        // Decision: see
        // docs/decisions.md#a-slot-holds-text-a-component-or-components.
        $element[$property_name] = static::arranged([
          '#type' => 'item',
          '#title' => $property_definition->getLabel(),
          '#markup' => (string) new TranslatableMarkup('A list of these is not drawn on this form; what it holds is kept. Set it through the tool, over HTTP or on the React page.'),
          DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
        ], $property_definition);
        continue;
      }
      // Each key drawn as its arrangement says, whichever widget drew it.
      // Decision: see docs/decisions.md#arrangement-is-declared-once.
      $element[$property_name] = static::arranged($this->widgetManager
        ->getWidgetFor($property_definition)
        ->buildElement($property_definition, is_array($value) ? ($value[$property_name] ?? NULL) : NULL), $property_definition);
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
      if (!isset($element[$property_name]) || !is_array($element[$property_name]) || !empty($element[$property_name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
        continue;
      }
      if ($property_definition->getDataType() === 'any' && DefinitionMetadata::variantsBy($property_definition) !== NULL) {
        // Drawn for a variant its deciding key no longer chooses: what was
        // posted for it belongs to that variant, and is dropped with it.
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
