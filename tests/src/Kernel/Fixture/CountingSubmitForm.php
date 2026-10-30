<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Fixture;

use Drupal\Core\Form\FormStateInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Pipeline\SurfacePipelineInterface;

/**
 * The operation form, its pipeline counting what it is asked.
 */
final class CountingSubmitForm extends SurfaceOperationForm {

  /**
   * The pipeline every instance of the form shares in a test.
   */
  public static ?CountingPipeline $pipeline = NULL;

  /**
   * What runs between validation and the submit, if anything.
   *
   * @var (\Closure(): void)|null
   */
  public static ?\Closure $between = NULL;

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (static::$between !== NULL) {
      (static::$between)();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function surfacePipeline(): SurfacePipelineInterface {
    return static::$pipeline ??= new CountingPipeline(parent::surfacePipeline());
  }

}
