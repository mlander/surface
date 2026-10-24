<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;

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
 * @see \Drupal\Tests\data_surface\Traits\DoorAgreementTrait::formDoor()
 */
final class DoorAgreementForm extends DataSurfaceOperationForm {

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
   * @return array{0: \Drupal\data_surface\Pipeline\DataSurfaceResult, 1: array}|null
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
  protected function storedSurfaceValues(DataSurfaceInterface $surface, DataSurfaceStorageInterface $storage): array {
    $stored = parent::storedSurfaceValues($surface, $storage);
    return $this->drawing && $this->drawn !== NULL ? array_intersect_key($this->drawn, $surface->getDefinitions()->toArray()) : $stored;
  }

  /**
   * {@inheritdoc}
   */
  protected function surfacePipeline(): DataSurfacePipelineInterface {
    return $this->dry ??= new DryRunPipeline(parent::surfacePipeline());
  }

}
