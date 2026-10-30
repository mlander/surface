<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Shape;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\ShapeInterface;

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
  public static function define(): DataDefinition {
    $phone = DataDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setDescription(t('Digits, with spaces if you like, and a leading + for a country code.'))
      ->addConstraint('Regex', [
        'pattern' => '/^\+?[0-9 ]{7,20}$/D',
        'message' => t('Write the number in digits, such as +1 555 0100.'),
      ]);
    DefinitionMetadata::setExamples($phone, ['+1 555 0100']);
    return $phone;
  }

}
