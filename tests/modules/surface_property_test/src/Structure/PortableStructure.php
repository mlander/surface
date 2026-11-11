<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;

/**
 * Keys declared with calls: PortablePropertiesStructure's twin.
 *
 * The twin spells each key with the Portable Schema package's attributes.
 */
#[Structure('property_test.portable')]
final class PortableStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $about = ArrangementGroup::fieldset('about', t('About'));

    $inputs->add('name', 'string', t('Name'))
      ->setDescription(t('As printed on the badge.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);
    $inputs->describe('name', group: $about, weight: 1, placeholder: t('Full name'));

    $inputs->add('email', 'email', t('Email'))
      ->addConstraint('Length', ['max' => 254]);

    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => ['weekly' => t('Weekly'), 'monthly' => t('Monthly')]]);
    $inputs->describe('frequency', group: $about);

    $inputs->add('code', Code::class, t('Code'));

    $inputs->add('module', 'string', t('Module'))
      ->addConstraint('ExtensionExists', ['type' => 'module']);

    $inputs->add('note', 'string', t('Note'))
      ->setSetting('multiline', TRUE);

    $tags = $inputs->addList('tags', 'string', t('Tags'), default: [])
      ->addConstraint('Count', ['max' => 5]);
    $tags->getItemDefinition()->addConstraint('Length', ['max' => 10]);

    $inputs->add('count', 'integer', t('Count'), default: 3)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);
  }

}
