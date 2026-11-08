<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\TransactionalStorageInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_examples\Storage\VenueStorage;
use Drupal\surface_tool_test\Storage\VaultStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a write into a stage, at the storage the pipeline is handed.
 *
 * An update of a page written into a stage writes the stage's record and
 * not the node: the record holds the whole document the pipeline merged,
 * the keys it moved and a token of the node as stored, and a load answers
 * the stage's view. A second write builds on the first; null clears and a
 * key left out keeps; two writes to one thing end last wins. A staged
 * write validates as a real write. A stage handed no arguments records
 * the operation's parameters. A thing staged through one operation is
 * refused through another. The write and read tools take the stage
 * as their `stage` input. What a stage cannot hold yet is refused at
 * prepare, as a violation of the whole call: a storage class that does
 * not implement TransactionalStorageInterface and a resource that holds
 * a secret among them.
 *
 * @see docs/decisions.md#a-staged-write-validates-as-a-real-write
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedUpdateTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    ...self::ENTITY_MODULES,
    'surface_stage_test',
    'surface_tool_test',
    'surface_examples',
    'my_module',
  ];

  /**
   * The page the tests stage writes to.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installConfig(['surface', 'my_module']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
      'administer nodes',
      'use text format basic',
      'keep the ledger',
      'administer event venues',
      'administer site configuration',
    ]);
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Spring meetup',
      'field_description' => 'In the park.',
      'body' => ['value' => '<p>Bring a hat.</p>', 'format' => 'basic'],
    ]);
    $this->node->save();
  }

  /**
   * Gets the page as stored.
   *
   * @return \Drupal\node\NodeInterface
   *   The page.
   */
  protected function stored(): NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->node->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Tests a staged update writes the stage and not the node.
   */
  public function testStagedUpdateWritesTheStageNotTheNode(): void {
    $id = $this->open();
    $arguments = ['id' => $this->node->id()];
    [$permanent_built, $permanent] = $this->permanent(self::PAGE, 'update', $arguments);
    $stored = $permanent->load($permanent_built);

    [$built, $storage] = $this->staged($id, self::PAGE, 'update', $arguments);
    $this->assertInstanceOf(StagedStorage::class, $storage);
    $result = $this->submitted($built, $storage, ['title' => ['value' => 'Spring meetup, moved']]);
    $this->assertTrue($result->isValid(), $this->said($result));
    $this->assertTrue($result->committed);
    $this->assertSame(['title.value'], $result->changes);
    $this->assertInstanceOf(OutputsStorageInterface::class, $storage);
    $this->assertSame((int) $this->node->id(), $storage->outputs($built, $result->values)['id']);

    // The node is as it was; the stage answers its own view.
    $this->assertSame('Spring meetup', $this->stored()->label());
    $this->assertSame(['value' => 'Spring meetup, moved'], $this->staged($id, self::PAGE, 'update', $arguments)[1]->load($built)['title']);
    $this->assertSame(['value' => 'Spring meetup'], $permanent->load($permanent_built)['title']);

    $write = $this->stages()->read($id)['writes'][0];
    $this->assertSame(1, $write['position']);
    $this->assertSame([self::PAGE, 'update', 'update'], [$write['resource'], $write['operation'], $write['kind']]);
    $this->assertSame($arguments, $write['arguments']);
    $this->assertSame((int) $this->node->id(), $write['values']['id'], 'The id as loaded.');
    $this->assertSame(['value' => 'Spring meetup, moved'], $write['values']['title']);
    $this->assertSame(['value' => 'In the park.'], $write['values']['field_description'], 'The document is whole.');
    $this->assertSame(['title'], $write['changed']);
    $this->assertSame(Fingerprint::of(self::PAGE, $stored), $write['token']);
  }

  /**
   * Tests a second write builds on the first, its changes against it.
   */
  public function testSecondWriteMergesAndChangesIsAgainstTheStage(): void {
    $id = $this->open();
    $arguments = ['id' => $this->node->id()];
    $this->assertTrue($this->stage($id, self::PAGE, $arguments, ['title' => ['value' => 'Staged']])->committed);
    $second = $this->stage($id, self::PAGE, $arguments, ['field_description' => ['value' => 'By the river.']]);
    $this->assertTrue($second->committed, $this->said($second));
    $this->assertSame(['field_description.value'], $second->changes);
    $write = $this->stages()->read($id)['writes'][0];
    $this->assertCount(1, $this->stages()->read($id)['writes']);
    $this->assertSame(['value' => 'Staged'], $write['values']['title']);
    $this->assertSame(['value' => 'By the river.'], $write['values']['field_description']);

    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(1, $changes['outputs']['total']);
    $this->assertCount(1, $changes['outputs']['items']);
    $item = $changes['outputs']['items'][0];
    $this->assertSame(1, $item['position']);
    $this->assertSame([self::PAGE, 'update', 'update'], [$item['resource'], $item['operation'], $item['kind']]);
    $this->assertSame([['name' => 'id', 'value' => (string) $this->node->id()]], $item['parameters']);
    $this->assertSame(['/title', '/field_description'], $item['changed']);
    $this->assertIsInt($item['staged']);
  }

  /**
   * Tests the tools stage an update and read the staged view.
   *
   * The write tool with `stage` keeps the write in the stage and says so;
   * a second staged write answers what it moved since the first; the read
   * tool with `stage` answers the staged view, and without it the stored
   * values.
   */
  public function testTheToolsStageAnUpdateAndReadTheStagedView(): void {
    $id = $this->open();
    $tool = 'surface:' . self::PAGE;
    $node = (int) $this->node->id();
    $named = ['id' => $node, OperationInputs::STAGE => $id];
    $first = $this->runTool($tool . ':update', $named + [OperationInputs::VALUES => ['title' => ['value' => 'Spring meetup, moved']]]);
    $this->assertTrue($first['success'], $first['message']);
    $this->assertSame('Edit Basic page: the values were accepted and staged in ' . $id . '.', $first['message']);
    $this->assertTrue($first['outputs'][OperationInputs::COMMITTED]);
    $this->assertTrue($first['outputs'][OperationInputs::CHANGED]);
    $this->assertSame(['/title/value'], $first['outputs'][OperationInputs::CHANGES]);
    $this->assertSame($node, $first['outputs']['id'], 'The permanent id.');
    $this->assertSame('Spring meetup', $this->stored()->label(), 'The node is as it was.');

    $second = $this->runTool($tool . ':update', $named + [OperationInputs::VALUES => ['field_description' => ['value' => 'By the river.']]]);
    $this->assertTrue($second['success'], $second['message']);
    $this->assertStringEndsWith('staged in ' . $id . '.', $second['message']);
    $this->assertSame(['/field_description/value'], $second['outputs'][OperationInputs::CHANGES], 'Against the stage: the title it moved before is no change now.');

    $staged = $this->runTool($tool . ':read', ['id' => $node, OperationInputs::STAGE => $id]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->assertSame(['value' => 'Spring meetup, moved'], $staged['outputs'][OperationInputs::VALUES]['title']);
    $this->assertSame(['value' => 'By the river.'], $staged['outputs'][OperationInputs::VALUES]['field_description']);
    $stored = $this->runTool($tool . ':read', ['id' => $node]);
    $this->assertTrue($stored['success'], $stored['message']);
    $this->assertSame(['value' => 'Spring meetup'], $stored['outputs'][OperationInputs::VALUES]['title']);
    $this->assertSame(['value' => 'In the park.'], $stored['outputs'][OperationInputs::VALUES]['field_description']);
  }

  /**
   * Tests a stage handed no arguments records the operation's parameters.
   *
   * What the context knows of them, never the whole identity: a derived
   * entity resource knows its entity type and bundle as identity, and its
   * update takes the id alone.
   */
  public function testStageWithNoArgumentsRecordsTheParameters(): void {
    $id = $this->open();
    $context = $this->resources()->operation(self::PAGE, 'update', ['id' => $this->node->id()]);
    $identity = ['entity_type', 'bundle', 'id'];
    $this->assertSame($identity, array_keys(array_intersect_key($context->known, array_flip($identity))), 'The context knows the whole identity.');
    $built = $this->resources()->build(self::PAGE, $context);
    $storage = $this->resources()->storage(self::PAGE, $context, $built, NULL, $this->stages()->named($id));
    $result = $this->submitted($built, $storage, ['title' => ['value' => 'Spring meetup, moved']]);
    $this->assertTrue($result->committed, $this->said($result));

    $arguments = $this->stages()->read($id)['writes'][0]['arguments'];
    $this->assertSame(['id'], array_keys($arguments));
    $this->assertSame((string) $this->node->id(), (string) $arguments['id']);
    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame([['name' => 'id', 'value' => (string) $this->node->id()]], $changes['outputs']['items'][0]['parameters']);

    // Applied from what it recorded.
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Spring meetup, moved', $this->stored()->label());
  }

  /**
   * Tests null clears and a key left out keeps, in the record.
   */
  public function testNullClearsAndAbsentKeyKeepsInTheRecord(): void {
    $this->ledger('a', 'Alpha', ['note' => 'First', 'tags' => ['one']]);
    $id = $this->open();
    $result = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['note' => NULL]);
    $this->assertTrue($result->committed, $this->said($result));
    $write = $this->stages()->read($id)['writes'][0];
    $this->assertArrayHasKey('note', $write['values']);
    $this->assertNull($write['values']['note']);
    $this->assertSame(['one'], $write['values']['tags']);
    $this->assertSame('Alpha', $write['values']['title']);
    $this->assertSame(['note'], $write['changed']);
    $this->assertSame('First', $this->row('a')['note'], 'Storage holds what it held.');
  }

  /**
   * Tests two writes to one thing over one load: the last wins whole.
   */
  public function testTwoParallelWritesToOneThingLastWins(): void {
    $id = $this->open();
    $arguments = ['id' => $this->node->id()];
    [$built, $first] = $this->staged($id, self::PAGE, 'update', $arguments);
    [, $second] = $this->staged($id, self::PAGE, 'update', $arguments);
    $pipeline = $this->pipeline();
    $one = $pipeline->submit($built, ['title' => ['value' => 'First']], $first, TRUE);
    $two = $pipeline->submit($built, ['field_description' => ['value' => 'Second']], $second, TRUE);
    $this->assertTrue($one->isValid() && $two->isValid());
    $pipeline->commitDryRun($one, $first);
    $pipeline->commitDryRun($two, $second);
    $write = $this->stages()->read($id)['writes'][0];
    $this->assertCount(1, $this->stages()->read($id)['writes']);
    $this->assertSame(['value' => 'Spring meetup'], $write['values']['title'], 'The second document, whole.');
    $this->assertSame(['value' => 'Second'], $write['values']['field_description']);
    $this->assertSame(['title', 'field_description'], $write['changed'], 'Both keys, which apply sends.');
  }

  /**
   * Tests a staged write is refused what a real write is refused.
   */
  public function testStagedWriteValidatesAsRealWrite(): void {
    $id = $this->open();
    $result = $this->stage($id, self::PAGE, ['id' => $this->node->id()], ['field_description' => NULL]);
    $this->assertFalse($result->isValid());
    $this->assertSame(['field_description.value' => Violation::REQUIRED], static::codes($result));
    $this->assertSame([], $this->stages()->read($id)['writes']);
    $this->assertSame(1, $this->stages()->read($id)['next']);
  }

  /**
   * Tests one stored thing staged through two resources is refused.
   */
  public function testTwoResourcesOverOneNodeAreRefused(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Staged']])->committed);
    $result = $this->stage($id, 'entity.content:node', ['id' => $this->node->id()], ['fields' => ['title' => ['value' => 'Again']]]);
    $this->assertSame(['' => Violation::CONFLICT], static::codes($result), $this->said($result));
    $this->assertStringContainsString(self::PAGE, $this->said($result));
    $this->assertCount(1, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests a thing staged through one operation is refused through another.
   *
   * The ledger's `update` and `touch` are two updates of one row: a row
   * staged through `update` is refused through `touch`, naming the
   * operation it is staged through, and the stage holds what it held.
   */
  public function testAnotherOperationOverOneThingIsRefused(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open();
    $staged = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Alpha, moved']);
    $this->assertTrue($staged->committed, $this->said($staged));
    $record = $this->stages()->read($id);
    $touched = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], [], 'touch');
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($touched), $this->said($touched));
    $this->assertSame(' (' . Violation::DOES_NOT_APPLY . '): This is staged through the update operation already; write it through that operation, or discard the stage.', $this->said($touched));
    $this->assertSame($record, $this->stages()->read($id), 'The stage holds what it held.');
  }

  /**
   * Tests what a stage cannot hold yet is refused, at prepare, whole.
   */
  public function testWhatCannotBeStagedIsRefusedAtPrepare(): void {
    $id = $this->open();
    $refused = function (string $resource, string $operation, array $arguments, array $values, string $code = Violation::DOES_NOT_APPLY, ?string $storage = NULL, ?string $message = NULL) use ($id): void {
      $result = $this->stage($id, $resource, $arguments, $values, $operation);
      $this->assertFalse($result->isValid(), $resource);
      $this->assertFalse($result->committed, $resource);
      $this->assertSame(['' => $code], static::codes($result), $resource . ': ' . $this->said($result));
      if ($storage !== NULL) {
        $message = sprintf('%s cannot be staged: its storage, %s, does not declare that a database transaction rolls its writes back whole (%s).', $resource, $storage, TransactionalStorageInterface::class);
      }
      if ($message !== NULL) {
        $this->assertSame(sprintf(' (%s): %s', $code, $message), $this->said($result));
      }
    };
    // A config object and a config entity.
    $refused('my_module.settings', 'configure', [], []);
    [$venue_built, $venue] = $this->permanent('event.venue', 'create');
    $venue_result = $this->pipeline()->submit($venue_built, ['label' => 'Riverside', 'capacity' => 40, 'rooms' => []], $venue);
    $this->assertTrue($venue_result->committed, $this->said($venue_result));
    $refused('event.venue', 'update', ['id' => 'riverside'], ['capacity' => 50], storage: VenueStorage::class);
    // A storage in State, which keeps a cache a rollback leaves stale.
    $refused('tool_test.vault', 'configure', [], ['endpoint' => 'https://example.com'], storage: VaultStorage::class);
    // A resource holding a secret, the secret sent or not.
    $secret = 'A stage keeps values in the clear until secrets are sealed, so a resource that holds a secret cannot be staged yet.';
    $refused('stage_test.vault_note', 'configure', [], ['token' => 'shh'], message: $secret);
    $refused('stage_test.vault_note', 'configure', [], ['label' => 'Open'], message: $secret);
    $this->assertSame([], $this->stages()->read($id)['writes']);

    // A stage applied.
    $this->assertTrue($this->stage($id, self::PAGE, ['id' => $this->node->id()], ['title' => ['value' => 'Applied']])->committed);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $refused(self::PAGE, 'update', ['id' => $this->node->id()], ['title' => ['value' => 'Late']]);
  }

}
