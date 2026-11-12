<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\surface\Contract\ServedContract;

/**
 * A document narrowed for an edit in progress.
 *
 * The values the edit stands at (the refinement overlay), the dotted path
 * of each answer it dropped, and the envelope for those values, emitted
 * once, the first time it is asked for: a form's rebuild reads the values
 * and draws the envelope its builder emits, the HTTP door serves it.
 *
 * @see \Drupal\surface\Form\NarrowsDocumentsInterface
 */
final class NarrowedDocument {

  /**
   * The envelope, once emitted.
   */
  protected ?ServedContract $contract = NULL;

  /**
   * Constructs a NarrowedDocument.
   *
   * @param array $values
   *   The values the edit stands at, as buildSurfaceForm() takes them.
   * @param string[] $discarded
   *   The keys, or dotted paths into a child, whose input was dropped.
   * @param \Closure $emit
   *   Emits the envelope for the values.
   */
  public function __construct(
    public readonly array $values,
    public readonly array $discarded,
    protected readonly \Closure $emit,
  ) {}

  /**
   * Gets the envelope for the values, emitted once.
   *
   * @return \Drupal\surface\Contract\ServedContract
   *   The envelope.
   */
  public function contract(): ServedContract {
    return $this->contract ??= ($this->emit)($this->values);
  }

}
