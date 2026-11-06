<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Reader\TypedDataReader;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\Tests\surface\Kernel\Reader\RecordingBlueprint;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests typed data read straight into the blueprint.
 *
 * A field definition read as its item, beside what FieldItemDerivation
 * builds for the same field, every difference listed with which side
 * knows more; and a map built by hand, with a nested map, a list and a
 * reference.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class TypedDataReaderTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = self::ENTITY_MODULES;

  /**
   * The fields compared, by bundle and name.
   */
  protected const FIELDS = [
    'node title' => ['page', 'title'],
    'body' => ['page', 'body'],
    'entity reference' => ['page', 'field_tags'],
    'boolean' => ['kitchen', 'field_boolean'],
    'datetime' => ['kitchen', 'field_datetime'],
    'list_string' => ['kitchen', 'field_list_string'],
  ];

  /**
   * Where the differences are written, for the README.
   */
  protected const REPORT = '/tmp/surface-reader/field-comparison.json';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests a field read as its item, beside FieldItemDerivation.
   */
  public function testFieldBesideTheDerivation(): void {
    $reader = $this->container->get(TypedDataReader::class);
    $derivation = $this->container->get(FieldItemDerivation::class);
    $fields = $this->container->get('entity_field.manager');
    $report = [];
    foreach (self::FIELDS as $name => [$bundle, $field_name]) {
      $field = $fields->getFieldDefinitions('node', $bundle)[$field_name];
      $inputs = new RecordingBlueprint();
      $reading = $reader->read($field, $inputs);
      $derived = $derivation->describe($field, $bundle);
      $direct = array_filter($inputs->keys, static fn (array $record): bool => $record['verb'] === 'addDefinition');
      // The derivation's keys are a subset: every key it carries, the
      // reader declares too.
      $this->assertSame([], array_values(array_diff(array_keys($derived), array_keys($direct))), $name . ': every key the derivation carries is read.');
      foreach (array_diff(array_keys($direct), array_keys($derived)) as $key) {
        $type = $direct[$key]['definition']->getDataType();
        $report[$name][] = [
          'key' => $key,
          'knows more' => 'reader',
          'what' => 'a key the derivation leaves out (' . $type . ')',
        ];
      }
      foreach ($derived as $key => ['definition' => $a, 'default' => $a_default]) {
        $b = $direct[$key]['definition'];
        $this->differ($name, (string) $key, $a, $b, $a_default, $direct[$key]['default'] ?? NULL, $report);
      }
      foreach ($reading->left() as $path => $reasons) {
        $report[$name][] = ['key' => $path, 'knows more' => 'left out', 'what' => implode(' ', $reasons)];
      }
    }
    @mkdir(dirname(self::REPORT), 0777, TRUE);
    file_put_contents(self::REPORT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // The reference key, read from the computed reference beside the id.
    $tags = new RecordingBlueprint();
    $reader->read($fields->getFieldDefinitions('node', 'page')['field_tags'], $tags);
    $this->assertSame(['entityTypeId' => 'taxonomy_term'], $tags->keys['target_id']['definition']->getConstraints()['SurfaceValidReference']);
  }

  /**
   * Tests a map built by hand: a nested map, a list, a reference.
   */
  public function testMapBuiltByHand(): void {
    $map = MapDataDefinition::create()
      ->setPropertyDefinition('name', DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE)->addConstraint('Length', ['max' => 20]))
      ->setPropertyDefinition('address', MapDataDefinition::create()
        ->setLabel('Address')
        ->setPropertyDefinition('city', DataDefinition::create('string')->setLabel('City'))
        ->setPropertyDefinition('zip', DataDefinition::create('integer')->setLabel('Zip')->addConstraint('Range', ['min' => 1])))
      ->setPropertyDefinition('tags', ListDataDefinition::create('string')->setLabel('Tags'))
      ->setPropertyDefinition('owner', DataReferenceDefinition::create('entity')->setLabel('Owner')->setTargetDefinition(EntityDataDefinition::create('user')))
      ->setPropertyDefinition('slug', DataDefinition::create('string')->setLabel('Slug')->setComputed(TRUE))
      ->setPropertyDefinition('code', DataDefinition::create('string')->setLabel('Code')->setReadOnly(TRUE));
    $inputs = new RecordingBlueprint();
    $reading = $this->container->get(TypedDataReader::class)->read($map, $inputs);

    $this->assertSame(['name', 'address', 'tags', 'owner', 'code'], array_keys($inputs->keys));
    $this->assertSame(['addDefinition', 'addMap', 'addList', 'addDefinition', 'addDefinition'], array_column($inputs->keys, 'verb'));
    $this->assertTrue($inputs->keys['name']['definition']->isRequired());
    $this->assertSame(['max' => 20], $inputs->keys['name']['definition']->getConstraints()['Length']);
    $this->assertSame(['city', 'zip'], array_keys($inputs->keys['address']['map']->keys));
    $this->assertSame(['min' => 1], $inputs->keys['address']['map']->keys['zip']['definition']->getConstraints()['Range']);
    $tags = $inputs->keys['tags']['definition'];
    $this->assertInstanceOf(ListDataDefinitionInterface::class, $tags);
    $this->assertSame('string', $tags->getItemDefinition()->getDataType());
    $this->assertSame('integer', $inputs->keys['owner']['definition']->getDataType());
    $this->assertSame(['entityTypeId' => 'user'], $inputs->keys['owner']['definition']->getConstraints()['SurfaceValidReference']);
    $this->assertSame(['Computed: no value of its own is stored.'], $reading->left()['slug']);
    $this->assertStringStartsWith('Read only:', $reading->left()['code'][0]);
  }

  /**
   * Lists how one key differs between the derivation and the reader.
   *
   * @param string $field
   *   The field's name in the report.
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $a
   *   The derivation's.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $b
   *   The reader's.
   * @param mixed $a_default
   *   The derivation's default.
   * @param mixed $b_default
   *   The reader's.
   * @param array $report
   *   The report, added to.
   */
  protected function differ(string $field, string $key, DataDefinitionInterface $a, DataDefinitionInterface $b, mixed $a_default, mixed $b_default, array &$report): void {
    $line = static function (string $knows, string $what) use ($field, $key, &$report): void {
      $report[$field][] = ['key' => $key, 'knows more' => $knows, 'what' => $what];
    };
    if ($a->getDataType() !== $b->getDataType()) {
      $line('derivation', sprintf('type %s beside %s', $a->getDataType(), $b->getDataType()));
    }
    if ($a->isRequired() !== $b->isRequired()) {
      $line($a->isRequired() ? 'derivation' : 'reader', sprintf('required %s beside %s', var_export($a->isRequired(), TRUE), var_export($b->isRequired(), TRUE)));
    }
    if ((string) $a->getLabel() !== (string) $b->getLabel()) {
      $line('wording', sprintf('label "%s" beside "%s"', $a->getLabel(), $b->getLabel()));
    }
    if ((string) $a->getDescription() !== (string) $b->getDescription()) {
      $line('wording', sprintf('description "%s" beside "%s"', $a->getDescription(), $b->getDescription()));
    }
    if ($a_default !== $b_default) {
      $line($a_default !== NULL ? 'derivation' : 'reader', sprintf('default %s beside %s', json_encode($a_default), json_encode($b_default)));
    }
    $ignored = ['PrimitiveType' => 1, 'NotNull' => 1];
    $mine = array_diff_key($a->getConstraints(), $ignored);
    $theirs = array_diff_key($b->getConstraints(), $ignored);
    foreach (array_diff_key($mine, $theirs) as $name => $options) {
      $line('derivation', sprintf('constraint %s %s', $name, json_encode($options)));
    }
    foreach (array_diff_key($theirs, $mine) as $name => $options) {
      $line('reader', sprintf('constraint %s %s', $name, json_encode($options)));
    }
    foreach (array_intersect_key($mine, $theirs) as $name => $options) {
      if ($options != $theirs[$name]) {
        $line('both', sprintf('constraint %s %s beside %s', $name, json_encode($options), json_encode($theirs[$name])));
      }
    }
    foreach (array_diff_key($a->getSettings(), $b->getSettings()) as $setting => $value) {
      $line('derivation', sprintf('setting %s %s', $setting, json_encode($value)));
    }
  }

}
