<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\Core\Form\FormStateInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;

/**
 * The operation form, drawn with a document and submitted dry.
 *
 * The form door as a kernel test drives it: the operation's own form,
 * built from its route, draws the document in place of what is stored
 * (what a person editing the form to those values would leave in it),
 * is posted as drawn, and extracts, validates and submits as it always
 * does; the submit is a dry run and its result is kept, so nothing is
 * written. Everything else is the form's own, its element naming
 * included.
 *
 * @see \Drupal\Tests\surface\Traits\DoorAgreementTrait::formDoor()
 */
final class DoorAgreementForm extends SurfaceOperationForm {

  /**
   * The document the form draws, or NULL for what is stored.
   */
  protected ?array $drawn = NULL;

  /**
   * Whether the form is being built, when the document is what it draws.
   */
  protected bool $drawing = FALSE;

  /**
   * The pipeline the form submits through, dry.
   */
  protected ?DryRunPipeline $dry = NULL;

  /**
   * Sets the document the form draws.
   *
   * @param array $document
   *   The accepted document, keyed by resource key.
   *
   * @return $this
   *   The form.
   */
  public function drawing(array $document): static {
    $this->drawn = $document;
    return $this;
  }

  /**
   * Gets the last submit's result and the document it answers.
   *
   * @return array{0: \Drupal\surface\Pipeline\SurfaceResult, 1: array}|null
   *   NULL when the form never submitted: it refused at validation.
   */
  public function submitted(): ?array {
    return $this->dry?->last;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->drawing = TRUE;
    try {
      return parent::buildForm($form, $form_state);
    }
    finally {
      $this->drawing = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * The document, while the form is drawn; what is stored, after, as the
   * form extracts against it.
   */
  protected function storedSurfaceValues(SurfaceInterface $surface, SurfaceStorageInterface $storage): array {
    $stored = parent::storedSurfaceValues($surface, $storage);
    return $this->drawing && $this->drawn !== NULL ? array_intersect_key($this->drawn, $surface->getDefinitions()->toArray()) : $stored;
  }

  /**
   * {@inheritdoc}
   */
  protected function surfacePipeline(): SurfacePipelineInterface {
    return $this->dry ??= new DryRunPipeline(parent::surfacePipeline());
  }

}
