<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\data_surface\Form\DataSurfaceFormBuilder;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface_examples\Resource\SpotlightResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests example 12's form where a component's props are hard to draw.
 *
 * Three findings on the development site, whose themes provide 312
 * components: a switch to a component whose prop has the name of the
 * stored one's and another type took the form down, and so did a prop
 * typed as several JSON types and a list of anything. The switch now
 * starts the new component from its defaults, a value of the wrong shape
 * is an error on its element, and the JSON widget draws what no other
 * widget does.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class SpotlightFormTest extends DataSurfaceKernelTestBase {

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
    'data_surface_widget_test',
  ];

  /**
   * The component whose `link` is a string, where the card's is a map.
   */
  protected const TEASER = 'data_surface_widget_test:teaser';

  /**
   * The component whose props only the JSON widget draws.
   */
  protected const MIXED = 'data_surface_widget_test:mixed';

  /**
   * The card's props as the installed spotlight stores them.
   */
  protected const CARD_PROPS = [
    'heading' => 'Doors open at half past six',
    'tone' => 'highlight',
    'link' => ['url' => 'https://example.com/tickets', 'label' => 'Find your ticket'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface', 'data_surface_examples', 'user']);
    // Anonymous, given the permission, so the form carries no token a
    // test would have to forge.
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('administer site configuration')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Puts example 12's route on the request stack.
   *
   * @param string $method
   *   The request method.
   */
  protected function serveSpotlight(string $method = 'GET'): void {
    $route_name = 'data_surface_examples.step12';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath(), $method);
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * Posts the form as the AJAX request a changed component sends.
   *
   * @param array $input
   *   The resource's input as the browser sent it.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the rebuild.
   */
  protected function switchComponent(array $input): FormStateInterface {
    $this->serveSpotlight('POST');
    $form_state = new FormState();
    $form_state->setUserInput([
      'form_id' => 'data_surface_operation_form_data_surface_examples_step12',
      'surface' => $input,
      '_triggering_element_name' => 'surface[component]',
    ]);
    $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Submits the form with the values given, as a programmatic save.
   *
   * @param array $values
   *   Raw values keyed by resource key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function save(array $values): FormStateInterface {
    $this->serveSpotlight('POST');
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Tests a switch from the card starts the teaser from its own defaults.
   *
   * The teaser's keys have the card's names, so the card's props, posted
   * as the page drew them, read as the teaser's by name: its `link` map
   * was read as the teaser's string and refused with an exception. The
   * card's elements say they were drawn for the card, so what they posted
   * goes, and the teaser is drawn from its defaults.
   */
  public function testSwitchingTheComponentDrawsTheNewOnesDefaults(): void {
    $state = $this->switchComponent([
      'component' => self::TEASER,
      'props' => self::CARD_PROPS,
      'slots' => ['body' => ['kind' => 'text', 'text' => 'Bring your ticket, on your phone or printed.']],
    ]);
    $this->assertTrue($state->isRebuilding());
    $this->assertSame([], $state->getErrors());
    $props = $state->getCompleteForm()[DataSurfaceOperationForm::SURFACE_KEY]['props'];
    $this->assertSame(['by' => 'component', 'for' => self::TEASER], $props[DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY]);
    $this->assertSame('textfield', $props['link']['#type']);
    $this->assertSame('https://example.com/program', $props['link']['#value']);
    $this->assertSame('Read the program', $props['heading']['#value']);
    $this->assertSame('plain', $props['tone']['#value']);
    // Nothing was written.
    $this->assertSame('data_surface_examples:card', $this->config('data_surface_examples.spotlight')->get('component'));
  }

  /**
   * Tests what a switched variant starts from, read off the form drawn.
   *
   * The chosen variant's defaults, unless what is stored was written for
   * it: then what is stored, as a reload draws it.
   */
  public function testSwitchedVariantStartsFromWhatReloadDraws(): void {
    $resources = $this->container->get('data_surface.resources');
    $surface = $resources->build(SpotlightResource::class, $resources->operation(SpotlightResource::class, 'configure'));
    $stored = $this->config('data_surface_examples.spotlight')->get();
    $drawn = static fn (string $for): array => [
      'surface' => [
        '#parents' => ['surface'],
        'props' => [
          '#parents' => ['surface', 'props'],
          DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY => ['by' => 'component', 'for' => $for],
        ],
      ],
    ];
    $teaser = ['heading' => 'Read the program', 'tone' => 'plain', 'link' => 'https://example.com/program'];

    // Drawn for the card, the teaser chosen: the teaser's defaults.
    $posted = ['component' => self::TEASER, 'props' => self::CARD_PROPS];
    [$switched, $input] = DataSurfaceFormBuilder::switchedVariants($surface, $drawn('data_surface_examples:card'), ['surface'], $posted, $stored);
    $this->assertSame(['props'], $switched);
    $this->assertSame($teaser, $input['props']);

    // Drawn for the teaser, the card chosen again: the card is stored, so
    // the card as stored.
    $posted = ['component' => 'data_surface_examples:card', 'props' => $teaser];
    [$switched, $input] = DataSurfaceFormBuilder::switchedVariants($surface, $drawn(self::TEASER), ['surface'], $posted, $stored);
    $this->assertSame(['props'], $switched);
    $this->assertSame(self::CARD_PROPS, $input['props']);

    // Drawn for what is chosen: nothing moves.
    $posted = ['component' => self::TEASER, 'props' => $teaser];
    [$switched, $input] = DataSurfaceFormBuilder::switchedVariants($surface, $drawn(self::TEASER), ['surface'], $posted, $stored);
    $this->assertSame([], $switched);
    $this->assertSame($teaser, $input['props']);
  }

  /**
   * Tests a value of the wrong shape is an error on its element.
   *
   * Core's text field posts the empty string for an array, so the shape
   * accept() cannot hold reaches it from an element drawn for another
   * shape: the card's link fieldset read as the teaser's string, as a
   * switch did before the elements said what they were drawn for. That
   * element, unmarked, is read here: the map is refused as the tool
   * refuses it, on the element, and the rest is accepted.
   */
  public function testWrongShapeIsAnErrorNotAnException(): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(SpotlightResource::class, 'configure');
    $surface = $resources->build(SpotlightResource::class, $context);
    $stored = $this->config('data_surface_examples.spotlight')->get();
    $builder = $this->container->get('data_surface.form_builder');
    $form_state = new FormState();
    $container = $builder->buildSurfaceForm($surface, $stored, $form_state);
    unset($container['props'][DataSurfaceFormBuilderInterface::DRAWN_FOR_KEY]);
    $form_state->setValues([
      'component' => self::TEASER,
      'props' => ['heading' => 'Read the program', 'tone' => 'plain', 'link' => ['url' => 'x', 'label' => 'y']],
    ]);
    $values = $builder->extractSurfaceValues($surface, $container, $form_state, $stored);
    $this->assertSame(['props][link'], array_keys($form_state->getErrors()));
    $this->assertSame('This value must be of type string, array given.', (string) $form_state->getErrors()['props][link']);
    $this->assertSame(self::TEASER, $values['component']);
    $this->assertSame('Read the program', $values['props']['heading']);

    // The pipeline refuses the same value with the same code at its door.
    $sent = ['component' => self::TEASER, 'props' => ['link' => ['url' => 'x']]];
    $result = $this->pipeline()->submit($surface, $sent, $resources->storage(SpotlightResource::class, $context, $surface), TRUE);
    $this->assertFalse($result->isValid());
    $violations = iterator_to_array($result->violations);
    $this->assertCount(1, $violations);
    [$refused] = $violations;
    $this->assertSame(['props', 'link', Violation::TYPE_MISMATCH], [$refused->key, $refused->path, $refused->code]);
  }

  /**
   * Tests the JSON widget draws and reads what no other widget does.
   *
   * A prop typed as several JSON types is read as `any`, and a list of
   * lists or of anything has no row widget: each is a text area of
   * JSON, the value decoded on save and held to the key's constraints,
   * and text that is not JSON an error on its element.
   */
  public function testTheJsonWidgetDrawsWhatNoOtherDoes(): void {
    $this->config('data_surface_examples.spotlight')->setData([
      'component' => self::MIXED,
      'props' => ['meta' => ['tone' => 'warm'], 'grid' => [['a', 'b']], 'classes' => ['wide']],
      'slots' => [],
    ])->save();
    $this->serveSpotlight();
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $props = $form[DataSurfaceOperationForm::SURFACE_KEY]['props'];
    foreach (['meta', 'grid', 'classes'] as $key) {
      $this->assertSame('textarea', $props[$key]['#type'], "$key is a text area.");
    }
    $this->assertSame("{\n    \"tone\": \"warm\"\n}", $props['meta']['#default_value']);
    $this->assertSame('Enter JSON: an object, a list, text in quotes or null.', (string) $props['meta']['#description']);
    $this->assertSame('Enter JSON: a list.', (string) $props['grid']['#description']);
    $this->assertSame('Enter JSON: a list.', (string) $props['classes']['#description']);

    $state = $this->save([
      'component' => self::MIXED,
      'props' => ['meta' => '"plain text"', 'grid' => '[["c"], ["d", "e"]]', 'classes' => ''],
    ]);
    $this->assertSame([], $state->getErrors());
    $this->assertSame([
      'meta' => 'plain text',
      'grid' => [['c'], ['d', 'e']],
      'classes' => ['wide'],
    ], $this->config('data_surface_examples.spotlight')->get('props'), 'An empty text area sent nothing, and a list keeps what it holds.');

    // Not JSON: an error on the element, and nothing written.
    $state = $this->save(['component' => self::MIXED, 'props' => ['meta' => '{"tone": warm}']]);
    $this->assertSame(['surface][props][meta'], array_keys($state->getErrors()));
    $this->assertSame('Not valid JSON: Syntax error.', (string) $state->getErrors()['surface][props][meta']);
    $this->assertSame('plain text', $this->config('data_surface_examples.spotlight')->get('props.meta'));

    // JSON of a type the key does not take: the key's own constraint.
    $state = $this->save(['component' => self::MIXED, 'props' => ['meta' => '12']]);
    $this->assertSame(['surface][props][meta'], array_keys($state->getErrors()));
  }

}
