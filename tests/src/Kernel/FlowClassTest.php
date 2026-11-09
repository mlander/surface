<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Url;
use Drupal\surface\Access\OperationRouteSubscriber;
use Drupal\surface\Flow\FlowRegistry;
use Drupal\surface\Flow\Staged;
use Drupal\surface\Flow\Steps;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\Staging\StageRecord;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface_stage_test\Flow\ItineraryFlow;
use Drupal\surface_stage_test\OutsideDiscovery\EmptyIdFlow;
use Drupal\surface_stage_test\OutsideDiscovery\EmptyLabelFlow;
use Drupal\surface_stage_test\OutsideDiscovery\KeptShortFlow;
use Drupal\surface_stage_test\OutsideDiscovery\NoStepFlow;
use Drupal\surface_stage_test\OutsideDiscovery\NotAFlow;
use Drupal\surface_stage_test\OutsideDiscovery\SameIdFlow;
use Drupal\surface_stage_test\Storage\ItineraryStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Tests a flow written as a class, and the map's `keys`.
 *
 * Over surface_stage_test's ItineraryFlow: the region, then the day and
 * the nights, then the ledger row while the stage holds more than two
 * nights, and a check before Save of a long trip's note. Its route names
 * the flow and no resource; a second route writes them.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FlowClassTest extends SurfaceKernelTestBase {

  use OperationRouteTrait;
  use StageTestTrait;
  use UserCreationTrait;

  /**
   * The flow route's path, for the trip.
   */
  protected const PATH = '/surface-test/itinerary/trip/flow-class';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_stage_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['keep the ledger']);
    $this->container->get('router.builder')->rebuild();
    $this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->set('trip', [
      'id' => 'trip',
      'region' => 'north',
      'day' => 'mon',
      'nights' => 2,
    ]);
    $this->ledger('row', 'First row');
  }

  /**
   * Tests the rebuild writes the first step's resource and operation.
   *
   * A route that names a flow class and no resource takes them from the
   * first step, and with them the operation's access requirement; one
   * that writes them, by the resource's class, is held to the first step.
   */
  public function testTheRebuildWritesTheFirstStepsOperation(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow_class');
    $this->assertSame('stage_test.itinerary', $route->getDefault(OperationRoute::RESOURCE));
    $this->assertSame('update', $route->getDefault(OperationRoute::OPERATION));
    $this->assertSame('TRUE', $route->getRequirement(OperationRoute::ACCESS));
    $this->visit('/surface-test/itinerary/trip/flow-class-written');
    $this->assertSame(['id', 'region'], $this->drawn($this->surfaceForm()));
  }

  /**
   * Tests the rebuild refuses a flow class a route cannot be served by.
   */
  public function testTheRebuildRefusesClassFlowsWrittenWrong(): void {
    $base = ['_form' => SurfaceOperationForm::class];
    $cases = [
      'The flow "stage_test.first_step_arguments" (Drupal\surface_stage_test\Flow\FirstStepArgumentsFlow) gives its first step "where" arguments: the first step is the route\'s own operation, which takes its arguments from the route, so leave them off the step and write them on the route, as its path parameters or as plain defaults, beside "_surface_resource" and "_surface_operation".' => $base + [
        '_surface_flow' => 'stage_test.first_step_arguments',
      ],
      'The flow "stage_test.first_step_condition" (Drupal\surface_stage_test\Flow\FirstStepConditionFlow) draws its first step "where" under a condition: the first step opens the stage, so it is always drawn; give "when" to a later step.' => $base + [
        '_surface_flow' => 'stage_test.first_step_condition',
      ],
      'The bad route names the flow "stage_test.itinerary_flow" and the "update" operation of stage_test.ledger, and the flow\'s first step is the "update" operation of stage_test.itinerary: the first step is the route\'s own operation, so write the two to match it, or leave "_surface_resource" and "_surface_operation" out and the route takes them from it.' => $base + [
        '_surface_resource' => 'stage_test.ledger',
        '_surface_operation' => 'update',
        '_surface_flow' => 'stage_test.itinerary_flow',
      ],
      'The bad route declares "_surface_flow" and its "_form" is not Drupal\surface\Form\SurfaceOperationForm: only the operation form draws a flow\'s steps, so serve the route with it or a class extending it.' => [
        '_form' => FormBase::class,
        '_surface_flow' => 'stage_test.itinerary_flow',
      ],
    ];
    foreach ($cases as $message => $defaults) {
      $collection = new RouteCollection();
      $collection->add('bad', new Route('/bad/{id}', $defaults, ['id' => '[a-z]+']));
      try {
        (new OperationRouteSubscriber($this->container->get('surface.flows')))->onAlterRoutes(new RouteBuildEvent($collection));
        $this->fail('The rebuild did not refuse: ' . $message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests a flow id that names no one class closes its route alone.
   *
   * What the id names depends on what is installed: the rebuild writes
   * every other route, and the route naming it is closed. Were it open,
   * its match would say why.
   */
  public function testTheRebuildClosesTheRouteNamingNoOneFlowClass(): void {
    $twice = $this->registry(ItineraryFlow::class, SameIdFlow::class);
    $cases = [
      'The bad route names the flow "stage_test.nowhere", which no class declares: a flow class is a class in a module\'s src/Flow carrying #[Flow], or write the flow on the route as a map of "steps".' => [
        $this->container->get('surface.flows'),
        'stage_test.nowhere',
      ],
      'The bad route names the flow "stage_test.itinerary_flow", which the classes Drupal\surface_stage_test\Flow\ItineraryFlow, Drupal\surface_stage_test\OutsideDiscovery\SameIdFlow all declare: a route names a flow by its id, so give each class its own.' => [
        $twice,
        'stage_test.itinerary_flow',
      ],
    ];
    foreach ($cases as $message => [$flows, $id]) {
      $collection = new RouteCollection();
      $collection->add('bad', new Route('/bad/{id}', [
        '_form' => SurfaceOperationForm::class,
        '_surface_flow' => $id,
      ], ['id' => '[a-z]+']));
      $collection->add('good', new Route('/good/{id}', [
        '_form' => SurfaceOperationForm::class,
        '_surface_resource' => 'stage_test.itinerary',
        '_surface_operation' => 'update',
      ], ['id' => '[a-z]+']));
      (new OperationRouteSubscriber($flows))->onAlterRoutes(new RouteBuildEvent($collection));
      $this->assertSame('FALSE', $collection->get('bad')->getRequirement('_access'), $id);
      $this->assertFalse($collection->get('bad')->hasDefault(OperationRoute::RESOURCE));
      $this->assertSame('TRUE', $collection->get('good')->getRequirement(OperationRoute::ACCESS), 'The other routes are written.');
      $this->assertSame($message, $flows->unnamed($id, 'bad'));
    }

    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow_class');
    $route->setDefault(CompiledFlow::ROUTE, 'stage_test.nowhere');
    $this->serveBuilt('surface_stage_test.itinerary_flow_class', $route, ['id' => 'trip']);
    $this->assertRefused('The surface_stage_test.itinerary_flow_class route names the flow "stage_test.nowhere", which no class declares: a flow class is a class in a module\'s src/Flow carrying #[Flow], or write the flow on the route as a map of "steps".');
  }

  /**
   * Tests a route its flow class no longer matches is refused, not thrown.
   *
   * The route was rebuilt for the itinerary; it now names the ledger,
   * as when the class's first step changed with no rebuild since. The
   * access check, asked about a link or the page, refuses for this
   * request alone, and the form answers 403.
   */
  public function testRouteItsFlowClassNoLongerMatchesIsRefused(): void {
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow_class');
    $route->setDefault(OperationRoute::RESOURCE, 'stage_test.ledger');
    $this->serveBuilt('surface_stage_test.itinerary_flow_class', $route, ['id' => 'row']);
    $message = 'The surface_stage_test.itinerary_flow_class route names the flow "stage_test.itinerary_flow" and the "update" operation of stage_test.ledger, and the flow\'s first step is the "update" operation of stage_test.itinerary: the first step is the route\'s own operation, so write the two to match it, or leave "_surface_resource" and "_surface_operation" out and the route takes them from it.';
    $this->assertRefused($message);
    $this->assertSame(['forbidden', $message, 0], static::answer($this->stepAccess()), 'Asked with the request too.');
  }

  /**
   * Tests the registry refuses a flow class written wrong, by its id.
   *
   * Each refusal is the class's own: a registry is built here over the
   * classes outside discovery. It compiles afresh on each call, and reads
   * the stage's lifetime and the redirect from the attribute.
   */
  public function testTheRegistryRefusesFlowClassesWrittenWrong(): void {
    $cases = [
      'Drupal\surface_stage_test\OutsideDiscovery\NotAFlow carries #[Flow(\'stage_test.not_a_flow\')] and does not implement Drupal\surface\Flow\FlowInterface, whose defineSteps() lists its steps.' => [
        NotAFlow::class,
        'stage_test.not_a_flow',
      ],
      'The flow "stage_test.no_step" (Drupal\surface_stage_test\OutsideDiscovery\NoStepFlow) declares no step: its first step is the route\'s own operation, so declare at least that one.' => [
        NoStepFlow::class,
        'stage_test.no_step',
      ],
      'Drupal\surface_stage_test\OutsideDiscovery\EmptyIdFlow carries #[Flow] with an empty id: a flow has an id a route names it by and a label, the label of the stage its first step opens.' => [
        EmptyIdFlow::class,
        '',
      ],
      'Drupal\surface_stage_test\OutsideDiscovery\EmptyLabelFlow carries #[Flow] with an empty label: a flow has an id a route names it by and a label, the label of the stage its first step opens.' => [
        EmptyLabelFlow::class,
        'stage_test.empty_label',
      ],
      'The classes Drupal\surface_stage_test\Flow\ItineraryFlow, Drupal\surface_stage_test\OutsideDiscovery\SameIdFlow all declare the flow "stage_test.itinerary_flow": a route names a flow by its id, so give each class its own.' => [
        [ItineraryFlow::class, SameIdFlow::class],
        'stage_test.itinerary_flow',
      ],
      'No flow class has the id "stage_test.nowhere": a flow class is a class in a module\'s src/Flow carrying #[Flow].' => [
        [],
        'stage_test.nowhere',
      ],
    ];
    foreach ($cases as $message => [$classes, $id]) {
      try {
        $this->registry(...(array) $classes)->get($id);
        $this->fail('The registry did not refuse: ' . $message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }

    $kept = $this->registry(KeptShortFlow::class);
    $flow = $kept->get('stage_test.kept_short');
    $this->assertSame(StageRecord::SHORT, $flow->keep);
    $this->assertSame('surface_stage_test.itinerary_update', $flow->redirect);
    $this->assertNotSame($flow, $kept->get('stage_test.kept_short'), 'Nothing compiled is kept.');

    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow_class');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The example.flow route names the flow "stage_test.itinerary_flow", and its reader was handed no flow registry.');
    CompiledFlow::onRoute($route, 'example.flow');
  }

  /**
   * Tests what a flow class declares into is refused when written wrong.
   */
  public function testStepsRefuseWhatIsDeclaredWrong(): void {
    $when = static fn (): bool => TRUE;
    $cases = [
      [
        'A flow\'s step names its id, resource and operation, each a non-empty string; step 1 does not.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('', 'stage_test.itinerary', 'update'),
      ],
      [
        'A flow\'s step names its id, resource and operation, each a non-empty string; step 2 does not.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => [
          $steps->step('a', 'stage_test.itinerary', 'update'),
          $steps->step('b', '', 'update'),
        ],
      ],
      [
        'A flow\'s step names its id, resource and operation, each a non-empty string; step 1 does not.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', ''),
      ],
      [
        'A flow lists the step "a" twice: each step has an id of its own.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => [
          $steps->step('a', 'stage_test.itinerary', 'update'),
          $steps->step('a', 'stage_test.ledger', 'update'),
        ],
      ],
      [
        'The step "a" of a flow gives its arguments as a list: they are the operation\'s arguments by name, as [\'id\' => \'row\'].',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.ledger', 'update', ['row']),
      ],
      [
        'The step "a" of a flow lists no key: name at least one, or list none and the step draws the whole operation.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->keys(),
      ],
      [
        'The step "a" of a flow lists a key twice: day, day.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->keys('day', 'day'),
      ],
      [
        'The step "a" of a flow lists keys and names the group "when": a step draws a group or keys, not both.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->keys('day')->group('when'),
      ],
      [
        'The step "a" of a flow names the group "when" and lists keys: a step draws a group or keys, not both.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->group('when')->keys('day'),
      ],
      [
        'The step "a" of a flow names an empty group.',
        \InvalidArgumentException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->group(''),
      ],
      [
        'The step "a" of a flow lists its keys twice: call keys() once, with every key the step draws.',
        \LogicException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->keys('day')->keys('nights'),
      ],
      [
        'The step "a" of a flow names a group twice, "when" and "where": a step draws one group.',
        \LogicException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->group('when')->group('where'),
      ],
      [
        'The step "a" of a flow is given a condition twice: call when() once, with one callable that answers for both.',
        \LogicException::class,
        static fn (Steps $steps): mixed => $steps->step('a', 'stage_test.itinerary', 'update')->when($when)->when($when),
      ],
    ];
    foreach ($cases as [$message, $class, $declare]) {
      try {
        $declare(new Steps());
        $this->fail('Not refused: ' . $message);
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
        $this->assertSame($class, get_class($e), $message);
      }
    }
  }

  /**
   * Tests a class step over the route's own operation is a step of it.
   *
   * The second step names the itinerary by its class: it compiles to a
   * step of the route's operation, not one over another resource, which
   * the match would refuse.
   */
  public function testClassStepOverTheRoutesOperationIsItsOwn(): void {
    $flow = $this->container->get('surface.flows')->get('stage_test.itinerary_flow');
    $this->assertNull($flow->over(1));
    $this->assertNull($flow->over(2), 'The second step is the route\'s own operation.');
    $over = $flow->over(3);
    $this->assertSame('stage_test.ledger', $over['resource'] ?? NULL);
    $this->assertSame('update', $over['operation'] ?? NULL);
    $this->assertSame(['id' => 'row'], $over['arguments'] ?? NULL);
  }

  /**
   * Tests a step's heading is drawn above its fields.
   */
  public function testTheStepsHeadingIsDrawn(): void {
    $this->visit(self::PATH);
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame(['id', 'region'], $this->drawn($form[SurfaceOperationForm::SURFACE_KEY]), 'The first step draws its keys, and the key in no group.');
    $heading = $form[SurfaceOperationForm::STEP_KEY];
    $this->assertSame(-90, $heading['#weight']);
    $this->assertSame('h2', $heading['label']['#tag']);
    $this->assertSame('Where', (string) $heading['label']['#value']);
    $this->assertSame('The region the trip goes to.', (string) $heading['description']['#value']);
    $this->assertSame(['label', 'description'], Element::children($heading), 'The heading and the line under it, and no step counter.');
  }

  /**
   * Tests a step's condition: not drawn, passed over, and redirected past.
   *
   * With two nights the ledger row's step is passed over: Next from the
   * second step goes to the confirm page, Back from it to the second
   * step, and a link to the third step answers 303 to the confirm page.
   * With three nights it is drawn, and Back from it goes to the second.
   */
  public function testStepIsPassedOverWhileItsConditionFails(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    $this->assertSame(['day', 'nights'], $this->drawn($this->surfaceForm()));
    [, $redirect] = $this->submitStep(['day' => 'tue', 'nights' => '2']);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $redirect->getOption('query'), 'Next passes over the ledger row.');

    $this->visit(self::PATH . '?stage=' . $stage . '&step=confirm');
    [, $redirect] = $this->submitStep([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '2'], $redirect->getOption('query'), 'Back passes over it too.');

    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    try {
      $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
      $this->fail('The step passed over was drawn.');
    }
    catch (EnforcedResponseException $e) {
      $this->assertSame(303, $e->getResponse()->getStatusCode());
      $this->assertStringEndsWith(self::PATH . '?stage=' . $stage . '&step=confirm', $e->getResponse()->headers->get('Location'));
    }
    $this->assertTrue($this->stepAccess()->isAllowed(), 'The access check judges the step as written.');

    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    [, $redirect] = $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->assertSame(['stage' => $stage, 'step' => '3'], $redirect->getOption('query'), 'Three nights draw the ledger row.');
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $form = $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $this->assertSame('The ledger row', (string) $form[SurfaceOperationForm::STEP_KEY]['label']['#value']);
    $this->assertSame('First row', $form[SurfaceOperationForm::SURFACE_KEY]['title']['#default_value']);
    [, $redirect] = $this->submitStep([], 'Back');
    $this->assertSame(['stage' => $stage, 'step' => '2'], $redirect->getOption('query'));
  }

  /**
   * Tests a post into a step passed over stages nothing.
   *
   * With two nights the ledger row's step is passed over; its values,
   * posted from a page drawn before or built by hand, are refused, and
   * the page goes on to the next step drawn.
   */
  public function testPostIntoStepPassedOverStagesNothing(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $state = (new FormState())->setValues([SurfaceOperationForm::SURFACE_KEY => ['title' => 'Hidden']]);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $state->setProgrammed(FALSE)->getRedirect()->getOption('query'));
    $this->assertSame(['This step is not part of the flow as it stands.'], array_map('strval', $this->container->get('messenger')->messagesByType('error')));
    $this->assertSame(['stage_test.itinerary'], array_column($this->stages()->read($stage)['writes'], 'resource'), 'Only the first step\'s write is staged.');
  }

  /**
   * Tests what a step staged before it was passed over is applied.
   *
   * Three nights draw the ledger row, which is staged; two nights pass
   * over it again, and Save applies its write with the rest.
   */
  public function testWriteOfStepPassedOverIsApplied(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $this->submitStep(['title' => 'Kept']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    [, $redirect] = $this->submitStep(['day' => 'tue', 'nights' => '2']);
    $this->assertSame(['stage' => $stage, 'step' => 'confirm'], $redirect->getOption('query'), 'The ledger row is passed over.');

    $this->visit(self::PATH . '?stage=' . $stage . '&step=confirm');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
    $this->assertSame(2, $this->trip()['nights']);
    $this->assertSame('Kept', $this->row('row')['title'], 'The write of the step passed over is applied.');
  }

  /**
   * Tests the stage's apply tool does not ask the flow class's check.
   *
   * The check is the form door's alone: a stage the confirm page would
   * refuse is applied by the tool.
   */
  public function testTheApplyToolDoesNotAskTheCheck(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    $this->submitStep(['day' => 'tue', 'nights' => '8']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $this->submitStep(['title' => 'Long trip']);
    $answer = $this->stageTool('apply', $stage);
    $this->assertTrue($answer['success'], $answer['message']);
    $this->assertSame(8, $this->trip()['nights']);
    $this->assertSame('Long trip', $this->row('row')['title']);
  }

  /**
   * Tests the form serializes without its flow, and draws it again after.
   *
   * The form cache stores the form object; the flow compiled and the
   * stage's reader hold callables, which do not serialize, so neither is
   * kept and each is read again.
   */
  public function testTheFormSerializesWithoutItsFlow(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    $this->submitStep(['day' => 'tue', 'nights' => '3']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $form_object = $this->container->get('class_resolver')->getInstanceFromDefinition(SurfaceOperationForm::class);
    $this->container->get('form_builder')->getForm($form_object);
    // Every class allowed, as core's form cache allows them.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $copy = unserialize(serialize($form_object));
    $form = $this->container->get('form_builder')->getForm($copy);
    $this->assertSame('The ledger row', (string) $form[SurfaceOperationForm::STEP_KEY]['label']['#value']);
    $this->assertSame('First row', $form[SurfaceOperationForm::SURFACE_KEY]['title']['#default_value']);
  }

  /**
   * Tests the flow class's check blocks Save, then lets it apply.
   *
   * Eight nights and a ledger row with no note: the confirm page refuses
   * Save with the flow's sentence and applies nothing. A note staged at
   * the third step lets Save apply both writes.
   */
  public function testTheCheckBeforeApplyBlocksSave(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage . '&step=2');
    $this->submitStep(['day' => 'tue', 'nights' => '8']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $this->submitStep(['title' => 'Long trip']);

    $this->visit(self::PATH . '?stage=' . $stage . '&step=confirm');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame(['A trip of more than seven nights needs a note on its ledger row. (note_required)'], array_map('strval', array_values($state->getErrors())));
    $this->assertSame(StageRecord::OPEN, $this->stages()->read($stage)['status']);
    $this->assertSame(2, $this->trip()['nights'], 'Nothing was applied.');

    $this->visit(self::PATH . '?stage=' . $stage . '&step=3');
    $this->submitStep(['title' => 'Long trip', 'note' => 'Book the ferry.']);
    $this->visit(self::PATH . '?stage=' . $stage . '&step=confirm');
    $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
    $state = (new FormState())->setValues(['op' => 'Save']);
    $this->container->get('form_builder')->submitForm(SurfaceOperationForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(StageStorage::APPLIED, $this->stages()->read($stage)['status']);
    $this->assertSame(8, $this->trip()['nights']);
    $this->assertSame('Book the ferry.', $this->row('row')['note']);
  }

  /**
   * Tests the stage's values are what is staged, else what is stored.
   */
  public function testStagedValuesAreStagedElseStored(): void {
    $stage = $this->firstStep();
    $this->visit(self::PATH . '?stage=' . $stage);
    $staged = new Staged(
      $this->resources(),
      $this->container->get('surface.resource_registry'),
      $this->stages(),
      $this->container->get('surface.flows')->get('stage_test.itinerary_flow')->resolved($this->container->get('surface.resource_registry')),
      $this->served(),
      $this->stages()->named($stage)->withArguments(['id' => 'trip']),
    );
    $this->assertSame('Edit a trip in steps', (string) $this->stages()->read($stage)['label'], 'The stage the first step opened carries the flow\'s label.');
    $this->assertSame('south', $staged->value('stage_test.itinerary', 'region'), 'What the first step posted, read back from the stage.');
    $this->assertSame('mon', $staged->value('stage_test.itinerary', 'day'), 'Stored, where the stage holds nothing.');
    $this->assertSame('First row', $staged->value('stage_test.ledger', 'title'), 'Another resource a step is drawn over, by the step\'s arguments.');
    $this->ledger('other', 'Second row');
    $this->assertSame('Second row', $staged->value('stage_test.ledger', 'title', ['id' => 'other']), 'By the arguments given.');
    $this->assertSame('north', $this->trip()['region'], 'Nothing was written.');

    // A resource no step is drawn over, through its read: its secret is
    // never answered, staged or stored.
    $this->assertTrue($this->stage($stage, 'stage_test.vault_note', [], ['label' => 'Vault', 'token' => 'hunter2'], 'configure')->committed);
    $staged = new Staged(
      $this->resources(),
      $this->container->get('surface.resource_registry'),
      $this->stages(),
      $this->container->get('surface.flows')->get('stage_test.itinerary_flow')->resolved($this->container->get('surface.resource_registry')),
      $this->served(),
      $this->stages()->named($stage)->withArguments(['id' => 'trip']),
    );
    $this->assertSame('Vault', $staged->value('stage_test.vault_note', 'label'));
    $this->assertNull($staged->value('stage_test.vault_note', 'token'), 'A secret reads as NULL.');
    $this->expectException(\InvalidArgumentException::class);
    $staged->value('stage_test.nowhere', 'label');
  }

  /**
   * Tests the stage's values of a flow over a create are the create's.
   *
   * The first step staged a create; a later step reads it at the
   * position the stage holds it, not as a thing that is stored.
   */
  public function testStagedReadsTheCreateTheFirstStepStaged(): void {
    $this->visit('/surface-test/itinerary/plan');
    [, $redirect] = $this->submitStep(['id' => 'fresh', 'day' => 'fri']);
    $stage = $redirect->getOption('query')['stage'];
    $this->visit('/surface-test/itinerary/plan?stage=' . $stage . '&step=2');
    $route_match = $this->container->get('current_route_match');
    $staged = new Staged(
      $this->resources(),
      $this->container->get('surface.resource_registry'),
      $this->stages(),
      CompiledFlow::onRoute($route_match->getRouteObject(), (string) $route_match->getRouteName(), $this->container->get('surface.flows'))->resolved($this->container->get('surface.resource_registry')),
      $this->served(),
      $this->stages()->named($stage),
    );
    $this->assertSame('fri', $staged->value('stage_test.itinerary', 'day'));
    $this->assertSame('fresh', $staged->value('stage_test.itinerary', 'id'));
    $this->assertNull($this->container->get('keyvalue.database')->get(ItineraryStorage::COLLECTION)->get('fresh'), 'Nothing was created.');
  }

  /**
   * Tests a map's step may list keys, and the refusals that come with it.
   */
  public function testTheMapListsKeys(): void {
    $route = clone $this->container->get('router.route_provider')->getRouteByName('surface_stage_test.itinerary_flow');
    $route->setDefault(CompiledFlow::ROUTE, ['steps' => [['keys' => ['region', 'day']], 'when']]);
    CompiledFlow::assertWritten($route, 'example.flow');
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertSame(['id', 'region', 'day'], $this->drawn($this->surfaceForm()), 'The first step draws its keys, and the key in no group.');
    $this->serveBuilt('example.flow', $route, ['id' => 'trip'], ['stage' => $this->open(), 'step' => '2']);
    $this->assertSame(['nights'], $this->drawn($this->surfaceForm()), 'The group less the key another step lists.');

    $route->setDefault(CompiledFlow::ROUTE, ['steps' => [['keys' => ['region', 'elsewhere']], 'when']]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s flow draws the key "elsewhere" at step 1, and the "update" operation of stage_test.itinerary has no such key: name one of "id", "region", "day", "nights", "guide".');
    $route->setDefault(CompiledFlow::ROUTE, ['steps' => [['keys' => ['region']], 'where']]);
    $this->serveBuilt('example.flow', $route, ['id' => 'trip']);
    $this->assertRefused('The example.flow route\'s flow draws no key of the "update" operation of stage_test.itinerary at step 2: every key it would draw is drawn at another step, so list keys of its own for it, or leave it out.');

    $ledger = ['resource' => 'stage_test.ledger', 'operation' => 'update', 'arguments' => ['id' => 'row']];
    $cases = [
      'The example.flow route\'s "_surface_flow" lists the key "region" at more than one step: each key is drawn at one step.' => [
        ['keys' => ['region']],
        ['keys' => ['region', 'day']],
      ],
      'The example.flow route\'s "_surface_flow" lists the key "title" of the "update" operation of stage_test.ledger at more than one step: each key is drawn at one step.' => [
        'where',
        $ledger + ['keys' => ['title']],
        $ledger + ['keys' => ['title', 'note']],
      ],
      'The example.flow route\'s "_surface_flow" gives a step both "keys" and a "group": a step draws a group or keys, not both.' => [
        'where',
        $ledger + ['keys' => ['title'], 'group' => 'notes'],
      ],
    ];
    foreach ($cases as $message => $steps) {
      $route->setDefault(CompiledFlow::ROUTE, ['steps' => $steps]);
      try {
        CompiledFlow::assertWritten($route, 'example.flow');
        $this->fail('The rebuild did not refuse: ' . $message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
    // An empty list of keys would draw every key: it is no step's shape.
    foreach ([[['keys' => []]], [['keys' => ['region'], 'arguments' => ['id' => 'trip']]]] as $steps) {
      $route->setDefault(CompiledFlow::ROUTE, ['steps' => $steps]);
      try {
        CompiledFlow::assertWritten($route, 'example.flow');
        $this->fail('The rebuild did not refuse a step written wrong.');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('"steps" lists at least one step', $e->getMessage());
      }
    }
  }

  /**
   * Opens the class flow and submits its first step.
   *
   * @return string
   *   The stage's id.
   */
  protected function firstStep(): string {
    $this->visit(self::PATH);
    [, $redirect] = $this->submitStep(['region' => 'south']);
    $this->assertSame('2', $redirect->getOption('query')['step']);
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
    $this->container->get('messenger')->deleteAll();
    return [$state, $redirect];
  }

  /**
   * Lists the keys a built container draws.
   *
   * @param array $container
   *   The data surface container.
   *
   * @return list<string>
   *   The keys with an element, in order.
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
   * Asks the route on the stack's access check, with the request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected function stepAccess(): mixed {
    return $this->container->get('surface.operation_access')->access($this->container->get('current_route_match'), $this->container->get('current_user'), $this->container->get('request_stack')->getCurrentRequest());
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
   * Builds a flow registry over the classes given.
   *
   * @param class-string ...$classes
   *   The flow classes, as discovery would list them.
   *
   * @return \Drupal\surface\Flow\FlowRegistry
   *   The registry.
   */
  protected function registry(string ...$classes): FlowRegistry {
    return new FlowRegistry(
      ['flows' => array_fill_keys($classes, 'surface_stage_test')],
      $this->container->get('class_resolver'),
      $this->container->get('surface.resource_registry'),
    );
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

}
