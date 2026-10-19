<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * One difference between a contract baseline and today's full schema.
 *
 * What changed, where, whose version answers for it, and the verdict:
 * compatible, or breaking and then versioned, allowed or neither.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 *
 * @see \Drupal\data_surface\Contract\ContractDiff
 */
final class ContractChange {

  /**
   * Every document the baseline accepted, the schema still accepts.
   */
  public const COMPATIBLE = 'compatible';

  /**
   * Breaking, and the owner's version was raised for it.
   */
  public const VERSIONED = 'versioned';

  /**
   * Breaking, and the baseline records an allowance for its path.
   */
  public const ALLOWED = 'allowed';

  /**
   * Breaking, with no version raised and no allowance: the lint fails.
   */
  public const BREAKING = 'breaking';

  /**
   * Constructs a ContractChange.
   *
   * @param string $surface
   *   The resource or structure whose document it was found in, and whose
   *   baseline records an allowance for it.
   * @param string $owner
   *   The id whose version answers for it: the document's resource or
   *   structure, or the def the path is inside.
   * @param string $path
   *   Where: the operation id, `#`, and a JSON pointer into its document's
   *   schema (`configure#/properties/capacity/maximum`), or `outputs`
   *   after the `#` for the output schema. What an allowance names.
   * @param string $change
   *   What changed, in a phrase.
   * @param string $effect
   *   One of the ContractDiff effects: what the change does to the
   *   documents the schema accepts.
   * @param string $status
   *   self::COMPATIBLE, self::VERSIONED, self::ALLOWED or self::BREAKING.
   */
  public function __construct(
    public readonly string $surface,
    public readonly string $owner,
    public readonly string $path,
    public readonly string $change,
    public readonly string $effect,
    public readonly string $status,
  ) {}

  /**
   * Answers whether the change breaks the promise the baseline made.
   *
   * @return bool
   *   TRUE for a breaking change, versioned, allowed or not.
   */
  public function isBreaking(): bool {
    return $this->status !== self::COMPATIBLE;
  }

  /**
   * Answers whether the lint fails on it.
   *
   * @return bool
   *   TRUE for a breaking change no version and no allowance answers for.
   */
  public function fails(): bool {
    return $this->status === self::BREAKING;
  }

}
