<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * On a class in src/Alter: which resource or structure it alters.
 *
 * Static, like #[Variant(of:)], so a catalog can
 * say who alters what without running anything. An alter reaches both
 * kinds. The class is registered as an autowired service, the way a hook
 * class is, so it may hold services.
 *
 * One with a class is named by its class. A derived resource has none,
 * so it is named by its id, `entity.content:node.page`, or every
 * derivative of one deriver at once by the deriver's base id,
 * `entity.content`.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Alters {

  /**
   * Constructs an Alters attribute.
   *
   * @param string $of
   *   What this class alters: the class of a resource or a structure, or
   *   for a derived one its id, or a deriver's base id for everything it
   *   derives.
   * @param string[] $operations
   *   Apply only in these operations. Empty means all.
   */
  public function __construct(
    public readonly string $of,
    public readonly array $operations = [],
  ) {}

}
