<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;
use Drupal\surface\Access\OperationRouteSubscriber;
use Drupal\surface\Form\Flow;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Staging\StageStore;
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
 * Over the itinerary of surface_stage_test, whose routes declare two
 * flows that hold and two the match refuses, and over the content type
 * example's flow route. Each step is submitted as a programmed form, so
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
    'surface_examples_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
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
    $shape = 'The bad route\'s "_surface_flow" is a map of "steps", a list of the group ids drawn one per step, an optional "label", an optional "redirect", a route name, and an optional "keep", short or long, as { steps: [where, when] }';
    $cases = [
      $shape . ': "steps" lists at least one group id, each a non-empty string.' => $base + [
        '_surface_flow' => ['steps' => []],
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
