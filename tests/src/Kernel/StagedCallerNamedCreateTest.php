<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Staging\StageRaceException;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_examples_entity\Resource\ContentEntityResources;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests staged creates whose caller names the thing.
 *
 * A name is the caller's to give once in a stage per storage class: a
 * second staged create of it is refused `taken` at the name, naming the
 * position that holds it, through the same resource or another of the
 * same storage, and the stage holds what it held. A name already stored
 * is refused by the storage at staging; one stored by another path after
 * staging is refused at `/writes/<n>.id` at apply, nothing written and the
 * stage kept. The ledger, whose storage refuses no taken name, has the
 * stage's check alone. Two creates of one name prepared at once race at
 * commit. A later write in the stage names the create by its name and
 * merges into it, the name fixed, and `changes` names it so.
 *
 * @see docs/decisions.md#a-staged-create-is-whole-and-rehearsed-as-a-create
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedCallerNamedCreateTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The entity type whose ids the caller names.
   */
  protected const NAMED = 'surface_string_id';

  /**
   * Its bundle's resource.
   */
  protected const OWN = ContentEntityResources::BASE . ':' . self::NAMED . '.' . self::NAMED;

  /**
   * Its entity type's resource.
   */
  protected const WHOLE = ContentEntityResources::BASE . ':' . self::NAMED;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_string_id_test', 'surface_stage_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installEntitySchema(self::NAMED);
    // A form display, so the deriver derives its resources.
    EntityFormDisplay::create([
      'targetEntityType' => self::NAMED,
      'bundle' => self::NAMED,
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('name', ['type' => 'string_textfield', 'weight' => 0])->save();
    // User 1, so the owner below is not.
    $this->setUpCurrentUser(admin: TRUE);
    $this->setCurrentUser($this->createUser([], NULL, TRUE));
  }

  /**
   * Stages a create of the bundle's resource.
   *
   * @param string $stage
   *   The stage.
   * @param string $name
   *   The id the caller gives.
   * @param string $label
   *   The entity's name.
   *
   * @return \Drupal\surface\Pipeline\SurfaceResult
   *   The result.
   */
  protected function stageNamed(string $stage, string $name, string $label): SurfaceResult {
    return $this->stage($stage, self::OWN, [], ['id' => $name, 'name' => ['value' => $label]], 'create');
  }

  /**
   * Loads an entity as the database holds it.
   *
   * @param string $id
   *   Its id.
   *
   * @return string|null
   *   Its name, or NULL when there is none.
   */
  protected function stored(string $id): ?string {
    $entity = $this->container->get('entity_type.manager')->getStorage(self::NAMED)->loadUnchanged($id);
    return $entity === NULL ? NULL : (string) $entity->label();
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
   * Tests a second staged create of one name is taken, naming the first.
   */
  public function testTwoCreatesOfOneNameAreTaken(): void {
    $id = $this->open();
    $first = $this->stageNamed($id, 'first', 'First');
    $this->assertTrue($first->committed, $this->said($first));
    $write = $this->stages()->read($id)['writes'][0];
    $this->assertSame(['entity_type' => self::NAMED, 'bundle' => self::NAMED, 'id' => 'first'], $write['known'], 'The name under its key.');
    $this->assertSame([], $write['arguments']);
    $record = $this->stages()->read($id);

    $again = $this->stageNamed($id, 'first', 'Again');
    $this->assertSame(['id' => Violation::TAKEN], static::codes($again), $this->said($again));
    $this->assertSame('id (check.drupal:SurfaceUnique.taken): A ' . self::OWN . ' named first is created in this stage already, at position 1.', $this->said($again));
    $this->assertSame($record, $this->stages()->read($id), 'The stage holds what it held.');

    // Another name is another thing.
    $other = $this->stageNamed($id, 'second', 'Second');
    $this->assertTrue($other->committed, $this->said($other));
    $this->assertCount(2, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests a name is taken per storage class, through either resource.
   */
  public function testNameIsTakenThroughTheOtherResource(): void {
    $id = $this->open();
    $first = $this->stageNamed($id, 'first', 'First');
    $this->assertTrue($first->committed, $this->said($first));
    $whole = $this->stage($id, self::WHOLE, [], [
      'id' => 'first',
      'bundle' => self::NAMED,
      ContentEntityResources::FIELDS => ['name' => ['value' => 'Again']],
    ], 'create');
    $this->assertSame(['id' => Violation::TAKEN], static::codes($whole), $this->said($whole));
    $this->assertStringContainsString('at position 1', $this->said($whole));

    $other = $this->open('The other way round');
    $wide = $this->stage($other, self::WHOLE, [], [
      'id' => 'first',
      'bundle' => self::NAMED,
      ContentEntityResources::FIELDS => ['name' => ['value' => 'First']],
    ], 'create');
    $this->assertTrue($wide->committed, $this->said($wide));
    $own = $this->stageNamed($other, 'first', 'Again');
    $this->assertSame(['id' => Violation::TAKEN], static::codes($own), $this->said($own));
    $this->assertSame('id (check.drupal:SurfaceUnique.taken): A ' . self::WHOLE . ' named first is created in this stage already, at position 1.', $this->said($own));
  }

  /**
   * Tests a name already stored is refused by the storage at staging.
   */
  public function testStoredNameIsTakenAtStaging(): void {
    [$built, $storage] = $this->permanent(self::OWN, 'create');
    $stored = $this->submitted($built, $storage, ['id' => 'first', 'name' => ['value' => 'First']]);
    $this->assertTrue($stored->committed, $this->said($stored));
    $id = $this->open();
    $again = $this->stageNamed($id, 'first', 'Again');
    $this->assertSame(['id' => Violation::TAKEN], static::codes($again), $this->said($again));
    $this->assertStringContainsString('with the id first already exists', $this->said($again));
    $this->assertSame([], $this->stages()->read($id)['writes']);
  }

  /**
   * Tests a name stored by another path after staging is taken at apply.
   */
  public function testNameStoredSinceStagingIsTakenAtApply(): void {
    $id = $this->open();
    $staged = $this->stageNamed($id, 'first', 'Staged');
    $this->assertTrue($staged->committed, $this->said($staged));
    [$built, $storage] = $this->permanent(self::OWN, 'create');
    $stored = $this->submitted($built, $storage, ['id' => 'first', 'name' => ['value' => 'Meanwhile']]);
    $this->assertTrue($stored->committed, $this->said($stored));

    foreach ([TRUE, FALSE] as $dry_run) {
      $applied = $this->stageTool('apply', $id, $dry_run);
      $this->assertFalse($applied['success'], $dry_run ? 'The dry run.' : 'The apply.');
      $this->assertSame(['/writes/1/id' => Violation::TAKEN], static::toolCodes($applied));
    }
    $this->assertSame('Meanwhile', $this->stored('first'), 'Nothing written.');
    $this->assertSame('open', $this->stages()->read($id)['status']);
    $this->assertCount(1, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests the stage alone refuses a ledger name it holds, then applies it.
   *
   * The ledger's storage writes over a row of the same id and refuses no
   * name: the second staged create of `x` is the stage's refusal.
   */
  public function testTheLedgerNameIsTheStagesToRefuse(): void {
    $id = $this->open();
    $first = $this->stage($id, 'stage_test.ledger', [], ['id' => 'x', 'title' => 'Ex'], 'create');
    $this->assertTrue($first->committed, $this->said($first));
    $again = $this->stage($id, 'stage_test.ledger', [], ['id' => 'x', 'title' => 'Again'], 'create');
    $this->assertSame(['id' => Violation::TAKEN], static::codes($again), $this->said($again));
    $this->assertSame('id (check.drupal:SurfaceUnique.taken): A stage_test.ledger named x is created in this stage already, at position 1.', $this->said($again));
    $this->assertNull($this->row('x'), 'Nothing is stored before apply.');

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Ex', $this->row('x')['title']);
    $kinds = array_map(static fn (array $applied): array => [$applied['kind'], $applied['id']], $applied['outputs']['applied']);
    $this->assertSame([['create', 'x']], $kinds);
  }

  /**
   * Tests a staged ledger create keeps its computed keys in step.
   *
   * Its slug and handle are computed from its title on every write in the
   * stage: a write that sends the title it holds moves nothing, says so,
   * and writes no record; a read answers both; a new title moves both;
   * and apply refuses neither.
   */
  public function testTheLedgerCreateComputesItsKeysOnEveryWrite(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'stage_test.ledger', [], ['id' => 'x', 'title' => 'Ex'], 'create')->committed);
    $record = $this->stages()->read($id);
    $update = 'surface:stage_test.ledger:update';

    $in = ['id' => 'x', OperationInputs::STAGE => $id];
    $same = $this->runTool($update, $in + [OperationInputs::VALUES => ['title' => 'Ex']]);
    $this->assertTrue($same['success'], $same['message']);
    $this->assertSame('Edit a ledger row: the values were accepted, and they are what is staged in ' . $id . ', so nothing was written.', $same['message']);
    $this->assertFalse($same['outputs'][OperationInputs::CHANGED]);
    $this->assertSame([], $same['outputs'][OperationInputs::CHANGES]);
    $this->assertSame($record, $this->stages()->read($id), 'No record is written.');

    $read = $this->runTool('surface:stage_test.ledger:read', $in);
    $this->assertTrue($read['success'], $read['message']);
    $values = $read['outputs'][OperationInputs::VALUES];
    $this->assertSame(['Ex', 'ex', 'ex'], [$values['title'], $values['slug'], $values['handle']]);

    $moved = $this->runTool($update, $in + [OperationInputs::VALUES => ['title' => 'Why']]);
    $this->assertTrue($moved['success'], $moved['message']);
    $this->assertTrue($moved['outputs'][OperationInputs::CHANGED]);
    $this->assertSame(['/title', '/slug', '/handle'], $moved['outputs'][OperationInputs::CHANGES]);
    $held = $this->stages()->read($id)['writes'][0]['values'];
    $this->assertSame(['why', 'why'], [$held['slug'], $held['handle']]);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame([], $applied['outputs'][OperationInputs::VIOLATIONS] ?? []);
    $row = $this->row('x');
    $this->assertSame(['Why', 'why', 'why'], [$row['title'], $row['slug'], $row['handle']]);
  }

  /**
   * Tests a later write names the create by its name, and cannot rename it.
   */
  public function testLaterWriteNamesTheCreateByItsName(): void {
    $id = $this->open();
    $first = $this->stageNamed($id, 'first', 'First');
    $this->assertTrue($first->committed, $this->said($first));
    $this->assertSame(['id' => 'first'], $this->stages()->read($id)['writes'][0]['address']);

    [$built, $storage] = $this->through($id, self::OWN, 'update', ['id' => 'first']);
    $renamed = $this->submitted($built, $storage, ['id' => 'second', 'name' => ['value' => 'Renamed']]);
    $this->assertSame(['id' => 'enum'], static::codes($renamed), $this->said($renamed));
    $merged = $this->submitted($built, $storage, ['name' => ['value' => 'First, again']]);
    $this->assertTrue($merged->committed, $this->said($merged));
    $this->assertSame(['id' => 'first'], $this->pipeline()->outputs($built, $storage, $merged));
    $writes = $this->stages()->read($id)['writes'];
    $this->assertCount(1, $writes, 'Merged into the create.');
    $this->assertSame('create', $writes[0]['kind']);
    $this->assertSame('first', $writes[0]['values']['id']);
    $this->assertSame(['value' => 'First, again'], $writes[0]['values']['name']);

    $changes = $this->stageTool('changes', $id);
    $this->assertSame([['name' => 'id', 'value' => 'first']], $changes['outputs']['items'][0]['parameters']);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('First, again', $this->stored('first'));
    $item = $applied['outputs']['applied'][0];
    $this->assertSame([['name' => 'id', 'value' => 'first']], $item['parameters']);
    $this->assertSame('first', $item['id']);
  }

  /**
   * Tests two creates of one name prepared at once race at commit.
   *
   * Both rehearse over a record that holds neither; the first commits,
   * and the second finds the name taken under the lock.
   */
  public function testParallelCreatesOfOneNameRace(): void {
    $id = $this->open();
    [$built, $one] = $this->staged($id, self::OWN, 'create');
    [, $two] = $this->staged($id, self::OWN, 'create');
    $first = $this->submitted($built, $one, ['id' => 'first', 'name' => ['value' => 'One']], TRUE);
    $second = $this->submitted($built, $two, ['id' => 'first', 'name' => ['value' => 'Two']], TRUE);
    $this->assertTrue($first->isValid() && $second->isValid(), $this->said($first) . $this->said($second));
    $this->pipeline()->commitDryRun($first, $one);
    try {
      $this->pipeline()->commitDryRun($second, $two);
      $this->fail('The name was taken while the second was prepared.');
    }
    catch (StageRaceException $e) {
      $this->assertStringContainsString('"first"', $e->getMessage());
    }
    $writes = $this->stages()->read($id)['writes'];
    $this->assertCount(1, $writes);
    $this->assertSame(['value' => 'One'], $writes[0]['values']['name']);
  }

}
