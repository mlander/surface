<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * On a class in src/Resource: it derives resources, under one base id.
 *
 * The class implements ResourceDeriverInterface and is registered as an
 * autowired service. Each resource it derives is `<base>:<derivative>`;
 * an alter naming the base, #[Alters('entity.content')], applies
 * to every one of them.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DerivesResources {

  /**
   * Constructs a DerivesResources attribute.
   *
   * @param string $base
   *   The base id, dotted like a resource id, with no colon.
   * @param class-string|null $overrides
   *   An attribute class: every class in an enabled module's src/Resource
   *   or src/Structure carrying it is handed to the deriver, as what
   *   replaces its derivation where someone wrote one by hand.
   */
  public function __construct(
    public readonly string $base,
    public readonly ?string $overrides = NULL,
  ) {}

}
