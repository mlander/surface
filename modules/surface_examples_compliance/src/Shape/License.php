<?php

declare(strict_types=1);

namespace Drupal\surface_examples_compliance\Shape;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * An event license: four digits, as printed after EV- on the license.
 *
 * A shape, so every key that asks for a license asks for the same thing,
 * in the same words, and the served contract writes it once.
 */
#[Shape('event.license')]
final class License implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Event license'))
      ->description(t('The four digits after EV- on the license, such as 2048. Required to host more than 100 people.'))
      ->pattern('^\d{4}$')
      ->message('pattern', t('A license number is four digits, such as 2048.'))
      ->examples(['2048']);
  }

}
