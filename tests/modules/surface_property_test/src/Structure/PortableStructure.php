<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;
use PortableSchema\Builder\Schema;

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

    $inputs->addSchema('name', Schema::string()
      ->title(t('Name'))
      ->description(t('As printed on the badge.'))
      ->maxLength(64)
      ->required());
    $inputs->describe('name', group: $about, weight: 1, placeholder: t('Full name'));

    $inputs->addSchema('email', Schema::string()->format('email')
      ->title(t('Email'))
      ->maxLength(254));

    $inputs->addSchema('frequency', Schema::options(['weekly' => t('Weekly'), 'monthly' => t('Monthly')])
      ->title(t('Frequency'))
      ->default('weekly')
      ->required());
    $inputs->describe('frequency', group: $about);

    $inputs->addSchema('code', Schema::of(Code::class)
      ->title(t('Code')));

    $inputs->addSchema('module', Schema::string()
      ->title(t('Module'))
      ->check('drupal:ExtensionExists', ['type' => 'module']));

    $inputs->addSchema('note', Schema::string()
      ->title(t('Note'))
      ->ui(widget: 'textarea'));

    $inputs->addSchema('tags', Schema::list(Schema::string()->maxLength(10))
      ->title(t('Tags'))
      ->default([])
      ->maxItems(5));

    $inputs->addSchema('count', Schema::integer()
      ->title(t('Count'))
      ->default(3)
      ->minimum(1)
      ->maximum(10));
  }

}
