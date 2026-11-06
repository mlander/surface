<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\CateringStorage;
use Drupal\surface_examples\Structure\PersonStructure;

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

    $inputs->add('public', 'boolean', t('Show the caterer on the event page'), default: FALSE);

    $provider = $inputs->add('provider', 'string', t('Caterer'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);
    DefinitionMetadata::setExamples($provider, ['Harbour Kitchen']);
    $inputs->describe('provider', placeholder: t('The name on the contract'));

    $inputs->add('headcount', 'integer', t('Headcount'))
      ->setDescription(t('How many people to cater for.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 5000]);

    $inputs->add('budget', 'float', t('Budget'))
      ->addConstraint('Range', ['min' => 0]);
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

    $inputs->add('served_at', 'string', t('Served at'))
      ->setDescription(t('HH:MM, on a 24-hour clock.'))
      ->setSetting('widget', 'time')
      ->addConstraint('Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => t('Write the time as HH:MM, such as 18:30.'),
      ]);

    $inputs->add('notes', 'string', t('Notes for the caterer'))
      ->setSetting('multiline', TRUE);
    $inputs->describe('notes', group: $details);

    $inputs->attach('contact', PersonStructure::class, t('Caterer contact'), reads: ['listed' => 'public']);

    $inputs->narrow('served_at')
      ->when(Condition::equals('public', TRUE))
      ->require()
      ->because(t('Say when food is served while the caterer is shown.'));
  }

}
