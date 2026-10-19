<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface\Pipeline\ViolationSummary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a rule's own refusal is what a person is told, at every door.
 *
 * Two ways it used to be lost, both found by the first fresh-eyes run: a
 * message written with Symfony's `{{ max }}` reached the person with the
 * braces in it, because core's translator respells the parameter and not
 * the message; and core's number element checked #min and #max itself,
 * before the pipeline, so its wording replaced the rule's.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RefusalMessagesTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_first',
  ];

  /**
   * The custom message, as Symfony documents placeholders.
   */
  protected const MESSAGE = 'At most {{ max }} recipients per send until a legal notice is written.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_first']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds a data surface whose Range carries the custom message.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The data surface.
   */
  protected function cappedSurface(): DataSurfaceInterface {
    return new DataSurface(DefinitionMap::fromArrays(definitions: [
      'max_recipients' => DataDefinition::create('integer')
        ->setLabel('Maximum recipients per send')
        ->addConstraint('Range', ['min' => 1, 'max' => 1000, 'notInRangeMessage' => self::MESSAGE]),
    ]));
  }

  /**
   * Tests the pipeline fills a Symfony placeholder in, for every door.
   *
   * The form, the tool's refusal and the served contract all read the
   * pipeline's violations, and each renders the message at its boundary.
   */
  public function testSymfonyPlaceholdersAreFilledIn(): void {
    $violations = $this->pipeline()->validate($this->cappedSurface(), ['max_recipients' => 5000]);
    $messages = array_map(static fn ($violation): string => (string) $violation->message, iterator_to_array($violations));

    $this->assertSame(['At most <em class="placeholder">1000</em> recipients per send until a legal notice is written.'], array_values($messages), 'Escaped and emphasized as a %placeholder is.');
    $this->assertSame('At most 1000 recipients per send until a legal notice is written.', PlainTextOutput::renderFromHtml(reset($messages)), 'What the tool and the served contract print.');
    $this->assertStringContainsString('max_recipients: At most 1000 recipients', strip_tags(ViolationSummary::fromViolations($violations)));
  }

  /**
   * Tests the Tool API's own check of the same constraint fills it too.
   *
   * The tool's values input carries the constraint across, and the Tool
   * API validates it before the pipeline runs, through core's translator.
   */
  public function testTheToolsOwnCheckFillsThemIn(): void {
    $map = $this->container->get('data_surface_tool.input_definitions')->fromSurface($this->cappedSurface(), 'Capped', '');
    $constraints = $map->getPropertyDefinition('max_recipients')->getConstraints();
    $this->assertSame('At most %max recipients per send until a legal notice is written.', $constraints['Range']['notInRangeMessage']);

    $definition = DataDefinition::create('integer')->setConstraints($constraints);
    $violations = $this->container->get('typed_data_manager')->create($definition, 5000)->validate();
    $this->assertSame('At most 1000 recipients per send until a legal notice is written.', strip_tags((string) $violations[0]->getMessage()));
  }

  /**
   * Tests a constraint declared without options crosses to the tool.
   *
   * NotNull and NotBlank are declared as NULL; the tool definition keeps
   * them as they are, with no warning from reading options they have not.
   */
  public function testAnOptionsLessConstraintCrosses(): void {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: [
      'name' => DataDefinition::create('string')->setLabel('Name')->addConstraint('NotNull'),
    ]));
    $map = $this->container->get('data_surface_tool.input_definitions')->fromSurface($surface, 'Named', '');
    $constraints = $map->getPropertyDefinition('name')->getConstraints();
    $this->assertArrayHasKey('NotNull', $constraints);
    $this->assertNull($constraints['NotNull']);
  }

  /**
   * Tests the number field shows the rule's message, not the element's.
   */
  public function testTheRulesMessageReachesTheNumberField(): void {
    $route_name = 'data_surface_first.newsletter';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);

    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $element = $form[DataSurfaceOperationForm::SURFACE_KEY]['max_recipients'];
    $this->assertSame(10000, $element['#max'], 'The bound still reaches the browser, as a hint.');
    $this->assertSame([], $element['#element_validate']);

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => [
        'sender_name' => 'The newsletter',
        'sender_email' => 'newsletter@example.com',
        'frequency' => 'weekly',
        'max_recipients' => '20000',
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);

    $errors = array_map(static fn ($error): string => strip_tags((string) $error), $form_state->getErrors());
    $this->assertSame(['surface][max_recipients' => 'This value should be between 1 and 10000.'], $errors);
    $this->assertSame(500, $this->config('data_surface_first.newsletter')->get('max_recipients'), 'Nothing was written.');
  }

  /**
   * Tests the text field shows the Length's message, not core's maxlength.
   *
   * ROADMAP item 10: core checks `#maxlength` before any form level
   * handler and keeps its own error. The maximum reaches the browser as
   * an attribute, and the rule's violation is the one message.
   */
  public function testTheRulesMessageReachesTheTextField(): void {
    $route_name = 'data_surface_first.newsletter';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);

    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $element = $form[DataSurfaceOperationForm::SURFACE_KEY]['sender_name'];
    $this->assertSame(64, $element['#attributes']['maxlength'], 'The maximum still reaches the browser, as a hint.');
    $this->assertNull($element['#maxlength'], 'Core does not check it beside the rule.');
    $this->assertNull($form[DataSurfaceOperationForm::SURFACE_KEY]['sender_email']['#maxlength'], 'Nor its own default for an element type.');

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => [
        'sender_name' => str_repeat('n', 65),
        'sender_email' => 'newsletter@example.com',
        'frequency' => 'weekly',
        'max_recipients' => '500',
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);

    $errors = array_map(static fn ($error): string => strip_tags((string) $error), $form_state->getErrors());
    $this->assertSame(['surface][sender_name' => 'This value is too long. It should have 64 characters or less.'], $errors);
    $this->assertSame('The newsletter', $this->config('data_surface_first.newsletter')->get('sender_name'), 'Nothing was written.');
  }

  /**
   * Tests a required field says the resource's words, not core's.
   *
   * The third fresh-eyes run (entry 21): core checks #required before any
   * form level handler and keeps its own "Name field is required." The
   * element is still drawn required, the browser's `required` attribute
   * and the label's marker, and the pipeline's violation is the message.
   */
  public function testTheRulesMessageReachesRequiredField(): void {
    $this->onRoute('data_surface_first.newsletter');
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $element = $form[DataSurfaceOperationForm::SURFACE_KEY]['sender_name'];
    $this->assertFalse($element['#required'], 'Core does not check it beside the rule.');
    $this->assertTrue($element[DataSurfaceFormBuilderInterface::REQUIRED_KEY]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($element);
    $this->assertMatchesRegularExpression('/<input[^>]*name="surface\[sender_name\]"[^>]*required="required"/', $html, 'The browser is still told, as a hint.');
    $this->assertStringContainsString('form-required', $html, 'The label keeps its marker.');

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => [
        'sender_name' => '',
        'sender_email' => 'newsletter@example.com',
        'frequency' => 'weekly',
        'max_recipients' => '500',
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    $errors = array_map(static fn ($error): string => strip_tags((string) $error), $form_state->getErrors());
    $this->assertSame(['surface][sender_name' => 'Sender name is required.'], $errors);
    $this->assertSame('The newsletter', $this->config('data_surface_first.newsletter')->get('sender_name'), 'Nothing was written.');
  }

  /**
   * Tests a key a rule made required says the rule's condition on the form.
   *
   * The parcel stores an insured parcel, so its form draws the signature
   * required; left empty, the refusal is the pipeline's, naming what the
   * rule read, as the tool's is.
   */
  public function testRuleMadeRequiredSaysItsConditionOnTheForm(): void {
    $this->container->get('module_installer')->install(['data_surface_resource_test']);
    $this->config('data_surface_resource_test.parcel')->set('insured', TRUE)->set('signature', 'Ann')->save();
    $this->onRoute('data_surface_resource_test.parcel');
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class);
    $this->assertTrue($form[DataSurfaceOperationForm::SURFACE_KEY]['signature'][DataSurfaceFormBuilderInterface::REQUIRED_KEY]);

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => ['signature' => ''] + $this->config('data_surface_resource_test.parcel')->getRawData(),
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    $errors = array_map(static fn ($error): string => strip_tags((string) $error), $form_state->getErrors());
    $this->assertSame(['surface][signature' => 'Signed for by is required when Insured is on and Declared value is 100.'], $errors);
    $this->assertSame('Ann', $this->config('data_surface_resource_test.parcel')->get('signature'), 'Nothing was written.');
  }

  /**
   * Makes a route the request being handled, as a page request would.
   *
   * @param string $route_name
   *   The route.
   */
  protected function onRoute(string $route_name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

}
