<?php

declare(strict_types=1);

namespace Drupal\data_surface;

/**
 * A structure mixed into its owner: its members are the owner's own keys.
 *
 * Attaching nests a structure under a key, in a frame of its own behind
 * the wall. Mixing it in merges its members into the owner instead:
 * stored flat, refined in the owner's frame, accepted, validated and
 * drawn as the owner's keys. What this carries is what only the contract
 * still needs to tell apart: which of the owner's keys the structure
 * brought, and the structure itself, built in its own frame for no
 * values, which the emitter writes once as a def and refers to from the
 * owner's `allOf`, so one def serves every owner it is mixed into and
 * every place it is attached.
 *
 * @see \Drupal\data_surface\Resource\BlueprintAdditionsInterface::with()
 * @see docs/resources.md#mixins
 */
final class Mixin {

  /**
   * Constructs a Mixin.
   *
   * @param \Drupal\data_surface\Attachment $structure
   *   The structure built in its own frame, by itself, with its source
   *   and id: what its def is written from.
   * @param string[] $keys
   *   The owner's keys it brought, in the owner's order, those of a
   *   structure it mixes in itself among them.
   * @param array<string, string> $reads
   *   Its ports, each wired to the owner's key it reads.
   */
  public function __construct(
    public readonly Attachment $structure,
    public readonly array $keys,
    public readonly array $reads = [],
  ) {
  }

}
