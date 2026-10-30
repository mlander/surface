<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Bundles with form displays, for the derived content entity resources.
 *
 * `page` is the worked example: a title, a required description, a body
 * with a summary and a format, publishing and authoring fields, the
 * revision log, and tags, which take any number of values and so are
 * left out. `kitchen` has one field per covered field type, plus one of
 * a type that is not covered and one that takes three values. `article`
 * has no stored form display, so nothing is derived for it.
 */
trait ContentEntityFixtureTrait {

  /**
   * The modules a derived content entity resource needs, in order.
   */
  protected const ENTITY_MODULES = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'datetime',
    'link',
    'telephone',
    'taxonomy',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_examples_entity',
  ];

  /**
   * The kitchen bundle's fields: type, storage settings, field settings.
   */
  protected const KITCHEN = [
    'field_string' => ['string', ['max_length' => 40], []],
    'field_string_long' => ['string_long', [], []],
    'field_text' => ['text', ['max_length' => 60], []],
    'field_text_long' => ['text_long', [], ['allowed_formats' => ['basic']]],
    'field_text_with_summary' => ['text_with_summary', [], []],
    'field_boolean' => ['boolean', [], []],
    'field_integer' => ['integer', [], ['min' => 1, 'max' => 9]],
    'field_decimal' => ['decimal', [], []],
    'field_float' => ['float', [], []],
    'field_email' => ['email', [], []],
    'field_telephone' => ['telephone', [], []],
    'field_list_string' => ['list_string', ['allowed_values' => ['red' => 'Red', 'blue' => 'Blue']], []],
    'field_list_integer' => ['list_integer', ['allowed_values' => [1 => 'One', 2 => 'Two']], []],
    'field_list_float' => ['list_float', ['allowed_values' => ['1.5' => 'One and a half']], []],
    'field_date' => ['datetime', ['datetime_type' => 'date'], []],
    'field_datetime' => ['datetime', ['datetime_type' => 'datetime'], []],
    'field_timestamp' => ['timestamp', [], []],
    'field_link' => ['link', [], ['title' => 1]],
    'field_term' => [
      'entity_reference',
      ['target_type' => 'taxonomy_term'],
      ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['tags' => 'tags']]],
    ],
    'field_uri' => ['uri', [], []],
  ];

  /**
   * Installs what nodes, terms and text formats need.
   */
  protected function installEntityFixture(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'system']);
    FilterFormat::create(['format' => 'basic', 'name' => 'Basic', 'weight' => 0])->save();
    FilterFormat::create(['format' => 'trusted', 'name' => 'Trusted', 'weight' => 1])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    Term::create(['vid' => 'tags', 'name' => 'Pantry'])->save();
    Term::create(['vid' => 'tags', 'name' => 'Larder'])->save();
    $this->createPage();
    $this->createKitchen();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Creates the page bundle and its form display.
   */
  protected function createPage(): void {
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->addField('page', 'field_description', 'string_long', [], [], 'Description', required: TRUE);
    $this->addField('page', 'body', 'text_with_summary', [], [], 'Body');
    $this->addField('page', 'field_tags', 'entity_reference', ['target_type' => 'taxonomy_term'], ['handler_settings' => ['target_bundles' => ['tags' => 'tags']]], 'Tags', cardinality: -1);
    $display = static::formDisplay('page');
    $weight = 0;
    foreach (['title', 'field_description', 'body', 'field_tags', 'uid', 'created', 'status', 'promote', 'revision_log'] as $name) {
      $display->setComponent($name, ['weight' => $weight++]);
    }
    $display->save();
  }

  /**
   * Creates the kitchen bundle, one field per covered type, and its display.
   */
  protected function createKitchen(): void {
    NodeType::create(['type' => 'kitchen', 'name' => 'Kitchen sink'])->save();
    $display = static::formDisplay('kitchen');
    $display->setComponent('title', ['weight' => 0]);
    $weight = 1;
    foreach (static::KITCHEN as $name => [$type, $storage, $settings]) {
      $this->addField('kitchen', $name, $type, $storage, $settings, ucfirst(str_replace(['field_', '_'], ['', ' '], $name)));
      $display->setComponent($name, ['weight' => $weight++]);
    }
    $this->addField('kitchen', 'field_multi', 'string', [], [], 'Three values', cardinality: 3);
    $display->setComponent('field_multi', ['weight' => $weight++]);
    $display->setComponent('changed', ['weight' => $weight]);
    $display->save();
  }

  /**
   * Makes a node bundle's default form display.
   *
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\Core\Entity\Entity\EntityFormDisplay
   *   The display, unsaved.
   */
  protected static function formDisplay(string $bundle): EntityFormDisplay {
    return EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ]);
  }

  /**
   * Adds one field to a node bundle.
   *
   * @param string $bundle
   *   The bundle.
   * @param string $name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array $storage
   *   The storage settings.
   * @param array $settings
   *   The field settings.
   * @param string $label
   *   The label.
   * @param bool $required
   *   Whether it is required.
   * @param int $cardinality
   *   How many values it takes.
   */
  protected function addField(string $bundle, string $name, string $type, array $storage, array $settings, string $label, bool $required = FALSE, int $cardinality = 1): void {
    if (FieldStorageConfig::loadByName('node', $name) === NULL) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $storage,
        'cardinality' => $cardinality,
      ])->save();
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'required' => $required,
      'settings' => $settings,
    ])->save();
  }

}
