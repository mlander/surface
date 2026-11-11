<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\surface\SurfaceInterface;

/**
 * Narrows the served document for an edit in progress, in process.
 *
 * The one function behind both a form's rebuild and the HTTP door's
 * `POST schema`: the in-progress input laid over what is stored, the
 * answers the edit orphaned dropped, the keys it orphaned held unanswered
 * and shown stale, a computed key computed (refinementOverlay()), and the
 * envelope emitted for those values. A form draws each rebuild from that
 * envelope, as the React app draws each narrowing it asks for. Beside it,
 * the document a deferred key's `choose` link serves for one value
 * (chosen()), for the place the envelope leaves it unresolved: a variant
 * inside a collection's item, whose items' schema every item shares.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
interface NarrowsDocumentsInterface {

  /**
   * Narrows a data surface's document for an edit in progress.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, as built in its context.
   * @param array $stored
   *   What is stored for its keys, over the defaults.
   * @param array $input
   *   The in-progress input, by key.
   * @param \Closure|null $emit
   *   How the envelope is emitted for the values, `fn (array $values):
   *   ServedContract`: the HTTP door's, with its URLs; NULL for the form's
   *   (SurfaceFormBuilderInterface::envelope()).
   *
   * @return \Drupal\surface\Form\NarrowedDocument
   *   The values the edit stands at, what it dropped, and the envelope.
   */
  public function narrow(SurfaceInterface $surface, array $stored, array $input, ?\Closure $emit = NULL): NarrowedDocument;

  /**
   * Gives the document a deferred key's `choose` link serves for a value.
   *
   * The full schema with the choice of that value at that place, and at
   * each key with variants on the way the choice its deciding key holds
   * in the values (Choices::along()), emitted as the link's route emits
   * it, with the widget hints, in process: no HTTP. A pointer below a
   * collection names any item by its index; every item is one structure.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, as built in its context.
   * @param array $values
   *   The values the envelope was emitted for, which the keys on the way
   *   choose by.
   * @param string $pointer
   *   The deferred key's JSON Pointer.
   * @param string|int|float|bool $value
   *   What its deciding key holds.
   *
   * @return \Drupal\surface\Form\NarrowedDocument|null
   *   The values and that document, or NULL where no key at the pointer
   *   takes that choice.
   */
  public function chosen(SurfaceInterface $surface, array $values, string $pointer, string|int|float|bool $value): ?NarrowedDocument;

}
