<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface_http\ServedPaths;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a create written into a stage, and applied.
 *
 * A staged create writes the stage's record and no node: an entry of kind
 * `create`, appended whole, with no arguments, no token, every key changed
 * and its address, and the tool says nothing is created until it is
 * applied and answers its local id. It names no stored thing, so two
 * creates are two entries, through one resource or two, and `changes`
 * lists each with its local id as its parameters. It validates as a real
 * create and asks create access. Apply creates each in position order and
 * answers its new id; a dry run creates nothing, and a rollback leaves no
 * node, in the database or the entity cache. The HTTP door stages a `POST`
 * with the stage header and answers 200, `created` naming the local id
 * and no `Location`.
 *
 * @see docs/decisions.md#a-staged-create-is-whole-and-rehearsed-as-a-create
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedCreateTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use ServedControllerTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * The node type's resource.
   */
  protected const NODE = 'entity.content:node';

  /**
   * A page's values, the required keys among them.
   */
  protected const VALUES = [
    'title' => ['value' => 'Autumn meetup'],
    'field_description' => ['value' => 'In the hall.'],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_stage_test'];

  /**
   * The account that owns the stages.
   */
  protected UserInterface $owner;

  /**
   * An account that may edit pages and not create them.
   */
  protected UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->editor = $this->createUser([
      'access content',
      'edit any page content',
      'use text format basic',
    ]);
    $this->owner = $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'edit own page content',
      'use text format basic',
      'keep the ledger',
    ]);
  }

  /**
   * Counts the nodes the database holds.
   *
   * @return int
   *   How many.
   */
  protected function nodes(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Stages a page's create through the pipeline.
   *
   * @param string $stage
   *   The stage.
   * @param array $values
   *   The values.
   */
  protected function stageCreate(string $stage, array $values = self::VALUES): void {
    $result = $this->stage($stage, self::PAGE, [], $values, 'create');
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
   * Tests a staged create writes the stage, not a node, and says so.
   */
  public function testStagedCreateWritesTheStageNotTheNode(): void {
    $id = $this->open();
    $answer = $this->runTool('surface:' . self::PAGE . ':create', [
      OperationInputs::VALUES => self::VALUES,
      OperationInputs::STAGE => $id,
    ]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame('Add Basic page: the values were accepted and staged in ' . $id . '; nothing is created until it is applied, and a write in the stage names it by -1.', $answer['message']);
    $this->assertTrue($answer['outputs'][OperationInputs::COMMITTED]);
    $this->assertTrue($answer['outputs'][OperationInputs::CHANGED]);
    $this->assertSame(-1, $answer['outputs']['id'], 'The local id until apply.');
    $this->assertSame(0, $this->nodes(), 'No node exists.');

    $record = $this->stages()->read($id);
    $this->assertSame(2, $record['next']);
    $this->assertCount(1, $record['writes']);
    $write = $record['writes'][0];
    $this->assertSame([1, self::PAGE, 'create', 'create'], [
      $write['position'],
      $write['resource'],
      $write['operation'],
      $write['kind'],
    ]);
    $this->assertSame([], $write['arguments']);
    $this->assertNull($write['token']);
    $this->assertSame(self::VALUES['title'], $write['values']['title']);
    $this->assertSame(self::VALUES['field_description'], $write['values']['field_description']);
    $this->assertSame(['node', 'page', NULL], [
      $write['values']['entity_type'],
      $write['values']['bundle'],
      $write['values']['id'],
    ], 'The document is whole.');
    $this->assertSame(array_map('strval', array_keys($write['values'])), $write['changed'], 'Every key.');
    $this->assertSame(['entity_type' => 'node', 'bundle' => 'page', 'id' => NULL], $write['known']);
    $this->assertSame(['id' => -1], $write['address']);

    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(1, $changes['outputs']['total']);
    $item = $changes['outputs']['items'][0];
    $this->assertSame([1, self::PAGE, 'create', 'create'], [
      $item['position'],
      $item['resource'],
      $item['operation'],
      $item['kind'],
    ]);
    $this->assertSame([['name' => 'id', 'value' => '-1']], $item['parameters'], 'A create is named by its local id until it is applied.');
    $this->assertContains('/title', $item['changed']);
    $this->assertContains('/field_description', $item['changed']);
  }

  /**
   * Tests two creates are two entries, the second building on nothing.
   */
  public function testTwoCreatesAreTwoEntries(): void {
    $id = $this->open();
    $this->stageCreate($id);
    [$built, $storage] = $this->staged($id, self::PAGE, 'create');
    $this->assertInstanceOf(StagedStorage::class, $storage);
    $this->assertSame([], $storage->load($built), 'A create names no stored thing, and finds no entry.');
    $second = $this->submitted($built, $storage, [
      'title' => ['value' => 'Winter meetup'],
      'field_description' => ['value' => 'Indoors.'],
    ]);
    $this->assertTrue($second->committed, $this->said($second));

    $record = $this->stages()->read($id);
    $this->assertSame([1, 2], array_column($record['writes'], 'position'));
    $this->assertSame(['Autumn meetup', 'Winter meetup'], array_map(static fn (array $write): string => $write['values']['title']['value'], $record['writes']));
    $this->assertSame(['In the hall.', 'Indoors.'], array_map(static fn (array $write): string => $write['values']['field_description']['value'], $record['writes']));
    $changes = $this->stageTool('changes', $id);
    $this->assertSame(2, $changes['outputs']['total']);
    $this->assertSame(['create', 'create'], array_column($changes['outputs']['items'], 'kind'));
  }

  /**
   * Tests creates through the bundle's and the entity type's resource.
   *
   * Two new things, two entries: the two resources' check for one stored
   * thing staged twice does not apply to a create.
   */
  public function testCreatesThroughTwoResourcesAreTwoThings(): void {
    $id = $this->open();
    $this->stageCreate($id);
    $result = $this->stage($id, self::NODE, [], [
      'bundle' => 'page',
      'fields' => ['title' => ['value' => 'Winter meetup'], 'field_description' => ['value' => 'Indoors.']],
    ], 'create');
    $this->assertTrue($result->committed, $this->said($result));
    $record = $this->stages()->read($id);
    $this->assertSame([self::PAGE, self::NODE], array_column($record['writes'], 'resource'));
    $this->assertSame(['create', 'create'], array_column($record['writes'], 'kind'));

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $titles = array_map(static fn (array $applied): string => (string) Node::load($applied['id'])?->label(), $applied['outputs']['applied']);
    $this->assertSame(['Autumn meetup', 'Winter meetup'], $titles);
  }

  /**
   * Tests a staged create is refused what a real create is refused.
   */
  public function testStagedCreateValidatesAsRealCreate(): void {
    $id = $this->open();
    $result = $this->stage($id, self::PAGE, [], ['title' => ['value' => 'No description']], 'create');
    $this->assertFalse($result->isValid());
    $this->assertSame(['field_description.value' => Violation::REQUIRED], static::codes($result), $this->said($result));
    $this->assertSame([], $this->stages()->read($id)['writes']);
    $this->assertSame(1, $this->stages()->read($id)['next']);
  }

  /**
   * Tests a staged create asks create access.
   *
   * The editor may edit any page and create none: the create is refused
   * in the editor's own stage, and nothing is staged.
   */
  public function testCreateAccessIsAsked(): void {
    $this->setCurrentUser($this->editor);
    $id = $this->open('The editor\'s');
    $answer = $this->runTool('surface:' . self::PAGE . ':create', [
      OperationInputs::VALUES => self::VALUES,
      OperationInputs::STAGE => $id,
    ]);
    $this->assertFalse($answer['success']);
    $this->assertSame(FailureCategory::Access, $answer['category']);
    $this->assertSame([], $this->stages()->read($id)['writes'], 'Nothing was staged.');
    $this->assertSame(0, $this->nodes());
  }

  /**
   * Tests a staged create under an applied stage and one that names nothing.
   */
  public function testStageAppliedOrNamingNothingIsRefused(): void {
    $id = $this->open();
    $this->stageCreate($id);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $late = $this->stage($id, self::PAGE, [], self::VALUES, 'create');
    $this->assertSame(['' => Violation::DOES_NOT_APPLY], static::codes($late), $this->said($late));

    $missing = $this->runTool('surface:' . self::PAGE . ':create', [
      OperationInputs::VALUES => self::VALUES,
      OperationInputs::STAGE => '00000000-0000-4000-8000-000000000000',
    ]);
    $this->assertFalse($missing['success']);
    $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($missing));
    $this->assertSame(1, $this->nodes(), 'The applied create alone.');
  }

  /**
   * Tests apply creates each in position order and answers each new id.
   */
  public function testApplyCreatesEachInOrder(): void {
    $id = $this->open();
    $this->stageCreate($id);
    $this->stageCreate($id, ['title' => ['value' => 'Winter meetup'], 'field_description' => ['value' => 'Indoors.']]);
    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame([1, 2], array_column($applied['outputs']['applied'], 'position'));
    $this->assertSame(['create', 'create'], array_column($applied['outputs']['applied'], 'kind'));
    $this->assertSame([[['name' => 'id', 'value' => '-1']], [['name' => 'id', 'value' => '-2']]], array_column($applied['outputs']['applied'], 'parameters'));
    $ids = array_column($applied['outputs']['applied'], 'id');
    $this->assertCount(2, $ids);
    $this->assertContainsOnlyString($ids, 'Each id as text.');
    $this->assertLessThan((int) $ids[1], (int) $ids[0], 'In position order.');
    $first = Node::load($ids[0]);
    $second = Node::load($ids[1]);
    $this->assertInstanceOf(NodeInterface::class, $first);
    $this->assertInstanceOf(NodeInterface::class, $second);
    $this->assertSame(['Autumn meetup', 'In the hall.'], [$first->label(), $first->get('field_description')->value]);
    $this->assertSame(['Winter meetup', 'Indoors.'], [$second->label(), $second->get('field_description')->value]);
    $this->assertSame((int) $this->owner->id(), (int) $first->getOwnerId(), 'Written as the owner.');
    $this->assertSame('applied', $this->stages()->read($id)['status']);
  }

  /**
   * Tests a dry run of apply creates nothing and answers no id.
   */
  public function testApplyDryRunCreatesNothing(): void {
    $id = $this->open();
    $this->stageCreate($id);
    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertFalse($dry['outputs'][OperationInputs::COMMITTED]);
    $this->assertSame('create', $dry['outputs']['applied'][0]['kind']);
    $this->assertArrayNotHasKey('id', $dry['outputs']['applied'][0], 'Not emitted: nothing was created.');
    $this->assertSame(0, $this->nodes());
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests a rolled back apply leaves no node, in the database or the cache.
   *
   * The create commits and its id is read back, the ledger row after it
   * throws: the transaction rolls the create back, and a load of the id
   * the save was given finds nothing in the same request.
   */
  public function testRollbackLeavesNoNode(): void {
    $this->ledger('a', 'Alpha');
    $existing = Node::create(['type' => 'page', 'title' => 'Before', 'field_description' => 'Stored.']);
    $existing->save();
    $assigned = (int) $existing->id() + 1;
    $id = $this->open();
    $this->stageCreate($id);
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
    $this->assertNull(Node::load($assigned), 'Not in the entity cache.');
    $this->assertSame(1, $this->nodes(), 'Not in the database.');
    $this->assertSame('Alpha', $this->row('a')['title']);
    $this->assertSame('open', $this->stages()->read($id)['status']);
  }

  /**
   * Tests `POST` with the stage header stages the create.
   */
  public function testTheHeaderStagesTheCreate(): void {
    $id = $this->open();
    $response = $this->served('POST', '/surface-api/' . self::PAGE, self::VALUES, [ServedPaths::STAGE_HEADER => $id]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertFalse($response->headers->has('Location'));
    $this->assertFalse($response->headers->has('ETag'));
    $answer = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertTrue($answer[OperationInputs::COMMITTED]);
    $this->assertSame(['resource' => self::PAGE, 'operation' => 'update', 'parameters' => ['id' => -1]], $answer['created'], 'Where it lives in the stage.');
    $this->assertSame(['id' => -1], (array) $answer['outputs'], 'The local id until apply.');
    $this->assertIsArray($answer['contract'], 'The create envelope, rebuilt.');
    $this->assertSame(0, $this->nodes(), 'No node until apply.');
    $this->assertSame('create', $this->stages()->read($id)['writes'][0]['kind']);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $node = Node::load($applied['outputs']['applied'][0]['id']);
    $this->assertSame('Autumn meetup', $node?->label());
  }

}
