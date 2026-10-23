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
use Drupal\data_surface_examples\Controller\ExamplesController;
use Drupal\data_surface_examples\ExamplePanels;
use Drupal\data_surface_examples\ConceptCalls;
use Drupal\data_surface_examples\Hook\SpotlightBlockHooks;
use Drupal\data_surface_examples\Resource\BulletinResource;
use Drupal\data_surface_examples\Resource\DoorsResource;
use Drupal\data_surface_examples\Resource\InfoDeskResource;
use Drupal\data_surface_examples\Resource\PressResource;
use Drupal\data_surface_examples\Resource\ProgramResource;
use Drupal\data_surface_examples\Resource\SpotlightResource;
use Drupal\data_surface_examples\Resource\TicketSalesResource;
use Drupal\data_surface\Variants\ComponentProps;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests examples 7 to 13: one small resource per concept, then a block.
 *
 * Each through what a person sees, its form built and saved by the
 * generic operation form, and what a client reads, its two documents:
 * a rule written as data, one part used twice, keys that come from the
 * site, access of both kinds with how each operation behaves, and the
 * stock widgets with what nothing means. Then the panel above each, and
 * none above the registration's; and example 13's page, with its module
 * off and on.
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
   * Example 12: the components this module ships, by id.
   */
  protected const COMPONENTS = [
    'data_surface_examples:call_to_action',
    'data_surface_examples:card',
    'data_surface_examples:hero',
    'data_surface_examples:section',
  ];

  /**
   * Example 12: the hero's props as its form posts them, less the overlay.
   */
  protected const HERO = [
    'title' => 'Doors open at six',
    'subtitle' => '',
    'align' => 'start',
    'full_width' => NULL,
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
      ->emitFull($resources->buildStatic($class, $context), $registry->getDefinition($class)->id, $operation, $definition->label, operation: $definition->facts())
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
    // Listed by the frame by index: no x-surface below an applicator.
    $declared = array_values(array_intersect_key($full['allOf'], array_flip($full[ContractEmitter::EXTENSION]['rules'])));
    $this->assertCount(3, $declared);
    $this->assertSame(['frequency' => ['const' => 'monthly']], $declared[0]['if']['properties']);
    $this->assertFalse($declared[0]['then']['properties']['send_day']);
    $this->assertSame(['send_day'], $declared[1]['then']['required']);
    $this->assertSame(['type' => ['integer', 'null'], 'description' => 'Up to 5 in a weekly issue.', 'maximum' => 5], $declared[2]['then']['properties']['items'], 'The declared type beside the bound.');
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
    $this->assertSame('live', $fees[ContractEmitter::EXTENSION]['access']);
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
    $this->assertSame('live', $this->full(ProgramResource::class)['properties']['fees'][ContractEmitter::EXTENSION]['access'], 'The full schema reads no stored value.');

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
    // No `readOnly` in the documents: `kind: read` says it.
    $facts = ['creates' => FALSE, 'destructive' => FALSE, 'idempotent' => TRUE];
    $this->assertSame([
      'id' => 'configure',
      'label' => 'Configure the program',
      'description' => 'Sets the program: its headline, how many sessions it has, whether it is published, and its fees.',
      'kind' => 'update',
    ] + $facts, $configure);
    $this->assertSame([
      'id' => 'check',
      'label' => 'Check a program',
      'description' => 'Checks a program against every rule the program configuration applies, and saves nothing.',
      'kind' => 'read',
    ] + $facts, $check);
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
   * Tests example 12: a component chosen, and its props read from its schema.
   */
  public function testTwelveReadsEachComponentsProps(): void {
    $built = $this->container->get('data_surface.resources')->buildOperation(SpotlightResource::class, 'configure');
    $variants = $built->getDefinitions()->entry('props')->variants;
    $ours = array_values(array_filter(array_keys($variants->variants), static fn (string $id): bool => str_starts_with($id, 'data_surface_examples:')));
    $this->assertSame(self::COMPONENTS, $ours, 'The four components this module ships are four variants.');
    $slots = $built->getDefinitions()->entry('slots')->variants;
    $this->assertSame(array_keys($variants->variants), array_keys($slots->variants), 'The same component chooses its slots.');
    $this->assertSame(['body'], $slots->variants['data_surface_examples:card']->child->getDefinitions()->names());
    $this->assertSame(['actions'], $slots->variants['data_surface_examples:hero']->child->getDefinitions()->names());
    $this->assertContains('config:core.extension', $built->getCacheTags());
    $this->assertSame('Card', (string) $this->options()->resolve($built->getDefinition('component'))->options['data_surface_examples:card']);

    // The card's props, read: each a key with its type and constraints.
    $card = $variants->variants['data_surface_examples:card']->child;
    $this->assertSame(['heading', 'tone', 'link'], $card->getDefinitions()->names());
    $this->assertTrue($card->getDefinition('heading')->isRequired());
    $this->assertSame(['max' => 80], $card->getDefinition('heading')->getConstraint('Length'));
    $this->assertSame([
      'choices' => ['plain', 'highlight'],
      'labels' => ['plain' => 'Plain', 'highlight' => 'Highlighted'],
    ], $card->getDefinition('tone')->getConstraint('LabeledChoice'));
    $this->assertSame('plain', $card->getDefault('tone'));
    $this->assertSame('uri', $card->getDefinition('link')->getPropertyDefinition('url')->getDataType());
    $hero = $variants->variants['data_surface_examples:hero']->child;
    $this->assertSame(['min' => 0, 'max' => 100], $hero->getDefinition('overlay')->getConstraint('Range'));
    $cta = $variants->variants['data_surface_examples:call_to_action']->child;
    $this->assertSame(['max' => 3], $cta->getDefinition('tags')->getConstraint('Count'));

    // Both documents state the components as variants.
    $full = $this->full(SpotlightResource::class);
    $this->assertSame('component', $full['properties']['props'][ContractEmitter::EXTENSION]['by']);
    $this->assertSame('#/$defs/event.spotlight.props.data_surface_examples:card', $full['properties']['props'][ContractEmitter::EXTENSION]['variants']->{'data_surface_examples:card'});
    $this->assertSame(['heading'], $full['$defs']['event.spotlight.props.data_surface_examples:card']['required']);
    $this->assertSame('component', $full['properties']['slots'][ContractEmitter::EXTENSION]['by']);
    $this->assertSame('#/$defs/component.slot.text', $full['$defs']['event.spotlight.slots.data_surface_examples:card']['properties']['body']['$ref']);

    // The form draws the stored component's props; another chosen, its own.
    $form = $this->formAt('data_surface_examples.step12');
    $this->assertSame(['heading', 'tone', 'link'], Element::children($form['props']));
    $this->assertSame('Doors open at half past six', $form['props']['heading']['#default_value']);
    $this->assertSame(['body'], Element::children($form['slots']));
    $this->assertSame('Bring your ticket, on your phone or printed.', $form['slots']['body']['text']['#default_value']);
    $state = $this->submitTheForm([
      'component' => 'data_surface_examples:hero',
      'props' => self::HERO + ['overlay' => '60'],
      'slots' => ['actions' => ['kind' => 'text', 'text' => 'Tickets at the door.']],
    ]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame([
      'component' => 'data_surface_examples:hero',
      'props' => [
        'title' => 'Doors open at six',
        'subtitle' => NULL,
        'align' => 'start',
        'overlay' => 60,
        'full_width' => FALSE,
      ],
      'slots' => ['actions' => ['kind' => 'text', 'text' => 'Tickets at the door.']],
    ], $this->stored('data_surface_examples.spotlight'));
    $this->assertCount(0, $this->container->get('config.typed')->get('data_surface_examples.spotlight')->validate(), 'What is stored is valid: the props are open in the config schema, and the hero held them to its own.');
    $form = $this->formAt('data_surface_examples.step12');
    $this->assertSame(['title', 'subtitle', 'align', 'overlay', 'full_width'], Element::children($form['props']));

    // A hero darker than its schema allows is refused, as the schema says.
    $state = $this->submitTheForm([
      'component' => 'data_surface_examples:hero',
      'props' => self::HERO + ['overlay' => '140'],
    ]);
    $this->assertStringContainsString('Overlay opacity: This value should be between', strip_tags((string) current($state->getErrors())));
  }

  /**
   * Tests the panel above examples 7 to 12, and none above 1 to 3.
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
      'step12' => 'event.spotlight',
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
    $this->assertSame('live', $accounts['you']['property'][ContractEmitter::EXTENSION]['access']);
    $this->assertTrue($accounts['without the permission']['property']['readOnly']);
    $this->assertStringContainsString('Enable data_surface_examples_workflow and reload', (string) $panel['workflow']['#markup'], 'Without the workflow module, the panel says how to see its answers.');
    $this->assertArrayNotHasKey('event.program:published_in_workflow', $panel['calls'], 'A call another module\'s part needs is made only where it is on.');
    $this->assertSame(['configure', 'update'], array_slice($panel['facts']['#rows'][0], 0, 2));
    $this->assertSame([
      'check',
      'read',
      'false',
      'true',
      'data_surface:event.program:check',
      'read',
      'readOnlyHint true, destructiveHint false, idempotentHint true',
      'false',
    ], $panel['facts']['#rows'][1]);
    // Example 12's component, its schema beside the structure it became.
    $panel = $this->formAt('data_surface_examples.step12')[DataSurfaceOperationForm::PANEL_KEY][ExamplePanels::CONCEPT];
    $read = json_decode(htmlspecialchars_decode((string) $panel['read']['content']['#value']), TRUE);
    $this->assertSame(['heading'], $read['schema']['required']);
    $this->assertSame('event.spotlight.props.data_surface_examples:card', $read['structure']['title']);
    $notes = json_decode(htmlspecialchars_decode((string) $panel['notes']['content']['#value']), TRUE);
    $this->assertSame(['/properties/link/additionalProperties' => 'Read closed: a map holds the keys it declares, and a key the schema leaves open is refused.'], $notes);
    $contract = json_decode(htmlspecialchars_decode((string) $panel['contract']['content']['#value']), TRUE);
    $this->assertSame(self::COMPONENTS, array_column($contract['component']['oneOf'], 'const'));
    $this->assertSame('data_surface_examples:card', $contract['allOf'][0]['if']['properties']['component']['const']);
    $this->assertSame(['props', 'slots'], array_merge(...array_map(static fn (array $branch): array => array_keys($branch['then']['properties']), $contract['allOf'])), 'One conditional per key the component chooses.');
    $this->assertSame('#/$defs/event.spotlight.slots.data_surface_examples:card', $contract['slots'][ContractEmitter::EXTENSION]['variants']['data_surface_examples:card']);
    $slots = json_decode(htmlspecialchars_decode((string) $panel['slots']['content']['#value']), TRUE);
    $this->assertSame([], $slots['metadata']['body']['expected']);
    $this->assertSame('event.spotlight.slots.data_surface_examples:card', $slots['structure']['title']);
    $this->assertSame(['component.slot.text'], array_keys($slots['slot']));
  }

  /**
   * Tests example 13: where the block is, then the block beside example 12.
   */
  public function testThirteenSaysWhereTheBlockIsThenShowsIt(): void {
    $page = $this->thirteen();
    $items = array_map(static fn ($item): string => strip_tags((string) (is_array($item) ? ($item['#markup'] ?? '') : $item)), $page['how']['#items']);
    $this->assertSame('Enable both: ddev drush pm:install data_surface_component_block data_surface_block', $items[0]);
    $this->assertStringStartsWith('The block is not enabled on this site yet.', $items[1]);
    $this->assertMatchesRegularExpression('/^The structure and the block plugin: \d+ lines of code\.$/', $items[2]);
    $this->assertArrayNotHasKey('parts', $page, 'While the module is off, nothing of it is shown.');
    $index = $this->container->get('class_resolver')->getInstanceFromDefinition(ExamplesController::class)->index();
    $this->assertSame('Example 13: a block that embeds a component', strip_tags((string) $index[13]['title']['#markup']));
    $this->assertArrayHasKey('block', $index);

    // On, with a default theme that has a content region.
    $this->installConfig(['system']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('module_installer')->install(['path_alias', SpotlightBlockHooks::MODULE]);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('block')->load(SpotlightBlockHooks::BLOCK), 'The Spotlight block is placed through the placement resource, which is not on yet.');
    $page = $this->thirteen();
    $this->assertStringStartsWith('The block is enabled on this site, the placement resource not yet.', strip_tags((string) $page['how']['#items'][1]));
    $this->assertArrayHasKey('parts', $page);
    $this->assertArrayNotHasKey('calls', $page);
    $this->container->get('module_installer')->install([SpotlightBlockHooks::PLACEMENT]);
    $block = $this->container->get('entity_type.manager')->getStorage('block')->load(SpotlightBlockHooks::BLOCK);
    $this->assertNotNull($block, 'Enabling both placed the Spotlight block, through the add operation.');
    $this->assertSame('stark', $block->get('theme'));
    $this->assertSame('content', $block->get('region'));
    $this->assertSame(SpotlightBlockHooks::PAGE, $block->get('visibility')['request_path']['pages']);
    $this->assertSame(ConceptCalls::CALLS['block.placement']['place']['values']['settings']['props'], $block->get('settings')['props']);
    $this->assertSame(ConceptCalls::CALLS['block.placement']['place']['values']['settings']['slots'], $block->get('settings')['slots']);
    $this->assertSame('Spotlight', $block->get('settings')['label']);
    $section = $this->container->get('entity_type.manager')->getStorage('block')->load(SpotlightBlockHooks::SECTION);
    $this->assertNotNull($section, 'And the Tonight block, a section holding a card.');
    $this->assertSame('data_surface_examples:card', $section->get('settings')['slots']['content']['component']['component']);
    $this->assertSame(['body' => ['kind' => 'text', 'text' => 'In the main hall, first floor.']], $section->get('settings')['slots']['content']['component']['slots']);
    $modules = ['data_surface_component_block', 'data_surface_examples', 'system'];
    $this->assertSame(['module' => $modules, 'theme' => ['stark']], $section->getDependencies());

    $page = $this->thirteen();
    $items = array_map(static fn ($item): string => strip_tags((string) (is_array($item) ? ($item['#title'] ?? $item['#markup'] ?? '') : $item)), $page['how']['#items']);
    $this->assertSame('Both are enabled on this site now.', $items[1]);
    $this->assertSame('Place a component block in stark: /admin/structure/block/add/data_surface_component/stark', $items[4]);
    [$spotlight, $block_lines] = array_map(static fn (array $cell): string => htmlspecialchars_decode((string) $cell['data']['#value']), $page['parts']['two_lines']['#rows'][0]);
    $lines = "\$inputs->attachVariants('props', by: 'component', from: ComponentProps::class)\n  ->setLabel(t('Props'));\n\$inputs->attachVariants('slots', by: 'component', from: ComponentSlots::class)\n  ->setLabel(t('Slots'));";
    $this->assertStringContainsString($lines, $spotlight);
    $this->assertSame("\$inputs->add('component', 'string', t('Component'));\n" . $lines, $block_lines);
    $contract = json_decode(htmlspecialchars_decode((string) $page['parts']['contract']['content']['#value']), TRUE);
    $this->assertSame(self::COMPONENTS, array_column($contract['component']['oneOf'], 'const'));
    $this->assertSame('#/$defs/block.data_surface_component.props.data_surface_examples:card', $contract['props'][ContractEmitter::EXTENSION]['variants']['data_surface_examples:card']);
    $this->assertSame('#/$defs/block.data_surface_component.slots.data_surface_examples:hero', $contract['slots'][ContractEmitter::EXTENSION]['variants']['data_surface_examples:hero']);
    $actions = json_decode(htmlspecialchars_decode((string) $page['parts']['actions']['content']['#value']), TRUE);
    $this->assertSame(['data_surface_examples:call_to_action'], array_column($actions['component.nested.data_surface_examples:call_to_action.1']['properties']['component']['oneOf'], 'const'), 'The actions offer the call to action alone.');
    $this->assertSame(2, $actions['component.slot.data_surface_examples:hero.actions.1']['properties']['components']['maxItems']);
    $this->assertSame([
      ['data_surface:block.placement:add', 'Place a block', 'write', 'values, dry_run'],
      ['data_surface:block.placement:edit', 'Edit a placed block', 'write', 'id, values, dry_run'],
      ['data_surface:block.placement:edit:read', 'Current values: Edit a placed block', 'read', 'id'],
      ['data_surface:block.placement:remove', 'Remove a placed block', 'write', 'id, dry_run'],
    ], $page['parts']['tools']['#rows']);
    $answer = static fn (string $call): string => htmlspecialchars_decode((string) $page['calls']['block.placement:' . $call]['answer']['#value']);
    $this->assertStringStartsWith("Failed: The values were refused:\n/id: The machine name data_surface_examples_spotlight is already in use", $answer('place'));
    $this->assertStringStartsWith("Failed: The values were refused:\n/id: The machine name data_surface_examples_section is already in use", $answer('section'));
    $this->assertStringStartsWith('Success: Dry run of Edit a placed block', $answer('props'));
    $this->assertStringStartsWith("Failed: The values were refused:\n/settings/slots/actions/component/component: The value you selected is not a valid choice. Choose data_surface_examples:call_to_action (Call to action).", $answer('wrong_slot'));
    $this->assertStringStartsWith('Success: Dry run of Remove a placed block', $answer('remove'));
    $this->assertSame('data_surface_examples:card', $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged(SpotlightBlockHooks::BLOCK)->get('settings')['component'], 'No call wrote anything.');
    $this->assertStringContainsString('Below, the Spotlight block, data_surface_examples_spotlight, placed through the add operation in the content region of stark', (string) $page['placed']['#markup']);
    $this->assertStringContainsString('Below it, the Tonight block, data_surface_examples_section, placed the same way', (string) $page['section']['#markup']);
  }

  /**
   * Builds example 13's page.
   *
   * @return array
   *   The render array.
   */
  protected function thirteen(): array {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(ExamplesController::class)->step13();
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
      SpotlightResource::class,
      ComponentProps::class,
    ];
    foreach ($classes as $class) {
      $lines = CodeLines::count((string) (new \ReflectionClass($class))->getFileName());
      $this->assertGreaterThan(10, $lines, $class);
      $this->assertLessThanOrEqual(45, $lines, $class);
    }
  }

}
