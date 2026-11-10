<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface_http\Controller\ResourceApiController;
use Drupal\surface\Staging\ListsStagesInterface;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Staging\StageStoreInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_stage_test\Staging\ArrayStageStore;
use Drupal\surface_stage_test\Storage\ItineraryStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a second store swapped in for `surface.stages`.
 *
 * The in-memory store of surface_stage_test, which keeps records for good
 * and lists them, serves every door with no change to the engine: a stage
 * opened through the tool takes a content write, a config write and a
 * create named by its local id, lists them, rehearses and applies them,
 * and nothing is kept in the expirable collection. The owner check holds,
 * the store lists the owner's open stages newest first, the HTTP door
 * reads a request's stage from it, and a flow's first step offers to
 * resume the one opened by the same operation for the same thing.
 *
 * @see docs/decisions.md#one-store-contract-a-stages-lifetime-is-data-on-its-record
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StageStoreSwapTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The page resource, whose create takes a local id.
   */
  protected const PAGE = 'entity.content:node.page';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_stage_test', 'my_module'];

  /**
   * {@inheritdoc}
   *
   * The test store in place of the shipped one.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('surface.stages', ArrayStageStore::class)->addArgument(new Reference('datetime.time'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ArrayStageStore::$records = [];
    $this->installEntityFixture();
    $this->installConfig(['my_module']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->ledger('a', 'Alpha');
  }

  /**
   * Tests a stage is opened, written, listed and applied in the test store.
   */
  public function testTheSwappedStoreServesEveryStep(): void {
    $this->assertInstanceOf(ArrayStageStore::class, $this->stages());
    $this->assertInstanceOf(ArrayStageStore::class, $this->container->get(StageStoreInterface::class));

    $opened = $this->runTool('surface:surface.stage:create', [OperationInputs::VALUES => ['label' => 'Swapped']]);
    $this->assertTrue($opened['success'], $opened['message']);
    $id = $opened['outputs']['id'];
    $this->assertSame(604800, ArrayStageStore::$records[$id]['keep'], 'The record carries its lifetime; this store ignores it.');

    // A content write, a config write and a create by its local id.
    $result = $this->stage($id, 'stage_test.ledger', ['id' => 'a'], ['title' => 'Beta']);
    $this->assertTrue($result->committed, $this->said($result));
    $result = $this->stage($id, 'my_module.settings', [], ['title' => 'Staged title'], 'configure');
    $this->assertTrue($result->committed, $this->said($result));
    $created = $this->runTool('surface:' . self::PAGE . ':create', [
      OperationInputs::STAGE => $id,
      OperationInputs::VALUES => [
        'title' => ['value' => 'Autumn meetup'],
        'field_description' => ['value' => 'In the hall.'],
      ],
    ]);
    $this->assertTrue($created['success'], $created['message']);
    $this->assertSame(-3, $created['outputs']['id'], 'The local id is the create\'s position, negated.');
    $merged = $this->runTool('surface:' . self::PAGE . ':update', [
      OperationInputs::STAGE => $id,
      'id' => -3,
      OperationInputs::VALUES => ['title' => ['value' => 'Spring meetup']],
    ]);
    $this->assertTrue($merged['success'], $merged['message']);
    $this->assertCount(3, ArrayStageStore::$records[$id]['writes'], 'The update merged into the create the store found by its local id.');

    $changes = $this->stageTool('changes', $id);
    $this->assertTrue($changes['success'], $changes['message']);
    $this->assertSame(['stage_test.ledger', 'my_module.settings', self::PAGE], array_column($changes['outputs']['items'], 'resource'));

    $dry = $this->stageTool('apply', $id, TRUE);
    $this->assertTrue($dry['success'], $dry['message']);
    $this->assertSame('Alpha', $this->row('a')['title'], 'A dry run writes nothing.');

    $applied = $this->stageTool('apply', $id);
    $this->assertTrue($applied['success'], $applied['message']);
    $this->assertSame('Beta', $this->row('a')['title']);
    $this->assertSame('Staged title', $this->config('my_module.settings')->get('title'));
    $nodes = $this->container->get('entity_type.manager')->getStorage('node')->loadByProperties(['title' => 'Spring meetup']);
    $this->assertCount(1, $nodes);
    $this->assertSame('applied', ArrayStageStore::$records[$id]['status']);

    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->getAll(), 'Nothing is kept in the expirable collection.');
  }

  /**
   * Tests the owner check and the listing, over the test store.
   */
  public function testOwnerAndListing(): void {
    $owner = (int) $this->container->get('current_user')->id();
    $first = $this->open('First');
    $second = $this->open('Second');
    $resources = $this->resources();
    $update = $resources->operation('surface.stage', 'update', ['id' => $first]);
    $this->assertTrue($resources->access('surface.stage', $update)->isAllowed(), 'The owner may write into the stage.');
    $other = $this->createUser(['keep the ledger']);
    $this->assertTrue($resources->access('surface.stage', $update, $other)->isForbidden(), 'Another account may not.');

    $store = $this->stages();
    $this->assertInstanceOf(ListsStagesInterface::class, $store);
    $this->assertSame([$second, $first], $store->openStages($owner), 'Opened at the same time: the last added first.');
    // Newest is by `opened`, whatever order the records were added in.
    ArrayStageStore::$records[$first]['opened'] += 60;
    $this->assertSame([$first, $second], $store->openStages($owner), 'Newest first.');
    ArrayStageStore::$records[$second]['opened'] += 120;
    $this->assertSame([$second, $first], $store->openStages($owner), 'Newest first.');
    $this->assertSame([], $store->openStages((int) $other->id()));
    $this->assertTrue($this->stageTool('delete', $first)['success']);
    $this->assertSame([$second], $store->openStages($owner), 'A discarded stage is gone.');
    ArrayStageStore::$records[$second]['status'] = 'applied';
    $this->assertSame([], $store->openStages($owner), 'An applied stage is not open.');
  }

  /**
   * Tests the HTTP door reads a request's stage from the swapped store.
   *
   * One request through the kernel: the API route's access check
   * (ServedOperationAccessCheck) asks the stage's owner, and the
   * controller (ServedOperations) names the stage and writes into it,
   * both through the test store.
   */
  public function testTheHttpDoorReadsTheSwappedStore(): void {
    $this->container->get('router.builder')->rebuild();
    $id = $this->open('Over HTTP');
    $registry = $this->container->get('surface.resource_registry');
    $paths = $this->container->get('surface.served_paths');
    $ledger = $registry->getDefinition('stage_test.ledger');
    $update = $registry->getOperation('stage_test.ledger', 'update');
    [$method, $named] = $paths->writes($ledger, $update);
    $url = $paths->url($ledger, $update, ['id' => 'a'], $named, FALSE)->getGeneratedUrl();
    $request = Request::create($url, $method, [], [], [], [
      'CONTENT_TYPE' => $method === 'PATCH' ? ResourceApiController::MERGE_PATCH : 'application/json',
      'HTTP_X_SURFACE_STAGE' => $id,
    ], (string) json_encode(['title' => 'Over HTTP']));
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $writes = ArrayStageStore::$records[$id]['writes'];
    $this->assertCount(1, $writes, 'The write is kept in the test store.');
    $this->assertSame('stage_test.ledger', $writes[0]['resource']);
    $this->assertSame('update', $writes[0]['operation']);
    $this->assertSame(['id' => 'a'], $writes[0]['arguments']);
    $this->assertSame('Alpha', $this->row('a')['title'], 'Staged, not written.');
    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->getAll());

    // Another account's stage, read from the test store, is refused by
    // the route's access check.
    $admin = $this->container->get('current_user')->getAccount();
    $this->setCurrentUser($this->createUser(['keep the ledger']));
    $foreign = $this->open('Not yours');
    $this->setCurrentUser($admin);
    $request = Request::create($url, $method, [], [], [], [
      'CONTENT_TYPE' => $method === 'PATCH' ? ResourceApiController::MERGE_PATCH : 'application/json',
      'HTTP_X_SURFACE_STAGE' => $foreign,
    ], (string) json_encode(['title' => 'Not yours']));
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([], ArrayStageStore::$records[$foreign]['writes']);
  }

  /**
   * Tests a flow's first step offers the open stage for the same thing.
   *
   * The newest open stage of the account whose first write is the
   * route's operation of its resource under the same arguments, linked
   * at the next step drawn, or at the confirm page of a one-step flow. A
   * stage whose
   * first write is another operation, a stage with no writes and another
   * thing's stage are passed over. Only the
   * first draw of step 1 offers it: a submission and a rebuild do not,
   * nor does a form with no flow.
   */
  public function testTheFlowOffersToResume(): void {
    $this->container->get('router.builder')->rebuild();
    $trips = $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION);
    foreach (['trip', 'cruise'] as $trip) {
      $trips->set($trip, ['id' => $trip, 'region' => 'north', 'day' => 'mon', 'nights' => 2]);
    }
    $messenger = $this->container->get('messenger');
    $offers = static fn (): array => array_values(array_filter(array_map('strval', $messenger->messagesByType('status')), static fn (string $said): bool => str_starts_with($said, 'You have an open stage')));
    $link = static fn (string $path, string $stage, string $step): string => 'You have an open stage for this: <a href="' . $path . '?stage=' . $stage . '&amp;step=' . $step . '">resume it</a> or start again.';

    $this->visit('/surface-test/itinerary/trip/flow');
    $this->surfaceForm();
    $this->assertSame([], $offers(), 'No stage yet: nothing to resume.');
    $stage = $this->submitFirstStep();
    $this->assertSame([], $offers(), 'A submission offers nothing.');
    $opened = ArrayStageStore::$records[$stage]['opened'];

    // Newer stages that are no match: one with no writes, and one whose
    // first write deletes the same trip.
    $empty = $this->open('Empty');
    $deletes = $this->open('Deletes the trip');
    $this->assertTrue($this->stage($deletes, 'stage_test.itinerary', ['id' => 'trip'], [], 'delete')->committed);
    ArrayStageStore::$records[$empty]['opened'] = $opened + 30;
    ArrayStageStore::$records[$deletes]['opened'] = $opened + 20;

    $this->visit('/surface-test/itinerary/trip/flow');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame(0, $form['#cache']['max-age'], 'The offer is this account\'s, so the page is not cached.');
    $this->assertSame([$link('/surface-test/itinerary/trip/flow', $stage, '2')], $offers());
    $messenger->deleteAll();

    // A rebuild, with the input of a submission, offers nothing.
    $this->visit('/surface-test/itinerary/trip/flow');
    $state = (new FormState())->setUserInput([SurfaceOperationForm::SURFACE_KEY => ['region' => 'south']])->setRebuild();
    $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $offers(), 'A rebuild offers nothing.');

    // Two stages match: the one opened last is linked, not the one added
    // last.
    $this->visit('/surface-test/itinerary/trip/flow');
    $later = $this->submitFirstStep();
    $this->assertNotSame($stage, $later);
    ArrayStageStore::$records[$stage]['opened'] = $opened + 10;
    $messenger->deleteAll();
    $this->visit('/surface-test/itinerary/trip/flow');
    $this->surfaceForm();
    $this->assertSame([$link('/surface-test/itinerary/trip/flow', $stage, '2')], $offers());
    $messenger->deleteAll();

    // A one-step flow over the same operation links its confirm page.
    $this->visit('/surface-test/itinerary/trip/flow-one');
    $this->surfaceForm();
    $this->assertSame([$link('/surface-test/itinerary/trip/flow-one', $stage, CompiledFlow::CONFIRM)], $offers());
    $messenger->deleteAll();

    // A flow class whose second step is passed over for what the stage
    // holds links the next step drawn. That step is over the ledger row,
    // which the flow's checks at the match build.
    $this->ledger('row', 'First row');
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow_class');
    $route->setDefault(CompiledFlow::ROUTE, 'stage_test.skips_second_step');
    $this->serveBuilt('surface_stage_test.itinerary_flow_class', $route, ['id' => 'trip']);
    $this->surfaceForm();
    $this->assertSame([$link('/surface-test/itinerary/trip/flow-class', $stage, '3')], $offers(), 'Two nights pass over the ledger row at step 2.');
    $messenger->deleteAll();

    // The same operation with no flow offers nothing.
    $this->visit('/surface-test/itinerary/trip/edit');
    $this->surfaceForm();
    $this->assertSame([], $offers(), 'A form with no flow offers nothing.');

    // Another trip's flow has nothing to resume.
    $this->visit('/surface-test/itinerary/cruise/flow');
    $this->surfaceForm();
    $this->assertSame([], $offers());
  }

  /**
   * Submits step 1 of the trip's flow on the stack, opening a stage.
   *
   * @return string
   *   The id of the stage it opened.
   */
  protected function submitFirstStep(): string {
    $state = (new FormState())->setValues([SurfaceOperationForm::SURFACE_KEY => ['region' => 'south']]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    // A programmed form answers no redirect until it is not programmed.
    return $state->setProgrammed(FALSE)->getRedirect()->getOption('query')['stage'];
  }

}
