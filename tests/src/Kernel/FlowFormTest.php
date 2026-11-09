<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Access\OperationRouteSubscriber;
use Drupal\surface\Form\Flow;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Staging\StageStore;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface_stage_test\Resource\LedgerResource;
use Drupal\surface_stage_test\Storage\ItineraryStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests a flow: one operation drawn a group a step, into one stage.
 *
 * Over the itinerary of surface_stage_test, whose routes declare flows
 * that hold and flows the match refuses, some with a step over another
 * resource, and over the content type example's flow route. Each step is
 * submitted as a programmed form, so
 * where it goes next is read from the redirect it set.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FlowFormTest extends SurfaceKernelTestBase {

  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'serialization',
    'tool',
    'surface',
    'surface_stage_test',
    'surface_tool_test',
    'my_module',
    'surface_examples_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node', 'my_module']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'keep the ledger',
      'administer content types',
      'administer surface examples content types',
    ]);
    $this->container->get('router.builder')->rebuild();
    $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->set('trip', [
      'id' => 'trip',
      'region' => 'north',
      'day' => 'mon',
      'nights' => 2,
    ]);
  }

  /**
   * Tests the first step opens a stage, stages its keys and goes on.
   */
  public function testTheFirstStepOpensStage(): void {
    $this->visit('/surface-test/itinerary/trip/flow?destination=/elsewhere');
    $form = $this->surfaceForm();
    $this->assertSame(['id', 'region'], $this->drawn($form), 'The first step draws the keys in no group and its own group.');
    [$state, $redirect] = $this->submitStep(['region' => 'south']);
    $this->assertSame([], $state->getErrors());
    $query = $redirect->getOption('query');
    $this->assertSame('2', $query['step']);
    $stage = $query['stage'];
    $this->assertSame('surface_stage_test.itinerary_flow', $redirect->getRouteName());
    $this->assertSame(['id' => 'trip'], $redirect->getRouteParameters());
    $record = $this->stages()->read($stage);
    $this->assertSame('Edit a trip', $record['label'], 'The stage is labeled by the flow.');
    $this->assertCount(1, $record['writes']);
    $this->assertSame(['region'], $record['writes'][0]['changed']);
    $this->assertSame('north', $this->trip()['region'], 'Nothing is written to the trip.');
    $this->assertStringContainsString('staged in', $this->statusMessage());
    $this->assertFalse($this->container->get('request_stack')->getCurrentRequest()->query->has('destination'), 'Next goes to the next step, whatever "destination" says.');
  }

  /**
   * Tests a flow's `keep` is how long the stage it opens lives.
   *
   * Short is the form cache's lifetime, long the temp store's, the
   * default; each later write starts the time again. The shipped store
   * lists no stages, so step 1 offers none to resume.
   */
  public function testKeepIsTheStagesLifetime(): void {
    $database = $this->container->get('database');
    $expiry = fn (string $id): int => (int) $database->select('key_value_expire', 'k')->fields('k', ['expire'])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $id)->execute()->fetchField();
    $now = $this->container->get('datetime.time')->getRequestTime();

    $long = $this->firstStep();
    $this->assertSame(604800, $this->stages()->read($long)['keep']);
    $this->assertEqualsWithDelta($now + 604800, $expiry($long), 5);

    $this->visit('/surface-test/itinerary/trip/flow-short');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame([], $this->container->get('messenger')->messagesByType('status'), 'The shipped store lists no stages: no resume is offered.');
    $this->assertNotSame(0, $form['#cache']['max-age'] ?? NULL, 'With no listing, step 1 stays cacheable.');
    [, $redirect] = $this->submitStep(['region' => 'south']);
    $short = $redirect->getOption('query')['stage'];
    $this->assertSame(21600, $this->stages()->read($short)['keep']);
    $this->assertEqualsWithDelta($now + 21600, $expiry($short), 5);

    // A later write starts the short time again.
    $database->update('key_value_expire')->fields(['expire' => $now + 10])
      ->condition('collection', StageStore::COLLECTION)->condition('name', $short)->execute();
    $this->visit('/surface-test/itinerary/trip/flow-short?stage=' . $short . '&step=2');
    $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->assertEqualsWithDelta($now + 21600, $expiry($short), 5);
  }

  /**
   * Tests a first step refused after its stage opened leaves no stage.
   */
  public function testRefusedFirstStepLeavesNoStage(): void {
    // The dry run in validation holds; the write into the stage just
    // opened is refused.
    $this->container->get('state')->set(ItineraryStorage::REFUSE, 2);
    $this->visit('/surface-test/itinerary/trip/flow');
    $state = (new FormState())->setValues([SurfaceOperationForm::SURFACE_KEY => ['region' => 'south']]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(0, $this->container->get('state')->get(ItineraryStorage::REFUSE), 'The write into the stage was refused.');
    $this->assertSame(['The trip cannot be written now.'], array_map('strval', $this->container->get('messenger')->messagesByType('error')));
    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->getAll(), 'The stage opened for the write is deleted.');
    $this->assertSame('north', $this->trip()['region']);
  }

  /**
   * Tests a later step draws its group alone, over what is staged.
   */
  public function testTheSecondStepDrawsItsGroupOverTheStage(): void {
    $stage = $this->firstStep();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    $form = $this->surfaceForm();
    $this->assertSame(['day', 'nights'], $this->drawn($form));
    $this->assertSame('mon', $form['day']['#default_value']);
    // What the first step staged is what the second reads.
    [$built, $storage] = $this->through($stage, 'stage_test.itinerary', 'update', ['id' => 'trip']);
    $this->assertSame('south', $storage->load($built)['region']);
    [, $redirect] = $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->assertSame(['stage' => $stage, 'step' => '3'], $redirect->getOption('query'));
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=3');
    $form = $this->surfaceForm();
    $this->assertSame(['guide'], $this->drawn($form));
    $this->assertSame(['cy', 'di'], array_values(array_filter(array_keys($form['guide']['#options']), static fn (string|int $key): bool => $key !== '' && $key !== '_none')), 'The guides are the region the first step staged.');
    [, $redirect] = $this->submitStep(['guide' => 'cy']);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $redirect->getOption('query'), 'After the last group, the confirm page.');
    $writes = $this->stages()->read($stage)['writes'];
    $this->assertCount(1, $writes, 'One thing, one entry.');
    $this->assertSame(['region', 'day', 'nights', 'guide'], $writes[0]['changed']);
    $this->assertSame(['id' => 'trip', 'region' => 'north', 'day' => 'mon', 'nights' => 2], $this->trip());
  }

  /**
   * Tests Back goes to the step before and stages nothing.
   */
  public function testBackStagesNothing(): void {
    $stage = $this->firstStep();
    $before = $this->stages()->read($stage);
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('Back', (string) $form['actions']['back']['#value']);
    $this->assertSame('Next', (string) $form['actions']['submit']['#value']);
    [$state, $redirect] = $this->submitStep(['day' => 'sun'], 'Back');
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['stage' => $stage, 'step' => '1'], $redirect->getOption('query'));
    $this->assertSame($before, $this->stages()->read($stage), 'The record is as it was.');
    // The first step has no Back.
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage);
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertArrayNotHasKey('back', $form['actions']);
  }

  /**
   * Tests a step after the first needs a stage, and a step must exist.
   */
  public function testLaterStepNeedsTheStage(): void {
    $this->visit('/surface-test/itinerary/trip/flow?step=2');
    $this->assertRefused('The surface_stage_test.itinerary_flow route draws step 2 of its flow into the stage its first step opened, and the query names none: open the flow at its first step, or name the stage with "stage".');
    $stage = $this->firstStep();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=4');
    $check = $this->container->get('surface.operation_access');
    $access = $check->access($this->container->get('current_route_match'), $this->container->get('current_user'));
    $this->assertTrue($access->isForbidden(), 'A step the flow does not have names nothing: no link to it is drawn.');
    $this->assertSame('', (string) $access->getReason());
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertTrue($check->access($this->container->get('current_route_match'), $this->container->get('current_user'), $request)->isAllowed(), 'The request itself goes on, for the form to answer it.');
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The form did not answer a page not found.');
    }
    catch (NotFoundHttpException $e) {
      $this->assertSame('This flow has no such step.', $e->getMessage());
    }
  }

  /**
   * Tests another account's stage is refused on a flow's page.
   *
   * Before anything the stage holds is read: an owner with two creates
   * in the stage hears that, another account hears only the refusal.
   */
  public function testAnotherAccountsStageIsRefusedAtFlowSteps(): void {
    $update = $this->firstStep();
    $creates = $this->open('Two trips');
    foreach (['east' => 'mon', 'west' => 'tue'] as $id => $day) {
      $result = $this->stage($creates, 'stage_test.itinerary', [], ['id' => $id, 'day' => $day], 'create');
      $this->assertTrue($result->committed, $this->said($result));
    }
    $this->visit('/surface-test/itinerary/plan?stage=' . $creates . '&step=2');
    $this->assertRefused('The stage holds 2 creates of stage_test.itinerary through the "create" operation, at the positions 1, 2, and a flow writes into the one its first step staged: open the flow without a stage to start again.');
    // The confirm page writes nothing through the create, so the owner
    // may still apply or discard the stage.
    $this->visit('/surface-test/itinerary/plan?stage=' . $creates . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertCount(2, $form['changes']['#rows']);
    $this->setUpCurrentUser([], ['keep the ledger']);
    $refusal = 'The stage is another account\'s.';
    $this->visit('/surface-test/itinerary/plan?stage=' . $creates . '&step=2');
    $this->assertRefused($refusal);
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $update . '&step=2');
    $this->assertRefused($refusal);
  }

  /**
   * Tests the three refusals that need the resource built.
   */
  public function testTheMatchRefusesWhatTheBuildContradicts(): void {
    $this->visit('/surface-test/itinerary/trip/flow-reordered');
    $this->assertRefused('The surface_stage_test.itinerary_flow_reordered route\'s flow draws "guide" at step 1, before "region" at step 2, and "guide" is refined by the value of "region": draw "region" at the same step or an earlier one.');
    $this->visit('/surface-test/itinerary/plan-split');
    $this->assertRefused('The surface_stage_test.itinerary_create_flow_split route\'s flow creates through the "create" operation of stage_test.itinerary and draws its required key "day", which has no default, at step 2: each step is validated as the whole create, so a required key with no default is drawn at the first step.');
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow');
    $route = clone $route;
    $route->setDefault('_surface_flow', ['steps' => ['where', 'elsewhere']]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s flow draws the group "elsewhere" at a step, and the "update" operation of stage_test.itinerary has no such group: name one of "where", "when", "who".');
    $this->visit('/surface-test/itinerary/trip/cancel-flow');
    $this->assertRefused('The surface_stage_test.itinerary_delete_flow route declares a flow over the "delete" operation of stage_test.itinerary, which a flow cannot stage: a flow writes each step into a stage, and a stage applies to an operation that writes one thing of a resource whose storage is transactional, and takes no delete over steps.');
  }

  /**
   * Tests the router's rebuild refuses a flow written wrong.
   */
  public function testTheRebuildRefusesFlowWrittenWrong(): void {
    $base = [
      '_form' => SurfaceOperationForm::class,
      '_surface_resource' => 'stage_test.itinerary',
      '_surface_operation' => 'update',
    ];
    $shape = 'The bad route\'s "_surface_flow" is a map of "steps", a list of the steps drawn in order, an optional "label", an optional "redirect", a route name, and an optional "keep", short or long, as { steps: [where, when] }';
    $ledger = ['resource' => 'stage_test.ledger', 'operation' => 'update'];
    $notes = $ledger + ['group' => 'notes'];
    $cases = [
      $shape . ': "steps" lists at least one step, each a group id of the route\'s operation, a non-empty string, or a step over another resource, a map of "resource" and "operation", each a non-empty string, an optional "group", a non-empty string, and optional "arguments", a map of values by parameter name, as { resource: example.settings, operation: configure }.' => $base + [
        '_surface_flow' => ['steps' => []],
      ],
      'The bad route\'s "_surface_flow" starts with a step over another resource: the first step\'s submit opens the stage for the route\'s own operation, so list one of its group ids first.' => $base + [
        '_surface_flow' => ['steps' => [$ledger, 'where']],
      ],
      'The bad route\'s "_surface_flow" lists the group "notes" of the "update" operation of stage_test.ledger twice: each group is drawn at one step.' => $base + [
        '_surface_flow' => ['steps' => ['where', $notes, $notes]],
      ],
      'The bad route\'s "_surface_flow" draws the "update" operation of stage_test.ledger at more than one step, and one of them names no group: a step with no group draws the whole operation, so give each step over it a group.' => $base + [
        '_surface_flow' => ['steps' => ['where', $ledger, $notes]],
      ],
      $shape . '.' => $base + [
        '_surface_flow' => ['steps' => ['where'], 'groups' => []],
      ],
      'The bad route\'s "_surface_flow" lists the group "where" twice: each group is drawn at one step.' => $base + [
        '_surface_flow' => ['steps' => ['where', 'where']],
      ],
      'The bad route declares "_surface_flow" and names no resource and operation: a flow draws one operation\'s form over steps, so name them in "_surface_resource" and "_surface_operation".' => [
        '_form' => SurfaceOperationForm::class,
        '_surface_flow' => ['steps' => ['where']],
      ],
      $shape . ': "label" is a non-empty string, or left out.' => $base + [
        '_surface_flow' => ['steps' => ['where'], 'label' => ''],
      ],
      $shape . ': "redirect" is a non-empty string, or left out.' => $base + [
        '_surface_flow' => ['steps' => ['where'], 'redirect' => ['entity.node_type.collection']],
      ],
      $shape . ': "keep" is "short" or "long", or left out.' => $base + [
        '_surface_flow' => ['steps' => ['where'], 'keep' => 3600],
      ],
      'The bad route declares "_surface_flow" and its "_form" is not Drupal\surface\Form\SurfaceOperationForm: only the operation form draws a flow\'s steps, so serve the route with it or a class extending it.' => [
        '_controller' => '\Drupal\surface_examples_react\Controller\ResourceReactPage::served',
        '_surface_flow' => ['steps' => ['where']],
      ] + array_diff_key($base, ['_form' => TRUE]),
    ];
    foreach ($cases as $message => $defaults) {
      $collection = new RouteCollection();
      $collection->add('bad', new Route('/bad/{id}', $defaults, ['id' => '[a-z]+']));
      try {
        (new OperationRouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));
        $this->fail('The rebuild did not refuse: ' . $message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
    // A step over another resource is held to its keys as the empty list
    // is held to having one.
    $collection = new RouteCollection();
    $collection->add('bad', new Route('/bad/{id}', $base + [
      '_surface_flow' => ['steps' => ['where', $ledger + ['step' => 2]]],
    ], ['id' => '[a-z]+']));
    try {
      (new OperationRouteSubscriber())->onAlterRoutes(new RouteBuildEvent($collection));
      $this->fail('The rebuild did not refuse a step over another resource with a key it does not take.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringStartsWith($shape . ': "steps" lists at least one step', $e->getMessage());
    }
    // A flow built in code is held to the same two lifetimes.
    try {
      new Flow(['where'], keep: '3600');
      $this->fail('The constructor did not refuse a third lifetime.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('A flow\'s "keep" is "short" or "long", not "3600".', $e->getMessage());
    }
  }

  /**
   * Tests a flow over a create stages it once and writes into it after.
   */
  public function testCreateFlowWritesIntoItsCreate(): void {
    $this->visit('/surface-test/itinerary/plan');
    $this->assertSame(['id', 'day', 'nights'], $this->drawn($this->surfaceForm()));
    [$state, $redirect] = $this->submitStep(['id' => 'fresh', 'day' => 'fri']);
    $this->assertSame([], $state->getErrors());
    $stage = $redirect->getOption('query')['stage'];
    $this->visit('/surface-test/itinerary/plan?stage=' . $stage . '&step=2');
    $this->assertSame(['region'], $this->drawn($this->surfaceForm()));
    $this->submitStep(['region' => 'south']);
    $writes = $this->stages()->read($stage)['writes'];
    $this->assertCount(1, $writes, 'The second step wrote into the first step\'s create.');
    $this->assertSame('create', $writes[0]['kind']);
    $this->assertSame('south', $writes[0]['values']['region'], 'The second step\'s value is in the create.');
    $this->assertSame('fri', $writes[0]['values']['day']);
    $this->assertContains('region', $writes[0]['changed']);
    $this->assertNull($this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->get('fresh'));
    $this->assertSame(['trip'], array_keys($this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->getAll()), 'Nothing is created.');
  }

  /**
   * Tests a flow over a create whose create is taken out of the stage.
   *
   * The create is deleted through the stage at the trip's cancel page,
   * by the id its first step named; the flow's later steps are then
   * refused, since a fresh create there would stage a second trip.
   */
  public function testCreateTakenOutOfTheStageEndsTheFlow(): void {
    $this->visit('/surface-test/itinerary/plan');
    [, $redirect] = $this->submitStep(['id' => 'fresh', 'day' => 'fri']);
    $stage = $redirect->getOption('query')['stage'];
    $this->container->get('messenger')->deleteAll();
    $this->visit('/surface-test/itinerary/fresh/cancel?stage=' . $stage);
    $state = new FormState();
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('Cancel a trip: the create staged in <em class="placeholder">Plan a trip</em> was taken out of it; nothing was created or removed.', $this->statusMessage());
    $this->assertSame([], $this->stages()->read($stage)['writes']);
    $this->visit('/surface-test/itinerary/plan?stage=' . $stage . '&step=2');
    $this->assertRefused('The surface_stage_test.itinerary_create_flow route draws step 2 of its flow into the create its first step staged, and its create was taken out of the stage; start the flow again.');
  }

  /**
   * Tests the content type flow: three groups over three steps.
   */
  public function testTheContentTypeFlowDrawsOneGroupPerStep(): void {
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $this->visit('/admin/structure/types/manage/page/surface-flow');
    $access = $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'));
    $this->assertTrue($access->isAllowed());
    $form = $this->surfaceForm();
    // The cosmetic layer's vertical tabs hold the one group drawn.
    $this->assertSame(['name', 'type', 'description', 'additional_settings', 'title_label', 'preview_mode', 'help'], $this->drawn($form));
    $this->assertTrue($form['@group.submission']['#open']);
    $this->assertArrayNotHasKey('@group.workflow', $form);
    $this->assertArrayNotHasKey('@group.display', $form);
    $stage = $this->open('Content type');
    $drawn = [];
    foreach ([2 => 'workflow', 3 => 'display'] as $step => $group) {
      $this->visit('/admin/structure/types/manage/page/surface-flow?stage=' . $stage . '&step=' . $step);
      $form = $this->surfaceForm();
      $this->assertTrue($form['@group.' . $group]['#open'], $group);
      $this->assertArrayNotHasKey('@group.submission', $form);
      $this->assertArrayNotHasKey('name', $form);
      $drawn[$step] = $this->drawn($form);
    }
    $this->assertSame([
      2 => ['additional_settings', 'status', 'promote', 'sticky', 'new_revision'],
      3 => ['additional_settings', 'display_submitted'],
    ], $drawn);
  }

  /**
   * Tests a step over another resource draws and stages that resource.
   *
   * The itinerary's two groups, then the ledger row's update: one stage
   * holds both writes, the confirm page lists both resources, and Save
   * makes both.
   */
  public function testStepOverAnotherResourceStagesIt(): void {
    $this->ledger('row', 'First row');
    $this->visit('/surface-test/itinerary/trip/flow-ledger');
    [, $redirect] = $this->submitStep(['region' => 'south']);
    $stage = $redirect->getOption('query')['stage'];
    $this->visit('/surface-test/itinerary/trip/flow-ledger?stage=' . $stage . '&step=2');
    [, $redirect] = $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->assertSame(['stage' => $stage, 'step' => '3'], $redirect->getOption('query'));
    $this->visit('/surface-test/itinerary/trip/flow-ledger?stage=' . $stage . '&step=3');
    $form = $this->surfaceForm();
    $drawn = $this->drawn($form);
    $keys = ['id', 'title', 'note', 'tags', 'region', 'day', 'guide'];
    $this->assertSame(['id', 'title', 'note', 'tags'], array_values(array_intersect($drawn, $keys)), 'The third step draws the ledger row\'s keys, and none of the trip\'s.');
    $this->assertSame('First row', $form['title']['#default_value']);
    $this->container->get('messenger')->deleteAll();
    [$state, $redirect] = $this->submitStep(['title' => 'Renamed row']);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $redirect->getOption('query'));
    $this->assertStringStartsWith('Edit a ledger row: the values were accepted and staged in', $this->statusMessage());
    $writes = $this->stages()->read($stage)['writes'];
    $this->assertSame(['stage_test.itinerary', 'stage_test.ledger'], array_column($writes, 'resource'));
    $this->assertSame(['id' => 'row'], $writes[1]['arguments']);
    $this->assertSame('First row', $this->row('row')['title'], 'Nothing is written to the row.');

    $this->visit('/surface-test/itinerary/trip/flow-ledger?stage=' . $stage . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame(['stage_test.itinerary', 'stage_test.ledger'], array_column($form['changes']['#rows'], 1), 'The confirm page lists both resources.');
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('south', $this->trip()['region']);
    $this->assertSame('Renamed row', $this->row('row')['title']);
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
  }

  /**
   * Tests a step over another resource asks that resource's access.
   *
   * The route's operation gates every page; the third step, my module's
   * settings, adds its own permission, which the account lacks.
   */
  public function testStepOverAnotherResourceAsksItsAccess(): void {
    $this->visit('/surface-test/itinerary/trip/flow-settings');
    $this->assertTrue($this->stepAccess()->isAllowed(), 'Step 1 is the trip\'s alone.');
    [, $redirect] = $this->submitStep(['region' => 'south']);
    $stage = $redirect->getOption('query')['stage'];
    $this->visit('/surface-test/itinerary/trip/flow-settings?stage=' . $stage . '&step=2');
    $this->assertTrue($this->stepAccess()->isAllowed(), 'So is step 2.');
    $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->visit('/surface-test/itinerary/trip/flow-settings?stage=' . $stage . '&step=3');
    $access = $this->stepAccess();
    $this->assertTrue($access->isForbidden(), 'Step 3 asks my module\'s permission.');
    $this->assertStringContainsString('administer my_module', (string) ($access instanceof AccessResultReasonInterface ? $access->getReason() : ''));
    $this->assertInstanceOf(CacheableDependencyInterface::class, $access);
    $this->assertSame([0, TRUE, TRUE], [
      $access->getCacheMaxAge(),
      in_array('url.query_args:stage', $access->getCacheContexts(), TRUE),
      in_array('url.query_args:step', $access->getCacheContexts(), TRUE),
    ], 'The refusal varies by the stage and the step, for this request alone.');
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The form did not refuse step 3.');
    }
    catch (AccessDeniedHttpException) {
      // The form asks the same.
    }
    // An account with both permissions is let in, and draws the settings.
    $this->setUpCurrentUser([], ['keep the ledger', 'administer my_module']);
    $stage = $this->open('Settings');
    $this->stage($stage, 'stage_test.itinerary', ['id' => 'trip'], ['region' => 'south']);
    $this->visit('/surface-test/itinerary/trip/flow-settings?stage=' . $stage . '&step=3');
    $this->assertTrue($this->stepAccess()->isAllowed());
    $this->assertSame(['title', 'items_per_page', 'show_author', 'notify_email', 'layout'], $this->drawn($this->surfaceForm()));
  }

  /**
   * Tests the match refuses a step over another resource it cannot draw.
   */
  public function testTheMatchRefusesStepsOverAnotherResource(): void {
    $this->visit('/surface-test/itinerary/trip/flow-vault');
    $this->assertRefused('The surface_stage_test.itinerary_flow_vault route\'s flow draws step 2 through the "configure" operation of tool_test.vault, and a stage does not apply to it (does_not_apply): a stage applies to an operation that writes one thing of a resource whose storage is transactional.');
    $this->visit('/surface-test/itinerary/trip/flow-ledger-create');
    $this->assertRefused('The surface_stage_test.itinerary_flow_ledger_create route\'s flow draws step 2 through the "create" operation of stage_test.ledger, which creates: a step over another resource updates a stored thing or configures a settings object; a create is drawn only by the route\'s own operation.');
    // A group the other resource does not have is refused at its step.
    $this->ledger('row', 'First row');
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow');
    $step = [
      'resource' => 'stage_test.ledger',
      'operation' => 'update',
      'group' => 'notes',
      'arguments' => ['id' => 'row'],
    ];
    $route->setDefault('_surface_flow', ['steps' => ['where', $step]]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip'], ['stage' => $this->open(), 'step' => '2']);
    $this->assertRefused('The example.flow route\'s flow draws the group "notes" of the "update" operation of stage_test.ledger at step 2, and the operation has no such group: name one of its groups, and it declares none, or leave "group" out to draw it whole.');
  }

  /**
   * Tests the match asks steps over another resource by resolved id.
   *
   * The rebuild compares a step's resource as written; the match names
   * each by its id, so a resource named by id at one step and by class at
   * another is one resource, and a step over the route's own operation is
   * refused. Arguments are compared as values: TRUE and "1" are two.
   */
  public function testTheMatchAsksStepsByTheirResolvedResource(): void {
    $this->ledger('row', 'First row');
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow');
    $whole = ['resource' => 'stage_test.ledger', 'operation' => 'update', 'arguments' => ['id' => 'row']];
    $by_class = ['group' => 'notes', 'resource' => LedgerResource::class] + $whole;
    $route->setDefault('_surface_flow', ['steps' => ['where', $whole, $by_class]]);
    Flow::assertWritten($route, 'example.flow');
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s "_surface_flow" draws the "update" operation of stage_test.ledger at more than one step, and one of them names no group: a step with no group draws the whole operation, so give each step over it a group.');

    $own = ['resource' => 'stage_test.itinerary', 'operation' => 'update', 'group' => 'when'];
    $route->setDefault('_surface_flow', ['steps' => ['where', $own]]);
    Flow::assertWritten($route, 'example.flow');
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s flow draws the route\'s own operation, "update" of stage_test.itinerary, as a step over another resource at step 2: its keys are drawn by the route\'s group steps, so write it as a group.');

    $one = ['resource' => 'stage_test.ledger', 'operation' => 'update'];
    $true = $one + ['arguments' => ['id' => TRUE]];
    $text = $one + ['arguments' => ['id' => '1']];
    $route->setDefault('_surface_flow', ['steps' => ['where', $true, $text]]);
    Flow::assertWritten($route, 'example.flow');
    $route->setDefault('_surface_flow', ['steps' => ['where', $text, $text]]);
    $this->expectExceptionMessage('draws the "update" operation of stage_test.ledger at more than one step');
    Flow::assertWritten($route, 'example.flow');
  }

  /**
   * Tests the first step asks every step over another resource.
   *
   * Before anything is staged: a third step whose arguments name nothing
   * makes step 1 a page not found, and one naming a group its operation
   * does not have refuses step 1.
   */
  public function testTheFirstStepAsksEveryStepOverAnotherResource(): void {
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow');
    $gone = ['resource' => 'stage_test.ledger', 'operation' => 'update', 'arguments' => ['id' => 'gone']];
    $route->setDefault('_surface_flow', ['steps' => ['where', 'when', $gone]]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertTrue($this->stepAccess()->isAllowed(), 'Let through in the dispatch, for the form to answer.');
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('Step 1 was served.');
    }
    catch (NotFoundHttpException) {
      // The third step names nothing.
    }

    $this->ledger('row', 'First row');
    $notes = ['group' => 'notes', 'arguments' => ['id' => 'row']] + $gone;
    $route->setDefault('_surface_flow', ['steps' => ['where', 'when', $notes]]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s flow draws the group "notes" of the "update" operation of stage_test.ledger at step 3, and the operation has no such group: name one of its groups, and it declares none, or leave "group" out to draw it whole.');
    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->getAll(), 'No stage was opened.');
  }

  /**
   * Tests a closed stage on a step page goes to the confirm page.
   *
   * A step asked for with an applied stage redirects to the confirm page,
   * which says when it was applied and links to the first step without a
   * stage. A Save posted after the apply, and a Next posted from step 2
   * drawn before it, as the browser's Back shows it, are refused with the
   * same sentence, write nothing, and go to the confirm page.
   */
  public function testClosedStageGoesToTheConfirmPage(): void {
    $stage = $this->applied();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    $this->assertGoesToConfirm($stage);

    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertMatchesRegularExpression('/^This stage was applied \d+ seconds? ago\.$/', (string) $form['status']['#markup']);
    $this->assertCount(1, $form['applied']['#rows']);
    $this->assertArrayNotHasKey('apply', $form['actions']);
    $this->assertArrayNotHasKey('discard', $form['actions']);
    $this->assertSame('Start again', (string) $form['actions']['start']['#title']);
    $this->assertSame('/surface-test/itinerary/trip/flow', $form['actions']['start']['#url']->toString());
    $this->assertArrayNotHasKey('redirect', $form['actions'], 'The flow names no redirect.');
    $sentence = '/^This stage was applied \d+ seconds? ago\. <a href="\/surface-test\/itinerary\/trip\/flow">Start again<\/a>\.$/';
    // The page draws no button now, so a Save posted is no button.
    $this->assertMatchesRegularExpression($sentence, $this->postClosed(['op' => 'Save'], $stage));

    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    $values = ['day' => 'sun', 'nights' => '4'];
    $posted = [SurfaceOperationForm::SURFACE_KEY => $values, 'op' => 'Next'];
    $this->assertMatchesRegularExpression($sentence, $this->postClosed($posted, $stage));
    $this->assertSame('mon', $this->trip()['day'], 'Nothing was written.');
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
  }

  /**
   * Tests Cancel and Back pressed on a step page of an applied stage.
   *
   * Cancel, really pressed on step 2 drawn before the apply, keeps the
   * record of what the stage made and goes to the confirm page. Back is
   * let through, to the step before, which goes to the confirm page.
   */
  public function testCancelKeepsAnAppliedStage(): void {
    $stage = $this->applied();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    $posted = [SurfaceOperationForm::SURFACE_KEY => [], 'op' => 'Cancel'];
    $this->assertSame('This stage was applied; there is nothing to cancel.', $this->postClosed($posted, $stage));
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status'], 'The record survives.');

    [$state, $redirect] = $this->submitStep([], 'Back');
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['stage' => $stage, 'step' => '1'], $redirect->getOption('query'));
    $this->assertSame([], $this->container->get('messenger')->messagesByType('error'));
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=1');
    $this->assertGoesToConfirm($stage);
  }

  /**
   * Tests a voided stage's confirm page, and what is posted into it.
   *
   * The page keeps Cancel, draws no Save, and links to the first step. A
   * Save posted from the page drawn before the stage was voided names no
   * button the page draws now, so core takes it for Cancel: it is refused,
   * and the record survives. Cancel pressed removes the stage.
   */
  public function testVoidedStageKeepsCancel(): void {
    $stage = $this->firstStep();
    $record = $this->stages()->read($stage);
    $record['status'] = StageStorage::VOIDED;
    $record['voided'] = ['position' => 1];
    $record['applied'] = [];
    $record['writes'] = [];
    $this->stages()->write($record);
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $voided = 'The stage ' . $stage . ' was voided at position 1; writes at and before it were made permanent by a schema change. Cancel removes it.';
    $this->assertSame($voided, (string) $form['status']['#markup']);
    $this->assertSame('Cancel', (string) $form['actions']['discard']['#value']);
    $this->assertArrayNotHasKey('apply', $form['actions']);
    $this->assertSame('Start again', (string) $form['actions']['start']['#title']);

    $this->assertSame($voided . ' <a href="/surface-test/itinerary/trip/flow">Start again</a>.', $this->postClosed(['op' => 'Save'], $stage));
    $this->assertSame(StageStorage::VOIDED, $this->stages()->read($stage)['status'], 'The record survives.');

    $state = (new FormState())->setValues(['op' => 'Cancel']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertNull($this->stages()->read($stage), 'Cancel pressed removes a voided stage.');
  }

  /**
   * Tests a closed stage's confirm page links to the flow's `redirect`.
   */
  public function testClosedStageLinksToTheRedirect(): void {
    NodeType::create(['type' => 'page', 'name' => 'Basic page'])->save();
    $stage = $this->open('Content type');
    $record = $this->stages()->read($stage);
    $record['status'] = StageStorage::APPLIED;
    $record['applied_at'] = $this->container->get('datetime.time')->getRequestTime();
    $record['applied'] = [];
    $record['writes'] = [];
    $this->stages()->write($record);
    $this->visit('/admin/structure/types/manage/page/surface-flow?stage=' . $stage . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('Continue', (string) $form['actions']['redirect']['#title']);
    $this->assertSame('/admin/structure/types', $form['actions']['redirect']['#url']->toString());
    $this->assertSame('Start again', (string) $form['actions']['start']['#title']);
  }

  /**
   * Tests a flow over a create sends a closed stage's step to the confirm.
   *
   * Apply empties the stage's writes, so the create is not looked for:
   * the step is let in, and the form answers the redirect.
   */
  public function testClosedCreateFlowGoesToTheConfirmPage(): void {
    $this->visit('/surface-test/itinerary/plan');
    [, $redirect] = $this->submitStep(['id' => 'fresh', 'day' => 'fri']);
    $stage = $redirect->getOption('query')['stage'];
    $this->visit('/surface-test/itinerary/plan?stage=' . $stage . '&step=confirm');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
    $this->visit('/surface-test/itinerary/plan?stage=' . $stage . '&step=2');
    $this->assertTrue($this->stepAccess()->isAllowed(), 'Not refused for want of the create.');
    $this->assertGoesToConfirm($stage, '/surface-test/itinerary/plan');
  }

  /**
   * Tests the flow's buttons: Next, Back and Cancel; Save on the confirm.
   *
   * Cancel discards the stage and starts again; on the first step with no
   * stage it writes nothing and starts again.
   */
  public function testTheButtonsSayWhatTheyDo(): void {
    $this->visit('/surface-test/itinerary/trip/flow');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('Next', (string) $form['actions']['submit']['#value']);
    $this->assertSame('Cancel', (string) $form['actions']['discard']['#value']);
    $this->assertArrayNotHasKey('back', $form['actions']);
    [$state, $redirect] = $this->submitStep(['region' => 'south'], 'Cancel');
    $this->assertSame([], $state->getErrors());
    $this->assertSame('surface_stage_test.itinerary_flow', $redirect->getRouteName());
    $this->assertSame([], $redirect->getOption('query'));
    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(StageStore::COLLECTION)->getAll(), 'No stage was opened.');

    $stage = $this->firstStep();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=confirm');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $buttons = [$form['actions']['apply'], $form['actions']['back'], $form['actions']['discard']];
    $this->assertSame(['Save', 'Back', 'Cancel'], array_map(static fn (array $button): string => (string) $button['#value'], $buttons));
    $this->assertArrayNotHasKey('#button_type', $form['actions']['discard'], 'Cancel is no danger button.');
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=2');
    [$state, $redirect] = $this->submitStep([], 'Cancel');
    $this->assertSame([], $state->getErrors());
    $this->assertSame([], $redirect->getOption('query'));
    $this->assertNull($this->stages()->read($stage), 'Cancel discards the stage.');
  }

  /**
   * Opens the itinerary's flow, stages its first step, and applies it.
   *
   * @return string
   *   The stage's id.
   */
  protected function applied(): string {
    $stage = $this->firstStep();
    $this->visit('/surface-test/itinerary/trip/flow?stage=' . $stage . '&step=confirm');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
    $this->container->get('messenger')->deleteAll();
    return $stage;
  }

  /**
   * Asserts the step page on the stack answers a 303 to its confirm page.
   *
   * @param string $stage
   *   The stage's id.
   * @param string $path
   *   The flow route's path.
   */
  protected function assertGoesToConfirm(string $stage, string $path = '/surface-test/itinerary/trip/flow'): void {
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The step page did not redirect.');
    }
    catch (EnforcedResponseException $e) {
      $response = $e->getResponse();
      $this->assertSame(303, $response->getStatusCode());
      $this->assertStringEndsWith($path . '?stage=' . $stage . '&step=confirm', $response->headers->get('Location'));
      $this->assertInstanceOf(CacheableResponseInterface::class, $response);
      $this->assertSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
    }
  }

  /**
   * Posts into a closed stage, asserting it is refused to the confirm page.
   *
   * @param array $values
   *   The values posted, `op` among them.
   * @param string $stage
   *   The stage's id.
   *
   * @return string
   *   The error messages, as one line.
   */
  protected function postClosed(array $values, string $stage): string {
    $this->container->get('messenger')->deleteAll();
    $state = (new FormState())->setValues($values);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors(), 'Refused as a message, by the submit handler.');
    $state->setProgrammed(FALSE);
    $redirect = $state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $redirect->getOption('query'));
    $messages = $this->container->get('messenger')->messagesByType('error');
    $this->container->get('messenger')->deleteAll();
    return implode(' ', array_map('strval', $messages));
  }

  /**
   * Asks the route on the stack's access check, with the request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function stepAccess(): mixed {
    return $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'), $this->container->get('request_stack')->getCurrentRequest());
  }

  /**
   * Opens the itinerary's flow and submits its first step.
   *
   * @return string
   *   The stage's id.
   */
  protected function firstStep(): string {
    $this->visit('/surface-test/itinerary/trip/flow');
    [, $redirect] = $this->submitStep(['region' => 'south']);
    $this->container->get('messenger')->deleteAll();
    return $redirect->getOption('query')['stage'];
  }

  /**
   * Submits the form of the route on the stack, as a programmed form.
   *
   * @param array $values
   *   The data surface's values.
   * @param string|null $op
   *   The button pressed, NULL for the first.
   *
   * @return array{0: \Drupal\Core\Form\FormState, 1: \Drupal\Core\Url}
   *   The form state, and where it redirects.
   */
  protected function submitStep(array $values, ?string $op = NULL): array {
    $state = (new FormState())->setValues([SurfaceOperationForm::SURFACE_KEY => $values] + ($op === NULL ? [] : ['op' => $op]));
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $state->setProgrammed(FALSE);
    $redirect = $state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect, implode(' ', array_map('strval', $state->getErrors())));
    return [$state, $redirect];
  }

  /**
   * Lists the keys a built container draws.
   *
   * @param array $container
   *   The data surface container.
   *
   * @return list<string>
   *   The keys with an element, in order; groups and markers left out.
   */
  protected function drawn(array $container): array {
    $keys = [];
    foreach (array_keys($container) as $key) {
      $key = (string) $key;
      if (!str_starts_with($key, '#') && !str_starts_with($key, '@') && is_array($container[$key]) && isset($container[$key]['#type'])) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  /**
   * Asserts the route on the stack is refused at its access and its form.
   *
   * @param string $message
   *   The refusal.
   */
  protected function assertRefused(string $message): void {
    $access = $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'));
    $this->assertTrue($access->isForbidden());
    $this->assertSame($message, $access->getReason());
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The form did not refuse.');
    }
    catch (AccessDeniedHttpException $e) {
      $this->assertSame($message, $e->getMessage());
    }
  }

  /**
   * Reads the stored trip.
   *
   * @return array
   *   The trip.
   */
  protected function trip(): array {
    return (array) $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->get('trip');
  }

  /**
   * Reads the status messages as one line, and clears them.
   *
   * @return string
   *   The messages.
   */
  protected function statusMessage(): string {
    $messages = $this->container->get('messenger')->messagesByType('status');
    $this->container->get('messenger')->deleteAll();
    return implode(' ', array_map('strval', $messages));
  }

}
