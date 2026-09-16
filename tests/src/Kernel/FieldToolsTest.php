<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\ListContextDefinition;
use Drupal\tool\TypedData\MapContextDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two field tools the Tool API bridge adds.
 *
 * What has to hold is that a tool contributes nothing of its own to the
 * settings: the field type's surface says what the values may be, the
 * pipeline accepts, validates, prepares and commits them, and the tool
 * is the caller. So a valid payload reaches storage in the shape the
 * field type reads back, an invalid one is refused with the path that
 * names it and leaves nothing behind, and a partial update changes only
 * the keys it carries — including clearing one by sending it as null,
 * which is the thing a null-stripping merge cannot express.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldToolsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The field administration permission for the test entity type.
   */
  protected const PERMISSION = 'administer entity_test fields';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // Present for its permissions alone: 'administer entity_test fields'
    // is a Field UI permission, and it is the answer both tools want.
    'field_ui',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_address',
    'data_surface_tool',
  ];

  /**
   * The tool manager.
   */
  protected ToolManager $toolManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // Both tools write, so both refuse an account that may not
    // administer this entity type's fields. Every round trip below is
    // run as somebody who may.
    $this->setUpCurrentUser([], [self::PERMISSION]);
    $this->toolManager = $this->container->get('plugin.manager.tool');
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
  }

  /**
   * Creates a tool with the three inputs its settings refine against.
   *
   * @param string $id
   *   The tool plugin identifier.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool, ready for a settings value.
   */
  protected function createTool(string $id) {
    $tool = $this->toolManager->createInstance($id);
    $tool->setInputValue('entity_type_id', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    return $tool;
  }

  /**
   * Reloads the field config, so stored settings come from storage.
   *
   * @return \Drupal\field\FieldConfigInterface|null
   *   The field, or NULL when it was never created.
   */
  protected function reloadField(): ?FieldConfigInterface {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    return FieldConfig::loadByName('entity_test', 'entity_test', 'field_address');
  }

  /**
   * Adds the field with the settings the round trip starts from.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function addField() {
    $tool = $this->createTool('data_surface:field_add');
    $tool->setInputValue('label', 'Address');
    $tool->setInputValue('settings', [
      'available_countries' => ['US', 'CA'],
      'field_overrides' => ['organization' => 'hidden'],
    ]);
    $tool->execute();
    return $tool;
  }

  /**
   * Tests that the settings input is the surface, not a free-form map.
   */
  public function testSettingsInputIsDescribed(): void {
    $tool = $this->createTool('data_surface:field_add');
    $definition = $tool->getInputDefinition('settings');
    assert($definition instanceof MapInputDefinition);
    $properties = $definition->getPropertyDefinitions();

    $this->assertSame(
      ['available_countries', 'langcode_override', 'field_overrides'],
      array_keys($properties),
    );
    $this->assertArrayNotHasKey('fields', $properties);

    // The vocabulary a caller would otherwise have to guess, with the
    // labels the surface carries and the defaults it declares.
    $overrides = $properties['field_overrides']->getPropertyDefinitions();
    $this->assertArrayHasKey('givenName', $overrides);
    $this->assertSame('Organization', (string) $overrides['organization']->getLabel());
    $this->assertSame(
      ['hidden', 'optional', 'required'],
      $overrides['organization']->getConstraint('Choice')['choices'],
    );
    $this->assertSame(
      ['hidden' => 'Hidden', 'optional' => 'Optional', 'required' => 'Required'],
      array_map('strval', $overrides['organization']->getConstraint('LabeledChoice')['choices']),
    );
    $this->assertSame([], $properties['available_countries']->getDefaultValue());
    $this->assertContains(
      'US',
      $properties['available_countries']->getItemDefinition()->getConstraint('Choice')['choices'],
    );
  }

  /**
   * Tests that a valid payload reaches storage in the storage shape.
   */
  public function testAddStoresTheStorageShape(): void {
    $tool = $this->addField();

    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $tool->getResultMessage());

    $settings = $this->reloadField()->getSettings();
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $settings['field_overrides']);
    $this->assertNull($settings['langcode_override']);
    // Written empty every time, so the deprecated key can never shadow
    // the overrides the surface just wrote.
    $this->assertSame([], $settings['fields']);
    $this->assertSame($settings, $result->getContextValues()['settings']);
  }

  /**
   * Tests that an invalid override is refused and nothing is created.
   *
   * The refusal arrives from the Tool API rather than from the pipeline,
   * and earlier than it otherwise would: the choice constraint the
   * bridge adds beside the labeled one so the allowed values reach the
   * advertised schema also validates, so the value never reaches the
   * tool at all. Either way the message names the property inside the
   * map, which is what a caller needs to correct itself.
   */
  public function testInvalidOverrideValueCreatesNothing(): void {
    $tool = $this->createTool('data_surface:field_add');
    $tool->setInputValue('label', 'Address');
    $tool->setInputValue('settings', ['field_overrides' => ['organization' => 'mandatory']]);
    $tool->execute();

    $result = $tool->getResult();
    $this->assertFalse($result->isSuccess());
    $message = (string) $tool->getResultMessage();
    $this->assertStringContainsString('field_overrides', $message);
    $this->assertStringContainsString('organization', $message);
    $this->assertStringContainsString('not a valid choice', $message);
    $this->assertNull($this->reloadField());
  }

  /**
   * Tests that a key the surface does not declare is refused.
   */
  public function testUnknownSettingKeyCreatesNothing(): void {
    $tool = $this->createTool('data_surface:field_add');
    $tool->setInputValue('label', 'Address');
    $tool->setInputValue('settings', ['field_overrides' => ['country_code' => 'hidden']]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('field_overrides.country_code', (string) $tool->getResultMessage());
    $this->assertNull($this->reloadField());
  }

  /**
   * Tests a partial update clearing one key and keeping its siblings.
   */
  public function testUpdateClearsOneOverrideAndKeepsTheCountries(): void {
    $this->addField();

    $tool = $this->createTool('data_surface:field_update');
    $tool->setInputValue('settings', ['field_overrides' => ['organization' => NULL]]);
    $tool->execute();

    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $tool->getResultMessage());

    $settings = $this->reloadField()->getSettings();
    $this->assertSame([], $settings['field_overrides']);
    // Untouched by a payload that never mentioned them: the pipeline
    // merges over what the target loads rather than replacing it.
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
  }

  /**
   * Tests that an update writes the label and the settings in one save.
   */
  public function testUpdateAppliesLabelAlongsideSettings(): void {
    $this->addField();

    $tool = $this->createTool('data_surface:field_update');
    $tool->setInputValue('label', 'Postal address');
    $tool->setInputValue('required', TRUE);
    $tool->setInputValue('settings', ['available_countries' => ['DE']]);
    $tool->execute();

    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResultMessage());
    $field = $this->reloadField();
    $this->assertSame('Postal address', $field->getLabel());
    $this->assertTrue($field->isRequired());
    $this->assertSame(['DE' => 'DE'], $field->getSettings()['available_countries']);
    $this->assertSame(
      ['organization' => ['override' => 'hidden']],
      $field->getSettings()['field_overrides'],
    );
  }

  /**
   * Tests that an update refusing the settings writes nothing at all.
   */
  public function testUpdateRefusesInvalidSettingsWithoutWriting(): void {
    $this->addField();

    $tool = $this->createTool('data_surface:field_update');
    $tool->setInputValue('label', 'Never stored');
    $tool->setInputValue('settings', ['available_countries' => ['US', 'ZZ']]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('available_countries', (string) $tool->getResultMessage());
    $field = $this->reloadField();
    $this->assertSame('Address', $field->getLabel());
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $field->getSettings()['available_countries']);
  }

  /**
   * Tests that a field type without a surface still works.
   *
   * The bridge is generic: a field type that declares no surface falls
   * back to the serialized config schema, which is what the free-form
   * tools offer for every field type.
   */
  public function testFieldTypeWithoutSurfaceFallsBack(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();

    $tool = $this->toolManager->createInstance('data_surface:field_add');
    $tool->setInputValue('entity_type_id', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_plain');
    $tool->setInputValue('label', 'Plain');
    $definition = $tool->getInputDefinition('settings');
    assert($definition instanceof MapInputDefinition);
    // The string field type's instance settings schema is empty, so
    // there is nothing to describe and nothing to guess either.
    $this->assertSame([], $definition->getPropertyDefinitions());

    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResultMessage());
    $this->assertNotNull(FieldConfig::loadByName('entity_test', 'entity_test', 'field_plain'));
  }

  /**
   * Tests that neither tool runs for an account without the permission.
   *
   * Two layers, because execute() runs no access check of its own: the
   * tool's access() answer, which is what an invoker asks, and the
   * refusal inside doExecute(), which is what a caller reaching the tool
   * straight from PHP meets. Nothing is written either way.
   */
  public function testAccessNeedsFieldAdministration(): void {
    $this->addField();
    $stranger = $this->createUser();

    $add = $this->createTool('data_surface:field_add');
    $add->setInputValue('field_name', 'field_plain');
    $add->setInputValue('label', 'Plain');
    $this->assertFalse($add->access($stranger));

    $update = $this->createTool('data_surface:field_update');
    $update->setInputValue('label', 'Renamed');
    $this->assertFalse($update->access($stranger));

    // The same two tools run as the stranger anyway.
    $this->setCurrentUser($stranger);
    $update = $this->createTool('data_surface:field_update');
    $update->setInputValue('label', 'Renamed');
    $update->execute();
    $this->assertFalse($update->getResult()->isSuccess());
    $this->assertSame('Address', $this->reloadField()->label());
  }

  /**
   * Tests that a privileged account is allowed by both tools.
   */
  public function testAccessIsAllowedWithFieldAdministration(): void {
    $this->addField();
    $account = $this->createUser([self::PERMISSION]);

    $add = $this->createTool('data_surface:field_add');
    $add->setInputValue('field_name', 'field_plain');
    $add->setInputValue('label', 'Plain');
    $this->assertTrue($add->access($account));

    $update = $this->createTool('data_surface:field_update');
    $update->setInputValue('label', 'Renamed');
    $this->assertTrue($update->access($account));
  }

  /**
   * Tests that an entity type id naming nothing is refused, not spelled.
   *
   * The guard behind the input constraint, and the one the permission
   * name depends on: no permission is assembled out of an id until the
   * entity type manager has said the id names something. The input
   * constraint refuses such an id before access() is reached, so the
   * check is asked directly here — which is also how doExecute() and any
   * PHP caller reach it.
   */
  public function testAccessIsDeniedForAnUnknownEntityType(): void {
    $account = $this->createUser([self::PERMISSION]);
    $values = [
      'entity_type_id' => 'no_such_entity_type',
      'bundle' => 'entity_test',
      'field_name' => 'field_address',
    ];

    foreach (['data_surface:field_add', 'data_surface:field_update'] as $id) {
      $result = $this->toolAccess($id, $values, $account);
      $this->assertTrue($result->isForbidden(), $id . ' refuses an unknown entity type.');
    }

    // And a field that does not exist is refused by the update tool with
    // no reason attached, so the refusal says nothing about the field.
    $result = $this->toolAccess('data_surface:field_update', [
      'entity_type_id' => 'entity_test',
      'bundle' => 'entity_test',
      'field_name' => 'field_nothing',
    ], $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Tests that a surface's outputs convert to the Tool API's own type.
   *
   * The other direction of the bridge. A tool declares its outputs with
   * plain context definitions rather than input definitions, and rightly
   * so: an output is never rendered as a form element, never refined by
   * a caller's other answers and never locked. What has to survive is
   * everything a consumer reads — the type, the label, the help text,
   * whether the value is always there, and the vocabulary.
   *
   * What cannot survive is stated where it is lost, in
   * SurfaceInputDefinitions::outputsFromSurface(): example values and
   * type settings, which the Tool API has nowhere to put, and the
   * refinement edges, because the Tool API has an
   * input_definition_refiners and no output counterpart. A caller that
   * wants the narrowed answer converts a surface it has already put
   * through refineOutputs().
   */
  public function testOutputsConvertToContextDefinitions(): void {
    $meta = MapDataDefinition::create()->setLabel(new TranslatableMarkup('Meta'));
    $meta->setPropertyDefinition('count', DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Count'))
      ->setRequired(TRUE));
    $builder = new DataSurfaceBuilder([
      'mode' => DataDefinition::create('string')->setLabel(new TranslatableMarkup('Mode')),
    ]);
    $builder
      ->setOutputDefinition('text', DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Text'))
        ->setDescription(new TranslatableMarkup('What is shown.'))
        ->setRequired(TRUE))
      ->setOutputDefinition('note', DataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Note'))
        ->addConstraint('LabeledChoice', [
          'choices' => ['short', 'long'],
          'labels' => [
            'short' => new TranslatableMarkup('Short'),
            'long' => new TranslatableMarkup('Long'),
          ],
        ]))
      ->setOutputDefinition('meta', $meta)
      ->setOutputDefinition('tags', ListDataDefinition::create('string')
        ->setLabel(new TranslatableMarkup('Tags')));
    $surface = $builder->seal();

    $outputs = $this->container->get('data_surface_tool.input_definitions')->outputsFromSurface($surface);

    $this->assertSame(['text', 'note', 'meta', 'tags'], array_keys($outputs));
    foreach ($outputs as $definition) {
      $this->assertInstanceOf(ContextDefinitionInterface::class, $definition);
      // An output carries no default, so the conversion has none to
      // carry: there is no value a caller can fail to send.
      $this->assertNull($definition->getDefaultValue());
    }

    // The scalar keeps everything a reader of the schema needs.
    $this->assertSame('string', $outputs['text']->getDataType());
    $this->assertSame('Text', (string) $outputs['text']->getLabel());
    $this->assertSame('What is shown.', (string) $outputs['text']->getDescription());
    $this->assertTrue($outputs['text']->isRequired());
    $this->assertFalse($outputs['note']->isRequired());

    // The vocabulary travels the way it does for inputs: the Tool API's
    // normalizer reads an enum off a plain Choice and matches by plugin
    // id rather than by class, so the labeled one is taught to travel
    // beside it rather than instead of it.
    $this->assertSame(['short', 'long'], $outputs['note']->getConstraint('Choice')['choices']);
    $this->assertSame(['short', 'long'], $outputs['note']->getConstraint('LabeledChoice')['choices']);

    // Structure becomes the Tool API's own structured definitions.
    $this->assertInstanceOf(MapContextDefinition::class, $outputs['meta']);
    $count = $outputs['meta']->getPropertyDefinition('count');
    $this->assertSame('integer', $count->getDataType());
    $this->assertTrue($count->isRequired());
    $this->assertInstanceOf(ListContextDefinition::class, $outputs['tags']);
    $this->assertSame('string', $outputs['tags']->getItemDefinition()->getDataType());
  }

  /**
   * Asks a tool's own access check, bypassing input validation.
   *
   * @param string $id
   *   The tool plugin identifier.
   * @param array $values
   *   The values to check access against.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function toolAccess(string $id, array $values, AccountInterface $account): AccessResultInterface {
    $tool = $this->toolManager->createInstance($id);
    return (new \ReflectionMethod($tool, 'checkAccess'))->invoke($tool, $values, $account, TRUE);
  }

}
