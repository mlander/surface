<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\OperationDefinition;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_resource_test\OutsideDiscovery\ContradictingCreatesStructure;
use Drupal\surface_resource_test\OutsideDiscovery\MisnamedContextStructure;
use Drupal\surface_resource_test\OutsideDiscovery\RetiredIdStructure;
use Drupal\surface_resource_test\OutsideDiscovery\StampedStructure;
use Drupal\surface_tool_test\Resource\LogbookResource;
use Drupal\surface_tool_test\Resource\VaultResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests an operation states how it behaves, and where that is read.
 *
 * `#[Operation]` says whether a call creates, whether it changes
 * nothing, whether it may destroy what is there, and, unless derived,
 * whether it is safe to repeat, with a description of what a call does.
 * Discovery carries the facts on the operation's definition without
 * running anything, stamps the id and the answer on the context an
 * operation's method returns and refuses one that says otherwise; the
 * tool deriver maps them onto the Tool API's operation kind and
 * destructive flag, which the MCP bridge reads as a tool's annotations,
 * and both schema documents state them at the top, the description as
 * their own. The test module's logbook has one operation of each kind,
 * all on its class.
 *
 * @see docs/decisions.md#an-operation-states-how-it-behaves
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class OperationFactsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_examples',
    'surface_resource_test',
    'surface_tool_test',
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
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the attribute says nothing it was not told.
   */
  public function testTheAttributeStatesNothingByDefault(): void {
    $operation = new Operation('configure', 'Configure');
    $this->assertFalse($operation->creates);
    $this->assertFalse($operation->destructive);
    $this->assertFalse($operation->readOnly);
    $this->assertNull($operation->idempotent, 'Derived unless declared.');
    $this->assertNull($operation->description);
  }

  /**
   * Tests discovery carries the facts, and idempotence is derived.
   *
   * An operation that does not create is idempotent, one that creates is
   * not, a read only one is, and a declaration wins over either.
   */
  public function testDiscoveryCarriesTheFacts(): void {
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    foreach (self::LOGBOOK as $id => [$creates, $read_only, $destructive, $idempotent]) {
      $operation = $registry->getOperation(LogbookResource::class, $id);
      $this->assertSame($creates, $operation->creates, $id . ': read off the attribute.');
      $this->assertSame($read_only, $operation->readOnly, $id);
      $this->assertSame($destructive, $operation->destructive, $id);
      $this->assertSame($id === 'append' ? FALSE : NULL, $operation->idempotent, $id . ' declares only what it must.');
      $this->assertSame($idempotent, $operation->idempotent(), $id);
      $context = $resources->operation(LogbookResource::class, $id);
      $this->assertSame($creates, $context->creates, $id . ': stamped on the context.');
      $this->assertSame([
        'id' => $id,
        'label' => (string) $operation->label,
        'description' => (string) $operation->description,
        'kind' => match (TRUE) {
          $read_only => OperationDefinition::READ,
          $creates => OperationDefinition::CREATE,
          default => OperationDefinition::UPDATE,
        },
        'creates' => $creates,
        'readOnly' => $read_only,
        'destructive' => $destructive,
        'idempotent' => $idempotent,
      ], $operation->facts(), $id);
    }
  }

  /**
   * Tests an operation on the class is its attribute and nothing else.
   *
   * The logbook declares its five operations on the class: there is no
   * method, and asking one builds the bare context, the attribute's id
   * and answer about creating, knowing nothing.
   */
  public function testAnOperationOnTheClassHasNoMethod(): void {
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $this->assertSame(array_keys(self::LOGBOOK), array_keys($registry->getOperations(LogbookResource::class)), 'In the order the class declares them.');
    foreach (self::LOGBOOK as $id => [$creates]) {
      $operation = $registry->getOperation(LogbookResource::class, $id);
      $this->assertTrue($operation->isBare(), $id);
      $this->assertFalse(method_exists(LogbookResource::class, $id), $id . ': no method.');
      $this->assertSame('#[Operation] on ' . LogbookResource::class, $operation->provider(), $id);
      $context = $resources->operation(LogbookResource::class, $id);
      $this->assertSame($id, $context->id);
      $this->assertSame($creates, $context->creates, $id);
      $this->assertSame([[], []], [$context->known, $context->constraints()], $id . ': it knows nothing.');
    }
    $this->assertSame([], $registry->getOperation(LogbookResource::class, 'configure')->parameters);
  }

  /**
   * Tests a method's context takes the attribute's id and answer.
   *
   * A method may leave both out; they are stamped on what it returns, and
   * its description is its docblock summary unless the attribute says
   * one. A context that was never stamped is no operation's, and building
   * it is refused.
   */
  public function testTheIdAndCreatingAreStamped(): void {
    $registry = $this->registryOf(StampedStructure::class);
    $operations = $registry->getOperations(StampedStructure::class);
    $this->assertSame(['look', 'fill', 'relabel'], array_keys($operations), 'The class\'s first, then its methods\'.');
    $this->assertTrue($operations['look']->isBare());
    $this->assertSame(OperationDefinition::READ, $operations['look']->kind());
    $this->assertNull($operations['look']->description, 'An operation on the class has no docblock to fall back on.');
    $this->assertSame('Fills a new jar with the named jam.', $operations['fill']->description, 'The docblock summary, its first paragraph alone.');
    $this->assertSame('Says what the label says, not the docblock.', $operations['relabel']->description);

    $fill = $operations['fill']->stamp(StampedStructure::fill());
    $this->assertSame(['fill', TRUE, ['jam' => 'plum']], [$fill->id, $fill->creates, $fill->known]);
    $relabel = $operations['relabel']->stamp(StampedStructure::relabel('fig'));
    $this->assertSame(['relabel', FALSE], [$relabel->id, $relabel->creates]);
    $this->assertSame($fill, $operations['fill']->stamp($fill), 'A context that already agrees is kept.');

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('A context with no id was handed to build');
    $this->container->get('surface.resources')->build(LogbookResource::class, new OperationContext());
  }

  /**
   * Tests a context that says otherwise is refused at discovery.
   *
   * An operation that needs nothing is built when discovery reads it, so
   * a context under another id, or one that creates for an operation
   * whose attribute does not say so, is refused before anything asks for
   * it, naming the operation and what to do.
   */
  public function testContextThatSaysOtherwiseIsRefusedAtDiscovery(): void {
    $refused = [
      MisnamedContextStructure::class => 'The "fill" operation, ' . MisnamedContextStructure::class . '::fill() in surface_resource_test, returned a context whose id is "pour".',
      ContradictingCreatesStructure::class => 'The "fill" operation, ' . ContradictingCreatesStructure::class . '::fill() in surface_resource_test, returned a context that creates, and its #[Operation] does not say creates: TRUE.',
    ];
    foreach ($refused as $class => $message) {
      $registry = $this->registryOf($class);
      try {
        $registry->getDefinitions();
        $this->fail($class . ' was not refused at discovery.');
      }
      catch (\LogicException $e) {
        $this->assertStringStartsWith($message, $e->getMessage(), $class);
      }
    }
  }

  /**
   * Tests an id the module retired is refused at discovery, naming its own.
   *
   * Drupal's words for the kinds, `create`, `update`, `delete`, and `get`, are
   * labels, not ids: each is refused with the standard id that replaces
   * it, on the class through discovery and on any definition, a derived
   * resource's included.
   *
   * @see docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals
   */
  public function testRetiredIdsAreRefusedAtDiscovery(): void {
    try {
      $this->registryOf(RetiredIdStructure::class)->getDefinitions();
      $this->fail('An operation named edit was not refused at discovery.');
    }
    catch (\LogicException $e) {
      $this->assertSame('The "edit" operation, #[Operation] on ' . RetiredIdStructure::class . ' in surface_resource_test, is named with a word the module retired as an id. Name it "update", the standard id an agent and a client generator expect, and keep Drupal\'s word in its label, where people read it.', $e->getMessage());
    }
    foreach (['add' => 'create', 'edit' => 'update', 'remove' => 'delete', 'get' => 'read'] as $retired => $standard) {
      try {
        new OperationDefinition($retired, 'A label', 'example', self::class, '', NULL, 'surface');
        $this->fail($retired . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString('Name it "' . $standard . '"', $e->getMessage(), $retired);
      }
    }
    // The standard ids, configure and any custom one are let through.
    foreach (['create', 'update', 'read', 'list', 'delete', 'configure', 'check'] as $id) {
      $this->assertSame($id, (new OperationDefinition($id, 'A label', 'example', self::class, '', NULL, 'surface'))->id);
    }
  }

  /**
   * Makes a registry that discovers one class, outside discovery.
   *
   * @param class-string $class
   *   The class: a structure carrying its operations.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceRegistry
   *   The registry.
   */
  protected function registryOf(string $class): ResourceRegistry {
    $module = 'surface_resource_test';
    return new ResourceRegistry(['resources' => [$class => $module], 'operations' => [$class => $module]], $this->container->get('cache.discovery'));
  }

  /**
   * Tests facts that contradict each other are refused, naming both.
   */
  public function testFactsThatContradictAreRefused(): void {
    $contradictions = [
      'destroys' => static fn (): OperationDefinition => new OperationDefinition('peek', 'Peek', 'example', self::class, 'peek', NULL, 'surface', readOnly: TRUE, destructive: TRUE),
      'is not idempotent' => static fn (): OperationDefinition => new OperationDefinition('peek', 'Peek', 'example', self::class, 'peek', NULL, 'surface', readOnly: TRUE, idempotent: FALSE),
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
      $definition = $definitions['surface:tool_test.logbook:' . $id];
      $this->assertInstanceOf(ToolDefinition::class, $definition);
      $this->assertSame($kind, $definition->getOperation(), $id);
      $this->assertSame($mcp, $annotations($definition), $id . ': read only, destructive, idempotent, as MCP reads them.');
    }
    // A read tool is read only by what it is; nothing beside an operation
    // that creates or one that changes nothing is read.
    foreach (['configure', 'append', 'clear'] as $id) {
      $read = $definitions['surface:tool_test.logbook:' . $id . ':read'];
      $this->assertInstanceOf(ToolDefinition::class, $read);
      $this->assertSame([TRUE, FALSE, TRUE], $annotations($read), $id . ':read');
    }
    $this->assertArrayNotHasKey('surface:tool_test.logbook:start:read', $definitions);
    $this->assertArrayNotHasKey('surface:tool_test.logbook:inspect:read', $definitions);

    // The examples state nothing, and their tools are what they were.
    $example = $definitions['surface:registration.step3:configure'];
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
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $emitter = $this->container->get('surface.contract_emitter');
    foreach ([RegistrationStep3Resource::class => 'configure', LogbookResource::class => 'start'] as $resource => $id) {
      $operation = $registry->getOperation($resource, $id);
      $context = $resources->operation($resource, $id);
      $built = $resources->build($resource, $context);
      $facts = $operation->facts();
      $this->assertArrayHasKey('description', $facts, $id);
      $resource_id = $registry->getDefinition($resource)->id;
      $documents = [
        'the schema for these answers' => $emitter->emit($built, $built->getDefaultValues(), $resource_id, $operation->id, $operation->label, operation: $facts)->document['schema'],
        'the full schema' => $emitter->emitFull($resources->buildStatic($resource, $context), $resource_id, $operation->id, $operation->label, operation: $facts)->document['schema'],
      ];
      foreach ($documents as $which => $schema) {
        $this->assertSame(['$schema', 'title', 'description', 'x-surface'], array_slice(array_keys($schema), 0, 4), $which);
        // `kind: read` says what `readOnly` said: the documents leave it to
        // the tool. Decision: see docs/decisions.md#contract-2.
        $stated = $facts;
        unset($stated['readOnly']);
        $this->assertSame($stated, $schema['x-surface']['operation'], $which);
        $this->assertSame($facts['description'], $schema['description'], $which . ': the operation\'s description is the document\'s.');
      }
    }
    $this->assertSame([
      'id' => 'configure',
      'label' => 'Configure registration (step 3)',
      'description' => 'Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact.',
      'kind' => 'update',
      'creates' => FALSE,
      'readOnly' => FALSE,
      'destructive' => FALSE,
      'idempotent' => TRUE,
    ], $registry->getOperation(RegistrationStep3Resource::class, 'configure')->facts());

    // An operation that says no description states none, in the facts or
    // at the top.
    $vault = $registry->getOperation(VaultResource::class, 'configure');
    $this->assertArrayNotHasKey('description', $vault->facts());
    $built = $resources->buildOperation(VaultResource::class, 'configure');
    $this->assertArrayNotHasKey('description', $emitter->emitFull($built, 'tool_test.vault', 'configure', $vault->label, operation: $vault->facts())->document['schema']);

    $built = $resources->buildOperation(RegistrationStep3Resource::class, 'configure', []);
    $this->assertSame(['contract' => 4, 'version' => 1], $emitter->emit($built, [], 'registration.step3')->document['schema']['x-surface'], 'No operation: only the contract and the version.');
    $this->assertSame(['contract' => 4, 'version' => 1], $emitter->emitFull($built, 'registration.step3')->document['schema']['x-surface'], 'No operation: only the contract and the version.');
  }

}
