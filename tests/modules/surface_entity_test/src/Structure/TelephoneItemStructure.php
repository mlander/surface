<?php

declare(strict_types=1);

namespace Drupal\surface_entity_test\Structure;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface_examples_entity\Structure\Attribute\FieldItemStructure;
use Drupal\surface_examples_entity\Structure\FieldItemStructureInterface;

/**
 * A telephone field's item structure, written by hand.
 */
#[FieldItemStructure(type: 'telephone')]
final class TelephoneItemStructure implements FieldItemStructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineItem(BlueprintInterface $inputs, FieldDefinitionInterface $field): void {
    $inputs->add('value', 'string', $field->getLabel())
      ->setDescription(t('Digits, spaces and a leading plus.'))
      ->addConstraint('Regex', [
        'pattern' => '/^\+?[0-9 ]+$/D',
        'message' => t('Use digits, spaces and a leading plus.'),
      ]);
  }

  /**
   * A number is at most twenty characters, said by a refiner of its own.
   */
  #[RefinesInput('value')]
  public static function shortNumbers(DataDefinitionInterface $value): DataDefinitionInterface {
    return $value->addConstraint('Length', ['max' => 20]);
  }

}
