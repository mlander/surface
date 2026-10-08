<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldConfigInterface;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinitionInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the field tools, derived from the field instance surface.
 *
 * `data_surface:field.instance:add`, `:reuse` and `:edit`, one per
 * situation of FieldInstanceSurface, replace the two hand-written tools
 * this module used to ship. What has to hold is what held for them: a
 * tool contributes nothing of its own to the settings. The field type's
 * settings surface says what the values may be, the pipeline accepts,
 * validates, prepares and commits them, and the tool is the caller. So a
 * valid payload reaches storage in the shape the field type reads back,
 * an invalid one is refused with the path that names it and leaves
 * nothing behind, and a partial edit changes only the keys it carries —
 * including clearing one by sending it as null, which is the thing a
 * null-stripping merge cannot express.
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
   * The tool adding a field with a storage of its own.
   */
  protected const ADD = 'data_surface:field.instance:add';

  /**
   * The tool adding an existing storage's field to a bundle.
   */
  protected const REUSE = 'data_surface:field.instance:reuse';

  /**
   * The tool editing a field.
   */
  protected const EDIT = 'data_surface:field.instance:edit';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // Present for its permissions alone: 'administer entity_test fields'
    // is a Field UI permission, and it is the answer the tools want.
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
    // The tools write, so they refuse an account that may not administer
    // this entity type's fields. Every round trip below is run as
    // somebody who may.
    $this->setUpCurrentUser([], [self::PERMISSION]);
    $this->toolManager = $this->container->get('plugin.manager.tool');
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
  }

  /**
   * Creates a field tool with its situation's parameters set.
   *
   * @param string $id
   *   The tool plugin identifier.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool, ready for its values.
   */
  protected function createTool(string $id) {
    $tool = $this->toolManager->createInstance($id);
    $parameters = match ($id) {
      self::ADD => ['entity_type_id' => 'entity_test', 'bundle' => 'entity_test'],
      self::REUSE => ['storage' => 'entity_test.field_address', 'bundle' => 'entity_test'],
      self::EDIT => ['field' => 'entity_test.entity_test.field_address'],
      default => throw new \InvalidArgumentException($id . ' is not a field tool.'),
    };
    foreach ($parameters as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    return $tool;
  }

  /**
   * Reloads a field config, so stored settings come from storage.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\field\FieldConfigInterface|null
   *   The field, or NULL when it was never created.
   */
  protected function reloadField(string $field_name = 'field_address'): ?FieldConfigInterface {
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
    return FieldConfig::loadByName('entity_test', 'entity_test', $field_name);
  }

  /**
   * Adds the address field with the settings the round trip starts from.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The executed tool.
   */
  protected function addField() {
    $tool = $this->createTool(self::REUSE);
    $tool->setInputValue(SituationInputs::VALUES, [
      'label' => 'Address',
      'settings' => [
        'available_countries' => ['US', 'CA'],
        'field_overrides' => ['organization' => 'hidden'],
      ],
    ]);
    $tool->execute();
    return $tool;
  }

  /**
   * Reads the settings input a tool advertises, refined to its subject.
   *
   * @param \Drupal\tool\Tool\ToolInterface $tool
   *   The tool, its parameters set.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   The settings' properties.
   */
  protected function settingsProperties($tool): array {
    $values = $tool->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $settings = $values->getPropertyDefinitions()['settings'];
    $this->assertInstanceOf(MapInputDefinition::class, $settings);
    return $settings->getPropertyDefinitions();
  }

  /**
   * Tests that the settings input is the surface, not a free-form map.
   */
  public function testSettingsInputIsDescribed(): void {
    foreach ([self::REUSE, self::EDIT] as $id) {
      if ($id === self::EDIT) {
        $this->addField();
      }
      $properties = $this->settingsProperties($this->createTool($id));
      $this->assertSame(
        ['available_countries', 'langcode_override', 'field_overrides'],
        array_keys($properties),
        $id,
      );
      $this->assertArrayNotHasKey('fields', $properties);

      // The vocabulary a caller would otherwise have to guess, with the
      // labels the surface carries and the defaults it declares.
      $field_overrides = $properties['field_overrides'];
      $this->assertInstanceOf(MapInputDefinition::class, $field_overrides);
      $overrides = $field_overrides->getPropertyDefinitions();
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
      $available_countries = $properties['available_countries'];
      $this->assertInstanceOf(ListInputDefinition::class, $available_countries);
      $this->assertContains(
        'US',
        $available_countries->getItemDefinition()->getConstraint('Choice')['choices'],
      );
    }
    $this->assertSame([], $this->settingsProperties($this->createTool(self::REUSE))['available_countries']->getDefaultValue());
  }

  /**
   * Tests that a valid payload reaches storage in the storage shape.
   *
   * The settings are written by the address settings surface's own
   * target, after the field's, and the field is placed on the bundle's
   * default form and view displays as Field UI places a new field.
   */
  public function testReuseStoresTheStorageShape(): void {
    $tool = $this->addField();

    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $tool->getResultMessage());
    $this->assertTrue($result->getContextValues()[SituationInputs::COMMITTED]);

    $settings = $this->reloadField()->getSettings();
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $settings['field_overrides']);
    $this->assertNull($settings['langcode_override']);
    // Written empty every time, so the deprecated key can never shadow
    // the overrides the surface just wrote.
    $this->assertSame([], $settings['fields']);
    // The accepted values come back in the surface's shape.
    $this->assertSame(
      ['US', 'CA'],
      $result->getContextValues()[SituationInputs::VALUES]['settings']['available_countries'],
    );

    $display_repository = $this->container->get('entity_display.repository');
    $this->assertNotNull($display_repository->getFormDisplay('entity_test', 'entity_test')->getComponent('field_address'));
    $this->assertNotNull($display_repository->getViewDisplay('entity_test', 'entity_test')->getComponent('field_address'));
  }

  /**
   * Tests adding a field with a storage of its own, settings and all.
   *
   * One submission of the whole field instance surface: the storage
   * child is written first, then the field, then the settings variant
   * the field type chose, after validation has passed for all three.
   */
  public function testAddWritesTheStorageTheFieldAndItsSettings(): void {
    $tool = $this->createTool(self::ADD);
    $tool->setInputValue(SituationInputs::VALUES, [
      'field_type' => 'address',
      'field_name' => 'field_home',
      'label' => 'Home',
      'storage' => ['cardinality' => 2],
      'settings' => ['available_countries' => ['DE']],
    ]);
    $tool->execute();

    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResultMessage());
    $this->assertSame(2, FieldStorageConfig::loadByName('entity_test', 'field_home')?->getCardinality());
    $field = $this->reloadField('field_home');
    $this->assertSame('Home', $field->getLabel());
    $this->assertSame(['DE' => 'DE'], $field->getSettings()['available_countries']);
  }

  /**
   * Tests a dry run: every part rehearsed, nothing written.
   *
   * Prepare builds each part as storage would be handed it and holds it
   * to its config schema: the field under `own`, and the storage and
   * the settings, each stored apart, under `children`.
   */
  public function testDryRunPreviewsEveryPartAndWritesNothing(): void {
    $tool = $this->createTool(self::ADD);
    $tool->setInputValue(SituationInputs::VALUES, [
      'field_type' => 'address',
      'field_name' => 'field_home',
      'label' => 'Home',
      'storage' => ['cardinality' => 2],
      'settings' => ['field_overrides' => ['organization' => 'hidden']],
    ]);
    $tool->setInputValue(SituationInputs::DRY_RUN, TRUE);
    $tool->execute();

    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), (string) $tool->getResultMessage());
    $this->assertFalse($result->getContextValues()[SituationInputs::COMMITTED]);
    $prepared = $result->getContextValues()[SituationInputs::PREPARED];
    $this->assertSame('entity_test.entity_test.field_home', $prepared['own']['id']);
    $this->assertSame('Home', $prepared['own']['label']);
    $this->assertSame(['storage', 'settings'], array_keys($prepared['children']));
    $this->assertSame('entity_test.field_home', $prepared['children']['storage']['id']);
    $this->assertSame(2, $prepared['children']['storage']['cardinality']);
    $this->assertSame(['organization' => ['override' => 'hidden']], $prepared['children']['settings']['field_overrides']);

    $this->assertNull(FieldStorageConfig::loadByName('entity_test', 'field_home'));
    $this->assertNull($this->reloadField('field_home'));
  }

  /**
   * Tests that what storage would refuse is refused at prepare.
   *
   * The surface limits nothing about a field's label but that it is
   * there; the field's config schema says a label holds no line break.
   * A dry run meets the refusal a write would, filed under the surface
   * key, and nothing is written.
   */
  public function testTheSchemaRefusesAtPrepare(): void {
    foreach ([TRUE, FALSE] as $dry_run) {
      $tool = $this->createTool(self::ADD);
      $tool->setInputValue(SituationInputs::VALUES, [
        'field_type' => 'address',
        'field_name' => 'field_home',
        'label' => "Two\nlines",
      ]);
      $tool->setInputValue(SituationInputs::DRY_RUN, $dry_run);
      $tool->execute();

      $this->assertFalse($tool->getResult()->isSuccess());
      $this->assertStringContainsString('label: Labels are not allowed to span multiple lines', (string) $tool->getResultMessage());
    }
    $this->assertNull(FieldStorageConfig::loadByName('entity_test', 'field_home'));
    $this->assertNull($this->reloadField('field_home'));
  }

  /**
   * Tests that a machine name storage could not hold is the surface's.
   */
  public function testAnInvalidMachineNameIsRefused(): void {
    $tool = $this->createTool(self::ADD);
    $tool->setInputValue(SituationInputs::VALUES, [
      'field_type' => 'address',
      'field_name' => 'Field Home',
      'label' => 'Home',
    ]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('field_name', (string) $tool->getResultMessage());
    $this->assertNull(FieldStorageConfig::loadByName('entity_test', 'Field Home'));
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
    $tool = $this->createTool(self::REUSE);
    $tool->setInputValue(SituationInputs::VALUES, [
      'label' => 'Address',
      'settings' => ['field_overrides' => ['organization' => 'mandatory']],
    ]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
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
    $tool = $this->createTool(self::REUSE);
    $tool->setInputValue(SituationInputs::VALUES, [
      'label' => 'Address',
      'settings' => ['field_overrides' => ['country_code' => 'hidden']],
    ]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('country_code', (string) $tool->getResultMessage());
    $this->assertNull($this->reloadField());
  }

  /**
   * Tests a partial edit clearing one key and keeping its siblings.
   */
  public function testEditClearsOneOverrideAndKeepsTheCountries(): void {
    $this->addField();

    $tool = $this->createTool(self::EDIT);
    $tool->setInputValue(SituationInputs::VALUES, ['settings' => ['field_overrides' => ['organization' => NULL]]]);
    $tool->execute();

    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResultMessage());
    $settings = $this->reloadField()->getSettings();
    $this->assertSame([], $settings['field_overrides']);
    // Untouched by a payload that never mentioned them: the pipeline
    // merges over what the target loads rather than replacing it.
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $settings['available_countries']);
  }

  /**
   * Tests that an edit writes the label and the settings in one run.
   */
  public function testEditAppliesLabelAlongsideSettings(): void {
    $this->addField();

    $tool = $this->createTool(self::EDIT);
    $tool->setInputValue(SituationInputs::VALUES, [
      'label' => 'Postal address',
      'required' => TRUE,
      'settings' => ['available_countries' => ['DE']],
    ]);
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
   * Tests that an edit refusing the settings writes nothing at all.
   */
  public function testEditRefusesInvalidSettingsWithoutWriting(): void {
    $this->addField();

    $tool = $this->createTool(self::EDIT);
    $tool->setInputValue(SituationInputs::VALUES, [
      'label' => 'Never stored',
      'settings' => ['available_countries' => ['US', 'ZZ']],
    ]);
    $tool->execute();

    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('available_countries', (string) $tool->getResultMessage());
    $field = $this->reloadField();
    $this->assertSame('Address', $field->getLabel());
    $this->assertSame(['US' => 'US', 'CA' => 'CA'], $field->getSettings()['available_countries']);
  }

  /**
   * Tests that a field type with no settings surface is not offered.
   *
   * The field instance surface's settings are a slot its field types'
   * settings surfaces fill, and a field type that has none is not among
   * the values its field type key offers, so it is refused by name
   * rather than added with settings nothing describes. The hand-written
   * tools fell back to the config schema here; that fallback is the
   * free-form tool's, tool_belt:field_add.
   */
  public function testFieldTypeWithoutSettingsSurfaceIsNotOffered(): void {
    $tool = $this->createTool(self::ADD);
    $values = $tool->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $field_type = $values->getPropertyDefinitions()['field_type'];
    $this->assertSame(['address'], $field_type->getConstraint('Choice')['choices']);

    $tool->setInputValue(SituationInputs::VALUES, [
      'field_type' => 'string',
      'field_name' => 'field_plain',
      'label' => 'Plain',
    ]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('field_type', (string) $tool->getResultMessage());
    $this->assertNull(FieldStorageConfig::loadByName('entity_test', 'field_plain'));
  }

  /**
   * Tests that no tool runs for an account without the permission.
   *
   * Two layers, because execute() runs no access check of its own: the
   * tool's access() answer, which is what an invoker asks, and the
   * refusal inside doExecute(), which is what a caller reaching the tool
   * straight from PHP meets. Nothing is written either way.
   */
  public function testAccessNeedsFieldAdministration(): void {
    $this->addField();
    $stranger = $this->createUser();

    foreach ([self::ADD, self::REUSE, self::EDIT] as $id) {
      $tool = $this->createTool($id);
      $this->assertFalse($tool->access($stranger), $id);
    }

    // The edit tool run as the stranger anyway.
    $this->setCurrentUser($stranger);
    $edit = $this->createTool(self::EDIT);
    $edit->setInputValue(SituationInputs::VALUES, ['label' => 'Renamed']);
    $edit->execute();
    $this->assertFalse($edit->getResult()->isSuccess());
    $this->assertSame('Address', $this->reloadField()->label());
  }

  /**
   * Tests that a privileged account is allowed by every tool.
   */
  public function testAccessIsAllowedWithFieldAdministration(): void {
    $this->addField();
    $account = $this->createUser([self::PERMISSION]);

    $this->assertTrue($this->createTool(self::EDIT)->access($account));
    // The two that create take values that are required, so their own
    // access check is asked directly, with the parameters alone.
    $this->assertTrue($this->toolAccess(self::ADD, ['entity_type_id' => 'entity_test', 'bundle' => 'entity_test'], $account)->isAllowed());
    $reuse = ['storage' => 'entity_test.field_address', 'bundle' => 'entity_test'];
    $this->assertTrue($this->toolAccess(self::REUSE, $reuse, $account)->isAllowed());
  }

  /**
   * Tests that a subject naming nothing is refused, not spelled.
   *
   * The permission is named from the identity the situation's context
   * knows. An entity type that does not exist names a permission nobody
   * holds; a field or a storage that does not exist builds no context at
   * all, and the refusal says nothing about why.
   */
  public function testAccessIsDeniedForAnUnknownSubject(): void {
    $account = $this->createUser([self::PERMISSION]);

    $add = ['entity_type_id' => 'no_such_entity_type', 'bundle' => 'entity_test'];
    $this->assertTrue($this->toolAccess(self::ADD, $add, $account)->isForbidden());
    $reuse = ['storage' => 'entity_test.field_nothing', 'bundle' => 'entity_test'];
    $this->assertTrue($this->toolAccess(self::REUSE, $reuse, $account)->isForbidden());
    $this->assertTrue($this->toolAccess(self::EDIT, ['field' => 'entity_test.entity_test.field_nothing'], $account)->isForbidden());
  }

  /**
   * Tests that a surface's outputs convert to the Tool API's own type.
   *
   * The other direction of the bridge. A tool declares its outputs with
   * output definitions rather than input definitions, and rightly
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
  public function testOutputsConvertToOutputDefinitions(): void {
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
      $this->assertInstanceOf(OutputDefinitionInterface::class, $definition);
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
    $this->assertInstanceOf(MapOutputDefinition::class, $outputs['meta']);
    $count = $outputs['meta']->getPropertyDefinition('count');
    $this->assertSame('integer', $count->getDataType());
    $this->assertTrue($count->isRequired());
    $this->assertInstanceOf(ListOutputDefinition::class, $outputs['tags']);
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
