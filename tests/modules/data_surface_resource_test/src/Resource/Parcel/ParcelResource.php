<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Parcel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\ParcelStorage;

/**
 * A parcel to send, whose every rule between keys is declared as data.
 *
 * Each word of the vocabulary at least once: every condition (present,
 * absent, equals, in, the four bounds, not, allOf, anyOf) and every
 * effect (Range, Length, Count, a subset of a list, a pattern, required,
 * does not apply, a description). ParcelAlter adds a customs code with
 * rules of its own, on its own key and on the owner's. The handling is
 * drawn as radios, which it asks for, with a "- None -" radio, since it
 * is optional and may be cleared.
 *
 * @see docs/refinement.md#declaring-a-rule
 */
#[Resource('resource_test.parcel', storage: ParcelStorage::class)]
final class ParcelResource implements ResourceInterface {

  /**
   * Configures the parcel, whose settings always exist.
   */
  #[Operation('configure', label: 'Configure the parcel', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('service', 'string', t('Service'), default: 'standard')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'standard' => t('Standard'),
          'express' => t('Express'),
          'freight' => t('Freight'),
        ],
      ]);
    $inputs->add('weight', 'integer', t('Weight in kilograms'), default: 1)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('insured', 'boolean', t('Insured'), default: FALSE);
    $inputs->add('declared_value', 'integer', t('Declared value'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100000]);
    $inputs->add('reference', 'string', t('Reference'))
      ->addConstraint('Length', ['max' => 32]);
    $inputs->add('signature', 'string', t('Signed for by'))
      ->addConstraint('Length', ['max' => 64]);
    $inputs->add('tracking', 'string', t('Tracking code'));
    $inputs->add('handling', 'string', t('Handling'))
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'fragile' => t('Fragile'),
          'upright' => t('This way up'),
          'cold' => t('Keep cold'),
        ],
      ]);
    $label = DataDefinition::create('string')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'dry' => t('Keep dry'),
          'heavy' => t('Heavy'),
          'perishable' => t('Perishable'),
        ],
      ]);
    $inputs->addList('labels', $label, t('Labels'))
      ->addConstraint('Count', ['max' => 3]);
    $inputs->add('note', 'string', t('Note for the driver'))
      ->setRequired(TRUE);

    // An insured parcel is signed for, by someone named.
    $inputs->narrow('signature')
      ->when(Condition::equals('insured', TRUE))
      ->require()
      ->because(t('An insured parcel is signed for.'));
    // Standard and express carry no cold parcel.
    $inputs->narrow('handling')
      ->when(Condition::in('service', ['standard', 'express']))
      ->constrain('LabeledChoice', ['choices' => ['fragile', 'upright']]);
    // From 30 kilograms a parcel goes by freight.
    $inputs->narrow('service')
      ->when(Condition::atLeast('weight', 30))
      ->constrain('Choice', ['choices' => ['freight']]);
    // Up to 5 kilograms, one label fits.
    $inputs->narrow('labels')
      ->when(Condition::atMost('weight', 5))
      ->constrain('Count', ['max' => 1]);
    // Under 2 kilograms, a small declared value.
    $inputs->narrow('declared_value')
      ->when(Condition::below('weight', 2))
      ->constrain('Range', ['max' => 500])
      ->because(t('Up to 500 for a parcel under 2 kilograms.'));
    // With no reference, a value no higher than 5000.
    $inputs->narrow('declared_value')
      ->when(Condition::absent('reference'))
      ->constrain('Range', ['max' => 5000]);
    // Insured, no higher than 2000: said only where it lowers the value,
    // so a light parcel keeps its own words.
    $inputs->narrow('declared_value')
      ->when(Condition::equals('insured', TRUE))
      ->constrain('Range', ['max' => 2000])
      ->because(t('Up to 2000 for an insured parcel.'));
    // Above 1000, a signature is a name, not an initial.
    $inputs->narrow('signature')
      ->when(Condition::above('declared_value', 1000))
      ->constrain('Length', ['min' => 2]);
    // A note is for the freight driver alone.
    $inputs->narrow('note')
      ->unless(Condition::equals('service', 'freight'))
      ->doesNotApply();
    // A referenced express or insured parcel is tracked by a code.
    $inputs->narrow('tracking')
      ->when(Condition::allOf(
        Condition::present('reference'),
        Condition::anyOf(Condition::equals('service', 'express'), Condition::equals('insured', TRUE)),
      ))
      ->constrain('Regex', [
        'pattern' => '/^[A-Z]{2}\d{6}$/D',
        'message' => 'A tracking code is two capitals and six digits, such as AB123456.',
      ]);
  }

}
