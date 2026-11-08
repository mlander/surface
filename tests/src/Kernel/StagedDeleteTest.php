<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Http\ServedPaths;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a delete written into a stage, and applied.
 *
 * A staged delete writes the stage's record and not the node: an entry
 * of kind `delete`, its values empty and its one changed key the empty
 * one, and the tool says the removal was staged. Through the stage the
 * node reads as nothing, and `changes` lists the delete. A second delete
 * or an update of the thing is refused, `not_found`, once it reaches the
 * storage; an update that leaves out a required key is refused
 * `required` first. An update and then a delete of one node is one entry,
 * at the update's position. Apply asks delete access again, refuses a
 * node that changed since, removes the node, and a rollback leaves it in
 * place. The HTTP door stages a `DELETE` with the stage header.
 *
 * @see docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedDeleteTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * The keys that say which write an entry is.
   */
  protected const DESCRIBED = ['position', 'resource', 'operation', 'kind'];

  /**
   * The removed-in-stage refusal.
   */
  protected const REMOVED = 'This was removed in the stage; '
    . 'the removal stands until the stage is applied or discarded.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_stage_test'];

  /**
   * The account that owns the stages.
   */
  protected UserInterface $owner;

  /**
   * The two pages.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->owner = $this->setUpCurrentUser([], [
      'access content',
      'edit any page content',
      'delete any page content',
      'use text format basic',
      'keep the ledger',
    ]);
    foreach (['One', 'Two'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => 'About ' . $title . '.']);
      $node->save();
      $this->nodes[] = $node;
    }
  }

  /**
   * Loads a page as the database holds it.
   *
   * @param int $index
   *   Which.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The page, or NULL when it is gone.
   */
  protected function stored(int $index): ?NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->nodes[$index]->id());
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Gets a page's id.
   *
   * @param int $index
   *   Which.
   *
   * @return int
   *   The id.
   */
  protected function nid(int $index): int {
    return (int) $this->nodes[$index]->id();
  }

  /**
   * Stages a page's delete through the pipeline.
   *
   * @param string $stage
   *   The stage.
   * @param int $index
   *   Which page.
   */
  protected function stageDelete(string $stage, int $index): void {
    $result = $this->stage($stage, self::PAGE, ['id' => $this->nid($index)], [], 'delete');
    $this->assertTrue($result->committed, $this->said($result));
  }

  /**
   * Lists a tool's violations, each its code by its pointer.
   *
   * @param array $answer
   *   What the tool answered.
   *
   * @return array<string, string>
   *   The codes, by path.
   */
  protected static function toolCodes(array $answer): array {
    return array_column($answer['outputs'][OperationInputs::VIOLATIONS] ?? [], 'code', 'path');
  }

  /**
   * Tests a staged delete writes the stage, not the node, and says so.
   */
  public function testStagedDeleteWritesTheStageNotTheNode(): void {
    $id = $this->open();
    $tool = 'surface:' . self::PAGE . ':delete';
    [$built, $storage] = $this->permanent(self::PAGE, 'delete', ['id' => $this->nid(0)]);
    $stored = $storage->load($built);

    $dry = $this->runTool($tool, [
      'id' => $this->nid(0),
      OperationInputs::DRY_RUN => TRUE,
      OperationInputs::STAGE => $id,
    ]);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertSame('Dry run of Delete Basic page: the removal was rehearsed, and nothing was removed. The values it holds are answered.', $dry['message']);
    $this->assertSame([], $this->stages()->read($id)['writes'], 'A dry run stages nothing.');

    $staged = $this->runTool($tool, ['id' => $this->nid(0), OperationInputs::STAGE => $id]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->assertSame('Delete Basic page: the removal was accepted and staged in ' . $id . '; nothing was removed.', $staged['message']);
    $this->assertTrue($staged['outputs'][OperationInputs::COMMITTED]);
    $this->assertTrue($staged['outputs'][OperationInputs::CHANGED]);
    $this->assertSame([''], $staged['outputs'][OperationInputs::CHANGES]);
    $this->assertSame(['value' => 'One'], $staged['outputs'][OperationInputs::VALUES]['title'], 'The values it held.');
    $this->assertNotNull($this->stored(0), 'The node is still there.');

    $write = $this->stages()->read($id)['writes'][0];
    $this->assertSame([1, self::PAGE, 'delete', 'delete'], array_values(array_intersect_key($write, array_flip(self::DESCRIBED))));
    $this->assertSame(['id' => $this->nid(0)], $write['arguments']);
    $this->assertSame([], $write['values']);
    $this->assertSame([''], $write['changed']);
    $this->assertSame(Fingerprint::of(self::PAGE, $stored), $write['token']);
  }

  /**
   * Tests the stage's view of a deleted thing, and its changes.
   */
  public function testReadAndChangesThroughTheStage(): void {
    $id = $this->open();
    $this->stageDelete($id, 0);
    $tool = 'surface:' . self::PAGE . ':read';
    $staged = $this->runTool($tool, ['id' => $this->nid(0), OperationInputs::STAGE => $id]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->assertSame([], $staged['outputs'][OperationInputs::VALUES], 'Removed in the stage: nothing.');
    $permanent = $this->runTool($tool, ['id' => $this->nid(0)]);
    $this->assertTrue($permanent['success'], $permanent['message']);
    $this->assertSame(['value' => 'One'], $permanent['outputs'][OperationInputs::VALUES]['title']);

    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(1, $changes['outputs']['total']);
    $item = $changes['outputs']['items'][0];
    $this->assertSame([1, self::PAGE, 'delete', 'delete'], array_values(array_intersect_key($item, array_flip(self::DESCRIBED))));
    $this->assertSame([['name' => 'id', 'value' => (string) $this->nid(0)]], $item['parameters']);
    $this->assertSame([''], $item['changed'], 'The whole thing, the empty pointer.');
  }

  /**
   * Tests a write to a thing the stage removed is refused.
   *
   * A second delete and an update that sends every required key reach the
   * stage's refusal; an update that leaves a required key out is refused
   * by validation first, since the stage answers nothing for the thing.
   */
  public function testWritesAfterStagedDeleteAreRefused(): void {
    $id = $this->open();
    $this->stageDelete($id, 0);
    $record = $this->stages()->read($id);

    $again = $this->stage($id, self::PAGE, ['id' => $this->nid(0)], [], 'delete');
    $this->assertSame(['' => Violation::NOT_FOUND], static::codes($again), $this->said($again));
    $this->assertSame(' (' . Violation::NOT_FOUND . '): ' . self::REMOVED, $this->said($again));

    $whole = $this->stage($id, self::PAGE, ['id' => $this->nid(0)], [
      'title' => ['value' => 'Back'],
      'field_description' => ['value' => 'Back again.'],
    ]);
    $this->assertSame(['' => Violation::NOT_FOUND], static::codes($whole), $this->said($whole));
    $this->assertSame(' (' . Violation::NOT_FOUND . '): ' . self::REMOVED, $this->said($whole));

    $partial = $this->stage($id, self::PAGE, ['id' => $this->nid(0)], ['title' => ['value' => 'Back']]);
    $this->assertSame(['field_description.value' => Violation::REQUIRED], static::codes($partial), 'Validation answers before the stage can.');
    $this->assertSame($record, $this->stages()->read($id), 'The stage holds what it held.');
  }

  /**
   * Tests an update then a delete of one node is one entry, at its place.
   */
  public function testUpdateThenDeleteReplacesTheEntryAtItsPosition(): void {
    $id = $this->open();
    foreach ([0 => 'One, moved', 1 => 'Two, moved'] as $index => $title) {
      $result = $this->stage($id, self::PAGE, ['id' => $this->nid($index)], ['title' => ['value' => $title]]);
      $this->assertTrue($result->committed, $this->said($result));
    }
    $before = $this->stages()->read($id);
    $update = $before['writes'][0];

    $staged = $this->runTool('surface:' . self::PAGE . ':delete', ['id' => $this->nid(0), OperationInputs::STAGE => $id]);
    $this->assertTrue($staged['success'], $staged['message']);
    $this->assertSame(['value' => 'One, moved'], $staged['outputs'][OperationInputs::VALUES]['title'], 'The values the stage held.');
    $record = $this->stages()->read($id);
    $this->assertCount(2, $record['writes']);
    $this->assertSame($before['next'], $record['next'], 'No new position.');
    $write = $record['writes'][0];
    $this->assertSame([1, 'delete', 'delete'], [$write['position'], $write['operation'], $write['kind']]);
    $this->assertSame([], $write['values']);
    $this->assertSame([''], $write['changed']);
    $this->assertSame(['id' => $this->nid(0)], $write['arguments']);
    $kept = array_flip(['token', 'staged', 'known']);
    $this->assertSame(array_intersect_key($update, $kept), array_intersect_key($write, $kept), 'The token of the first staging, kept.');
    $this->assertSame($before['writes'][1], $record['writes'][1]);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame(['delete', 'update'], array_column($applied['outputs']['applied'], 'kind'));
    $this->assertSame([(string) $this->nid(0), (string) $this->nid(1)], array_column($applied['outputs']['applied'], 'id'));
    $this->assertSame([TRUE, TRUE], array_column($applied['outputs']['applied'], 'changed'));
    $this->assertNull($this->stored(0), 'Removed.');
    $this->assertSame('Two, moved', $this->stored(1)?->label());
  }

  /**
   * Tests apply asks delete access again, and refuses the whole apply.
   */
  public function testApplyAsksDeleteAccessAgain(): void {
    $id = $this->open();
    $this->stageDelete($id, 0);
    $result = $this->stage($id, self::PAGE, ['id' => $this->nid(1)], ['title' => ['value' => 'Two, moved']]);
    $this->assertTrue($result->committed, $this->said($result));
    foreach ($this->owner->getRoles(TRUE) as $role) {
      Role::load($role)?->revokePermission('delete any page content')->save();
    }
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Input, $applied['category']);
    $this->assertSame(['/writes/1' => Violation::ACCESS], static::toolCodes($applied));
    $this->assertNotNull($this->stored(0), 'Nothing removed.');
    $this->assertSame('Two', $this->stored(1)?->label(), 'Nothing written.');
  }

  /**
   * Tests a node changed since its delete was staged is a conflict.
   */
  public function testChangedSinceStagedIsConflict(): void {
    $id = $this->open();
    $this->stageDelete($id, 0);
    $this->stored(0)?->setTitle('One, changed meanwhile')->save();
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(['/writes/1' => Violation::CONFLICT], static::toolCodes($applied));
    $this->assertSame('One, changed meanwhile', $this->stored(0)?->label());
  }

  /**
   * Tests a rolled back apply leaves the node in place, and loadable.
   *
   * The delete commits, the ledger row after it throws: the transaction
   * rolls the delete back, and a load in the same request finds the node.
   */
  public function testRollbackLeavesTheNode(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open();
    $this->stageDelete($id, 0);
    $result = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Alpha moved']);
    $this->assertTrue($result->committed, $this->said($result));
    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    try {
      $this->pipeline()->submit($built, [], $storage);
      $this->fail('The second write fails.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The ledger row "a" could not be written.', $e->getMessage());
    }
    $node = Node::load($this->nid(0));
    $this->assertInstanceOf(NodeInterface::class, $node, 'Loadable in the same request.');
    $this->assertSame('One', $node->getTitle());
    $this->assertNotNull($this->stored(0));
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests `DELETE` with the stage header stages the delete.
   */
  public function testTheHeaderStagesTheDelete(): void {
    $id = $this->open();
    $response = $this->served('DELETE', '/surface-api/' . self::PAGE . '/' . $this->nid(0), NULL, [ServedPaths::STAGE_HEADER => $id]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $answer = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($answer[OperationInputs::COMMITTED]);
    $this->assertSame([''], $answer[OperationInputs::CHANGES]);
    $this->assertNotNull($this->stored(0), 'The node remains until apply.');
    $this->assertSame('delete', $this->stages()->read($id)['writes'][0]['kind']);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertNull($this->stored(0));
  }

}
