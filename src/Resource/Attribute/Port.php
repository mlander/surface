<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a property: a port, a value read from the container.
 *
 * The spelling of reads(). It declares no key: the property is never
 * stored, drawn or accepted. Its type is the property's, or `type:` for
 * a type the property cannot say, `any` among them.
 *
 * @code
 * #[Port]
 * public bool $listed;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::reads()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Port {

  /**
   * Constructs a Port attribute.
   *
   * @param string|null $type
   *   The port's type; NULL to read it from the property.
   * @param bool $required
   *   Whether every container must wire it.
   */
  public function __construct(
    public readonly ?string $type = NULL,
    public readonly bool $required = FALSE,
  ) {}

}
