<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Contract\UnknownChoiceException;
use Drupal\surface\SurfaceInterface;

/**
 * Narrows the served document for an edit in progress, in process.
 *
 * The discard and orphan rules, and the overlay they settle to, are the
 * form builder's (discardedRefinementInput(), refinementOverlay()); the
 * envelope is the one the form is drawn from (envelope()), or the HTTP
 * door's, which names its own URLs. A deferred key's chosen document is
 * the emitter's full schema with the choices, as the HTTP door's `GET
 * schema` emits it, under the form's document id.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
final class DocumentNarrowing implements NarrowsDocumentsInterface {

  /**
   * Constructs a DocumentNarrowing.
   *
   * @param \Drupal\surface\Form\SurfaceFormBuilderInterface $formBuilder
   *   The form builder, which holds the overlay and emits the envelope.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter, which writes a deferred key's chosen document.
   */
  public function __construct(
    protected readonly SurfaceFormBuilderInterface $formBuilder,
    protected readonly ContractEmitter $emitter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function narrow(SurfaceInterface $surface, array $stored, array $input, ?\Closure $emit = NULL): NarrowedDocument {
    $discarded = $this->formBuilder->discardedRefinementInput($surface, $stored, $input);
    $values = $this->formBuilder->refinementOverlay($surface, $stored, $input);
    return new NarrowedDocument($values, $discarded, $emit ?? fn (array $values) => $this->formBuilder->envelope($surface, $values));
  }

  /**
   * {@inheritdoc}
   */
  public function chosen(SurfaceInterface $surface, array $values, string $pointer, string|int|float|bool $value): ?NarrowedDocument {
    try {
      $pairs = Choices::along($surface, $pointer, $values);
      $pairs[] = [$pointer, (string) $value];
      $choices = Choices::resolve($surface, $pairs);
    }
    catch (UnknownChoiceException) {
      return NULL;
    }
    return new NarrowedDocument($values, [], fn (): ServedContract => $this->emitter->emitFull($surface, SurfaceFormBuilder::DOCUMENT_ID, widgets: TRUE, choices: $choices));
  }

}
