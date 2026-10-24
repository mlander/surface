<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * When a computed key is computed: the `when:` of compute().
 *
 * Decision: see
 * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
 */
final class Compute {

  /**
   * Once, when the thing is created; kept as stored after, the default.
   *
   * A slug a link points at is set once: renaming the thing leaves it as
   * it was.
   */
  public const ON_CREATE = 'create';

  /**
   * Again on every write that changes a key it is computed from.
   *
   * A write that changes none of them leaves it as stored.
   */
  public const ON_CHANGE = 'change';

  /**
   * Every value `when:` takes.
   */
  public const ALL = [self::ON_CREATE, self::ON_CHANGE];

}
