<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Parcel;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\ParcelStorage;
use PortableSchema\Builder\Schema;

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
    $inputs->addSchema('service', Schema::options([
      'standard' => t('Standard'),
      'express' => t('Express'),
      'freight' => t('Freight'),
    ])
      ->title(t('Service'))
      ->default('standard')
      ->required());
    $inputs->addSchema('weight', Schema::integer()
      ->title(t('Weight in kilograms'))
      ->default(1)
      ->minimum(1)
      ->maximum(1000)
      ->required());
    $inputs->addSchema('insured', Schema::bool()
      ->title(t('Insured'))
      ->default(FALSE));
    $inputs->addSchema('declared_value', Schema::integer()
      ->title(t('Declared value'))
      ->minimum(0)
      ->maximum(100000));
    $inputs->addSchema('reference', Schema::string()
      ->title(t('Reference'))
      ->maxLength(32));
    $inputs->addSchema('signature', Schema::string()
      ->title(t('Signed for by'))
      ->maxLength(64));
    $inputs->addSchema('tracking', Schema::string()
      ->title(t('Tracking code')));
    $inputs->addSchema('handling', Schema::options([
      'fragile' => t('Fragile'),
      'upright' => t('This way up'),
      'cold' => t('Keep cold'),
    ])
      ->title(t('Handling'))
      ->ui(widget: 'radios'));
    $inputs->addSchema('labels', Schema::list(Schema::options([
      'dry' => t('Keep dry'),
      'heavy' => t('Heavy'),
      'perishable' => t('Perishable'),
    ]))
      ->title(t('Labels'))
      ->maxItems(3));
    $inputs->addSchema('note', Schema::string()
      ->title(t('Note for the driver'))
      ->required());

    // An insured parcel is signed for, by someone named.
    $inputs->narrow('signature')
      ->when(Condition::equals('insured', TRUE))
      ->require()
      ->because(t('An insured parcel is signed for.'));
    // Standard and express carry no cold parcel.
    $inputs->narrow('handling')
      ->when(Condition::in('service', ['standard', 'express']))
      ->constrain(Schema::enum(['fragile', 'upright']));
    // From 30 kilograms a parcel goes by freight.
    $inputs->narrow('service')
      ->when(Condition::atLeast('weight', 30))
      ->constrain(Schema::enum(['freight']));
    // Up to 5 kilograms, one label fits.
    $inputs->narrow('labels')
      ->when(Condition::atMost('weight', 5))
      ->constrain(Schema::any()->maxItems(1));
    // Under 2 kilograms, a small declared value.
    $inputs->narrow('declared_value')
      ->when(Condition::below('weight', 2))
      ->constrain(Schema::any()->maximum(500))
      ->because(t('Up to 500 for a parcel under 2 kilograms.'));
    // With no reference, a value no higher than 5000.
    $inputs->narrow('declared_value')
      ->when(Condition::absent('reference'))
      ->constrain(Schema::any()->maximum(5000));
    // Insured, no higher than 2000: said only where it lowers the value,
    // so a light parcel keeps its own words.
    $inputs->narrow('declared_value')
      ->when(Condition::equals('insured', TRUE))
      ->constrain(Schema::any()->maximum(2000))
      ->because(t('Up to 2000 for an insured parcel.'));
    // Above 1000, a signature is a name, not an initial.
    $inputs->narrow('signature')
      ->when(Condition::above('declared_value', 1000))
      ->constrain(Schema::any()->minLength(2));
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
      ->constrain(Schema::any()->check('drupal:Regex', [
        'pattern' => '/^[A-Z]{2}\d{6}$/D',
        'message' => 'A tracking code is two capitals and six digits, such as AB123456.',
      ]));
  }

}
