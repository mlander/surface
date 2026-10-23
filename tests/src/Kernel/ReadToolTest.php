<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface_examples\ExampleCalls;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool_test\Storage\VaultStorage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the read tool derived beside each write tool.
 *
 * Every operation that can be asked on its own and changes a thing that
 * exists has one, `data_surface:<resource>:<operation>:read`: its inputs
 * the operation's parameters, its one output `values`, what the storage
 * loads, in the shape the write tool takes, less every secret. Its access
 * is the write tool's.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReadToolTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The compliance module, whose keys step 3 mounts.
   */
  protected const COMPLIANCE = 'data_surface_examples_compliance';

  /**
   * The read tools these modules derive, sorted.
   */
  protected const READ_TOOLS = [
    'data_surface:event.bulletin:configure:read',
    'data_surface:event.desk:configure:read',
    'data_surface:event.doors:configure:read',
    'data_surface:event.press:configure:read',
    'data_surface:event.program:configure:read',
    'data_surface:event.sales:configure:read',
    'data_surface:event.spotlight:configure:read',
    'data_surface:field.instance:edit:read',
    'data_surface:field.storage:edit:read',
    'data_surface:node.type:edit:read',
    'data_surface:registration.step1:configure:read',
    'data_surface:registration.step2:configure:read',
    'data_surface:registration.step3:configure:read',
    'data_surface:tool_test.cabinet:edit:read',
    'data_surface:tool_test.logbook:append:read',
    'data_surface:tool_test.logbook:clear:read',
    'data_surface:tool_test.logbook:configure:read',
    'data_surface:tool_test.specimen:configure:read',
    'data_surface:tool_test.vault:configure:read',
  ];

  /**
   * The constraints that would promise a stored value is still allowed.
   */
  protected const PROMISES = ['Choice', 'LabeledChoice', 'Range', 'Regex'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_demo_node_type',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets one tool's definition.
   *
   * @param string $id
   *   The plugin id.
   *
   * @return \Drupal\tool\Tool\ToolDefinition
   *   The definition.
   */
  protected function definition(string $id): ToolDefinition {
    $definition = $this->container->get('plugin.manager.tool')->getDefinition($id);
    $this->assertInstanceOf(ToolDefinition::class, $definition);
    return $definition;
  }

  /**
   * Gets a read tool's values output.
   *
   * @param string $id
   *   The plugin id.
   *
   * @return \Drupal\tool\TypedData\MapOutputDefinition
   *   The map.
   */
  protected function readValues(string $id): MapOutputDefinition {
    $values = $this->definition($id)->getOutputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapOutputDefinition::class, $values);
    return $values;
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{tool: \Drupal\tool\Tool\ToolInterface, success: bool, message: string, values: mixed}
   *   The tool, whether it succeeded, its message and its values output.
   */
  protected function runTool(string $id, array $inputs = []): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'tool' => $tool,
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'values' => $result->getContextValues()[OperationInputs::VALUES] ?? NULL,
    ];
  }

  /**
   * Gets the property names of a map, at the top.
   *
   * @param \Drupal\tool\TypedData\MapInputDefinition|\Drupal\tool\TypedData\MapOutputDefinition $map
   *   The map.
   *
   * @return string[]
   *   Its property names, in order.
   */
  protected static function properties(MapInputDefinition|MapOutputDefinition $map): array {
    return array_map('strval', array_keys($map->getPropertyDefinitions()));
  }

  /**
   * Tests which read tools exist: one per operation that changes a thing.
   */
  public function testOneReadToolPerOperationThatChangesWhatExists(): void {
    $ids = array_values(array_filter(
      array_keys($this->container->get('plugin.manager.tool')->getDefinitions()),
      static fn (string $id): bool => str_starts_with($id, 'data_surface:') && str_ends_with($id, ':read'),
    ));
    sort($ids);
    $this->assertSame(self::READ_TOOLS, $ids);
    // An operation that creates has a write tool and no read: the content
    // type's add needs nothing and says so; the field's add leaves its
    // name to the caller, and its reuse is an add on another bundle.
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    foreach (['node.type:add', 'field.instance:add', 'field.instance:reuse'] as $operation) {
      $this->assertArrayHasKey('data_surface:' . $operation, $definitions);
      $this->assertArrayNotHasKey('data_surface:' . $operation . ':read', $definitions);
    }
  }

  /**
   * Tests what a read tool's definition says before anyone calls it.
   *
   * A read operation, not destructive; the write tool's parameters as its
   * inputs, exactly, and nothing else; one output, a map whose properties
   * are the write tool's values, with every secret left out.
   */
  public function testDefinitionIsTheParametersAndTheValues(): void {
    foreach (self::READ_TOOLS as $id) {
      $read = $this->definition($id);
      $write = $this->definition(substr($id, 0, -strlen(':read')));
      $this->assertSame(ToolOperation::Read, $read->getOperation(), $id);
      $this->assertFalse($read->isDestructive(), $id);
      $this->assertSame([], $read->getInputDefinitionRefiners(), $id);

      $own = [OperationInputs::VALUES, OperationInputs::DRY_RUN];
      $parameters = array_diff_key($write->getInputDefinitions(TRUE), array_flip($own));
      $inputs = $read->getInputDefinitions(TRUE);
      $this->assertSame(array_keys($parameters), array_keys($inputs), $id);
      foreach ($parameters as $name => $parameter) {
        $this->assertSame($parameter->getDataType(), $inputs[$name]->getDataType(), $id . ' ' . $name);
        $this->assertSame($parameter->isRequired(), $inputs[$name]->isRequired(), $id . ' ' . $name);
        $this->assertSame((string) $parameter->getLabel(), (string) $inputs[$name]->getLabel(), $id . ' ' . $name);
        $this->assertSame((string) $parameter->getDescription(), (string) $inputs[$name]->getDescription(), $id . ' ' . $name);
      }

      $this->assertSame([OperationInputs::VALUES], array_keys($read->getOutputDefinitions()), $id);
      $values = $read->getOutputDefinition(OperationInputs::VALUES);
      $this->assertInstanceOf(MapOutputDefinition::class, $values, $id);
      $this->assertTrue($values->isRequired(), $id);
      $written = $write->getInputDefinition(OperationInputs::VALUES);
      $this->assertInstanceOf(MapInputDefinition::class, $written, $id);
      $expected = array_values(array_diff(self::properties($written), $id === 'data_surface:tool_test.vault:configure:read' ? ['token'] : []));
      $this->assertSame($expected, self::properties($values), $id);
      foreach ($values->getPropertyDefinitions() as $name => $property) {
        $this->assertFalse($property->isRequired(), $id . ' ' . $name);
        $promises = array_intersect_key($property->getConstraints(), array_flip(self::PROMISES));
        $this->assertSame([], $promises, $id . ' ' . $name);
      }
    }

    // The keys less the identity the operation knows: the content type's
    // machine name, and everything that says which field it is.
    $this->assertSame(['type'], array_keys($this->definition('data_surface:node.type:edit:read')->getInputDefinitions(TRUE)));
    $this->assertNotContains('type', self::properties($this->readValues('data_surface:node.type:edit:read')));
    $this->assertSame(['field'], array_keys($this->definition('data_surface:field.instance:edit:read')->getInputDefinitions(TRUE)));
    $field = self::properties($this->readValues('data_surface:field.instance:edit:read'));
    $this->assertSame([], array_intersect(['entity_type_id', 'bundle', 'field_name', 'field_type'], $field));
    $this->assertContains('settings', $field);
    $this->assertContains('storage', $field);

    // Step 3 needs nothing and takes nothing, and its map nests as the
    // write tool's does: the variants, the attached contact, and the keys the
    // compliance module mounts under third_party_settings.
    $step3 = $this->definition(ExampleCalls::READ_TOOL);
    $this->assertSame([], $step3->getInputDefinitions(TRUE));
    $values = $step3->getOutputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapOutputDefinition::class, $values);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame([
      'title',
      'open',
      'venue',
      'room',
      'capacity',
      'pricing',
      'ticket',
      'contact',
      'third_party_settings',
    ], self::properties($values));
    $this->assertSame('integer', $properties['capacity']->getDataType());
    $this->assertInstanceOf(MapOutputDefinition::class, $properties['contact']);
    $this->assertSame(['email', 'phone'], self::properties($properties['contact']));
    $mount = $properties['third_party_settings'];
    $this->assertInstanceOf(MapOutputDefinition::class, $mount);
    $compliance = $mount->getPropertyDefinitions()[self::COMPLIANCE];
    $this->assertInstanceOf(MapOutputDefinition::class, $compliance);
    $this->assertSame(['license', 'stewards'], self::properties($compliance));
    $this->assertSame('Event license', (string) $compliance->getPropertyDefinitions()['license']->getLabel());
  }

  /**
   * Tests reading step 3, before and after a write through its tool.
   */
  public function testReadsWhatIsStoredAndWhatWasWritten(): void {
    $read = $this->runTool(ExampleCalls::READ_TOOL);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Configure registration (step 3): the stored values were read, and nothing was written.', $read['message']);
    $this->assertSame([
      'title' => 'Spring meetup',
      'open' => TRUE,
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'capacity' => 50,
      'pricing' => 'free',
      'ticket' => ['note' => ''],
      'contact' => ['email' => 'events@example.com', 'phone' => ''],
    ], $read['values']);

    $write = $this->runTool(ExampleCalls::TOOL, [
      OperationInputs::VALUES => [
        'title' => 'Harbour night',
        'venue' => 'harbour',
        'room' => 'harbour_auditorium',
        'capacity' => 150,
        'pricing' => 'paid',
        'ticket' => ['price' => 12.5, 'currency' => 'EUR'],
        'third_party_settings' => [self::COMPLIANCE => ['license' => '2048', 'stewards' => 3]],
      ],
    ]);
    $this->assertTrue($write['success'], $write['message']);

    $read = $this->runTool(ExampleCalls::READ_TOOL);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Harbour night', $read['values']['title']);
    $this->assertSame('harbour_auditorium', $read['values']['room']);
    $this->assertSame(150, $read['values']['capacity']);
    $this->assertEquals(['price' => 12.5, 'currency' => 'EUR'], $read['values']['ticket']);
    $this->assertSame([self::COMPLIANCE => ['license' => '2048', 'stewards' => 3]], $read['values']['third_party_settings']);
    // Reading wrote nothing.
    $this->assertSame('Harbour night', $this->config('data_surface_examples.registration_step3')->get('title'));
  }

  /**
   * Tests the content type's read: it takes the type, and reads that one.
   */
  public function testNodeTypeReadTakesTheTypeAndReadsThatOne(): void {
    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'description' => 'News and such.',
      'preview_mode' => NodePreviewMode::Required->value,
    ])->save();
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();

    $read = $this->runTool('data_surface:node.type:edit:read', ['type' => 'article']);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Edit a content type: the stored values were read, and nothing was written.', $read['message']);
    $this->assertArrayNotHasKey('type', $read['values']);
    $this->assertSame('Article', $read['values']['name']);
    $this->assertSame('News and such.', $read['values']['description']);
    $this->assertSame(NodePreviewMode::Required->value, $read['values']['preview_mode']);

    $read = $this->runTool('data_surface:node.type:edit:read', ['type' => 'page']);
    $this->assertSame('Basic page', $read['values']['name']);
  }

  /**
   * Tests a parameter that names nothing: not found, as the write tool says.
   *
   * B13 of the 2026-10-20 reviews: an account the operation's permission
   * lets in heard "Access denied" for an id that names nothing, which an
   * agent reads as final; a wrong id is input to correct.
   */
  public function testUnknownParameterFailsAsTheWriteToolDoes(): void {
    $read = $this->runTool('data_surface:node.type:edit:read', ['type' => 'nowhere']);
    $write = $this->runTool('data_surface:node.type:edit', [
      'type' => 'nowhere',
      OperationInputs::VALUES => [
        'name' => 'Nowhere',
        'title_label' => 'Title',
        'preview_mode' => NodePreviewMode::Optional->value,
      ],
    ]);
    $this->assertFalse($read['success']);
    $this->assertFalse($write['success']);
    $this->assertSame($write['message'], $read['message']);
    $this->assertStringStartsWith('Not found: ', $read['message']);
    $this->assertStringContainsString('there is none with the id "nowhere"', $read['message']);
    $this->assertSame(FailureCategory::Input, $read['tool']->getResult()->getFailureCategory());
    $this->assertSame(FailureCategory::Input, $write['tool']->getResult()->getFailureCategory());
    $this->assertSame($write['tool']->access(), $read['tool']->access());
    $this->assertTrue($read['tool']->access(), 'An account the permission lets in hears that the id names nothing.');
  }

  /**
   * Tests access: who may not change it may not read it either.
   */
  public function testAccessIsTheWriteTools(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser([], ['access content']);
    foreach ([ExampleCalls::READ_TOOL => [], 'data_surface:node.type:edit:read' => ['type' => 'article']] as $id => $inputs) {
      $read = $this->runTool($id, $inputs);
      $this->assertFalse($read['tool']->access(), $id);
      $this->assertFalse($read['success'], $id);
      $this->assertSame('You do not have permission to do this.', $read['message'], $id);
      $this->assertSame([], $read['values'], $id . ': nothing read, in the one result shape.');
    }

    // The content type's operation asks for the demo's permission beside
    // core's, so core's alone is not enough, here as for a write.
    $this->setUpCurrentUser([], ['administer content types']);
    $this->assertFalse($this->runTool('data_surface:node.type:edit:read', ['type' => 'article'])['tool']->access());
    $this->setUpCurrentUser([], ['administer content types', 'administer data surface node type demo']);
    $read = $this->runTool('data_surface:node.type:edit:read', ['type' => 'article']);
    $this->assertTrue($read['tool']->access());
    $this->assertTrue($read['success'], $read['message']);

    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->assertTrue($this->runTool(ExampleCalls::READ_TOOL)['tool']->access());
  }

  /**
   * Tests that a secret is never returned, at the top, in a child or item.
   *
   * Inside a collection's items only the secret is left out: the list
   * and every other key of each item are read.
   */
  public function testSecretIsNeverReturned(): void {
    $write = $this->runTool('data_surface:tool_test.vault:configure', [
      OperationInputs::VALUES => [
        'endpoint' => 'https://vault.example.com',
        'token' => 'top-secret-token',
        'backup' => ['host' => 'backup.example.com', 'password' => 'hunter2'],
        'mirrors' => [
          ['host' => 'east.example.com', 'password' => 'mirror-one'],
          ['host' => 'west.example.com', 'password' => 'mirror-two'],
        ],
      ],
    ]);
    $this->assertTrue($write['success'], $write['message']);
    // Storage holds both secrets: there is something to leave out.
    $stored = $this->container->get('state')->get(VaultStorage::KEY);
    $this->assertSame('top-secret-token', $stored['token']);
    $this->assertSame('hunter2', $stored['backup']['password']);
    $this->assertSame('mirror-two', $stored['mirrors'][1]['password']);

    $read = $this->runTool('data_surface:tool_test.vault:configure:read');
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame([
      'endpoint' => 'https://vault.example.com',
      'backup' => ['host' => 'backup.example.com'],
      'mirrors' => [['host' => 'east.example.com'], ['host' => 'west.example.com']],
    ], $read['values']);
    $this->assertStringNotContainsString('mirror-', (string) json_encode($read['values']));
    $this->assertStringNotContainsString('top-secret-token', (string) json_encode($read['values']));
    $this->assertStringNotContainsString('hunter2', (string) json_encode($read['values']));

    // Nor is it described: the output says what is returned.
    $values = $this->definition('data_surface:tool_test.vault:configure:read')->getOutputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapOutputDefinition::class, $values);
    $this->assertSame(['endpoint', 'backup', 'mirrors'], self::properties($values));
    $backup = $values->getPropertyDefinitions()['backup'];
    $this->assertInstanceOf(MapOutputDefinition::class, $backup);
    $this->assertSame(['host'], self::properties($backup));
    $mirrors = $values->getPropertyDefinitions()['mirrors'];
    $this->assertInstanceOf(ListOutputDefinition::class, $mirrors);
    $mirror = $mirrors->getItemDefinition();
    $this->assertInstanceOf(MapOutputDefinition::class, $mirror);
    $this->assertSame(['host'], self::properties($mirror));
  }

}
