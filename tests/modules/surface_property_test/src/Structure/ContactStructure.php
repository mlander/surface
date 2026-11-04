<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A contact reading two ports, with calls: ContactPropertiesStructure's twin.
 *
 * One port typed and optional, one of any type and required.
 */
#[Structure('property_test.contact')]
final class ContactStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('listed', 'boolean');

    $inputs->reads('anything', 'any', required: TRUE);

    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);

    $inputs->add('email', 'email', t('Email'));

    $inputs->narrow('email')
      ->when(Condition::equals('listed', TRUE))
      ->require()
      ->because(t('Required while the contact is listed.'));
  }

}
