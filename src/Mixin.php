<?php

declare(strict_types=1);

namespace Drupal\surface;

/**
 * A structure mixed in: its members are the declaring class's own keys.
 *
 * Attaching nests a structure under a key, in a frame of its own behind
 * the wall. Mixing it in merges its members into the declaring class instead:
 * stored flat, refined in the declaring class's frame, accepted, validated and
 * drawn as the declaring class's keys. What this carries is what only the
 * contract still needs to tell apart: which of the declaring class's keys the
 * structure brought, and the structure itself, built in its own frame for no
 * values, which the emitter writes once as a def and refers to from the
 * declaring class's `allOf`, so one def serves every declaring class it is
 * mixed into and every place it is attached.
 *
 * @see \Drupal\surface\Resource\BlueprintInterface::mixIn()
 * @see docs/resources.md#mixins
 */
final class Mixin {

  /**
   * Constructs a Mixin.
   *
   * @param \Drupal\surface\Attachment $structure
   *   The structure built in its own frame, by itself, with its source
   *   and id: what its def is written from.
   * @param string[] $keys
   *   The declaring class's keys it brought, in the declaring class's order,
   *   those of a structure it mixes in itself among them.
   * @param array<string, string> $reads
   *   Its ports, each wired to the declaring class's key it reads.
   */
  public function __construct(
    public readonly Attachment $structure,
    public readonly array $keys,
    public readonly array $reads = [],
  ) {
  }

}
