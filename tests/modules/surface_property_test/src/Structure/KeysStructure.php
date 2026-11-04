<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;

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

    $name = $inputs->add('name', 'string', t('Name'))
      ->setDescription(t('As printed on the badge.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64])
      ->addConstraint('Regex', ['pattern' => '/^\S/', 'message' => t('Start the name with a letter.')]);
    DefinitionMetadata::setExamples($name, ['Ada Lovelace']);
    $inputs->describe('name', group: $about, weight: 1, placeholder: t('Full name'), help: t('Shown under the photo.'));

    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => ['weekly' => t('Weekly'), 'monthly' => t('Monthly')]]);
    $inputs->describe('frequency', group: $about, weight: 2);

    $inputs->add('email', 'email', t('Email'));

    $inputs->add('id', 'machine_name', t('Handle'));

    $inputs->add('code', Code::class, t('Code'));

    $inputs->add('note', 'string', t('Note'))
      ->setSetting('multiline', TRUE);
    $inputs->describe('note', group: $more, prefix: t('Note:'), suffix: t('(optional)'));

    $inputs->add('day', 'string', t('Day'))
      ->setSetting('widget', 'date');

    $tags = $inputs->addList('tags', 'string', t('Tags'), default: [])
      ->addConstraint('Count', ['max' => 5]);
    $tags->getItemDefinition()->addConstraint('Length', ['max' => 10]);

    $inputs->add('count', 'integer', t('Count'), default: 3)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);

    $inputs->narrow('email')
      ->when(Condition::equals('frequency', 'monthly'))
      ->require()
      ->because(t('A monthly issue is sent by email.'));

    $inputs->narrow('id')
      ->when(Condition::present('note'))
      ->doesNotApply();

    $inputs->narrow('code')
      ->when(Condition::absent('email'))
      ->constrain('Length', ['max' => 4])
      ->because(t('Up to 4 letters without an email.'));

    $inputs->narrow('day')
      ->when(Condition::allOf(Condition::present('name'), Condition::in('frequency', ['weekly'])))
      ->require();
  }

}
