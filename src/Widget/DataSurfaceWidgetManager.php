<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;

/**
 * Manages data surface widget plugins.
 */
final class DataSurfaceWidgetManager extends DefaultPluginManager {

  /**
   * Every widget, instantiated once and sorted by weight.
   *
   * Widgets hold no per-definition state — a definition is an argument
   * to every method they have — so one instance serves every definition
   * for the rest of the request, and applicability can be asked of an
   * instance without paying for a new one each time.
   *
   * @var \Drupal\data_surface\Widget\DataSurfaceWidgetInterface[]
   */
  protected array $widgets;

  /**
   * Constructs a DataSurfaceWidgetManager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/DataSurfaceWidget',
      $namespaces,
      $module_handler,
      DataSurfaceWidgetInterface::class,
      DataSurfaceWidget::class,
    );
    $this->alterInfo('data_surface_widget_info');
    $this->setCacheBackend($cache_backend, 'data_surface_widget_plugins');
  }

  /**
   * Gets the widget serving a definition.
   *
   * Widgets are instantiated in weight order (lower first) and each is
   * asked whether it serves the definition; the first that says yes
   * wins, so specialized widgets undercut the generic per-type ones.
   * Instantiating before asking is what lets a widget consult its own
   * collaborators before answering.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The data definition.
   *
   * @return \Drupal\data_surface\Widget\DataSurfaceWidgetInterface
   *   The widget instance.
   *
   * @throws \InvalidArgumentException
   *   When no widget can serve the definition.
   */
  public function getWidgetFor(DataDefinitionInterface $definition): DataSurfaceWidgetInterface {
    foreach ($this->widgets() as $widget) {
      if ($widget->isApplicable($definition)) {
        return $this->asked($definition, $widget);
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget serves the "%s" data type.', $definition->getDataType()));
  }

  /**
   * Holds a definition that asks for a widget to the one it asked for.
   *
   * A definition's `widget` setting names the element it wants, and the
   * widget that answers to that name claims it. When another widget
   * claimed it first, the name does not fit the definition (radios for a
   * list, checkboxes for one value, a word no widget answers to), and
   * drawing what was not asked for would hide the mistake.
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\data_surface\Widget\DataSurfaceWidgetInterface $widget
   *   The widget that claimed it.
   *
   * @return \Drupal\data_surface\Widget\DataSurfaceWidgetInterface
   *   The widget.
   *
   * @throws \InvalidArgumentException
   *   When the definition asked for another.
   */
  protected function asked(DataDefinitionInterface $definition, DataSurfaceWidgetInterface $widget): DataSurfaceWidgetInterface {
    $asked = $definition->getSetting('widget');
    if (!is_string($asked) || !$widget instanceof DataSurfaceWidgetBase || in_array($asked, $widget->answersTo(), TRUE)) {
      return $widget;
    }
    throw new \InvalidArgumentException(sprintf(
      '"%s" asks for the %s widget, which does not draw a key of type %s; the %s widget does. Ask for a widget that draws it, or for none.',
      $definition->getLabel() ?? $definition->getDataType(),
      $asked,
      $definition instanceof ListDataDefinitionInterface ? 'list of ' . $definition->getItemDefinition()->getDataType() : $definition->getDataType(),
      $widget->answersTo()[0] ?? $widget->getPluginId(),
    ));
  }

  /**
   * Gets every widget, instantiated once, in weight order.
   *
   * @return \Drupal\data_surface\Widget\DataSurfaceWidgetInterface[]
   *   The widget instances, keyed by plugin ID, lowest weight first.
   */
  protected function widgets(): array {
    if (!isset($this->widgets)) {
      $candidates = $this->getDefinitions();
      uasort($candidates, static fn (array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
      $this->widgets = [];
      foreach (array_keys($candidates) as $plugin_id) {
        /** @var \Drupal\data_surface\Widget\DataSurfaceWidgetInterface $widget */
        $widget = $this->createInstance($plugin_id);
        $this->widgets[$plugin_id] = $widget;
      }
    }
    return $this->widgets;
  }

}
