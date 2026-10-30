<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\StorageAdapter;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content entity storage, the derived tools and the contracts.
 *
 * Adding creates a page with the values, editing changes them and makes
 * a revision with the log, and a constraint the entity type declares
 * reaches the key it is about; the derived write tool adds and edits,
 * the read tool reads, and the contract for one page carries its stored
 * values while the full schema reads none.
 *
 * @see \Drupal\surface_examples_entity\Storage\ContentEntityStorage
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityStorageTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * The derived resource.
   */
  protected const RESOURCE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_entity_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
      'administer nodes',
      'use text format basic',
      'bypass surface entity test field access',
    ]);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Builds an operation and its storage.
   *
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   Its arguments.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Pipeline\SurfaceStorageInterface}
   *   The built resource and its storage.
   */
  protected function operation(string $operation, array $arguments = []): array {
    $context = $this->resources()->operation(self::RESOURCE, $operation, $arguments);
    $surface = $this->resources()->build(self::RESOURCE, $context);
    return [$surface, $this->resources()->storage(self::RESOURCE, $context, $surface)];
  }

  /**
   * Submits values through the pipeline.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   The storage.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return array<string, string>
   *   The violations, by full path; empty when it was written.
   */
  protected function submit(SurfaceInterface $surface, SurfaceStorageInterface $storage, array $values, bool $dry_run = FALSE): array {
    $result = $this->pipeline()->submit($surface, $values, $storage, $dry_run);
    $violations = [];
    foreach ($result->violations as $violation) {
      $violations[$violation->fullPath()] = PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    return $violations;
  }

  /**
   * Gets the newest page.
   *
   * @return \Drupal\node\NodeInterface
   *   The page, loaded from storage.
   */
  protected function newest(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('nid', 'DESC')->range(0, 1)->execute();
    $node = $ids === [] ? NULL : $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Counts the nodes.
   *
   * @return int
   *   How many there are.
   */
  protected function nodeCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Tests that add creates a page with the values, and edit changes them.
   */
  public function testAddCreatesAndEditChanges(): void {
    [$surface, $storage] = $this->operation('create');
    $violations = $this->submit($surface, $storage, [
      'title' => ['value' => 'Made by a resource'],
      'field_description' => ['value' => 'Described.'],
      'body' => ['value' => '<p>Body.</p>', 'summary' => 'Short.', 'format' => 'basic'],
      'status' => ['value' => FALSE],
    ]);
    $this->assertSame([], $violations);
    $node = $this->newest();
    $this->assertSame('page', $node->bundle());
    $this->assertSame('Made by a resource', $node->label());
    $this->assertSame('Described.', $node->get('field_description')->value);
    $body = $node->get('body')->first()?->getValue() ?? [];
    $body = array_intersect_key($body, array_flip(['value', 'summary', 'format']));
    $this->assertSame(['value' => '<p>Body.</p>', 'summary' => 'Short.', 'format' => 'basic'], $body);
    $this->assertFalse($node->isPublished());
    // A new node is the account's it is written as: the current user,
    // since the storage was asked for none.
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $node->getOwnerId());
    // The storage describes what it saved, so the id output answers.
    $this->assertInstanceOf(StorageAdapter::class, $storage);
    $outputs = $storage->outputs($surface, ['entity_type' => 'node', 'bundle' => 'page', 'id' => NULL]);
    $this->assertSame((int) $node->id(), $outputs['id']);

    $revision = (int) $node->getRevisionId();
    [$surface, $storage] = $this->operation('update', ['id' => $node->id()]);
    $stored = $storage->load($surface);
    $this->assertSame(['value' => 'Made by a resource'], $stored['title']);
    $this->assertSame((int) $node->id(), $stored['id']);
    $violations = $this->submit($surface, $storage, [
      'title' => ['value' => 'Changed by a resource'],
      'revision_log' => ['value' => 'Through the resource.'],
    ]);
    $this->assertSame([], $violations);
    $node = $this->newest();
    $this->assertSame('Changed by a resource', $node->label());
    $this->assertSame('Described.', $node->get('field_description')->value);
    // The resource carries the revision log, so an edit is a revision.
    $this->assertGreaterThan($revision, (int) $node->getRevisionId());
    $this->assertSame('Through the resource.', $node->getRevisionLogMessage());
  }

  /**
   * Tests a write is made as the account the storage is handed.
   *
   * The account travels as an argument: a storage made for another account
   * than the current user writes as that one, the new node its owner's and
   * the revision of an edit by it, and never reads the current user.
   *
   * @see docs/decisions.md#nothing-an-operation-runs-reads-the-request
   */
  public function testTheWriteIsMadeAsTheAccountItIsHanded(): void {
    $writer = $this->createUser([
      'access content',
      'create page content',
      'edit any page content',
      'administer nodes',
      'use text format basic',
      'bypass surface entity test field access',
    ]);
    $this->assertNotSame((int) $writer->id(), (int) $this->container->get('current_user')->id());
    $context = $this->resources()->operation(self::RESOURCE, 'create');
    $surface = $this->resources()->build(self::RESOURCE, $context);
    $storage = $this->resources()->storage(self::RESOURCE, $context, $surface, $writer);
    $this->assertSame([], $this->submit($surface, $storage, [
      'title' => ['value' => 'Written as another'],
      'field_description' => ['value' => 'x'],
    ]));
    $node = $this->newest();
    $this->assertSame((int) $writer->id(), (int) $node->getOwnerId());

    $context = $this->resources()->operation(self::RESOURCE, 'update', ['id' => $node->id()]);
    $surface = $this->resources()->build(self::RESOURCE, $context);
    $storage = $this->resources()->storage(self::RESOURCE, $context, $surface, $writer);
    $this->assertSame([], $this->submit($surface, $storage, [
      'title' => ['value' => 'Edited as another'],
      'revision_log' => ['value' => 'By the account handed in.'],
    ]));
    $node = $this->newest();
    $this->assertSame('Edited as another', $node->label());
    $this->assertSame((int) $writer->id(), (int) $node->getRevisionUserId());
  }

  /**
   * Tests that a constraint the entity declares reaches its key.
   */
  public function testAnEntityViolationLandsOnItsKey(): void {
    [$surface, $storage] = $this->operation('create');
    $this->assertSame(
      ['title.value' => 'A node may not be called Forbidden.'],
      $this->submit($surface, $storage, ['title' => ['value' => 'Forbidden'], 'field_description' => ['value' => 'x']]),
    );
    $this->assertSame(
      [ContentEntityStorage::ENTITY => 'This node is refused as a whole.'],
      $this->submit($surface, $storage, [
        'title' => ['value' => 'Refused whole'],
        'field_description' => ['value' => 'x'],
      ]),
    );
    $this->assertSame(0, $this->nodeCount());

    // The mapping itself: delta dropped, list-level kept, whole entity.
    $this->assertSame('body.format', ContentEntityStorage::violation('body.0.format', 'x')->fullPath());
    $this->assertSame('body', ContentEntityStorage::violation('body', 'x')->fullPath());
    $this->assertSame('body', ContentEntityStorage::violation('body.0', 'x')->fullPath());
    $this->assertSame(ContentEntityStorage::ENTITY, ContentEntityStorage::violation('', 'x')->fullPath());
  }

  /**
   * Tests a dry run: prepared, checked, nothing written.
   */
  public function testDryRunWritesNothing(): void {
    [$surface, $storage] = $this->operation('create');
    $values = ['title' => ['value' => 'Rehearsed'], 'field_description' => ['value' => 'x']];
    $result = $this->pipeline()->submit($surface, $values, $storage, TRUE);
    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame(0, $this->nodeCount());
  }

  /**
   * Tests the derived write tool adding and editing, and the read tool.
   */
  public function testTheDerivedToolsWriteAndRead(): void {
    $ids = array_keys($this->container->get('plugin.manager.tool')->getDefinitions());
    foreach (['create', 'update', 'update:read'] as $suffix) {
      $this->assertContains('surface:' . self::RESOURCE . ':' . $suffix, $ids);
    }
    // A field item is no tool: its parent stores it.
    $this->assertEmpty(preg_grep('/^surface:field\.item:/', $ids));

    $added = $this->runTool('surface:' . self::RESOURCE . ':create', [
      OperationInputs::VALUES => [
        'title' => ['value' => 'Made by a tool'],
        'field_description' => ['value' => 'By the tool.'],
      ],
    ]);
    $this->assertTrue($added['success'], $added['message']);
    $node = $this->newest();
    $this->assertSame('Made by a tool', $node->label());
    $this->assertSame((int) $node->id(), $added['outputs']['id']);

    $edited = $this->runTool('surface:' . self::RESOURCE . ':update', [
      'id' => (string) $node->id(),
      OperationInputs::VALUES => [
        'title' => ['value' => 'Changed by a tool'],
        'field_description' => ['value' => 'By the tool.'],
      ],
    ]);
    $this->assertTrue($edited['success'], $edited['message']);
    $this->assertSame('Changed by a tool', $this->newest()->label());

    $read = $this->runTool('surface:' . self::RESOURCE . ':update:read', ['id' => (string) $node->id()]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame(['value' => 'Changed by a tool'], $read['outputs'][OperationInputs::VALUES]['title']);
    $this->assertSame(['value' => 'By the tool.'], $read['outputs'][OperationInputs::VALUES]['field_description']);
    // The identity is the tool's input, not its values.
    $this->assertArrayNotHasKey('id', $read['outputs'][OperationInputs::VALUES]);

    $refused = $this->runTool('surface:' . self::RESOURCE . ':update', [
      'id' => (string) $node->id(),
      OperationInputs::VALUES => ['title' => ['value' => 'Forbidden'], 'field_description' => ['value' => 'x']],
    ]);
    $this->assertFalse($refused['success']);
    $this->assertStringContainsString('/title/value', $refused['message']);
  }

  /**
   * Tests the React door: a page added there lives at edit, by its new id.
   */
  public function testTheServedAddSaysWhereThePageNowLives(): void {
    $served = $this->container->get('surface.served_operations');
    $operation = $served->resolve(self::RESOURCE, 'create', []);
    $this->assertNotNull($operation->storage);
    $values = ['title' => ['value' => 'Served'], 'field_description' => ['value' => 'x']];
    $result = $this->pipeline()->submit($operation->surface, $values, $operation->storage);
    $this->assertTrue($result->isValid());
    $this->assertInstanceOf(StorageAdapter::class, $operation->storage);
    $outputs = $operation->storage->outputs($operation->surface, $result->values);
    $node = $this->newest();
    $this->assertSame(
      ['resource' => self::RESOURCE, 'operation' => 'update', 'parameters' => ['id' => (int) $node->id()]],
      $served->created($operation, $result->values, $outputs),
    );
  }

  /**
   * Tests the contract for one page, and the full schema, which reads none.
   */
  public function testTheContractCarriesStoredValuesAndTheFullSchemaNone(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $full = [];
    foreach (['First', 'Second'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => $title . ' description']);
      $node->save();
      [$surface, $storage] = $this->operation('update', ['id' => $node->id()]);
      $now = $emitter->emit($surface, array_replace($surface->getDefaultValues(), $storage->load($surface)), self::RESOURCE, 'update')->document;
      $this->assertSame(['value' => $title], $now['values']['title']);
      $this->assertSame((int) $node->id(), $now['values']['id']);
      $this->assertSame((int) $node->id(), $now['schema']['properties']['id']['const'] ?? NULL);
      $full[$title] = $emitter->emitFull($this->resources()->build(self::RESOURCE, new OperationContext('update')), self::RESOURCE, 'update')->document;
      $this->assertArrayNotHasKey('values', $full[$title]);
    }
    $this->assertSame($full['First'], $full['Second']);
    // The formats this account may use are a runtime refinement.
    $format = $full['First']['schema']['$defs']['field.item:node.page.body']['properties']['format']['x-surface'] ?? [];
    $this->assertTrue($format['live'] ?? FALSE);
  }

  /**
   * Runs one tool.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{success: bool, message: string, outputs: array}
   *   Whether it succeeded, its message and its outputs.
   */
  protected function runTool(string $id, array $inputs): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $tool->execute();
    $result = $tool->getResult();
    return [
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'outputs' => $result->getContextValues(),
    ];
  }

}
