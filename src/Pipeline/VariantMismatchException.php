<?php

declare(strict_types=1);

namespace Drupal\data_surface\Pipeline;

/**
 * Thrown when a slot's value is shaped for a variant nobody chose.
 *
 * The value carries keys the chosen variant does not declare and another
 * variant does, so it was written for a different answer to the deciding
 * key. Refused rather than read: taking the keys the chosen variant
 * knows and dropping the rest would store a value the caller never
 * meant. The pipeline turns it into one violation per misplaced key,
 * each on its own path inside the slot, naming the variant the key
 * belongs to.
 */
final class VariantMismatchException extends \InvalidArgumentException {

  /**
   * Constructs a VariantMismatchException.
   *
   * @param string $path
   *   The dotted path of the slot.
   * @param string $by
   *   The deciding key.
   * @param string $chosen
   *   The variant it chose.
   * @param array<string, string[]> $owners
   *   The misplaced keys, each with the variants that do declare it.
   */
  public function __construct(
    protected readonly string $path,
    protected readonly string $by,
    protected readonly string $chosen,
    protected readonly array $owners,
  ) {
    parent::__construct(sprintf(
      'The value at %s is shaped for another variant than "%s", which %s chose: %s.',
      $path,
      $chosen,
      $by,
      implode(', ', array_keys($owners)),
    ));
  }

  /**
   * Gets the dotted path of the slot.
   *
   * @return string
   *   The path.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the deciding key.
   *
   * @return string
   *   The key.
   */
  public function getBy(): string {
    return $this->by;
  }

  /**
   * Gets the variant the deciding key chose.
   *
   * @return string
   *   The variant id.
   */
  public function getChosen(): string {
    return $this->chosen;
  }

  /**
   * Gets the misplaced keys and the variants that declare each.
   *
   * @return array<string, string[]>
   *   The variant ids, keyed by misplaced key.
   */
  public function getOwners(): array {
    return $this->owners;
  }

}
