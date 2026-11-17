<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\OutputsDocument;
use Drupal\surface\Contract\PropertySchema;
use Drupal\surface\Pipeline\OutputsConformance;

/**
 * Makes the outputs' check through the dialect's validator, not yet wired.
 *
 * What the pipeline will hold once outputs conform through the validator:
 * the validation document written by the served document's writer of a
 * key, and the validator the pipeline holds.
 */
trait OutputsConformanceTrait {

  /**
   * Makes the check.
   *
   * @return \Drupal\surface\Pipeline\OutputsConformance
   *   The check.
   */
  protected function outputsConformance(): OutputsConformance {
    return new OutputsConformance(
      $this->container->get('surface.portable_validator'),
      $this->outputsDocument(),
      $this->container->get('surface.pipeline'),
      $this->container->get('string_translation'),
    );
  }

  /**
   * Makes the writer of the outputs' validation document.
   *
   * @return \Drupal\surface\Contract\OutputsDocument
   *   The writer.
   */
  protected function outputsDocument(): OutputsDocument {
    $options = $this->container->get('surface.options');
    $constraints = $this->container->get('validation.constraint');
    return new OutputsDocument(new PropertySchema($options, $this->container->get('string_translation'), $constraints, NULL, new DefinitionSchema($options, $constraints)));
  }

}
