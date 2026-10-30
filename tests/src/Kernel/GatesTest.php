<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\Gate;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_gate_test\Access\GateNoteAccess;
use Drupal\surface_gate_test\OutsideDiscovery\MissingEntityTypeGateStructure;
use Drupal\surface_gate_test\OutsideDiscovery\MissingOperationGateStructure;
use Drupal\surface_gate_test\OutsideDiscovery\MissingResourceGateStructure;
use Drupal\surface_gate_test\OutsideDiscovery\FieldDeleteGateStructure;
use Drupal\surface_gate_test\Resource\GatedBatchResource;
use Drupal\surface_gate_test\Resource\GatedNoteResource;
use Drupal\surface_gate_test\Resource\GatedRelayResource;
use Drupal\surface_gate_test\Storage\GateBatchStorage;
use Drupal\surface_gate_test\Storage\GateNoteStorage;
use Drupal\surface_tool\OperationInputs;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests gates: access an operation borrows from another thing's owner.
 *
 * Both spellings parse, and a gate naming what the site lacks is refused
 * at discovery; a gate is asked about the node the context knows, after
 * the operation's own permission and before its access class, which
 * narrows and never widens; its kind is read from its cacheability; asked
 * once per item of a collection, a refusal lands on that item's path with
 * the gate's reason and the storage never sees the item; the tool listing
 * says what is settled per thing.
 *
 * @see docs/decisions.md#a-gate-is-borrowed-never-copied
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class GatesTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_gate_test',
  ];

  /**
   * The nodes, by name.
   *
   * @var array<string, \Drupal\node\NodeInterface>
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter', 'surface']);
    // The super user takes uid 1 and is asked nothing.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $written = [
      'page' => ['page', 'A page'],
      'locked' => ['page', 'Locked page'],
      'article' => ['article', 'An article'],
    ];
    foreach ($written as $name => [$type, $title]) {
      $node = Node::create(['type' => $type, 'title' => $title, 'uid' => 1, 'status' => 1]);
      $node->save();
      $this->nodes[$name] = $node;
    }
  }

  /**
   * Makes an account with the permissions named, and makes it current.
   *
   * @param string[] $permissions
   *   The permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function as(array $permissions): AccountInterface {
    $account = $this->createUser(['access content', ...$permissions]);
    $this->assertNotFalse($account);
    $this->setCurrentUser($account);
    return $account;
  }

  /**
   * Tests the attribute's gates read in both spellings, in order.
   */
  public function testTheAttributeParsesBothForms(): void {
    $registry = $this->container->get('surface.resource_registry');
    $read = static fn (Gate $gate): array => [
      $gate->kind,
      $gate->resource,
      $gate->entityType,
      $gate->field,
      $gate->operation,
    ];
    $this->assertSame([
      [Gate::ENTITY, NULL, 'node', NULL, 'update'],
      [Gate::FIELD, NULL, 'node', 'title', 'edit'],
    ], array_map($read, $registry->getOperation(GatedBatchResource::class, 'update')->gates));
    $this->assertSame([[Gate::ENTITY, NULL, 'node', NULL, 'update']], array_map($read, $registry->getOperation(GatedNoteResource::class, 'update')->gates), 'One gate, written as a string.');
    $this->assertSame([[Gate::OPERATION, 'gate_test.note', NULL, NULL, 'update']], array_map($read, $registry->getOperation(GatedRelayResource::class, 'update')->gates));
    // A resource id with a colon reads up to the last one.
    $this->assertSame([Gate::OPERATION, 'entity.content:node.page', NULL, NULL, 'update'], $read(Gate::parse('entity.content:node.page:update', 'a test')));
    $this->assertSame([], $registry->getOperation(GatedNoteResource::class, 'update')->facts()['gates'] ?? [], 'The facts say nothing of gates.');
  }

  /**
   * Tests a gate naming what the site does not have is refused at discovery.
   */
  public function testGateNamingWhatTheSiteLacksIsRefusedAtDiscovery(): void {
    $refusals = [
      MissingOperationGateStructure::class => 'The gate "gate_test.note:rename" on the "update" operation of gate_test.broken.missing_operation names the "rename" operation of gate_test.note, which it does not have; it has update, annotate.',
      MissingResourceGateStructure::class => 'The gate "gate_test.nowhere:update" on the "update" operation of gate_test.broken.missing_resource names the resource gate_test.nowhere, which the site does not have.',
      MissingEntityTypeGateStructure::class => 'The gate "entity:spaceship:update" on the "update" operation of gate_test.broken.missing_entity_type names the entity type spaceship, which the site does not have.',
      FieldDeleteGateStructure::class => 'The gate "field:node.title:delete" on the "update" operation of ' . FieldDeleteGateStructure::class . ' asks field access for "delete"; core\'s field access takes view or edit.',
    ];
    foreach ($refusals as $class => $message) {
      $module = 'surface_gate_test';
      $classes = [GatedNoteResource::class => $module, $class => $module];
      $registry = new ResourceRegistry(['resources' => $classes, 'operations' => $classes], $this->container->get('cache.discovery'), NULL, NULL, $this->container->get('entity_type.manager'));
      try {
        $registry->getDefinitions();
        $this->fail($class . ' was not refused.');
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests a gate is asked about the node the context knows, and its reason.
   */
  public function testGateIsAskedAboutTheNodeTheContextKnows(): void {
    $resources = $this->container->get('surface.resources');
    $context = static fn (string $class, NodeInterface $node) => $resources->operation($class, 'update', ['node' => $node]);
    $editor = $this->as(['write gated notes', 'edit any page content']);
    $this->assertTrue($resources->access(GatedNoteResource::class, $context(GatedNoteResource::class, $this->nodes['page']), $editor)->isAllowed());
    $refused = $resources->access(GatedNoteResource::class, $context(GatedNoteResource::class, $this->nodes['article']), $editor);
    $this->assertTrue($refused->isForbidden());
    $this->assertSame("You may not update An article: The 'edit any article content' permission is required.", $refused->getReason(), "Core's reason, after what may not be done to what.");
    // The operation's own permission is asked first, and alone refuses.
    $outsider = $this->as(['edit any page content']);
    $this->assertSame("The 'write gated notes' permission is required.", $resources->access(GatedNoteResource::class, $context(GatedNoteResource::class, $this->nodes['page']), $outsider)->getReason());
    // The relay borrows the note's update whole: its permission, its gate
    // and its access class, so it refuses what the note refuses.
    $this->assertTrue($resources->access(GatedRelayResource::class, $context(GatedRelayResource::class, $this->nodes['page']), $editor)->isAllowed());
    $relayed = $resources->access(GatedRelayResource::class, $context(GatedRelayResource::class, $this->nodes['page']), $outsider);
    $this->assertSame("You may not update A page: The 'write gated notes' permission is required.", $relayed->getReason(), "The note's permission refuses the relay.");
    $relayed = $resources->access(GatedRelayResource::class, $context(GatedRelayResource::class, $this->nodes['article']), $editor);
    $this->assertSame("You may not update An article: The 'edit any article content' permission is required.", $relayed->getReason(), "The note's gate refuses the relay, through the note, said once.");
    // A context that names no node refuses: a gate is never let through
    // unasked.
    $nameless = $resources->access(GatedNoteResource::class, new OperationContext('update'), $editor);
    $this->assertSame('Nothing names the node the gate "entity:node:update" asks about.', $nameless->getReason());
  }

  /**
   * Tests a gate's kind is read from its cacheability.
   */
  public function testGateIsStaticOnTheAccountAloneAndLiveOnTheSubject(): void {
    $resources = $this->container->get('surface.resources');
    $this->as(['write gated notes', 'edit any page content']);
    $node = $this->nodes['page'];
    // Core's update access read the account's permissions alone: static,
    // stated for the account, nothing marked.
    $update = $resources->operation(GatedNoteResource::class, 'update', ['node' => $node]);
    $this->assertFalse($resources->isRuntimeAtTheTop(GatedNoteResource::class, $update, $this->container->get('current_user')));
    // The title's edit access read the node, through the module's hook:
    // runtime, every key marked.
    $annotate = $resources->operation(GatedNoteResource::class, 'annotate', ['node' => $node]);
    $this->assertTrue($resources->isRuntimeAtTheTop(GatedNoteResource::class, $annotate, $this->container->get('current_user')));
    $this->assertTrue($resources->buildStatic(GatedNoteResource::class, $annotate)->isRuntimeAccessWhole());
    // A tool's static definition, which knows no node yet, waits for it.
    $this->assertTrue($resources->isRuntimeAtTheTop(GatedNoteResource::class, new OperationContext('update'), $this->container->get('current_user')));
    // Asked per item, a gate is settled per thing: the list is marked.
    $batch = $resources->operation(GatedBatchResource::class, 'update');
    $this->assertFalse($resources->isRuntimeAtTheTop(GatedBatchResource::class, $batch, $this->container->get('current_user')), 'Per item, nothing at the top waits.');
    $static = $resources->buildStatic(GatedBatchResource::class, $batch);
    $this->assertTrue($static->getDefinitions()->entry('items')->isRefinedAtRuntime() || $static->getDefinitions()->isRuntimeAccess('items'));
    $full = $this->container->get('surface.contract_emitter')->emitFull($static, 'gate_test.batch', 'update', 'Note many nodes')->document['schema'];
    $this->assertTrue($full['properties']['items']['x-surface']['live'] ?? FALSE, 'The full schema marks the list live.');
  }

  /**
   * Tests each item is asked about its own node, and refused on its path.
   */
  public function testEachItemIsAskedAboutItsOwnNode(): void {
    $state = $this->container->get('state');
    $this->as(['write gated notes', 'edit any page content']);
    $items = [
      ['node' => (int) $this->nodes['page']->id(), 'note' => 'Fine'],
      ['node' => (int) $this->nodes['locked']->id(), 'note' => 'Title locked'],
      ['node' => (int) $this->nodes['article']->id(), 'note' => 'Not a page'],
    ];
    $answer = $this->call('surface:gate_test.batch:update', ['items' => $items]);
    $this->assertFalse($answer['ok']);
    $this->assertSame([
      [
        'path' => '/items/1/node',
        'code' => 'access',
        'message' => 'You may not edit the title of Locked page: Its title is locked.',
      ],
      [
        'path' => '/items/2/node',
        'code' => 'access',
        'message' => "You may not update An article: The 'edit any article content' permission is required.",
      ],
    ], $answer['violations'], 'Each refusal on its item, with its gate\'s reason; the page passes, and field access is not entity access.');
    $this->assertSame([], $state->get(GateBatchStorage::SEEN, []), 'The storage saw none of it.');
    // The item every gate allows is written.
    $answer = $this->call('surface:gate_test.batch:update', ['items' => [$items[0]]], FALSE);
    $this->assertTrue($answer['ok']);
    $this->assertSame([[(int) $this->nodes['page']->id()]], $state->get(GateBatchStorage::SEEN));
    $this->assertSame([(int) $this->nodes['page']->id() => 'Fine'], $state->get(GateNoteStorage::KEY));
    // An id naming nothing is refused by the reference before any gate.
    $answer = $this->call('surface:gate_test.batch:update', ['items' => [['node' => 999]]]);
    $this->assertSame(['/items/0/node'], array_column($answer['violations'], 'path'));
  }

  /**
   * Tests the resource's own access narrows on top, and never widens.
   */
  public function testTheResourceNarrowsAndCannotWiden(): void {
    $resources = $this->container->get('surface.resources');
    $state = $this->container->get('state');
    $editor = $this->as(['write gated notes', 'edit any page content']);
    $article = $resources->operation(GatedNoteResource::class, 'update', ['node' => $this->nodes['article']]);
    // The access class allows, and the gate's refusal stands.
    $this->assertTrue($resources->access(GatedNoteResource::class, $article, $editor)->isForbidden());
    $answer = $this->call('surface:gate_test.batch:update', ['items' => [['node' => (int) $this->nodes['article']->id()]]]);
    $this->assertSame(['/items/0/node'], array_column($answer['violations'], 'path'), 'Per item too.');
    // The gates allow, and the access class refuses.
    $state->set(GateNoteAccess::CLOSED, TRUE);
    $page = $resources->operation(GatedNoteResource::class, 'update', ['node' => $this->nodes['page']]);
    $this->assertSame('The notes are closed.', $resources->access(GatedNoteResource::class, $page, $editor)->getReason());
    $answer = $this->call('surface:gate_test.batch:update', ['items' => [['node' => (int) $this->nodes['page']->id()]]]);
    $this->assertFalse($answer['ok']);
    $this->assertSame([''], array_column($answer['violations'] ?? [['path' => '']], 'path'), 'Refused at the top, before any item.');
  }

  /**
   * Tests what the tool listing says of gates.
   */
  public function testTheToolListing(): void {
    $this->as(['write gated notes', 'edit any page content']);
    $tools = $this->container->get('plugin.manager.tool');
    $batch = $tools->getDefinition('surface:gate_test.batch:update');
    $this->assertSame('write gated notes', $batch->getPermission() ?? NULL, "The operation's own permission is declared.");
    $this->assertStringNotContainsString('once the thing is known', (string) $batch->getDescription(), 'Asked per item, nothing at the top waits.');
    $input = $tools->createInstance('surface:gate_test.batch:update')->getInputDefinitions()[OperationInputs::VALUES];
    $items = $input->getPropertyDefinitions()['items'];
    $this->assertStringContainsString('Whether this may be changed is decided once the thing it belongs to is known', (string) $items->getDescription(), 'The list says it is settled per thing.');
    $note = $tools->getDefinition('surface:gate_test.note:update');
    $this->assertStringEndsWith('Every key may be found locked or left out once the thing is known.', (string) $note->getDescription(), 'A gate asked about the node the call names waits for it.');
  }

  /**
   * Calls a tool as the current account, and answers its result document.
   *
   * @param string $tool_id
   *   The tool.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether the call is a dry run.
   *
   * @return array
   *   The result's context values.
   */
  protected function call(string $tool_id, array $values, bool $dry_run = TRUE): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($tool_id);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, $dry_run);
    $tool->execute();
    return $tool->getResult()->getContextValues();
  }

}
