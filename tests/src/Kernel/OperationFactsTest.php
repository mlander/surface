<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface_examples\Resource\RegistrationStep3Resource;
use Drupal\data_surface_tool_test\Resource\LogbookResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an operation states how it behaves, and where that is read.
 *
 * `#[Operation]` says whether a call changes nothing, whether it may
 * destroy what is there, and, unless derived, whether it is safe to
 * repeat. Discovery carries the facts on the operation's definition, the
 * tool deriver maps them onto the Tool API's operation kind and
 * destructive flag, which the MCP bridge reads as a tool's annotations,
 * and both schema documents state them at the top. The test module's
 * logbook has one operation of each kind.
 *
 * @see docs/decisions.md#an-operation-states-how-it-behaves
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class OperationFactsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_tool_test',
  ];

  /**
   * The facts each of the logbook's operations states, in the order given.
   *
   * Creates, read only, destructive, idempotent.
   */
  protected const LOGBOOK = [
    'configure' => [FALSE, FALSE, FALSE, TRUE],
    'start' => [TRUE, FALSE, FALSE, FALSE],
    'append' => [FALSE, FALSE, FALSE, FALSE],
    'clear' => [FALSE, FALSE, TRUE, TRUE],
    'inspect' => [FALSE, TRUE, FALSE, TRUE],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the attribute says nothing it was not told.
   */
  public function testTheAttributeStatesNothingByDefault(): void {
    $operation = new Operation('configure', 'Configure');
    $this->assertFalse($operation->destructive);
    $this->assertFalse($operation->readOnly);
    $this->assertNull($operation->idempotent, 'Derived unless declared.');
  }

  /**
   * Tests discovery carries the facts, and idempotence is derived.
   *
   * An operation that does not create is idempotent, one that creates is
   * not, a read only one is, and a declaration wins over either.
   */
  public function testDiscoveryCarriesTheFacts(): void {
    $resources = $this->container->get('data_surface.resources');
    $registry = $this->container->get('data_surface.resource_registry');
    foreach (self::LOGBOOK as $id => [$creates, $read_only, $destructive, $idempotent]) {
      $operation = $registry->getOperation(LogbookResource::class, $id);
      $this->assertSame($read_only, $operation->readOnly, $id);
      $this->assertSame($destructive, $operation->destructive, $id);
      $this->assertSame($id === 'append' ? FALSE : NULL, $operation->idempotent, $id . ' declares only what it must.');
      $context = $resources->operation(LogbookResource::class, $id);
      $this->assertSame($idempotent, $operation->idempotent($context->creates), $id);
      $this->assertSame([
        'id' => $id,
        'label' => (string) $operation->label,
        'creates' => $creates,
        'readOnly' => $read_only,
        'destructive' => $destructive,
        'idempotent' => $idempotent,
      ], $operation->facts($context), $id);
    }
  }

  /**
   * Tests facts that contradict each other are refused, naming both.
   */
  public function testFactsThatContradictAreRefused(): void {
    $contradictions = [
      'destroys' => static fn (): OperationDefinition => new OperationDefinition('peek', 'Peek', 'example', self::class, 'peek', NULL, 'data_surface', readOnly: TRUE, destructive: TRUE),
      'is not idempotent' => static fn (): OperationDefinition => new OperationDefinition('peek', 'Peek', 'example', self::class, 'peek', NULL, 'data_surface', readOnly: TRUE, idempotent: FALSE),
    ];
    foreach ($contradictions as $what => $make) {
      try {
        $make();
        $this->fail('A read only operation that ' . $what . ' is refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString('says it changes nothing and also that it', $e->getMessage(), $what);
      }
    }
  }

  /**
   * Tests the deriver maps the facts onto the Tool API, and what MCP reads.
   *
   * A read only operation is the Read kind, any other the Write kind, and
   * a destructive one says so. The MCP bridge reads read only from a kind
   * that does not modify, idempotent from the kind, and destructive from
   * the flag (McpToolConfigDeriver), so an operation that changes what
   * exists reads as not idempotent there: idempotence is the kind's in
   * the Tool API, and every kind that modifies is not idempotent.
   */
  public function testTheDeriverMapsTheFacts(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $definitions = $manager->getDefinitions();
    $annotations = static fn (ToolDefinition $definition): array => [
      !$definition->getOperation()->isModifying(),
      $definition->isDestructive(),
      $definition->getOperation()->isIdempotent(),
    ];
    $expected = [
      'configure' => [ToolOperation::Write, [FALSE, FALSE, FALSE]],
      'start' => [ToolOperation::Write, [FALSE, FALSE, FALSE]],
      'append' => [ToolOperation::Write, [FALSE, FALSE, FALSE]],
      'clear' => [ToolOperation::Write, [FALSE, TRUE, FALSE]],
      'inspect' => [ToolOperation::Read, [TRUE, FALSE, TRUE]],
    ];
    foreach ($expected as $id => [$kind, $mcp]) {
      $definition = $definitions['data_surface:tool_test.logbook:' . $id];
      $this->assertInstanceOf(ToolDefinition::class, $definition);
      $this->assertSame($kind, $definition->getOperation(), $id);
      $this->assertSame($mcp, $annotations($definition), $id . ': read only, destructive, idempotent, as MCP reads them.');
    }
    // A read tool is read only by what it is; nothing beside an operation
    // that creates or one that changes nothing is read.
    foreach (['configure', 'append', 'clear'] as $id) {
      $read = $definitions['data_surface:tool_test.logbook:' . $id . ':read'];
      $this->assertInstanceOf(ToolDefinition::class, $read);
      $this->assertSame([TRUE, FALSE, TRUE], $annotations($read), $id . ':read');
    }
    $this->assertArrayNotHasKey('data_surface:tool_test.logbook:start:read', $definitions);
    $this->assertArrayNotHasKey('data_surface:tool_test.logbook:inspect:read', $definitions);

    // The examples state nothing, and their tools are what they were.
    $example = $definitions['data_surface:registration.step3:configure'];
    $this->assertInstanceOf(ToolDefinition::class, $example);
    $this->assertSame(ToolOperation::Write, $example->getOperation());
    $this->assertFalse($example->isDestructive());
  }

  /**
   * Tests both schema documents state the facts at the top.
   *
   * Under the top's `x-surface`, first, beside the title that is the
   * operation's label; a document asked for in no operation states none.
   */
  public function testBothDocumentsStateTheFacts(): void {
    $resources = $this->container->get('data_surface.resources');
    $registry = $this->container->get('data_surface.resource_registry');
    $emitter = $this->container->get('data_surface.contract_emitter');
    foreach ([RegistrationStep3Resource::class => 'configure', LogbookResource::class => 'start'] as $resource => $id) {
      $operation = $registry->getOperation($resource, $id);
      $context = $resources->operation($resource, $id);
      $built = $resources->build($resource, $context);
      $facts = $operation->facts($context);
      $resource_id = $registry->getDefinition($resource)->id;
      $documents = [
        'the schema for these answers' => $emitter->emit($built, $built->getDefaultValues(), $resource_id, $operation->id, $operation->label, operation: $facts)->document['schema'],
        'the full schema' => $emitter->emitFull($resources->buildStatic($resource, $context), $resource_id, $operation->id, $operation->label, operation: $facts)->document['schema'],
      ];
      foreach ($documents as $which => $schema) {
        $this->assertSame(['$schema', 'title', 'x-surface'], array_slice(array_keys($schema), 0, 3), $which);
        $this->assertSame($facts, $schema['x-surface']['operation'], $which);
      }
    }
    $this->assertSame([
      'id' => 'configure',
      'label' => 'Configure registration',
      'creates' => FALSE,
      'readOnly' => FALSE,
      'destructive' => FALSE,
      'idempotent' => TRUE,
    ], $registry->getOperation(RegistrationStep3Resource::class, 'configure')->facts(new OperationContext('configure')));

    $built = $resources->buildOperation(RegistrationStep3Resource::class, 'configure', []);
    $this->assertArrayNotHasKey('x-surface', $emitter->emit($built, [], 'registration.step3')->document['schema']);
    $this->assertArrayNotHasKey('x-surface', $emitter->emitFull($built, 'registration.step3')->document['schema']);
  }

}
