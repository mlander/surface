<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Http\ServedOperationAccessCheck;
use Drupal\surface\Http\ServedPaths;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Storage\LedgerStorage;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests a staged create named by its local id, and the writes that name it.
 *
 * A create staged with no id of its own answers `id: -N`, the negative of
 * its position, and a later update, read or delete in the same stage that
 * names `-N` is built as the create's own context again: an update merges
 * into the create, validated whole as a create, a read answers the staged
 * values, and a delete takes the create out of the stage. The local id
 * names nothing without the stage, through another resource, or as a
 * reference. Access is the bundle's create access. Apply creates each
 * thing once, whole, and pairs its local id with the id storage gave. The
 * HTTP door answers a staged POST 200 with `created` naming the local id
 * and no Location, and serves `/-N` with the header alone.
 *
 * @see docs/decisions.md#a-staged-create-is-named-by-a-local-id-and-a-later-write-merges-into-it
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StagedLocalIdTest extends SurfaceKernelTestBase {

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
  protected static $modules = [...self::ENTITY_MODULES, 'surface_stage_test'];

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
    // A page may name another page.
    $settings = ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['page' => 'page']]];
    $this->addField('page', 'field_related', 'entity_reference', ['target_type' => 'node'], $settings, 'Related');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display instanceof EntityFormDisplay);
    $display->setComponent('field_related', ['type' => 'entity_reference_autocomplete', 'weight' => 20])->save();
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
   * Runs one of the page's tools, in a stage.
   *
   * @param string $operation
   *   The operation.
   * @param string $stage
   *   The stage.
   * @param array $inputs
   *   More inputs.
   *
   * @return array{success: bool, message: string, outputs: array, category: ?\Drupal\tool\FailureCategory}
   *   What it answered.
   */
  protected function page(string $operation, string $stage, array $inputs = []): array {
    return $this->runTool('surface:' . self::PAGE . ':' . $operation, $inputs + [OperationInputs::STAGE => $stage]);
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
   * Answers the API route's access check for a request.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path after `/surface-api/`.
   * @param array<string, string> $headers
   *   More headers, by name.
   *
   * @return bool
   *   Whether the route lets the current account in.
   */
  protected function routeAllows(string $method, string $path, array $headers = []): bool {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $segments = explode('/', $path);
    $ids = ['resource' => array_shift($segments)];
    foreach (ServedPaths::SEGMENTS as $index => $name) {
      $ids[$name] = $segments[$index] ?? '';
    }
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    $server = [];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create('/surface-api/' . $path, $method, [], [], [], $server);
    return $check->access($route_match, $this->container->get('current_user'), $request)->isAllowed();
  }

  /**
   * Tests the context a stage builds for a thing it creates.
   */
  public function testTheStageBuildsTheCreatesContext(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, self::PAGE, [], self::VALUES, 'create')->committed);
    $stage = $this->stages()->named($id);
    foreach ([-1, '-1'] as $local) {
      $context = $this->resources()->operation(self::PAGE, 'update', ['id' => $local], $stage);
      $this->assertSame('update', $context->id);
      $this->assertTrue($context->creates, 'Rehearsed as a create.');
      $this->assertSame([StagedStorage::POSITION => 1, 'entity_type' => 'node', 'bundle' => 'page', 'id' => NULL], $context->known);
    }
    $read = $this->resources()->operation(self::PAGE, 'read', ['id' => -1], $stage);
    $this->assertSame(['read', TRUE], [$read->id, $read->creates]);

    $create = $this->resources()->operation(self::PAGE, 'create', [], $stage);
    $this->assertArrayNotHasKey(StagedStorage::POSITION, $create->known, 'A create never looks up.');

    $other = $this->stages()->named($this->open('Another'));
    foreach ([
      'no stage' => [self::PAGE, NULL],
      'another stage' => [self::PAGE, $other],
      'another resource' => [self::NODE, $stage],
    ] as $case => [$resource, $through]) {
      try {
        $this->resources()->operation($resource, 'update', ['id' => -1], $through);
        $this->fail($case . ': -1 names nothing.');
      }
      catch (NamesNothingException $e) {
        $this->assertSame('id', $e->parameter, $case);
      }
    }
  }

  /**
   * Tests a staged create answers its local id, and two are two.
   */
  public function testStagedCreateAnswersItsLocalId(): void {
    $id = $this->open();
    $answer = $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame(-1, $answer['outputs']['id']);
    $this->assertSame('Add Basic page: the values were accepted and staged in ' . $id . '; nothing is created until it is applied, and a write in the stage names it by -1.', $answer['message']);
    $second = $this->page('create', $id, [OperationInputs::VALUES => ['title' => ['value' => 'Winter meetup']] + self::VALUES]);
    $this->assertSame(-2, $second['outputs']['id']);
    $this->assertSame(0, $this->nodes());

    $writes = $this->stages()->read($id)['writes'];
    $this->assertSame([['id' => -1], ['id' => -2]], array_column($writes, 'address'));
    $this->assertSame([[], []], array_column($writes, 'arguments'));
  }

  /**
   * Tests an update of the local id merges into the create.
   */
  public function testUpdateMergesIntoTheCreate(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $before = $this->stages()->read($id)['writes'][0];

    $spring = ['title' => ['value' => 'Spring meetup']];
    $answer = $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => $spring]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame('Edit Basic page: the values were accepted and staged in ' . $id . '.', $answer['message']);
    $this->assertSame(-1, $answer['outputs']['id']);
    $record = $this->stages()->read($id);
    $this->assertCount(1, $record['writes'], 'One entry.');
    $write = $record['writes'][0];
    $this->assertSame([1, 'create', 'create', [], ['id' => -1], NULL], [
      $write['position'],
      $write['operation'],
      $write['kind'],
      $write['arguments'],
      $write['address'],
      $write['token'],
    ]);
    $this->assertSame($before['changed'], $write['changed'], 'The union: a create moved every key already.');
    $this->assertSame(['value' => 'Spring meetup'], $write['values']['title']);
    $this->assertSame(self::VALUES['field_description'], $write['values']['field_description']);
    $this->assertSame(2, $record['next']);

    $read = $this->page('read', $id, ['id' => -1]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('View Basic page: the values staged in ' . $id . ' were read, and nothing was written.', $read['message']);
    $this->assertSame('Spring meetup', $read['outputs'][OperationInputs::VALUES]['title']['value']);
    $this->assertSame('In the hall.', $read['outputs'][OperationInputs::VALUES]['field_description']['value']);

    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:' . self::PAGE . ':update');
    assert($tool instanceof ToolInterface);
    $tool->setInputValue('id', -1);
    $tool->setInputValue(OperationInputs::STAGE, $id);
    $refined = $tool->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $refined);
    $title = $refined->getPropertyDefinitions()['title'];
    $this->assertInstanceOf(MapInputDefinition::class, $title);
    $this->assertSame('Spring meetup', $title->getPropertyDefinitions()['value']->getDefaultValue(), 'The refined values default to the staged ones.');
    $this->assertFalse($refined->getPropertyDefinitions()['field_description']->isRequired(), 'And require nothing.');

    $dry = $this->page('update', $id, [
      'id' => -1,
      OperationInputs::DRY_RUN => TRUE,
      OperationInputs::VALUES => ['title' => ['value' => 'Not staged']],
    ]);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertFalse($dry['outputs'][OperationInputs::COMMITTED]);
    $this->assertSame(-1, $dry['outputs']['id']);
    $this->assertSame($record, $this->stages()->read($id), 'A dry run stages nothing.');
  }

  /**
   * Tests a write to the local id that moves nothing writes no record.
   */
  public function testWriteThatMovesNothingWritesNoRecord(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $record = $this->stages()->read($id);
    $answer = $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => ['title' => self::VALUES['title']]]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame('Edit Basic page: the values were accepted, and they are what is staged in ' . $id . ', so nothing was written.', $answer['message']);
    $this->assertFalse($answer['outputs'][OperationInputs::CHANGED]);
    $this->assertSame([], $answer['outputs'][OperationInputs::CHANGES]);
    $this->assertSame($record, $this->stages()->read($id), 'Neither touched nor updated.');
  }

  /**
   * Tests the merged create is validated whole, as a create.
   */
  public function testMergedCreateIsValidatedWhole(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $record = $this->stages()->read($id);
    [$built, $storage] = $this->through($id, self::PAGE, 'update', ['id' => -1]);
    $result = $this->submitted($built, $storage, ['field_description' => NULL]);
    $this->assertFalse($result->isValid());
    $this->assertSame(['field_description.value' => Violation::REQUIRED], static::codes($result), $this->said($result));
    $this->assertSame($record, $this->stages()->read($id));
  }

  /**
   * Tests a delete of the local id takes the create out of the stage.
   */
  public function testDeleteTakesTheCreateOut(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->page('create', $id, [OperationInputs::VALUES => ['title' => ['value' => 'Winter meetup']] + self::VALUES]);
    $answer = $this->page('delete', $id, ['id' => -1]);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame('Delete Basic page: the create staged in ' . $id . ' was taken out of it; nothing was created or removed.', $answer['message']);
    $this->assertSame(-1, $answer['outputs']['id']);
    $record = $this->stages()->read($id);
    $this->assertSame([2], array_column($record['writes'], 'position'));
    $this->assertSame(3, $record['next'], 'Positions are never reused.');
    $changes = $this->stageTool('changes', $id);
    $this->assertSame([[['name' => 'id', 'value' => '-2']]], array_column($changes['outputs']['items'], 'parameters'));

    $gone = $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => ['title' => ['value' => 'Late']]]);
    $this->assertFalse($gone['success']);
    $this->assertSame(['' => Violation::NOT_FOUND], static::toolCodes($gone));

    // Built before the delete, prepared after it: whole, since what the
    // stage no longer holds is validated before storage is asked.
    [$built, $storage] = $this->through($id, self::PAGE, 'update', ['id' => -2]);
    $this->page('delete', $id, ['id' => -2]);
    $late = $this->submitted($built, $storage, self::VALUES);
    $this->assertSame(['' => Violation::NOT_FOUND], static::codes($late));
    $this->assertSame(' (not_found): The stage no longer creates this thing: its create was taken out of the stage.', $this->said($late));

    $third = $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->assertSame(-3, $third['outputs']['id']);
    $this->assertSame(0, $this->nodes());
  }

  /**
   * Tests the local id asks the bundle's create access, the stage's first.
   *
   * The editor may edit any page and create none: in the editor's own
   * stage, a write to a page it creates is refused as a create would be.
   */
  public function testAccessIsTheBundlesCreateAccess(): void {
    $this->setCurrentUser($this->editor);
    $mine = $this->open('The editor\'s');
    $this->setCurrentUser($this->owner);
    // Staged from PHP, which asks no access, into the editor's stage.
    $this->assertTrue($this->stage($mine, self::PAGE, [], self::VALUES, 'create')->committed);
    $this->setCurrentUser($this->editor);
    $refused = $this->page('update', $mine, ['id' => -1, OperationInputs::VALUES => ['title' => ['value' => 'Edited']]]);
    $this->assertFalse($refused['success']);
    $this->assertSame(FailureCategory::Access, $refused['category']);
    $this->assertSame(self::VALUES['title'], $this->stages()->read($mine)['writes'][0]['values']['title']);

    // Another account's stage is refused naming the stage, before the
    // thing it holds is asked about.
    $this->setCurrentUser($this->owner);
    $theirs = $this->page('read', $mine, ['id' => -1]);
    $this->assertFalse($theirs['success']);
    $this->assertSame('You do not have permission to use the stage ' . $mine . '.', $theirs['message']);
  }

  /**
   * Tests an account that may only create pages keeps its own create.
   *
   * It may edit no page: the read and the update of the local id are
   * asked as the bundle's create access, so it reads back and changes
   * what it staged. Node's create access asks `access content` too, so
   * the account holds it.
   */
  public function testAnAccountThatMayOnlyCreateKeepsItsCreate(): void {
    $this->setCurrentUser($this->createUser([
      'access content',
      'create page content',
      'use text format basic',
    ]));
    $id = $this->open('The creator\'s');
    $created = $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->assertTrue($created['success'], $created['message']);
    $this->assertSame(-1, $created['outputs']['id']);

    $read = $this->page('read', $id, ['id' => -1]);
    $this->assertTrue($read['success'], $read['message']);
    $this->assertSame('Autumn meetup', $read['outputs'][OperationInputs::VALUES]['title']['value']);

    $spring = ['title' => ['value' => 'Spring meetup']];
    $updated = $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => $spring]);
    $this->assertTrue($updated['success'], $updated['message']);
    $this->assertSame('Edit Basic page: the values were accepted and staged in ' . $id . '.', $updated['message']);
    $this->assertSame(['value' => 'Spring meetup'], $this->stages()->read($id)['writes'][0]['values']['title']);
    $this->assertSame(0, $this->nodes());
  }

  /**
   * Tests a reference to the local id is refused.
   */
  public function testReferenceToTheLocalIdIsRefused(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $answer = $this->page('create', $id, [OperationInputs::VALUES => ['field_related' => ['target_id' => -1]] + self::VALUES]);
    $this->assertFalse($answer['success']);
    $this->assertSame(['/field_related/target_id' => 'valid_reference'], static::toolCodes($answer));
    $this->assertSame('This entity (node: -1) cannot be referenced.', $answer['outputs'][OperationInputs::VIOLATIONS][0]['message']);
    $this->assertCount(1, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests a key computed on create follows the merged sources.
   *
   * The ledger's handle is computed from the title it is created with: a
   * later write in the stage computes it again from the merged title, and
   * apply, which creates the row from the whole document, refuses nothing.
   */
  public function testComputedOnCreateFollowsTheMergedSources(): void {
    $id = $this->open();
    $this->assertTrue($this->stage($id, 'stage_test.ledger', [], ['id' => 'x', 'title' => 'Alpha'], 'create')->committed);
    $this->assertSame('alpha', $this->stages()->read($id)['writes'][0]['values']['handle']);
    foreach (['Bravo', 'Charlie delta'] as $title) {
      [$built, $storage] = $this->through($id, 'stage_test.ledger', 'update', ['id' => 'x']);
      $result = $this->submitted($built, $storage, ['title' => $title]);
      $this->assertTrue($result->committed, $this->said($result));
    }
    $writes = $this->stages()->read($id)['writes'];
    $this->assertCount(1, $writes);
    $this->assertSame(['charlie-delta', 'charlie-delta'], [$writes[0]['values']['handle'], $writes[0]['values']['slug']]);

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $row = $this->row('x');
    $this->assertSame(['Charlie delta', 'charlie-delta', 'charlie-delta'], [$row['title'], $row['handle'], $row['slug']]);
  }

  /**
   * Tests apply creates each thing once, in order, pairing the local ids.
   */
  public function testApplyCreatesEachOnceInOrder(): void {
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->page('create', $id, [OperationInputs::VALUES => ['title' => ['value' => 'Winter meetup']] + self::VALUES]);
    $outdoors = ['field_description' => ['value' => 'Moved outdoors.']];
    $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => $outdoors]);
    $this->page('update', $id, ['id' => -2, OperationInputs::VALUES => ['title' => ['value' => 'Winter social']]]);

    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertSame(0, $this->nodes(), 'A dry run saves nothing.');

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $items = $applied['outputs']['applied'];
    $this->assertSame([[['name' => 'id', 'value' => '-1']], [['name' => 'id', 'value' => '-2']]], array_column($items, 'parameters'));
    $this->assertSame(['create', 'create'], array_column($items, 'kind'));
    $ids = array_column($items, 'id');
    $this->assertContainsOnlyString($ids);
    $this->assertLessThan((int) $ids[1], (int) $ids[0], 'In position order.');
    $this->assertSame(2, $this->nodes(), 'Each once.');
    $first = Node::load($ids[0]);
    $second = Node::load($ids[1]);
    $this->assertInstanceOf(NodeInterface::class, $first);
    $this->assertInstanceOf(NodeInterface::class, $second);
    $this->assertSame(['Autumn meetup', 'Moved outdoors.'], [$first->label(), $first->get('field_description')->value]);
    $this->assertSame(['Winter social', 'In the hall.'], [$second->label(), $second->get('field_description')->value]);
  }

  /**
   * Tests a rolled back apply leaves no node and the stage open.
   */
  public function testRolledBackApplyLeavesNoNode(): void {
    $this->ledger('a', 'Alpha');
    $id = $this->open();
    $this->page('create', $id, [OperationInputs::VALUES => self::VALUES]);
    $this->page('update', $id, ['id' => -1, OperationInputs::VALUES => ['title' => ['value' => 'Spring meetup']]]);
    $this->assertTrue($this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Alpha moved'])->committed);
    $this->container->get('state')->set(LedgerStorage::FAIL, 'a');
    $applied = $this->stageTool('apply', $id);
    $this->assertFalse($applied['success']);
    $this->assertSame(FailureCategory::Runtime, $applied['category']);
    $this->assertSame(0, $this->nodes());
    $this->assertSame('open', $this->stages()->read($id)['status']);
    $this->assertCount(2, $this->stages()->read($id)['writes']);
  }

  /**
   * Tests the HTTP door stages a create and serves its local id.
   *
   * Through the controller, with the route's access check asked beside it
   * where a request without the header is refused.
   */
  public function testTheHttpDoorServesTheLocalId(): void {
    $id = $this->open();
    $header = [ServedPaths::STAGE_HEADER => $id];
    $response = $this->served('POST', '/surface-api/' . self::PAGE, self::VALUES, $header);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertFalse($response->headers->has('Location'));
    $this->assertFalse($response->headers->has('ETag'));
    $answer = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame(['id' => -1], (array) $answer['outputs']);
    $this->assertSame(['resource' => self::PAGE, 'operation' => 'update', 'parameters' => ['id' => -1]], $answer['created']);

    $this->assertTrue($this->routeAllows('GET', self::PAGE . '/-1', $header));
    $read = $this->served('GET', '/surface-api/' . self::PAGE . '/-1', NULL, $header);
    $this->assertSame(200, $read->getStatusCode(), (string) $read->getContent());
    $etag = (string) $read->headers->get('ETag');
    $this->assertNotSame('', $etag, 'The staged view is tagged.');
    $this->assertSame('Autumn meetup', json_decode((string) $read->getContent(), TRUE)['values']['title']['value']);
    $this->assertFalse($this->routeAllows('GET', self::PAGE . '/-1'), 'Without the header, the fixed refusal.');

    $this->assertTrue($this->routeAllows('POST', self::PAGE . '/-1/schema', $header));
    $narrowed = $this->served('POST', '/surface-api/' . self::PAGE . '/-1/schema', [], $header);
    $this->assertSame(200, $narrowed->getStatusCode(), (string) $narrowed->getContent());
    $this->assertSame('Autumn meetup', json_decode((string) $narrowed->getContent(), TRUE)['values']['title']['value'], 'Narrowed over the staged view.');
    $this->assertFalse($narrowed->headers->has('ETag'), 'A narrowing has no tag, with a stage or without.');

    $this->assertTrue($this->routeAllows('PATCH', self::PAGE . '/-1', $header));
    $patched = $this->served('PATCH', '/surface-api/' . self::PAGE . '/-1', ['title' => ['value' => 'Spring meetup']], $header + ['If-Match' => $etag]);
    $this->assertSame(200, $patched->getStatusCode(), (string) $patched->getContent());
    $this->assertNotSame($etag, (string) $patched->headers->get('ETag'));
    $this->assertSame(['value' => 'Spring meetup'], $this->stages()->read($id)['writes'][0]['values']['title']);
    $stale = $this->served('PATCH', '/surface-api/' . self::PAGE . '/-1', ['title' => ['value' => 'Late']], $header + ['If-Match' => $etag]);
    $this->assertSame(412, $stale->getStatusCode(), 'If-Match compares staged with staged.');

    $deleted = $this->served('DELETE', '/surface-api/' . self::PAGE . '/-1', NULL, $header);
    $this->assertSame(200, $deleted->getStatusCode(), (string) $deleted->getContent());
    $this->assertSame([], $this->stages()->read($id)['writes']);
    $this->assertFalse($this->routeAllows('GET', self::PAGE . '/-1', $header), 'It names nothing now.');
    $this->assertSame(0, $this->nodes());
  }

}
