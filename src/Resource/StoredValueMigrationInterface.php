<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * Converts values stored at one version of a resource or structure forward.
 *
 * Designed, not run yet: discovery refuses a `version` above 1 until the
 * runner is built (ROADMAP.md, Phase D item 13), so nothing calls this.
 * It is here so the recipe in docs/evolution.md names a real interface.
 *
 * A resource or structure that raised its `version` implements it, on the
 * class that declares the version, since a structure is stored by every
 * container that holds it and only its own declaration knows its keys.
 * The pipeline will call it on what storage loads, before anything reads
 * it, once per step from the version the destination's ledger records to
 * the one declared, in order; storage stamps the declared version at
 * commit. Only up-transforms are required: going back is a restore, and a
 * value restored or imported from an older export carries its older
 * stamp, so it is converted forward again when read.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 */
interface StoredValueMigrationInterface {

  /**
   * Converts a stored value from one version to the next.
   *
   * A pure function, as a static refiner is: no services, no site state,
   * the same answer for the same value every time, so a read that
   * converts and does not write changes nothing, and the next read
   * converts again to the same thing.
   *
   * @param array $stored
   *   What is stored for this class's own frame, in input layout, as the
   *   accepted document of version $from has it: a structure's map, a
   *   resource's own keys; never a child's (each child converts its own),
   *   and never another module's mount.
   * @param int $from
   *   The version it is stored at; the answer is at $from + 1.
   *
   * @return array
   *   The value at $from + 1.
   */
  public static function migrate(array $stored, int $from): array;

}
