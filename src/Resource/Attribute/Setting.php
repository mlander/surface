<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: one definition setting. Repeatable.
 *
 * The spelling of setSetting(), for any setting but `widget`, which is
 * #[Widget].
 *
 * @code
 * #[Setting('multiline', TRUE)]
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class Setting {

  /**
   * Constructs a Setting attribute.
   *
   * @param string $name
   *   The setting's name.
   * @param mixed $value
   *   Its value.
   */
  public function __construct(
    public readonly string $name,
    public readonly mixed $value,
  ) {}

}
