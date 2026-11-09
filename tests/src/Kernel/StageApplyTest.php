<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests applying a stage: every write, in order, in one transaction.
 *
 * Apply rehearses each staged write first, for the owner, against what is
 * stored now: a token that moved, an access refused, a value refused,
 * each is filed under `/writes/<position>` and nothing is written. Then
 * it writes them all in order and marks the stage applied, keeping its
 * record. A dry run writes nothing and holds no lock; a record that moved
 * or was deleted between the rehearsal and the writes is a race; a write
 * that fails rolls back every write before it, and each storage drops
 * what it cached of them; arguments that build no operation are a
 * violation of their write; only the keys the stage moved are sent, so a
 * key it never moved keeps what is stored then.
 *
 * @see docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageApplyTest extends SurfaceKernelTestBase {

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
   *
   * The database's lock, which a kernel test replaces with one that is
   * always free.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->owner = $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit any page content',
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
   * Gets a page as stored.
   *
   * @param int $index
   *   Which.
   *
   * @return \Drupal\node\NodeInterface
   *   The page.
   */
  protected function stored(int $index): NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($this->nodes[$index]->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Stages a new title for a page.
   *
   * @param string $stage
   *   The stage.
   * @param int $index
   *   Which page.
   * @param string $title
   *   The title.
   */
  protected function retitle(string $stage, int $index, string $title): void {
    $values = ['title' => ['value' => $title], 'revision_log' => ['value' => 'Applied.']];
    $result = $this->stage($stage, self::PAGE, ['id' => $this->nodes[$index]->id()], $values);
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
   * Tests apply writes every staged write in order, and marks the stage.
   */
  public function testApplyWritesInOrderAndMarksTheStageApplied(): void {
    $id = $this->open('Move the meetups');
    $this->retitle($id, 0, 'One, moved');
    $this->retitle($id, 1, 'Two, moved');
    $revisions = [(int) $this->stored(0)->getRevisionId(), (int) $this->stored(1)->getRevisionId()];

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Apply the stage: the values were accepted and written.', $applied['message']);
    $this->assertTrue($applied['outputs'][OperationInputs::COMMITTED]);
    $this->assertTrue($applied['outputs'][OperationInputs::CHANGED]);
    $this->assertSame([''], $applied['outputs'][OperationInputs::CHANGES]);
    $this->assertSame([1, 2], array_column($applied['outputs']['applied'], 'position'));
    $this->assertSame([(string) $this->nodes[0]->id(), (string) $this->nodes[1]->id()], array_column($applied['outputs']['applied'], 'id'));
    $this->assertSame([TRUE, TRUE], array_column($applied['outputs']['applied'], 'changed'));
    foreach ([0 => 'One, moved', 1 => 'Two, moved'] as $index => $title) {
      $node = $this->stored($index);
      $this->assertSame($title, $node->label());
      $this->assertGreaterThan($revisions[$index], (int) $node->getRevisionId(), 'A new revision.');
      $this->assertSame((int) $this->owner->id(), (int) $node->getRevisionUserId(), 'By the owner.');
      $this->assertSame('Applied.', $node->getRevisionLogMessage());
    }

    $record = $this->stages()->read($id);
    $this->assertSame('applied', $record['status']);
    $this->assertSame([], $record['writes']);
    $this->assertIsInt($record['applied_at']);
    $this->assertSame($applied['outputs']['applied'], $record['applied']);

    $again = $this->stageTool('apply', $id);
    $this->assertFalse($again['success']);
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::toolCodes($again));
    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(0, $changes['outputs']['total']);
    $this->assertSame([], $changes['outputs']['items']);
    $read = $this->runTool('surface:surface.stage:read', ['id' => $id]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Move the meetups', $read['outputs'][OperationInputs::VALUES]['label']);
  }

  /**
   * Tests a dry run checks, writes nothing, holds no lock, keeps the stage.
   */
  public function testApplyDryRunWritesNothingAndKeepsTheStage(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertTrue($dry['outputs'][OperationInputs::OK]);
    $this->assertFalse($dry['outputs'][OperationInputs::COMMITTED]);
    $this->assertCount(1, $dry['outputs']['applied']);
    $this->assertSame(1, $dry['outputs']['applied'][0]['position']);
    $this->assertTrue($dry['outputs']['applied'][0]['changed']);
    $this->assertArrayNotHasKey('id', $dry['outputs']['applied'][0], 'A dry run answers no id.');
    $this->assertSame('One', $this->stored(0)->label());

    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->lockMayBeAvailable(StageStore::COLLECTION . ':' . $id), 'A dry run holds no lock.');
    $this->retitle($id, 1, 'Two, moved');
    $record = $this->stages()->read($id);
    $this->assertSame('open', $record['status']);
    $this->assertCount(2, $record['writes']);
  }

  /**
   * Tests a record written between the rehearsal and the writes is a race.
   */
  public function testRecordMovedBetweenPassesIsRace(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    $dry = $this->pipeline()->submit($built, [], $storage, TRUE);
    $this->assertTrue($dry->isValid(), $this->said($dry));
    $this->retitle($id, 1, 'Two, moved');
    try {
      $this->pipeline()->commitDryRun($dry, $storage);
      $this->fail('The record moved between the passes.');
    }
    catch (StageRaceException $e) {
      $this->assertStringContainsString($id, $e->getMessage());
    }
    $this->assertSame(['One', 'Two'], [$this->stored(0)->label(), $this->stored(1)->label()]);
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests a stage deleted between the rehearsal and the writes is a race.
   *
   * The delete takes the stage's lock, so it lands before the apply has
   * the lock or after it released it; before, the apply finds no record
   * under the lock and writes nothing, the record never written back.
   */
  public function testStageDeletedBetweenPassesIsRace(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    $dry = $this->pipeline()->submit($built, [], $storage, TRUE);
    $this->assertTrue($dry->isValid(), $this->said($dry));
    $deleted = $this->runTool('surface:surface.stage:delete', ['id' => $id]);
    $this->assertTrue($deleted['success'], $deleted['message']);
    $this->assertNull($this->stages()->read($id));
    try {
      $this->pipeline()->commitDryRun($dry, $storage);
      $this->fail('The stage was deleted between the passes.');
    }
    catch (StageRaceException $e) {
      $this->assertStringContainsString($id, $e->getMessage());
    }
    $this->assertSame('One', $this->stored(0)->label(), 'Nothing was written.');
    $this->assertNull($this->stages()->read($id), 'The deleted stage was not written back.');
  }

  /**
   * Tests a write whose thing changed refuses the whole apply, by position.
   */
  public function testConflictRefusesTheWholeApplyAndNamesTheWrite(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    $this->retitle($id, 1, 'Two, moved');
    $node = $this->stored(1);
    $node->setTitle('Two, changed meanwhile')->save();

    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Input, $applied['category']);
    $this->assertFalse($applied['outputs'][OperationInputs::COMMITTED]);
    $this->assertSame(['/writes/2' => Violation::CONFLICT], static::toolCodes($applied));
    $this->assertSame(['One', 'Two, changed meanwhile'], [$this->stored(0)->label(), $this->stored(1)->label()]);
    $this->assertCount(2, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests access is asked again at apply, for the owner, per write.
   */
  public function testAccessIsAskedAgainAtApply(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    foreach ($this->owner->getRoles(TRUE) as $role) {
      Role::load($role)?->revokePermission('edit any page content')->save();
    }
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(['/writes/1' => Violation::ACCESS], static::toolCodes($applied));
    $this->assertSame('One', $this->stored(0)->label());
  }

  /**
   * Tests only the owner may apply a stage.
   */
  public function testApplyRunsForTheOwnerOnly(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    $this->setUpCurrentUser([], ['access content', 'edit any page content', 'use text format basic']);
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Access, $applied['category']);
    $this->assertSame(['' => Violation::ACCESS], static::toolCodes($applied));
    $this->assertSame('One', $this->stored(0)->label());
  }

  /**
   * Tests a write that fails rolls back every write made before it.
   */
  public function testRollbackLeavesNothingWritten(): void {
    $this->ledger('a', 'Alpha');
    $this->ledger('b', 'Bravo');
    $id = $this->open();
    foreach (['a' => 'Alpha moved', 'b' => 'Bravo moved'] as $row => $title) {
      $result = $this->stage($id, 'stage_test.ledger', ['id' => $row], ['title' => $title]);
      $this->assertTrue($result->committed, $this->said($result));
    }
    $record = $this->stages()->read($id);
    $this->container->get('state')->set(LedgerStorage::FAIL, 'b');
    [$built, $storage] = $this->permanent('surface.stage', 'apply', ['id' => $id]);
    try {
      $this->pipeline()->submit($built, [], $storage);
      $this->fail('The second write fails.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The ledger row "b" could not be written.', $e->getMessage());
    }
    $this->assertSame('Alpha', $this->row('a')['title'], 'The first write was rolled back.');
    $this->assertSame('alpha', $this->row('a')['slug']);
    $this->assertSame('Bravo', $this->row('b')['title']);
    $this->assertSame($record, $this->stages()->read($id), 'The stage is open and intact.');
    $other = new DatabaseLockBackend($this->container->get('database'));
    $this->assertTrue($other->lockMayBeAvailable(StageStore::COLLECTION . ':' . $id), 'The lock was released.');

    // Fixed, the stage applies, the slugs computed from the staged titles.
    $this->container->get('state')->delete(LedgerStorage::FAIL);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame(['a', 'b'], array_column($applied['outputs']['applied'], 'id'));
    $this->assertSame(['Alpha moved', 'alpha-moved'], [$this->row('a')['title'], $this->row('a')['slug']]);
    $this->assertSame(['Bravo moved', 'bravo-moved'], [$this->row('b')['title'], $this->row('b')['slug']]);
  }

  /**
   * Tests a rolled back apply leaves no entity cached as it was written.
   *
   * The page's write commits, its outputs load the page into the entity
   * storage's cache, and the ledger row after it throws: the transaction
   * rolls the save back, and the content entity storage, told of the
   * rollback, drops its cache of the page, so a load in the same request
   * reads the stored title and not the staged one.
   */
  public function testRollbackDropsWhatWasCachedBesideTheDatabase(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
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
    $node = Node::load($this->nodes[0]->id());
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('One', $node->getTitle(), 'The cache holds what the database holds.');
    $this->assertSame('One', $this->stored(0)->label());
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests arguments that build no operation are a violation of their write.
   */
  public function testArgumentsThatBuildNothingAreViolation(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    $record = $this->stages()->read($id);
    $record['writes'][0]['arguments'] = ['nid' => $this->nodes[0]->id()];
    $this->stages()->write($record);

    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Input, $applied['category']);
    $this->assertSame(['/writes/1' => Violation::DOES_NOT_APPLY], static::toolCodes($applied));
    $this->assertSame('One', $this->stored(0)->label(), 'Nothing was written.');
    $this->assertSame($record, $this->stages()->read($id), 'The stage is open and intact.');
  }

  /**
   * Tests apply sends the keys the stage moved, and no other.
   *
   * The token covers every key of the data surface, so a key the stage
   * never moved, changed by another path, is a conflict and nothing is
   * written. Past that check, only the moved keys are sent, which the
   * second half shows by rewriting the token to what is stored then, a
   * state no caller reaches: the key the stage never moved keeps what is
   * stored when the stage is applied, not the copy the stage read.
   */
  public function testApplySendsOnlyChangedKeys(): void {
    $id = $this->open();
    $this->retitle($id, 0, 'One, moved');
    $node = $this->stored(0);
    $node->set('field_description', 'Changed meanwhile.')->save();
    $refused = $this->stageTool('apply', $id);
    $this->assertFalse($refused['success']);
    $this->assertSame(['/writes/1' => Violation::CONFLICT], static::toolCodes($refused));
    $this->assertSame('One', $this->stored(0)->label(), 'Nothing was written.');

    $record = $this->stages()->read($id);
    [$built, $storage] = $this->permanent(self::PAGE, 'update', ['id' => $this->nodes[0]->id()]);
    $record['writes'][0]['token'] = Fingerprint::of(self::PAGE, $storage->load($built));
    $this->stages()->write($record);
    $this->assertSame(['value' => 'About One.'], $record['writes'][0]['values']['field_description'], 'The stage read the old description.');

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $node = $this->stored(0);
    $this->assertSame('One, moved', $node->label());
    $this->assertSame('Changed meanwhile.', $node->get('field_description')->value);
  }

}
