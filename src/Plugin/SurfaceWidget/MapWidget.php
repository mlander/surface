<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetManager;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A details holding one element per property of an object node.
 *
 * Children are keyed by property name directly, with no wrapper nesting,
 * and each property's value reaches its child, so stored nested values
 * populate at every depth. The children are drawn by the drawer
 * (WidgetContext::properties()), by the rules every key is drawn by:
 * groups, variants, collections, a key that does not apply, a lock.
 *
 * An object that declares no properties, such as a variant for a choice
 * with nothing more to say (a daily digest has no send day), holds
 * nothing: it renders as an empty container, with no title around
 * nothing, and answers an empty map. One that only groups the objects
 * inside it (`x-surface.group`) is a container with no title.
 */
#[SurfaceWidget(
  id: 'fieldset',
  label: new TranslatableMarkup('Fieldset'),
  weight: 5,
)]
final class MapWidget extends SurfaceWidgetBase implements ContainerFactoryPluginInterface, DefinitionWidgetInterface {

  /**
   * Constructs a MapWidget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\surface\Widget\SurfaceWidgetManager $widgetManager
   *   The widget manager, whose widgets read each child back.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly SurfaceWidgetManager $widgetManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('plugin.manager.surface_widget'));
  }

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
    return static::type($node) === 'object' || $node->has('properties');
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $properties = $node->get('properties');
    $extension = static::extension($node);
    if (!is_array($properties) || ($properties === [] && !isset($extension['doesNotApply']))) {
      // Nothing to fill in, so nothing to draw; the container is still
      // there for a rebuild to replace when another variant is chosen.
      return ['#type' => 'container'];
    }
    // An object that only groups the objects inside it, each its own
    // details, has nothing of its own to title, so nothing is drawn
    // around them.
    $element = !empty($extension['group']) ? ['#type' => 'container'] : [
      '#type' => 'details',
      '#title' => static::text($node->get('title')),
      '#open' => TRUE,
    ];
    $description = static::text($node->get('description'));
    if ($element['#type'] === 'details' && $description !== NULL) {
      $element['#description'] = $description;
    }
    if (is_string($extension['after'] ?? NULL)) {
      // A module's fieldset its alter placed. It is built here, where its
      // values are posted; the container draws it after that key.
      $element[SurfaceFormBuilderInterface::PLACED_AFTER_KEY] = $extension['after'];
    }
    if ($context->required) {
      // A details holds no value of its own, so this paints the required
      // marker on the summary and nothing else: what makes the object
      // required is the pipeline's validate().
      $element['#required'] = TRUE;
    }
    return $element + $context->properties($node, is_array($value) ? $value : []);
  }

  /**
   * {@inheritdoc}
   *
   * Only properties that were rendered are collected. A property with no
   * element was never offered (access denied, removed by a host, or not
   * drawn), so it has nothing to say, and the pipeline's accept() keeps
   * whatever the level already holds for it. A rendered property is
   * collected empty or not: what an empty submission means is the
   * pipeline's rule, not the widget's. Each child is read back by the
   * widget that drew it (`#surface_widget`), through its property's node.
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed {
    $values = [];
    $properties = (array) ($node->get('properties') ?? []);
    foreach (Element::children($element) as $name) {
      $child = $element[$name];
      if (!is_array($child) || !empty($child[SurfaceFormBuilderInterface::PLACEHOLDER_KEY]) || !isset($child[SurfaceFormBuilderInterface::WIDGET_KEY])) {
        continue;
      }
      $property = $properties[$name] ?? NULL;
      $values[$name] = $this->widgetManager
        ->widget((string) $child[SurfaceFormBuilderInterface::WIDGET_KEY])
        ->extractValue($child, $form_state, $property instanceof Schema ? $property : Schema::from([]));
    }
    return $values;
  }

}
