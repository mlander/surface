<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\CateringStorage;
use Drupal\surface_examples\Structure\PersonStructure;
use PortableSchema\Builder\Schema;

/**
 * Example 18: the same keys as CateringResource, declared with calls.
 *
 * Written as examples 1 to 17 write their keys, so the two can be read
 * side by side; both save to the same config object.
 */
#[Resource('event.catering.method', storage: CateringStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the catering'),
  description: new TranslatableMarkup('Sets who caters the event, for how many, the budget, the dietary needs, when food is served and who to contact.'),
  permission: 'administer site configuration',
)]
final class CateringByMethodResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $details = ArrangementGroup::fieldset('details', t('Details'));

    $inputs->addSchema('public', Schema::bool()
      ->title(t('Show the caterer on the event page'))
      ->default(FALSE));

    $inputs->addSchema('provider', Schema::string()
      ->title(t('Caterer'))
      ->maxLength(128)
      ->examples(['Harbour Kitchen'])
      ->required());
    $inputs->describe('provider', placeholder: t('The name on the contract'));

    $inputs->addSchema('headcount', Schema::integer()
      ->title(t('Headcount'))
      ->description(t('How many people to cater for.'))
      ->minimum(1)
      ->maximum(5000)
      ->required());

    $inputs->addSchema('budget', Schema::number()
      ->title(t('Budget'))
      ->minimum(0));
    $inputs->describe('budget', group: $details, suffix: t('EUR'));

    $dietary = $inputs->addList('dietary', 'string', t('Dietary needs'), default: []);
    $dietary->getItemDefinition()->addConstraint('LabeledChoice', [
      'choices' => [
        'vegetarian' => t('Vegetarian'),
        'vegan' => t('Vegan'),
        'gluten_free' => t('Gluten free'),
        'halal' => t('Halal'),
        'kosher' => t('Kosher'),
      ],
    ]);

    $inputs->addSchema('served_at', Schema::string()
      ->title(t('Served at'))
      ->description(t('HH:MM, on a 24-hour clock.'))
      ->check('drupal:Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 18:30.'),
      ])
      ->ui(widget: 'time'));

    $inputs->addSchema('notes', Schema::string()
      ->title(t('Notes for the caterer'))
      ->ui(widget: 'textarea'));
    $inputs->describe('notes', group: $details);

    $inputs->attach('contact', PersonStructure::class, t('Caterer contact'), reads: ['listed' => 'public']);

    $inputs->narrow('served_at')
      ->when(Condition::equals('public', TRUE))
      ->require()
      ->because(t('Say when food is served while the caterer is shown.'));
  }

}
