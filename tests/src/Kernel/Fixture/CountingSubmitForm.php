<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;

/**
 * The operation form, its pipeline counting what it is asked.
 */
final class CountingSubmitForm extends DataSurfaceOperationForm {

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
  protected function surfacePipeline(): DataSurfacePipelineInterface {
    return static::$pipeline ??= new CountingPipeline(parent::surfacePipeline());
  }

}
