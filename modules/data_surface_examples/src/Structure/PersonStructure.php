<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_examples\Shape\PhoneNumber;

/**
 * Example 8: a person to contact, a structure two resources attach.
 *
 * It names neither of them. Whether the person is listed publicly is the
 * container's to say, so the structure reads it through a port, `listed`,
 * and its rule asks for an email while it holds: the press page wires the
 * port to its own `public`, the information desk to its `in_program`, and
 * the desk's volunteers, a list of persons, wire it to nothing.
 */
#[Structure('event.person')]
final class PersonStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('listed', 'boolean');

    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);

    $inputs->add('email', 'email', t('Email'));

    $inputs->add('phone', PhoneNumber::class, t('Phone'));

    $inputs->narrow('email')
      ->when(Condition::equals('listed', TRUE))
      ->require()
      ->because(t('Required while this person is listed publicly.'));
  }

}
