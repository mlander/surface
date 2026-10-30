<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

/**
 * One difference between a contract baseline and today's full schema.
 *
 * What changed, where, whose version answers for it, and the verdict:
 * compatible, or breaking and then versioned, allowed or neither.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 *
 * @see \Drupal\surface\Contract\ContractDiff
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
   * Declared with no baseline yet: the lint fails until one is written.
   *
   * Not a breaking change, so writing the baselines pins it with no
   * allowance; but a lint that passed it could not tell a declaration
   * nobody pinned, or a directory mistyped, from one that kept its
   * promise.
   */
  public const UNPINNED = 'unpinned';

  /**
   * The baseline was written in another version of the contract's format.
   *
   * Not a breaking change of the resource: the wire every document is
   * written in moved (`x-surface.contract`), so every keyword may be
   * spelled anew and none is compared. The lint fails until writing the
   * baselines re-pins them in today's version, which needs no allowance.
   * Decision: see docs/decisions.md#contract-2.
   */
  public const CONTRACT = 'contract';

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
   *   self::COMPATIBLE, self::VERSIONED, self::ALLOWED, self::BREAKING,
   *   self::UNPINNED or self::CONTRACT.
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
    return !in_array($this->status, [self::COMPATIBLE, self::UNPINNED, self::CONTRACT], TRUE);
  }

  /**
   * Answers whether the lint fails on it.
   *
   * @return bool
   *   TRUE for a breaking change no version and no allowance answers for,
   *   for a declaration with no baseline, and for a baseline written in
   *   another version of the contract.
   */
  public function fails(): bool {
    return in_array($this->status, [self::BREAKING, self::UNPINNED, self::CONTRACT], TRUE);
  }

  /**
   * Answers whether writing the baselines needs a reason to accept it.
   *
   * @return bool
   *   TRUE for a breaking change no version and no allowance answers for;
   *   a declaration with no baseline, and a baseline of another contract
   *   version, are pinned by writing them.
   */
  public function needsAllowance(): bool {
    return $this->status === self::BREAKING;
  }

}
