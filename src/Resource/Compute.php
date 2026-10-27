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
   * When a call leaves it out; a value sent is kept as sent.
   *
   * A machine name suggested from a title, which a person may type over:
   * left out, or sent empty, it is computed while storage holds nothing
   * for it, as ON_CREATE computes it, and kept as stored after; sent, it
   * is taken as it was sent and judged by its own constraints.
   */
  public const UNLESS_SENT = 'unless_sent';

  /**
   * Every value `when:` takes.
   */
  public const ALL = [self::ON_CREATE, self::ON_CHANGE, self::UNLESS_SENT];

}
