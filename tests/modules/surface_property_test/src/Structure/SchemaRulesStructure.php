<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Keys declared with addSchema() whose schemas say one rule two ways.
 *
 * For AddSchemaTest: a check beside the keyword that reads as the same
 * constraint, a union of one schema and null, a map of one value type,
 * and a list of numbers to choose from.
 */
#[Structure('property_test.schema_rules')]
final class SchemaRulesStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('length', Schema::string()->maxLength(5)->check('drupal:Length', ['min' => 2]), required: FALSE);
    $inputs->addSchema('strict', Schema::string()->maxLength(5)->check('drupal:Length', ['max' => 9]), required: FALSE);
    $inputs->addSchema('range', Schema::integer()->minimum(1)->check('drupal:Range', ['max' => 9]), required: FALSE);
    $inputs->addSchema('regex', Schema::string()->pattern('^a')->check('drupal:Regex', ['pattern' => '/b$/']), required: FALSE);
    $choice = Schema::any()->with('enum', ['a', 'b'])->type('string')->check('drupal:Choice', ['choices' => ['a', 'c']]);
    $inputs->addSchema('choice', $choice, required: FALSE);
    $inputs->addSchema('size', Schema::any()->with('anyOf', [
      Schema::any()->with('enum', [1, 2]),
      Schema::null(),
    ])->title(t('Size')));
    $inputs->addSchema('count', Schema::any()->with('anyOf', [Schema::integer(), Schema::null()])->title(t('Count')));
    $inputs->addSchema('place', Schema::any()->with('anyOf', [
      Schema::object()->property('city', Schema::string())->with('additionalProperties', FALSE),
      Schema::null(),
    ])->title(t('Place')));
    $inputs->addSchema('link', Schema::any()->with('oneOf', [
      Schema::null(),
      Schema::of('portable:link'),
    ])->title(t('Link')));
    $inputs->addSchema('extras', Schema::object()->with('additionalProperties', Schema::integer()), required: FALSE);
    $inputs->addSchema('words', Schema::map(Schema::string()), required: FALSE);
    $inputs->addSchema('level', Schema::any()->with('enum', [1, 2, 3]), required: FALSE);
    $inputs->addSchema('ratio', Schema::any()->with('enum', [1.5, 2.5]), required: FALSE);
  }

}
