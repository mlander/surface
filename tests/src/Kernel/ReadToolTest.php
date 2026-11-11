<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Routing\RouteMatch;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface_examples\ExampleCalls;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_tool_test\Storage\VaultStorage;
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
use Symfony\Component\Routing\Route;

/**
 * Tests the read of one thing, an operation of the resource.
 *
 * Every resource that can be asked on its own and has a default update
 * has one, `surface:<resource>:read`: the one its owner declares, or the
 * one the build adds beside the default update. Its inputs are the
 * operation's parameters, its one output `values`, what the storage
 * loads, in the shape the update takes, less every secret. The read the
 * build adds asks the update's access.
 *
 * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ReadToolTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The compliance module, whose keys step 3 mounts.
   */
  protected const COMPLIANCE = 'surface_examples_compliance';

  /**
   * The read tools these modules derive, sorted, each by its update's tool.
   */
  protected const READ_TOOLS = [
    'surface:event.bulletin:read' => 'surface:event.bulletin:configure',
    'surface:event.catering.method:read' => 'surface:event.catering.method:configure',
    'surface:event.catering:read' => 'surface:event.catering:configure',
    'surface:event.desk:read' => 'surface:event.desk:configure',
    'surface:event.doors:read' => 'surface:event.doors:configure',
    'surface:event.mailer:read' => 'surface:event.mailer:configure',
    'surface:event.notices:read' => 'surface:event.notices:configure',
    'surface:event.press:read' => 'surface:event.press:configure',
    'surface:event.program:read' => 'surface:event.program:configure',
    'surface:event.sales:read' => 'surface:event.sales:configure',
    'surface:event.setup:read' => 'surface:event.setup:configure',
    'surface:event.spotlight:read' => 'surface:event.spotlight:configure',
    'surface:event.venue:read' => 'surface:event.venue:update',
    'surface:event.webhook:read' => 'surface:event.webhook:configure',
    'surface:field.instance:read' => 'surface:field.instance:update',
    'surface:field.storage:read' => 'surface:field.storage:update',
    'surface:node.type:read' => 'surface:node.type:update',
    'surface:registration.step1:read' => 'surface:registration.step1:configure',
    'surface:registration.step2:read' => 'surface:registration.step2:configure',
    'surface:registration.step3:read' => 'surface:registration.step3:configure',
    'surface:surface.stage:read' => 'surface:surface.stage:update',
    'surface:tool_test.cabinet:read' => 'surface:tool_test.cabinet:update',
    'surface:tool_test.logbook:read' => 'surface:tool_test.logbook:configure',
    'surface:tool_test.specimen:read' => 'surface:tool_test.specimen:configure',
    'surface:tool_test.vault:read' => 'surface:tool_test.vault:configure',
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
    'surface',
    'surface_http',
    'surface_examples_node_type',
    'surface_examples_field',
    'surface_examples',
    'surface_examples_compliance',
    'surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'surface_examples']);
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
   * Tests which reads exist: one per resource with a default update.
   *
   * Each is an operation of its resource, `read`, which the build added
   * beside the default update, read only, provided by that update. One
   * update beside the default (the venues' reorder, the logbook's append)
   * has no read of its own: the resource's read is the default update's.
   * A resource whose only operations create has none.
   */
  public function testOneReadPerResourceThatCanBeChanged(): void {
    $definitions = $this->container->get('plugin.manager.tool')->getDefinitions();
    $ids = array_values(array_filter(
      array_keys($definitions),
      static fn (string $id): bool => str_starts_with($id, 'surface:') && str_ends_with($id, ':read'),
    ));
    sort($ids);
    $this->assertSame(array_keys(self::READ_TOOLS), $ids);
    $registry = $this->container->get('surface.resource_registry');
    foreach (self::READ_TOOLS as $id => $update) {
      [, $resource] = explode(':', $id, 2);
      $resource = substr($resource, 0, -strlen(':read'));
      $read = $registry->getOperation($resource, 'read');
      $this->assertTrue($read->readsOne(), $id);
      $this->assertSame(substr($update, strrpos($update, ':') + 1), $read->reads, $id . ': the build added it beside the default update.');
      $this->assertSame('default read of ' . $read->reads, $read->provider(), $id);
      $this->assertArrayHasKey($update, $definitions);
    }
    $beside = [
      'event.venue:reorder',
      'tool_test.logbook:append',
      'tool_test.logbook:clear',
      'node.type:create',
      'field.instance:create',
    ];
    foreach ($beside as $operation) {
      $this->assertArrayHasKey('surface:' . $operation, $definitions);
      $this->assertArrayNotHasKey('surface:' . $operation . ':read', $definitions, $operation . ': no read is derived beside an operation.');
    }

    // A resource whose only operation creates: nothing exists to read.
    $type = $registry->getDefinition('node.type');
    $creates = array_filter($type->operations, static fn ($operation): bool => $operation->creates);
    $this->assertNotSame([], $creates);
    $default = new \ReflectionMethod($registry, 'defaultRead');
    $this->assertNull($default->invoke($registry, $type, array_values($creates)));
    $this->assertNotNull($default->invoke($registry, $type, array_values(array_filter($type->operations, static fn ($operation): bool => $operation->id !== 'read'))));
  }

  /**
   * Tests what a read tool's definition says before anyone calls it.
   *
   * A read operation, not destructive; the update's parameters as its
   * inputs, exactly, and nothing else; one output, a map whose properties
   * are the update's values, with every secret left out.
   */
  public function testDefinitionIsTheParametersAndTheValues(): void {
    foreach (self::READ_TOOLS as $id => $update) {
      $read = $this->definition($id);
      $write = $this->definition($update);
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
      // A secret at the top is left out of the read.
      $secrets = match ($id) {
        'surface:tool_test.vault:read' => ['token'],
        'surface:event.webhook:read' => ['api_key'],
        default => [],
      };
      // A computed key is in the read and not in the write: nobody types it.
      $computed = match ($id) {
        'surface:event.venue:read' => ['slug'],
        default => [],
      };
      $expected = array_values(array_diff(self::properties($written), $secrets));
      $this->assertSame($expected, array_values(array_diff(self::properties($values), $computed)), $id);
      $this->assertSame($computed, array_values(array_intersect(self::properties($values), $computed)), $id);
      $this->assertSame([], array_values(array_intersect(self::properties($written), $computed)), $id);
      foreach ($values->getPropertyDefinitions() as $name => $property) {
        $this->assertFalse($property->isRequired(), $id . ' ' . $name);
        $promises = array_intersect_key($property->getConstraints(), array_flip(self::PROMISES));
        $this->assertSame([], $promises, $id . ' ' . $name);
      }
    }

    // The keys less the identity the operation knows: the content type's
    // machine name, and everything that says which field it is.
    $this->assertSame(['type', OperationInputs::STAGE], array_keys($this->definition('surface:node.type:read')->getInputDefinitions(TRUE)));
    $this->assertNotContains('type', self::properties($this->readValues('surface:node.type:read')));
    $this->assertSame(['entity_type', 'bundle', 'field_name'], array_keys($this->definition('surface:field.instance:read')->getInputDefinitions(TRUE)));
    $field = self::properties($this->readValues('surface:field.instance:read'));
    $this->assertSame([], array_intersect(['entity_type', 'bundle', 'field_name', 'field_type'], $field));
    $this->assertContains('settings', $field);
    $this->assertContains('storage', $field);

    // Step 3 needs nothing and takes only the stage, and its map nests as
    // the write tool's does: the variants, the attached contact, and the
    // keys the compliance module mounts under third_party_settings.
    $step3 = $this->definition(ExampleCalls::READ_TOOL);
    $this->assertSame([OperationInputs::STAGE], array_keys($step3->getInputDefinitions(TRUE)));
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
    $this->assertSame('Harbour night', $this->config('surface_examples.registration_step3')->get('title'));
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

    $read = $this->runTool('surface:node.type:read', ['type' => 'article']);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Edit a content type: the stored values were read, and nothing was written.', $read['message']);
    $this->assertArrayNotHasKey('type', $read['values']);
    $this->assertSame('Article', $read['values']['name']);
    $this->assertSame('News and such.', $read['values']['description']);
    $this->assertSame(NodePreviewMode::Required->value, $read['values']['preview_mode']);

    $read = $this->runTool('surface:node.type:read', ['type' => 'page']);
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
    $read = $this->runTool('surface:node.type:read', ['type' => 'nowhere']);
    $write = $this->runTool('surface:node.type:update', [
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
   * Tests access: the read the build adds asks what its update asks.
   *
   * Who may not change it may not read it either.
   */
  public function testAccessOfTheDefaultReadIsTheUpdates(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->setUpCurrentUser([], ['access content']);
    foreach ([ExampleCalls::READ_TOOL => [], 'surface:node.type:read' => ['type' => 'article']] as $id => $inputs) {
      $read = $this->runTool($id, $inputs);
      $this->assertFalse($read['tool']->access(), $id);
      $this->assertFalse($read['success'], $id);
      $this->assertSame('You do not have permission to do this.', $read['message'], $id);
      $this->assertSame([], $read['values'], $id . ': nothing read, in the one result shape.');
    }

    // The content type's operation asks for the example's permission beside
    // core's, so core's alone is not enough, here as for a write.
    $this->setUpCurrentUser([], ['administer content types']);
    $this->assertFalse($this->runTool('surface:node.type:read', ['type' => 'article'])['tool']->access());
    $this->setUpCurrentUser([], ['administer content types', 'administer surface examples content types']);
    $read = $this->runTool('surface:node.type:read', ['type' => 'article']);
    $this->assertTrue($read['tool']->access());
    $this->assertTrue($read['success'], $read['message']);

    $this->setUpCurrentUser([], ['administer site configuration']);
    $this->assertTrue($this->runTool(ExampleCalls::READ_TOOL)['tool']->access());
  }

  /**
   * Tests a form route to the read the build adds is refused.
   *
   * That read is asked in its update's context, so a form for it would
   * be the update's form, and would write; the update's route serves it.
   */
  public function testNoFormServesTheDefaultRead(): void {
    $route = new Route('/registration/read', [
      OperationRoute::RESOURCE => 'registration.step3',
      OperationRoute::OPERATION => 'read',
    ]);
    try {
      OperationRoute::fromRouteMatch(new RouteMatch('example.read', $route), $this->container->get('surface.resource_registry'), $this->container->get('surface.operation_arguments'), $this->container->get('surface.resources'));
      $this->fail('A form route to the default read was served.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('The example.read route serves the "read" operation of ' . RegistrationStep3Resource::class . ', the read the build adds beside "configure", which is asked as that update is: a form for it would write. Serve the "configure" operation, or declare "read" on the resource.', $e->getMessage());
    }
  }

  /**
   * Tests that a secret is never returned, at the top, in a child or item.
   *
   * Inside a collection's items only the secret is left out: the list
   * and every other key of each item are read.
   */
  public function testSecretIsNeverReturned(): void {
    $write = $this->runTool('surface:tool_test.vault:configure', [
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

    $read = $this->runTool('surface:tool_test.vault:read');
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
    $values = $this->definition('surface:tool_test.vault:read')->getOutputDefinition(OperationInputs::VALUES);
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
