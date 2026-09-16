<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\tool\Tool\ToolManager;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Group;

/**
 * Compares the settings input two field tools advertise for one field type.
 *
 * Both tools take the same seven inputs and both answer the same
 * question: given an entity type, a bundle and a field name, what may
 * the settings be? One derives its answer from the field type's config
 * schema, the other from the field type's surface, and the two answers
 * are put side by side in the data_surface_tool module's COMPARISON.md.
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
   * Builds the settings schema one tool advertises for the address field.
   *
   * @param string $id
   *   The tool plugin identifier.
   *
   * @return array
   *   The JSON Schema of the tool's settings input, refined.
   */
  protected function settingsSchema(string $id): array {
    $tool = $this->toolManager->createInstance($id);
    $tool->setInputValue('entity_type_id', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    $schema = $this->container->get('tool.definition_serializer')->normalizeInputSchema($tool);
    return $schema['properties']['settings'];
  }

  /**
   * Tests what the surface derived settings input says.
   */
  public function testSurfaceSchemaCarriesMeaning(): void {
    $schema = $this->settingsSchema('data_surface:field_add');

    // Every address property that may be overridden is named, so a
    // caller never has to guess the vocabulary.
    $overrides = $schema['properties']['field_overrides']['properties'];
    $this->assertArrayHasKey('givenName', $overrides);
    $this->assertArrayHasKey('administrativeArea', $overrides);
    // And the values each one takes, with a label a person can read.
    $this->assertSame(['hidden', 'optional', 'required'], $overrides['givenName']['enum']);
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
   * Tests what the config schema derived settings input says.
   */
  public function testSchemaDerivedInputSaysLess(): void {
    $schema = $this->settingsSchema('tool_belt:field_add');

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
    $tool = $this->toolManager->createInstance('data_surface:field_add');
    $tool->setInputValue('entity_type_id', 'entity_test');
    $tool->setInputValue('bundle', 'entity_test');
    $tool->setInputValue('field_name', 'field_address');
    $definition = $tool->getInputDefinition('settings');
    assert($definition instanceof MapInputDefinition);
    $properties = $definition->getPropertyDefinitions();

    $this->assertSame([], $properties['available_countries']->getDefaultValue());
    $this->assertSame([], $properties['field_overrides']->getDefaultValue());
    $this->assertNull($properties['langcode_override']->getDefaultValue());

    $schema = $this->settingsSchema('data_surface:field_add');
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
    $surface = $this->settingsSchema('data_surface:field_add');
    $belt = $this->settingsSchema('tool_belt:field_add');
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
