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

  /**
   * Builds the surface a class declares in its DataSurfaceAware attribute.
   *
   * Routed through build(), so the build event fires and alters apply to
   * attribute-declared surfaces exactly as they do to hand-built ones.
   * Each call reflects a fresh attribute instance, so the definition
   * objects are never shared between instances — which matters because
   * refinement mutates a definition's constraints.
   *
   * The attribute declares the flat part of a surface, which is all a
   * PHP attribute argument can express. A host whose surface also
   * carries map properties supplies them in $before_seal, so the
   * complete surface still reaches the build event.
   *
   * @param string $class
   *   The fully qualified class name.
   * @param \Drupal\data_surface\DataSurfaceRefinerInterface|null $refiner
   *   The provider's refiner, typically the plugin instance itself.
   * @param string $host_id
   *   The namespaced identifier for the host, `<host type>:<id>`.
   * @param callable|null $before_seal
   *   Receives the builder after the attribute has been read and before
   *   the build event is dispatched, for the parts of a surface an
   *   attribute cannot declare.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The advertised surface.
   *
   * @throws \LogicException
   *   When the class declares no attribute or no static definitions.
   */
  public function buildFromClass(string $class, ?DataSurfaceRefinerInterface $refiner, string $host_id, ?callable $before_seal = NULL): DataSurfaceInterface;

}
