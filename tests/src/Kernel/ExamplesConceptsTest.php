<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\Form\DataSurfaceFormBuilder;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_examples\CodeLines;
use Drupal\data_surface_examples\ConceptPanel;
use Drupal\data_surface_examples\ExamplePanels;
use Drupal\data_surface_examples\Resource\BulletinResource;
use Drupal\data_surface_examples\Resource\DoorsResource;
use Drupal\data_surface_examples\Resource\InfoDeskResource;
use Drupal\data_surface_examples\Resource\PressResource;
use Drupal\data_surface_examples\Resource\ProgramResource;
use Drupal\data_surface_examples\Resource\TicketSalesResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests examples 7 to 11: one small resource per concept.
 *
 * Each through what a person sees, its form built and saved by the
 * generic operation form, and what a client reads, its two documents:
 * a rule written as data, one part used twice, keys that come from the
 * site, access of both kinds with how each operation behaves, and the
 * stock widgets with what nothing means. Then the panel above each, and
 * none above the registration's.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesConceptsTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Puts one of the examples' routes on the request stack.
   *
   * @param string $route_name
   *   The route.
   */
  protected function serveRoute(string $route_name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Builds the generic form at a route.
   *
   * @param string $route_name
   *   The route.
   *
   * @return array
   *   The data surface's container in the form.
   */
  protected function formAt(string $route_name): array {
    $this->serveRoute($route_name);
    return $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Submits the generic form at the route on the stack.
   *
   * @param array $values
   *   Raw values keyed by resource key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Emits a resource's full schema for the current account.
   *
   * @param class-string $class
   *   The resource class.
   * @param string $operation
   *   The operation.
   *
   * @return array
   *   The schema.
   */
  protected function full(string $class, string $operation = 'configure'): array {
    $resources = $this->container->get('data_surface.resources');
    $registry = $this->container->get('data_surface.resource_registry');
    $context = $resources->operation($class, $operation);
    $definition = $registry->getOperation($class, $operation);
    return $this->container->get('data_surface.contract_emitter')
      ->emitFull($resources->buildStatic($class, $context), $registry->getDefinition($class)->id, $operation, $definition->label, operation: $definition->facts($context))
      ->document['schema'];
  }

  /**
   * Emits a resource's schema for the values stored now.
   *
   * @param class-string $class
   *   The resource class.
   * @param bool $widgets
   *   Whether to name each key's widget.
   *
   * @return array
   *   The schema.
   */
  protected function now(string $class, bool $widgets = FALSE): array {
    $resources = $this->container->get('data_surface.resources');
    $built = $resources->buildOperation($class, 'configure');
    $values = array_replace($built->getDefaultValues(), $resources->storage($class, $resources->operation($class, 'configure'), $built)->load($built));
    return $this->container->get('data_surface.contract_emitter')
      ->emit($built, $values, $this->container->get('data_surface.resource_registry')->getDefinition($class)->id, 'configure', NULL, $widgets)
      ->document['schema'];
  }

  /**
   * Reads one example's settings as stored, less what the storage adds.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset($name);
    return array_diff_key($this->config($name)->getRawData(), ['_core' => TRUE, 'langcode' => TRUE]);
  }

  /**
   * Tests example 7: two rules as data, and a send day that does not apply.
   */
  public function testSevenStatesItsRulesAsWritten(): void {
    $full = $this->full(BulletinResource::class);
    $declared = array_values(array_filter($full['allOf'], static fn (array $branch): bool => !empty($branch[ContractEmitter::EXTENSION]['declared'])));
    $this->assertCount(3, $declared);
    $this->assertSame(['frequency' => ['const' => 'monthly']], $declared[0]['if']['properties']);
    $this->assertFalse($declared[0]['then']['properties']['send_day']);
    $this->assertSame(['send_day'], $declared[1]['then']['required']);
    $this->assertSame(['description' => 'Up to 5 in a weekly issue.', 'maximum' => 5], $declared[2]['then']['properties']['items']);
    $this->assertTrue($full['properties']['send_day'][ContractEmitter::EXTENSION]['declared']);
    $this->assertSame(['frequency'], $full['required']);

    // Weekly, as shipped: the send day is drawn, required, and the items
    // stop at five, said under the field; the frequency rebuilds both.
    $form = $this->formAt('data_surface_examples.step7');
    $this->assertSame('friday', $form['send_day']['#default_value']);
    $this->assertTrue($form['send_day'][DataSurfaceFormBuilder::REQUIRED_KEY], 'Drawn required.');
    $this->assertSame(5, $form['items']['#max']);
    $this->assertSame('Up to 5 in a weekly issue.', (string) $form['items']['#description']);
    $this->assertArrayHasKey('#ajax', $form['frequency']);

    // Monthly: no element for the send day, and the stored one kept.
    $state = $this->submitTheForm(['frequency' => 'monthly', 'items' => '12']);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['frequency' => 'monthly', 'send_day' => 'friday', 'items' => 12], $this->stored('data_surface_examples.bulletin'));
    $form = $this->formAt('data_surface_examples.step7');
    $this->assertArrayNotHasKey('#type', $form['send_day'] ?? [], 'Not drawn: not disabled, not hidden.');
    $this->assertSame(20, $form['items']['#max']);
  }

  /**
   * Tests example 8: one person at two sites, a shape, a mixin, a list.
   */
  public function testEightUsesOnePartTwice(): void {
    $press = $this->full(PressResource::class);
    $desk = $this->full(InfoDeskResource::class);
    $this->assertSame(['event.person', 'event.review', 'event.phone'], array_keys($press['$defs']));
    $this->assertSame(['event.person', 'event.phone'], array_keys($desk['$defs']));
    $this->assertSame($press['$defs']['event.person'], $desk['$defs']['event.person'], 'One def, whatever the site.');
    $this->assertSame(['listed' => ['type' => 'boolean', 'required' => FALSE]], $press['$defs']['event.person'][ContractEmitter::EXTENSION]['ports']);
    $this->assertSame(['listed' => 'public'], $press['properties']['contact'][ContractEmitter::EXTENSION]['reads']);
    $this->assertSame(['listed' => 'in_program'], $desk['properties']['lead'][ContractEmitter::EXTENSION]['reads']);
    // The email rule is stated at each site, on the key the port reads.
    $rules = static fn (array $full, string $on): array => array_values(array_filter($full['allOf'] ?? [], static fn (array $branch): bool => isset($branch['if']['properties'][$on])));
    [$public] = $rules($press, 'public');
    $this->assertSame(['email'], $public['then']['properties']['contact']['required']);
    [$in_program] = $rules($desk, 'in_program');
    $this->assertSame(['email'], $in_program['then']['properties']['lead']['required']);
    // The shape at two keys, the volunteers a list of the same def.
    $this->assertSame('#/$defs/event.phone', $desk['properties']['phone']['$ref']);
    $this->assertSame('#/$defs/event.phone', $desk['$defs']['event.person']['properties']['phone']['$ref']);
    $this->assertSame('#/$defs/event.person', $desk['properties']['volunteers']['items']['$ref']);
    $this->assertSame(4, $desk['properties']['volunteers']['maxItems']);
    // The review is mixed in: the press page's own keys, its def in allOf.
    $this->assertSame(['$ref' => '#/$defs/event.review'], $press['allOf'][0]);
    $surface = $this->container->get('data_surface.resources')->buildOperation(PressResource::class, 'configure');
    $this->assertSame(['public', 'contact', 'reviewed_on', 'reviewed_by'], $surface->getDefinitions()->names());

    // Through the forms: the review stored flat, two volunteers in a list.
    $this->formAt('data_surface_examples.step8');
    $state = $this->submitTheForm([
      'public' => '1',
      'contact' => ['name' => 'Robin Lee', 'email' => 'press@example.com', 'phone' => '+44 20 7946 0000'],
      'reviewed_on' => '2026-10-19',
      'reviewed_by' => 'Sam Ortiz',
    ]);
    $this->assertSame([], $state->getErrors());
    $stored = $this->stored('data_surface_examples.press');
    $this->assertSame('2026-10-19', $stored['reviewed_on']);
    $this->assertSame('+44 20 7946 0000', $stored['contact']['phone']);
    $state = $this->submitTheForm([
      'public' => '1',
      'contact' => ['name' => 'Robin Lee', 'email' => '', 'phone' => ''],
      'reviewed_on' => '2026-10-19',
      'reviewed_by' => 'Sam Ortiz',
    ]);
    $this->assertArrayHasKey('surface][contact][email', $state->getErrors());

    $form = $this->formAt('data_surface_examples.step8_desk');
    $this->assertSame('Alex Kim', $form['lead']['name']['#default_value']);
    $state = $this->submitTheForm([
      'phone' => '+1 555 0199',
      'in_program' => NULL,
      'lead' => ['name' => 'Alex Kim', 'email' => '', 'phone' => ''],
      'volunteers' => [
        ['name' => 'Jo Park', 'email' => '', 'phone' => ''],
        ['name' => 'Lee Chan', 'email' => '', 'phone' => '+1 555 0123'],
      ],
    ]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame(['Jo Park', 'Lee Chan'], array_column($this->stored('data_surface_examples.desk')['volunteers'], 'name'));
  }

  /**
   * Tests example 9: the ticket types are keys, and one is locked.
   */
  public function testNineTakesItsKeysFromTheSite(): void {
    $full = $this->full(TicketSalesResource::class);
    $on_sale = SchemaResolver::resolve($full['properties']['on_sale'], $full);
    $this->assertSame(['standard', 'student', 'speaker'], array_keys($on_sale['properties']));
    $this->assertTrue($on_sale['properties']['speaker']['readOnly']);
    $this->assertTrue($on_sale['properties']['speaker'][ContractEmitter::EXTENSION]['locked']);
    $this->assertArrayNotHasKey('readOnly', $on_sale['properties']['standard']);
    $limits = SchemaResolver::resolve($full['properties']['limits'], $full);
    $this->assertTrue($limits['properties']['speaker']['readOnly']);
    $now = $this->now(TicketSalesResource::class);
    $this->assertFalse(SchemaResolver::resolve($now['properties']['on_sale'], $now)['properties']['speaker']['const']);

    $form = $this->formAt('data_surface_examples.step9');
    $this->assertTrue($form['on_sale']['speaker']['#disabled']);
    $this->assertArrayNotHasKey('#disabled', $form['on_sale']['standard']);

    // A type added to the configuration is a key at the next build, with
    // no cache rebuild: the configuration's tag is the build's.
    $built = $this->container->get('data_surface.resources')->buildOperation(TicketSalesResource::class, 'configure');
    $this->assertContains('config:data_surface_examples.ticket_types', $built->getCacheTags());
    $this->config('data_surface_examples.ticket_types')->set('types.vip', ['label' => 'VIP', 'locked' => FALSE])->save();
    $full = $this->full(TicketSalesResource::class);
    $this->assertSame(['standard', 'student', 'speaker', 'vip'], array_keys(SchemaResolver::resolve($full['properties']['on_sale'], $full)['properties']));

    // A save keeps the locked type as stored, whatever is posted for it.
    $this->formAt('data_surface_examples.step9');
    $state = $this->submitTheForm([
      'on_sale' => ['standard' => '1', 'student' => NULL, 'speaker' => '1', 'vip' => '1'],
      'limits' => [
        'standard' => ['per_order' => '6', 'waiting_list' => NULL],
        'student' => ['per_order' => '1', 'waiting_list' => '1'],
        'speaker' => ['per_order' => '9', 'waiting_list' => '1'],
        'vip' => ['per_order' => '2', 'waiting_list' => NULL],
      ],
    ]);
    $this->assertSame([], $state->getErrors());
    $stored = $this->stored('data_surface_examples.sales');
    $this->assertSame(['standard' => TRUE, 'student' => FALSE, 'speaker' => FALSE, 'vip' => TRUE], $stored['on_sale']);
    $this->assertSame(['per_order' => 1, 'waiting_list' => FALSE], $stored['limits']['speaker']);
    $this->assertSame(6, $stored['limits']['standard']['per_order']);
  }

  /**
   * Tests example 10: the fees for two accounts, and once published.
   */
  public function testTenLocksTheFeesStaticallyAndAtRuntime(): void {
    // An account that may set event fees: the program decides, marked.
    $fees = $this->full(ProgramResource::class)['properties']['fees'];
    $this->assertSame('runtime', $fees[ContractEmitter::EXTENSION]['access']);
    $this->assertArrayNotHasKey('readOnly', $fees);
    $this->assertArrayNotHasKey('#disabled', $this->formAt('data_surface_examples.step10')['fees']);

    // Published, the fees are fixed: the form draws them disabled and a
    // save keeps them as stored.
    $this->config('data_surface_examples.program')->set('published', TRUE)->save();
    $form = $this->formAt('data_surface_examples.step10');
    $this->assertTrue($form['fees']['#disabled']);
    $state = $this->submitTheForm([
      'headline' => 'Two days of talks',
      'sessions' => '9',
      'published' => '1',
      'fees' => ['standard' => '99', 'concession' => '1'],
    ]);
    $this->assertSame([], $state->getErrors());
    $stored = $this->stored('data_surface_examples.program');
    $this->assertSame(9, $stored['sessions']);
    $this->assertEquals(['standard' => 20.0, 'concession' => 10.0], $stored['fees']);
    $this->assertSame('runtime', $this->full(ProgramResource::class)['properties']['fees'][ContractEmitter::EXTENSION]['access'], 'The full schema reads no stored value.');

    // An account without the permission: locked, stated ahead of time.
    $this->setUpCurrentUser(permissions: ['administer site configuration']);
    $fees = $this->full(ProgramResource::class)['properties']['fees'];
    $this->assertTrue($fees['readOnly']);
    $this->assertTrue($fees[ContractEmitter::EXTENSION]['locked']);
    $this->assertArrayNotHasKey('access', $fees[ContractEmitter::EXTENSION]);
  }

  /**
   * Tests example 10: what each operation states, and its tools.
   */
  public function testTenStatesHowEachOperationBehaves(): void {
    $configure = $this->full(ProgramResource::class)[ContractEmitter::EXTENSION]['operation'];
    $check = $this->full(ProgramResource::class, 'check')[ContractEmitter::EXTENSION]['operation'];
    $facts = ['creates' => FALSE, 'readOnly' => FALSE, 'destructive' => FALSE, 'idempotent' => TRUE];
    $this->assertSame(['id' => 'configure', 'label' => 'Configure the program', 'kind' => 'update'] + $facts, $configure);
    $this->assertSame(array_replace(['id' => 'check', 'label' => 'Check a program', 'kind' => 'read'] + $facts, ['readOnly' => TRUE]), $check);
    $tools = $this->container->get('plugin.manager.tool');
    $this->assertSame(ToolOperation::Write, $tools->getDefinition('data_surface:event.program:configure')->getOperation());
    $this->assertSame(ToolOperation::Read, $tools->getDefinition('data_surface:event.program:check')->getOperation());
    $this->assertTrue($tools->hasDefinition('data_surface:event.program:configure:read'));
    $this->assertFalse($tools->hasDefinition('data_surface:event.program:check:read'), 'Nothing to read before a call that changes nothing.');

    // The check's form accepts and writes nothing.
    $this->formAt('data_surface_examples.step10_check');
    $state = $this->submitTheForm([
      'headline' => 'Checked only',
      'sessions' => '3',
      'published' => NULL,
      'fees' => ['standard' => '5', 'concession' => '2'],
    ]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('Two days of talks', $this->stored('data_surface_examples.program')['headline']);
    $this->assertSame(
      ['The values were accepted. Check a program changes nothing, so nothing was written.'],
      array_map('strval', $this->container->get('messenger')->messagesByType('status')),
    );
  }

  /**
   * Tests example 11: each stock widget, and the keys that may hold nothing.
   */
  public function testElevenDrawsEveryStockWidget(): void {
    $now = $this->now(DoorsResource::class, TRUE);
    $widgets = array_map(static fn (array $property): ?string => $property[ContractEmitter::EXTENSION]['widget'] ?? NULL, $now['properties']);
    $this->assertSame([
      'opens' => 'time',
      'days' => 'checkboxes',
      'badges' => 'radios',
      'notes' => 'textarea',
      'greeters' => 'list',
    ], $widgets);
    foreach ([$now, $this->full(DoorsResource::class)] as $schema) {
      $this->assertSame('string', $schema['properties']['badges']['type'], 'Always held: no null.');
      $this->assertSame(['string', 'null'], $schema['properties']['notes']['type']);
      $this->assertNotContains('badges', $schema['required'] ?? []);
    }

    $form = $this->formAt('data_surface_examples.step11');
    $this->assertSame(['date', 'time'], [$form['opens']['#type'], $form['opens']['#attributes']['type']]);
    $this->assertSame('checkboxes', $form['days']['#type']);
    $this->assertSame('radios', $form['badges']['#type']);
    $this->assertSame(['printed', 'digital', 'none'], array_keys($form['badges']['#options']), 'No "- None -" for a key always held.');
    $this->assertSame('textarea', $form['notes']['#type']);
    $this->assertSame(['Ana', 'Ben'], [$form['greeters'][0]['#default_value'], $form['greeters'][1]['#default_value']]);
    $this->assertSame('submit', $form['greeters']['add']['#type']);

    $this->assertSame([], $this->submitTheForm([
      'opens' => '19:00',
      'days' => ['fri' => 'fri', 'sun' => 'sun'],
      'badges' => 'digital',
      'notes' => '',
      'greeters' => ['Ana', '', 'Cy'],
    ])->getErrors());
    $stored = $this->stored('data_surface_examples.doors');
    $this->assertSame([
      'opens' => '19:00',
      'days' => ['fri', 'sun'],
      'badges' => 'digital',
      'notes' => NULL,
      'greeters' => ['Ana', 'Cy'],
    ], $stored);
    $this->assertCount(0, $this->container->get('config.typed')->get('data_surface_examples.doors')->validate(), 'What is stored is fully valid.');
  }

  /**
   * Tests the panel above examples 7 to 11, and none above 1 to 3.
   */
  public function testThePanelSaysWhatEachShows(): void {
    $panel = $this->formAt('data_surface_examples.step3')[DataSurfaceOperationForm::PANEL_KEY];
    $this->assertArrayNotHasKey(ExamplePanels::CONCEPT, $panel);

    $pages = [
      'step7' => 'event.bulletin',
      'step8' => 'event.press',
      'step8_desk' => 'event.desk',
      'step9' => 'event.sales',
      'step10' => 'event.program',
      'step10_check' => 'event.program',
      'step11' => 'event.doors',
    ];
    $order = [ExamplePanels::CONCEPT, ExamplePanels::CONTRACT, ExamplePanels::TODAY];
    foreach ($pages as $route => $id) {
      $panel = $this->formAt('data_surface_examples.' . $route)[DataSurfaceOperationForm::PANEL_KEY];
      $concept = $panel[ExamplePanels::CONCEPT];
      $this->assertSame(-1, $concept['#weight'], $route);
      $this->assertTrue($concept['#open']);
      $this->assertSame('What this example shows', (string) $concept['#title']);
      $this->assertStringContainsString((string) $this->container->get('data_surface_examples.concept_panel')->paragraph($id), (string) $concept['what']['#markup']);
      $this->assertSame(ConceptPanel::DOCS[$id], array_map(static fn (array $item): string => strip_tags((string) $item['#markup']), $concept['docs']['#items']));
      foreach (ConceptPanel::DOCS[$id] as $page) {
        $file = dirname(__DIR__, 3) . '/' . strtok($page, '#');
        $this->assertFileExists($file);
      }
      $this->assertArrayHasKey('calls', $concept);
      $this->assertSame($order, array_values(array_intersect(Element::children($panel, TRUE), $order)), $route);
    }

    // Example 7's rules, quoted from the class as they are.
    $panel = $this->formAt('data_surface_examples.step7')[DataSurfaceOperationForm::PANEL_KEY][ExamplePanels::CONCEPT];
    $rules = htmlspecialchars_decode((string) $panel['rules']['content']['#value']);
    $this->assertStringContainsString("\$inputs->narrow('send_day')\n  ->when(Condition::equals('frequency', 'monthly'))\n  ->doesNotApply();", $rules);
    // Example 10's two accounts and its facts.
    $panel = $this->formAt('data_surface_examples.step10')[DataSurfaceOperationForm::PANEL_KEY][ExamplePanels::CONCEPT];
    $accounts = json_decode(htmlspecialchars_decode((string) $panel['accounts']['content']['#value']), TRUE);
    $this->assertSame('runtime', $accounts['you']['property'][ContractEmitter::EXTENSION]['access']);
    $this->assertTrue($accounts['without the permission']['property']['readOnly']);
    $this->assertSame(['configure', 'update'], array_slice($panel['facts']['#rows'][0], 0, 2));
    $this->assertSame([
      'check',
      'read',
      'true',
      'false',
      'true',
      'data_surface:event.program:check',
      'read',
      'readOnlyHint true, destructiveHint false, idempotentHint true',
      'false',
    ], $panel['facts']['#rows'][1]);
  }

  /**
   * Tests each class the landing page counts stays screen sized.
   */
  public function testEachExampleFitsOnOneScreen(): void {
    $classes = [
      BulletinResource::class,
      PressResource::class,
      InfoDeskResource::class,
      TicketSalesResource::class,
      ProgramResource::class,
      DoorsResource::class,
    ];
    foreach ($classes as $class) {
      $lines = CodeLines::count((string) (new \ReflectionClass($class))->getFileName());
      $this->assertGreaterThan(10, $lines, $class);
      $this->assertLessThanOrEqual(45, $lines, $class);
    }
  }

}
