<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Options;

use Symfony\Component\Validator\Constraint;

/**
 * "This value is one of the values in that source."
 *
 * Used as: ->addConstraint('OptionsList', ['source' => CountryOptions::class])
 *
 * The validator, the form's select, and the schema's enum all ask the
 * same source, after the same alters. That behavior lives below the
 * pattern and is not sketched here.
 */
final class OptionsListConstraint extends Constraint {

  /**
   * The source class.
   *
   * @var class-string<\Drupal\surface_sketch\Options\OptionsSourceInterface>
   */
  public string $source;

  /**
   * Passed to the source, fully known when the constraint is written.
   * A list that depends on a sibling's value is refinement: attach the
   * constraint in refine(), with the value as an argument. refine()
   * points; the source fetches. That is how refine() stays free of
   * services.
   *
   * @var array<string, mixed>
   */
  public array $arguments = [];

  public string $message = 'The value %value is not one of the allowed options.';

}
