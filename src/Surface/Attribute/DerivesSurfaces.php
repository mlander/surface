<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * On a class in src/Surface: it derives surfaces, under one base id.
 *
 * The class implements SurfaceDeriverInterface and is registered as an
 * autowired service. Each surface it derives is `<base>:<derivative>`;
 * an alter naming the base, #[AltersSurface('entity.content')], applies
 * to every one of them.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DerivesSurfaces {

  /**
   * Constructs a DerivesSurfaces attribute.
   *
   * @param string $base
   *   The base id, dotted like a surface id, with no colon.
   * @param class-string|null $overrides
   *   An attribute class: every class in an enabled module's src/Surface
   *   carrying it is handed to the deriver, as what replaces its
   *   derivation where someone wrote one by hand.
   */
  public function __construct(
    public readonly string $base,
    public readonly ?string $overrides = NULL,
  ) {}

}
