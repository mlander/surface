<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests the generic form against the node type example's routes.
 *
 * The example has no form class. Its two routes name the content type
 * resource, an operation and a cosmetic layer; the update route's {type}
 * parameter is the update operation's $type. Everything else (the
 * context, the access answer, the built resource, the storage, the three
 * pipeline stages, the message and the redirect) is
 * SurfaceOperationForm, which knows nothing about content types.
 *
 * So what this has to show is that the generic path does what the
 * bespoke one did: the same values reach the same two destinations, the
 * lock still refuses a tampered machine name, a violation is still
 * flagged on the element that carries it, and the message and the
 * redirect are still the example's own. The routes are read from the route
 * provider rather than written out here, so what is asserted is what the
 * module ships.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SurfaceOperationFormTest extends SurfaceKernelTestBase {

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
    'surface',
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
    // User 1 bypasses every access check, which is what the example's two
    // routes and the form's own floor are both answering for here.
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Puts one of the example's own routes on the request stack.
   *
   * The form reads where it is from the route it is served at, so a test
   * of the form is a test of the routing file: the route object comes
   * from the route provider rather than being written out again.
   *
   * @param string $route_name
   *   The route to serve.
   * @param string|null $type
   *   The raw value of the update route's type parameter.
   */
  protected function serveRoute(string $route_name, ?string $type = NULL): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    // The kernel's own mock session, carried onto the request this test
    // serves from: a form built for an authenticated account asks for a
    // CSRF token, and the base class asks the current request for its
    // session again when it tears the test down.
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    if ($type !== NULL) {
      $request->attributes->set('type', NodeType::load($type));
      $request->attributes->set('_raw_variables', new InputBag(['type' => $type]));
    }
    $stack->push($request);
  }

  /**
   * Builds the generic form as the route it is being served at.
   *
   * @return array
   *   The built form.
   */
  protected function buildTheForm(): array {
    return $this->container->get('form_builder')->getForm(SurfaceOperationForm::class);
  }

  /**
   * Submits the generic form with raw values, as a browser would.
   *
   * @param array $values
   *   Raw values keyed by resource key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([SurfaceOperationForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')
      ->submitForm(SurfaceOperationForm::class, $form_state);
    return $form_state;
  }

  /**
   * Reads the status messages the submission left behind.
   *
   * Stripped of the placeholder markup a message with a %name argument
   * renders, so what is asserted is the sentence a person reads, which
   * is what the functional test asserts too.
   *
   * @return string[]
   *   The messages, as text.
   */
  protected function statusMessages(): array {
    return array_map(
      static fn ($message): string => strip_tags((string) $message),
      $this->container->get('messenger')->messagesByType('status'),
    );
  }

  /**
   * Tests the create route end to end, cosmetics and all.
   */
  public function testTheAddRouteStoresEveryDestination(): void {
    $this->serveRoute('surface_examples_node_type.create');

    $form = $this->buildTheForm();
    // Every declared key is on the page, at the value path the generic
    // form nests the built resource at.
    $this->assertArrayHasKey('name', $form[SurfaceOperationForm::SURFACE_KEY]);
    $this->assertArrayHasKey('title_label', $form[SurfaceOperationForm::SURFACE_KEY]);
    // The declaration's defaults are the form's values, which for an add
    // means the values a new content type starts from.
    $this->assertSame('Title', $form[SurfaceOperationForm::SURFACE_KEY]['title_label']['#default_value']);
    $this->assertTrue($form[SurfaceOperationForm::SURFACE_KEY]['status']['#default_value']);
    // The resource's arrangement drew its groups, and the key stays where
    // its value is posted; the cosmetic layer ran: the tabs exist and hold
    // the groups, and the machine name got its mirror, neither of which
    // the generic form knows about.
    $surface = $form[SurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('details', $surface['@group.workflow']['#type']);
    $this->assertSame('Publishing options', (string) $surface['@group.workflow']['#title']);
    $this->assertSame('workflow', $surface['promote'][SurfaceFormBuilderInterface::GROUP_KEY]['id']);
    $this->assertArrayNotHasKey('#group', $surface['promote']);
    $this->assertSame('vertical_tabs', $surface['additional_settings']['#type']);
    $this->assertSame('surface][additional_settings', $surface['@group.workflow']['#group']);
    $this->assertSame('machine_name', $surface['type']['#type']);

    $form_state = $this->submitTheForm([
      'name' => 'Recipe',
      'type' => 'recipe',
      'description' => 'Cooking instructions.',
      'title_label' => 'Recipe name',
      'preview_mode' => '2',
      'promote' => 1,
    ]);

    $this->assertSame([], $form_state->getErrors());
    // The config entity half of the composite storage.
    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $type);
    $this->assertSame('Recipe', $type->label());
    $this->assertSame('Cooking instructions.', $type->getDescription());
    $this->assertSame(2, $type->getPreviewMode(FALSE)->value);
    // And the base field override half, which the add storage could only
    // reach once the values had named the bundle. Read as the config it
    // is as well as through the field manager: a definition comes from a
    // cache with a process-long memo behind it, and what was actually
    // stored is the thing a second process, a deployment or an export
    // would see.
    $title_override = BaseFieldOverride::load('node.recipe.title');
    $this->assertInstanceOf(BaseFieldOverride::class, $title_override);
    $this->assertSame('Recipe name', (string) $title_override->label());
    $promote_override = BaseFieldOverride::load('node.recipe.promote');
    $this->assertInstanceOf(BaseFieldOverride::class, $promote_override);
    $this->assertTrue((bool) $promote_override->getDefaultValueLiteral()[0]['value']);
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'recipe');
    $this->assertSame('Recipe name', (string) $fields['title']->getLabel());
    $this->assertTrue((bool) $fields['promote']->getDefaultValueLiteral()[0]['value']);

    // The example's own message and the example's own redirect, both from the
    // cosmetic layer rather than from the form.
    $this->assertContains('The content type Recipe has been added.', $this->statusMessages());
    // A programmed submission is not redirected, and form state says so
    // by refusing to hand the redirect over, so the flag is dropped to
    // read what the cosmetic layer set.
    $form_state->setProgrammed(FALSE);
    $this->assertSame('entity.node_type.collection', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Tests the update route, including the lock against a tampered value.
   */
  public function testTheEditRouteUpdatesAndKeepsTheMachineName(): void {
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $this->serveRoute('surface_examples_node_type.update', 'recipe');

    $form = $this->buildTheForm();
    // The route's type parameter reached the update operation as the
    // content type, so the machine name is known: still advertised, its
    // value shown, and fixed.
    $this->assertSame('recipe', $form[SurfaceOperationForm::SURFACE_KEY]['type']['#default_value']);
    $this->assertTrue($form[SurfaceOperationForm::SURFACE_KEY]['type']['#disabled']);
    // No machine name mirror on edit: there is nothing to mirror.
    $this->assertNotSame('machine_name', $form[SurfaceOperationForm::SURFACE_KEY]['type']['#type'] ?? NULL);

    $form_state = $this->submitTheForm([
      'name' => 'Renamed',
      'type' => 'tampered',
      'title_label' => 'Dish name',
    ]);

    $this->assertSame([], $form_state->getErrors());
    $this->assertNull(NodeType::load('tampered'));
    $this->assertSame('Renamed', NodeType::load('recipe')->label());
    $fields = $this->container->get('entity_field.manager')->getFieldDefinitions('node', 'recipe');
    $this->assertSame('Dish name', (string) $fields['title']->getLabel());
    $this->assertContains('The content type Renamed has been updated.', $this->statusMessages());
  }

  /**
   * Tests a violation reaching the element that carries the value.
   *
   * The element and the message both, because only the pair says the
   * check that ran was the resource's. A form state keeps the FIRST
   * error set on an element and drops every later one, so an element
   * type borrowed for presentation, such as the machine name element
   * the form draws here, answering first with a sentence of its own
   * would leave the resource's violation nowhere to go. Asserting the element
   * alone cannot tell that apart, which is how it reached a browser
   * before anything noticed.
   */
  public function testViolationsAreFlaggedOnTheirOwnElements(): void {
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $this->serveRoute('surface_examples_node_type.create');

    $form_state = $this->submitTheForm([
      'name' => 'Second recipe',
      'type' => 'recipe',
      'title_label' => 'Title',
    ]);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('surface][type', $errors);
    // Printed at the top of the page, so named for its field.
    $this->assertSame(
      'Machine name: A content type named recipe already exists.',
      strip_tags((string) $errors['surface][type']),
    );
    $this->assertSame('Recipe', NodeType::load('recipe')->label());
    $this->assertSame([], $this->statusMessages());
  }

  /**
   * Tests that a forbidden answer refuses the form outright.
   *
   * The floor the generic form puts under every route: the operation's
   * answer, asked before anything is built. The example's routes ask the
   * same thing as their requirement and core answers it first, so this
   * is about the caller that arrived some other way.
   */
  public function testRefusedOperationRefusesTheForm(): void {
    $this->serveRoute('surface_examples_node_type.create');
    $stranger = $this->createUser();
    $this->container->get('current_user')->setAccount($stranger);

    $this->expectException(AccessDeniedHttpException::class);
    $this->buildTheForm();
  }

}
