<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\TypedData\DataDefinitionInterface;
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
        return $widget;
      }
    }
    throw new \InvalidArgumentException(sprintf('No data surface widget serves the "%s" data type.', $definition->getDataType()));
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
