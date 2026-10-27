<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_compliance\Structure;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_examples_compliance\Shape\License;

/**
 * What compliance asks of any event: a license and enough stewards.
 *
 * A structure, so any resource can attach it, and an alter can attach it
 * at its own mount, as RegistrationComplianceAlter does on example 3. It
 * names no key of any of them: the capacity it needs it reads through a
 * port, under its own name, which whoever attaches it wires to a key of
 * its own. The license is a shape, License, declared once.
 */
#[Structure('registration.compliance')]
final class ComplianceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('capacity', 'integer', required: TRUE);

    $inputs->add('license', License::class, t('Event license'));

    $inputs->add('stewards', 'integer', t('Stewards'), default: 1)->setRequired(TRUE);
  }

  /**
   * One steward for every fifty people, at least one.
   */
  #[RefinesInput('stewards')]
  public static function stewardsForCapacity(DataDefinition $stewards, int $capacity): DataDefinition {
    $n = max(1, (int) ceil($capacity / 50));
    return $stewards->addConstraint('Range', ['min' => $n])
      ->setDescription(new PluralTranslatableMarkup(
        $n,
        'At least 1 steward for @capacity attendees.',
        'At least @count stewards for @capacity attendees.',
        ['@capacity' => $capacity],
      ));
  }

}
