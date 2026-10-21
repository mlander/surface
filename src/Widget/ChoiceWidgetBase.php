<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Options\OptionSet;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Common ground for the widgets that draw a list of allowed values.
 *
 * A select, radios and checkboxes all ask the options service for what a
 * definition offers, a list's from its item definition, and render what
 * the resolver said about how long the answer may be reused.
 */
abstract class ChoiceWidgetBase extends DataSurfaceWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a choice widget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\data_surface\Options\DataSurfaceOptions $options
   *   The options service.
   */
  final public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly DataSurfaceOptions $options,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('data_surface.options'));
  }

  /**
   * Resolves the values a definition offers, list or single.
   *
   * A list definition offers what one of its items may be, so the
   * options come from the item definition; anything else offers what it
   * may be itself.
   *
   * The service memoizes its answer per definition object for the rest
   * of the request, so asking here for applicability and again for the
   * element costs one resolution, not two.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition to read.
   *
   * @return \Drupal\data_surface\Options\OptionSet|null
   *   The option set, or NULL when the definition names no list.
   */
  protected function optionSet(DataDefinitionInterface $definition): ?OptionSet {
    return $definition instanceof ListDataDefinitionInterface
      ? $this->options->resolve($definition->getItemDefinition())
      : $this->options->resolve($definition);
  }

  /**
   * Applies what a resolver said about reusing its answer to an element.
   *
   * An element built from a list read out of site state may only be
   * cached for as long as that state holds. Without this the whole
   * declaration is computed and dropped. (The data surface's own
   * cacheability is a separate half.)
   *
   * @param array $element
   *   The element.
   * @param \Drupal\data_surface\Options\OptionSet|null $set
   *   The values it offers.
   *
   * @return array
   *   The element, carrying the set's cacheability.
   */
  protected static function cachedBy(array $element, ?OptionSet $set): array {
    if ($set !== NULL) {
      CacheableMetadata::createFromObject($set)->applyTo($element);
    }
    return $element;
  }

  /**
   * Puts each option's description under it, where a resolver gave one.
   *
   * @param array $element
   *   A radios or checkboxes element.
   * @param \Drupal\data_surface\Options\OptionSet $set
   *   The values it offers.
   *
   * @return array
   *   The element, an option's child carrying its description.
   */
  protected static function describedOptions(array $element, OptionSet $set): array {
    foreach ($set->descriptions as $value => $description) {
      if (isset($set->options[$value])) {
        $element[$value]['#description'] = $description;
      }
    }
    return $element;
  }

}
