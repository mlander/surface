<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;
use PortableSchema\Builder\Schema;

/**
 * Keys of one value, declared with calls: the twin of KeysPropertiesStructure.
 *
 * Required by inference and with a default, optional, a type the property
 * cannot say, a shape, repeated constraints, examples, a widget, a
 * setting, an arrangement in a fieldset and in a details element, a list
 * with an item constraint, and a rule of each condition and each effect.
 */
#[Structure('property_test.keys')]
final class KeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $about = ArrangementGroup::fieldset('about', t('About'));
    $more = ArrangementGroup::details('more', t('More'), collapsed: TRUE, weight: 5, description: t('Rarely needed.'));

    $inputs->addSchema('name', Schema::string()
      ->title(t('Name'))
      ->description(t('As printed on the badge.'))
      ->maxLength(64)
      ->check('drupal:Regex', ['pattern' => '/^\S/', 'message' => t('Start the name with a letter.')])
      ->examples(['Ada Lovelace'])
      ->required());
    $inputs->describe('name', group: $about, weight: 1, placeholder: t('Full name'), help: t('Shown under the photo.'));

    $inputs->addSchema('frequency', Schema::options(['weekly' => t('Weekly'), 'monthly' => t('Monthly')])
      ->title(t('Frequency'))
      ->default('weekly')
      ->required());
    $inputs->describe('frequency', group: $about, weight: 2);

    $inputs->addSchema('email', Schema::string()->format('email')
      ->title(t('Email')));

    $inputs->addSchema('id', Schema::of('drupal:config/machine_name')
      ->title(t('Handle')));

    $inputs->addSchema('code', Schema::of(Code::class)
      ->title(t('Code')));

    $inputs->addSchema('note', Schema::string()
      ->title(t('Note'))
      ->ui(widget: 'textarea'));
    $inputs->describe('note', group: $more, prefix: t('Note:'), suffix: t('(optional)'));

    $inputs->addSchema('day', Schema::string()
      ->title(t('Day'))
      ->ui(widget: 'date'));

    $inputs->addSchema('tags', Schema::list(Schema::string()->maxLength(10))
      ->title(t('Tags'))
      ->default([])
      ->maxItems(5));

    $inputs->addSchema('count', Schema::integer()
      ->title(t('Count'))
      ->default(3)
      ->minimum(1)
      ->maximum(10));

    $inputs->narrow('email')
      ->when(Condition::equals('frequency', 'monthly'))
      ->require()
      ->because(t('A monthly issue is sent by email.'));

    $inputs->narrow('id')
      ->when(Condition::present('note'))
      ->doesNotApply();

    $inputs->narrow('code')
      ->when(Condition::absent('email'))
      ->constrain(Schema::any()->maxLength(4))
      ->because(t('Up to 4 letters without an email.'));

    $inputs->narrow('day')
      ->when(Condition::allOf(Condition::present('name'), Condition::in('frequency', ['weekly'])))
      ->require();
  }

}
