<?php

declare(strict_types=1);

namespace Drupal\data_surface\Variants;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One variant per single-directory component, from its props.
 *
 * Every component the site's modules and themes provide, or those
 * `data_surface.settings:components_offered` allows (ComponentsOffered),
 * in the order of their ids, each labeled with its name, its props schema
 * as the component declares it. Which components there are follows from
 * the extensions installed and the setting, so the list is cached by both
 * configurations' tags. Example 12 reads the props of its spotlight with
 * it, and the component block of data_surface_component_block the props
 * of a placed block.
 */
final class ComponentProps implements SiteListInterface, ContainerInjectionInterface {

  /**
   * The JSON Schema types; a component may also name a PHP class.
   */
  protected const JSON_TYPES = ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'];

  /**
   * Constructs the provider.
   *
   * @param \Drupal\data_surface\Variants\ComponentsOffered $components
   *   The components the site offers.
   */
  public function __construct(
    protected readonly ComponentsOffered $components,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(ComponentsOffered::create($container));
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $variants = (new SiteList())->addCacheTags(ComponentsOffered::CACHE_TAGS);
    foreach ($this->components->offered() as $id => $component) {
      $variants->add((string) $id, (string) ($component['name'] ?? $id), schema: self::props($component['props'] ?? []));
    }
    return $variants;
  }

  /**
   * Writes a component's props as the JSON Schema it is checked with.
   *
   * Closed, as core reads them. A prop typed by a PHP class takes a value
   * only Twig code passes, which nothing stores: that type is left out,
   * and a prop with no other type with it.
   *
   * @param array $props
   *   The props as the component declares them.
   *
   * @return array
   *   The schema.
   */
  public static function props(array $props): array {
    $props += ['type' => 'object', 'properties' => []];
    foreach ($props['properties'] as $name => $prop) {
      $types = (array) ($prop['type'] ?? []);
      $json = array_values(array_intersect($types, self::JSON_TYPES));
      if (count($json) === count($types)) {
        continue;
      }
      if ($json === []) {
        unset($props['properties'][$name]);
        $props['required'] = array_values(array_diff($props['required'] ?? [], [$name]));
        continue;
      }
      $props['properties'][$name]['type'] = $json;
    }
    $props['additionalProperties'] = FALSE;
    return $props;
  }

}
