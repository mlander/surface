<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Shape;

use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;
use PortableSchema\Builder\Schema;

/**
 * Example 8: a phone number, declared once and asked for at two keys.
 *
 * The person structure asks for one, and so does the information desk,
 * each with this pattern, its message and its example; both documents
 * write it once, as the def `event.phone`.
 */
#[Shape('event.phone')]
final class PhoneNumber implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function schema(): Schema {
    return Schema::string()
      ->title(t('Phone'))
      ->description(t('Digits, with spaces if you like, and a leading + for a country code.'))
      ->pattern('^\+?[0-9 ]{7,20}$')
      ->message('pattern', t('Write the number in digits, such as +1 555 0100.'))
      ->examples(['+1 555 0100']);
  }

}
