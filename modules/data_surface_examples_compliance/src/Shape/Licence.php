<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_compliance\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * An event licence: four digits, as printed after EV- on the licence.
 *
 * A shape, so every key that asks for a licence asks for the same thing,
 * in the same words, and the served contract writes it once.
 */
#[Shape('event.licence')]
final class Licence implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^\d{4}$/D', 'message' => 'A licence number is four digits, such as 2048.'];
    $licence = DataDefinition::create('string')
      ->setLabel(t('Event licence'))
      ->setDescription(t('The four digits after EV- on the licence, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($licence, ['2048']);
    return $licence;
  }

}
