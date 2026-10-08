<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_tool\SituationInputs;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Compares the settings input two field tools advertise for one field type.
 *
 * Both tools answer the same question: given an existing field storage
 * and a bundle to add its field to, what may the settings be? Tool
 * Belt's field_add derives its answer from the field type's config
 * schema; data_surface:field.instance:reuse, the tool derived from the
 * field instance surface's reuse situation, from the field type's
 * settings surface, which fills that surface's settings slot. The two
 * answers are put side by side in the data_surface_tool module's
 * COMPARISON.md.
 * That file is written by scripts/generate-comparison.php; the last test
 * here asserts on every ordinary run that it still says what the
 * generator would say.
 *
 * The schemas are produced the way an invoker produces them: the Tool
 * API's own definition serializer, normalizing each tool's inputs to
 * JSON Schema, which is the document an MCP client or a function calling
 * model is handed.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class FieldToolsComparisonTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'address',
    'serialization',
    'tool',
    'tool_belt',
    'tool_belt_entity',
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
    // The renderer the generator script uses, included rather than
    // restated, so the document this test asserts against and the
    // document the script writes come from one piece of code.
    require_once dirname(__DIR__, 3) . '/scripts/DataSurfaceComparisonDocument.php';
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->toolManager = $this->container->get('plugin.manager.tool');
    FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'entity_test',
      'type' => 'address',
    ])->save();
  }

  /**
   * The tool derived from the field instance surface's reuse situation.
   */
  protected const SURFACE_TOOL = 'data_surface:field.instance:reuse';

  /**
   * The free-form tool it is compared with.
   */
  protected const BELT_TOOL = 'tool_belt:field_add';

  /**
   * Creates one of the two tools, told which field it is about.
   *
   * @param string $id
   *   The tool plugin identifier.
   *
   * @return \Drupal\tool\Tool\ToolInterface
   *   The tool.
   */
  protected function tool(string $id) {
    $tool = $this->toolManager->createInstance($id);
    if ($id === self::SURFACE_TOOL) {
      $tool->setInputValue('storage', 'entity_test.field_address');
      $tool->setInputValue('bundle', 'entity_test');
      return $tool;
    }
    $tool->setInputValue('entity_type_id', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    return $tool;
  }

  /**
   * Builds the settings schema one tool advertises for the address field.
   *
   * @param string $id
   *   The tool plugin identifier.
   *
   * @return array
   *   The JSON Schema of the tool's settings input, refined.
   */
  protected function settingsSchema(string $id): array {
    $tool = $this->tool($id);
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
    return $id === self::SURFACE_TOOL
      ? $schema['properties'][SituationInputs::VALUES]['properties']['settings']
      : $schema['properties']['settings'];
  }

  /**
   * Tests what the surface derived settings input says.
   */
  public function testSurfaceSchemaCarriesMeaning(): void {
    $schema = $this->settingsSchema(self::SURFACE_TOOL);

    // Every address property that may be overridden is named, so a
    // caller never has to guess the vocabulary.
    $overrides = $schema['properties']['field_overrides']['properties'];
    $this->assertArrayHasKey('givenName', $overrides);
    $this->assertArrayHasKey('administrativeArea', $overrides);
    // And the values each one takes, with a label a person can read. The
    // Tool API lists null among an optional input's values (#3583072).
    $this->assertSame(['hidden', 'optional', 'required', NULL], $overrides['givenName']['enum']);
    $this->assertSame('First name', $overrides['givenName']['title']);
    $this->assertSame('Organization', $overrides['organization']['title']);

    // The country list that validates is the country list that is
    // offered, resolved live rather than repeated in a schema file.
    $this->assertContains('US', $schema['properties']['available_countries']['items']['enum']);
    $this->assertContains('CA', $schema['properties']['available_countries']['items']['enum']);
    $this->assertSame('Available countries', $schema['properties']['available_countries']['title']);
    $this->assertSame(
      'Available countries: Leave empty for all countries.',
      $schema['properties']['available_countries']['description'],
    );
    $this->assertSame('Country', $schema['properties']['available_countries']['items']['title']);

    // The deprecated key, which silently overrules the one beside it, is
    // not offered at all.
    $this->assertArrayNotHasKey('fields', $schema['properties']);
    // Neither is the single-key wrapper the field type stores each
    // override in; that is storage shape, and the target owns it.
    $this->assertArrayNotHasKey('override', $overrides['givenName']);
    $this->assertArrayNotHasKey('items', $schema['properties']['field_overrides']);
  }

  /**
   * Tests that the derived tools say at least what the retired ones did.
   *
   * The hand-written data_surface:field_add advertised an entity type
   * with its vocabulary, a label, help text and a required flag, each
   * with a sentence saying what it is, and the address settings. The
   * tools derived from the field instance surface's situations say all
   * of it, from the surface, and more: the storage's cardinality with
   * its range, and the field name's shape.
   */
  public function testTheDerivedToolsSayAtLeastWhatTheRetiredOnesDid(): void {
    $add = $this->toolManager->createInstance('data_surface:field.instance:add');
    $add->setInputValue('entity_type_id', 'entity_test');
    $add->setInputValue('bundle', 'entity_test');
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($add);
    // A situation parameter named for a surface key is that key.
    $entity_type = $schema['properties']['entity_type_id'];
    $this->assertSame('Entity type', $entity_type['title']);
    $this->assertStringContainsString('The machine name of the entity type', $entity_type['description']);
    $this->assertContains('entity_test', $entity_type['enum']);
    $this->assertSame(['entity_type_id', 'bundle', SituationInputs::VALUES], $schema['required']);
    $values = $schema['properties'][SituationInputs::VALUES]['properties'];
    // Every field type in the UI: address's own settings surface, the
    // rest derived from config schema.
    $this->assertSame('address', $values['field_type']['enum'][0]);
    $this->assertContains('string', $values['field_type']['enum']);
    $this->assertSame('^[_a-z]+[_a-z0-9]*$', $values['field_name']['pattern']);
    $this->assertSame(32, $values['field_name']['maxLength']);

    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($this->tool(self::SURFACE_TOOL));
    $values = $schema['properties'][SituationInputs::VALUES];
    $this->assertSame(['label'], $values['required']);
    $this->assertSame('Label', $values['properties']['label']['title']);
    $this->assertStringContainsString('The human readable label for the field on this bundle.', $values['properties']['label']['description']);
    $this->assertSame('Help text', $values['properties']['description']['title']);
    $this->assertStringContainsString('Help text to display for the field.', $values['properties']['description']['description']);
    $this->assertSame('Required field', $values['properties']['required']['title']);
    $this->assertStringContainsString('Whether the field is required.', $values['properties']['required']['description']);
    $this->assertSame(-1, $values['properties']['storage']['properties']['cardinality']['minimum']);
    $this->assertSame(1, $values['properties']['storage']['properties']['cardinality']['default']);
    // A false default does not survive the normalizer; the definition
    // carries it.
    $values = $this->tool(self::SURFACE_TOOL)->getInputDefinition(SituationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $this->assertFalse($values->getPropertyDefinitions()['required']->getDefaultValue());
  }

  /**
   * Tests what the config schema derived settings input says.
   */
  public function testSchemaDerivedInputSaysLess(): void {
    $schema = $this->settingsSchema(self::BELT_TOOL);

    // Three types and a deprecated fourth key, with no hint that it
    // overrules the third whenever it is not empty.
    $this->assertSame(
      ['available_countries', 'langcode_override', 'field_overrides', 'fields'],
      array_keys($schema['properties']),
    );
    // No vocabulary anywhere: not for the countries, not for the
    // overrides, not even for which properties may be overridden.
    $this->assertArrayNotHasKey('enum', $schema['properties']['available_countries']['items']);
    $this->assertArrayNotHasKey('enum', $schema['properties']['langcode_override']);
    // The overrides arrive as the serialized storage shape, an array of
    // single-key objects with no key to put a property name in, so the
    // one thing a caller most needs to say cannot be said at all.
    $this->assertArrayHasKey('items', $schema['properties']['field_overrides']);
    $this->assertSame(
      ['override'],
      array_keys($schema['properties']['field_overrides']['items']['properties']),
    );
    $this->assertArrayNotHasKey('properties', $schema['properties']['field_overrides']);
  }

  /**
   * Tests that the surface's defaults survive the conversion.
   *
   * They do not survive normalization: the Tool API's JSON Schema
   * normalizer emits a default only on the scalar path and only when the
   * value is truthy, so an empty list default and a null default are
   * both dropped. The definitions carry them either way, which is where
   * the next consumer can pick them up.
   */
  public function testDefaultsSurviveTheConversionButNotTheSchema(): void {
    $values = $this->tool(self::SURFACE_TOOL)->getInputDefinition(SituationInputs::VALUES);
    assert($values instanceof MapInputDefinition);
    $definition = $values->getPropertyDefinitions()['settings'];
    assert($definition instanceof MapInputDefinition);
    $properties = $definition->getPropertyDefinitions();

    $this->assertSame([], $properties['available_countries']->getDefaultValue());
    $this->assertSame([], $properties['field_overrides']->getDefaultValue());
    $this->assertNull($properties['langcode_override']->getDefaultValue());

    $schema = $this->settingsSchema(self::SURFACE_TOOL);
    $this->assertArrayNotHasKey('default', $schema['properties']['available_countries']);
  }

  /**
   * Tests that the checked-in comparison still matches the generator.
   *
   * The recorder this replaces was a generator wearing a test's clothes:
   * it was skipped on every ordinary run and wrote a file on the one run
   * nobody made, so the document in the repository was whatever it had
   * been the last time somebody remembered. The generator now lives in
   * scripts/generate-comparison.php, and what is left here is the only
   * thing a test can usefully say about a generated file — that it is
   * still the file the generator would write.
   *
   * The same renderer produces both sides, so a drift can only mean the
   * two schemas changed, which is exactly the change worth noticing.
   * Running with DATA_SURFACE_WRITE_COMPARISON=1 writes the new document
   * first, which is what the script does.
   */
  public function testComparisonHasNotDrifted(): void {
    $surface = $this->settingsSchema(self::SURFACE_TOOL);
    $belt = $this->settingsSchema(self::BELT_TOOL);
    $this->assertNotSame($surface, $belt);

    $document = \DataSurfaceComparisonDocument::render($surface, $belt);
    $path = dirname(__DIR__, 3) . '/modules/data_surface_tool/COMPARISON.md';
    if (getenv(\DataSurfaceComparisonDocument::WRITE_VARIABLE) === '1') {
      file_put_contents($path, $document);
    }

    $this->assertFileExists($path);
    $this->assertSame(
      \DataSurfaceComparisonDocument::normalize($document),
      \DataSurfaceComparisonDocument::normalize((string) file_get_contents($path)),
      'modules/data_surface_tool/COMPARISON.md is out of date. Regenerate it with scripts/generate-comparison.php.',
    );
  }

}
