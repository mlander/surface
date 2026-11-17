<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Keys declared with addSchema(), for AddSchemaTest.
 */
#[Structure('property_test.schema_keys')]
final class SchemaKeysStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('name', Schema::string()->title(t('Name'))->maxLength(20)->required());
    $inputs->addSchema('size', Schema::integer()->title(t('Size'))->default(10));
    $inputs->addSchema('kept', Schema::integer()->title(t('Kept'))->default(10)->required());
    $inputs->addSchema('loose', Schema::string()->title(t('Loose')));
    $inputs->addSchema('tags', Schema::list(Schema::string()->maxLength(8))->title(t('Tags'))->maxItems(3));
    $inputs->addSchema('where', Schema::object()
      ->title(t('Where'))
      ->property('city', Schema::string()->title(t('City'))->required())
      ->property('zip', Schema::string()->title(t('Zip')))
      ->with('additionalProperties', FALSE));
    $inputs->addSchema('contact', Schema::of('#/$defs/address')
      ->title(t('Contact'))
      ->def('address', Schema::string()->format('email')));
    $inputs->addSchema('module', Schema::string()
      ->title(t('Module'))
      ->check('drupal:ExtensionExists', ['type' => 'module']));
    $inputs->addSchema('slug', Schema::string()
      ->title(t('Slug'))
      ->check('drupal:Regex', ['pattern' => '/^[a-z]+$/'])
      ->message('check.drupal:Regex', 'Lowercase letters only.'));
    $inputs->addSchema('sender', Schema::of('portable:email')->title(t('Sender')));
    $inputs->addSchema('other', Schema::string()->title(t('Other'))->check('acme:thing'));
  }

}
