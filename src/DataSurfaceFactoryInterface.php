<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * Builds surfaces through the alter stage.
 *
 * The single entry point every provider routes through, which is what
 * makes alteration coherent: the extended surface is what every consumer
 * reads — form, validation, defaults, schema — never a form-only
 * variant. A surface that did not come from here was never offered to
 * subscribers, so nothing may assume it is complete.
 *
 * One method, because there is one way in. A host declares its surface
 * in a method, fills a builder there, and hands the builder over; how
 * much of that method is the host's own code and how much a base class
 * wrote for it is the host's business and not the factory's.
 */
interface DataSurfaceFactoryInterface {

  /**
   * Dispatches the build event and seals the surface.
   *
   * Host ids are namespaced `<host type>:<id>`, so that two hosts of
   * different kinds that happen to share a name are two hosts to a
   * subscriber. The host type is the plugin type for a plugin
   * (`block:`, `field_formatter:`, `action:`, `condition:`), the field
   * type plugin id for a field type (`field_type:address`), and the
   * entity type id for a config entity a provider speaks for
   * (`entity_type:node_type`). A host whose kind core has no name for
   * picks one and keeps it; the id is part of its public surface,
   * because subscribers match on it.
   *
   * @param \Drupal\data_surface\DataSurfaceBuilderInterface $builder
   *   The provider's builder, not yet sealed.
   * @param string $host_class
   *   The class providing the surface.
   * @param string $host_id
   *   The namespaced identifier for the host, `<host type>:<id>`.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The advertised surface, alters applied.
   *
   * @throws \LogicException
   *   When the builder has already been sealed: a builder describes one
   *   surface and dispatches one build event.
   */
  public function build(DataSurfaceBuilderInterface $builder, string $host_class, string $host_id): DataSurfaceInterface;

}
