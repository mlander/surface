<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * Names a surface, and says where its values go and who may send them.
 *
 * The name is so things outside PHP can ask for it. Any class in a
 * module's src/Surface directory carrying this is found.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Surface {

  /**
   * Constructs a Surface attribute.
   *
   * @param string $id
   *   Machine name.
   * @param string[] $identity
   *   The input keys that say WHICH thing this is. An identity key is
   *   settable until the context knows it, and locked from then on.
   *   That is the whole difference between add and edit. Here rather
   *   than in defineInputs() because it is part of the address: what a
   *   discovery document lists, what a tool or route must supply.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceTargetInterface>|null $target
   *   Where accepted values are written. NULL means the host supplies
   *   one (a plugin's configuration) or the parent stores this
   *   subsurface under its key.
   * @param class-string<\Drupal\data_surface\Surface\SurfaceAccessInterface>|null $access
   *   The run-time part of access, for what depends on the subject. NULL
   *   means the situation's permission is the whole answer.
   */
  public function __construct(
    public readonly string $id,
    public readonly array $identity = [],
    public readonly ?string $target = NULL,
    public readonly ?string $access = NULL,
  ) {}

}
