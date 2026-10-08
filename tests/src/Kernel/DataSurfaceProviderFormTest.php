<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceProviderForm;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Tests the generic form against the node type demo's routes.
 *
 * The demo has no form class. Its two routes name the content type
 * surface, a situation and a cosmetic layer; the edit route's {type}
 * parameter is the edit situation's $type. Everything else — the
 * context, the access answer, the surface, the target, the three
 * pipeline stages, the message and the redirect — is
 * DataSurfaceProviderForm, which knows nothing about content types.
 *
 * So what this has to show is that the generic path does what the
 * bespoke one did: the same values reach the same two destinations, the
 * lock still refuses a tampered machine name, a violation is still
 * flagged on the element that carries it, and the message and the
 * redirect are still the demo's own. The routes are read from the route
 * provider rather than written out here, so what is asserted is what the
 * module ships.
 *
 * The old provider spelling of the same form is still served, for the
 * deprecated provider, on a route this test builds itself: no module
 * ships one any more.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class DataSurfaceProviderFormTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_demo_node_type',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['field', 'node']);
    // User 1 bypasses every access check, which is what the demo's two
    // routes and the form's own floor are both answering for here.
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Puts one of the demo's own routes on the request stack.
   *
   * The form reads where it is from the route it is served at, so a test
   * of the form is a test of the routing file: the route object comes
   * from the route provider rather than being written out again.
   *
   * @param string $route_name
   *   The route to serve.
   * @param string|null $type
   *   The raw value of the edit route's type parameter.
   * @param \Symfony\Component\Routing\Route|null $route
   *   A route to serve under that name instead of the one shipped.
   */
  protected function serveRoute(string $route_name, ?string $type = NULL, ?Route $route = NULL): void {
    $route ??= $this->container->get('router.route_provider')->getRouteByName($route_name);
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
      $parameter = $route->hasDefault(DataSurfaceProviderForm::SUBJECT) ? 'node_type' : 'type';
      $request->attributes->set($parameter, NodeType::load($type));
      $request->attributes->set('_raw_variables', new InputBag([$parameter => $type]));
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
    return $this->container->get('form_builder')->getForm(DataSurfaceProviderForm::class);
  }

  /**
   * Submits the generic form with raw values, as a browser would.
   *
   * @param array $values
   *   Raw values keyed by surface key.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state after the submission.
   */
  protected function submitTheForm(array $values): FormStateInterface {
    $form_state = new FormState();
    $form_state->setValues([DataSurfaceProviderForm::SURFACE_KEY => $values]);
    $this->container->get('form_builder')
      ->submitForm(DataSurfaceProviderForm::class, $form_state);
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
   * Tests the add route end to end, cosmetics and all.
   */
  public function testTheAddRouteStoresEveryDestination(): void {
    $this->serveRoute('data_surface_demo_node_type.add');

    $form = $this->buildTheForm();
    // Every declared key is on the page, at the value path the generic
    // form nests its surface at.
    $this->assertArrayHasKey('name', $form[DataSurfaceProviderForm::SURFACE_KEY]);
    $this->assertArrayHasKey('title_label', $form[DataSurfaceProviderForm::SURFACE_KEY]);
    // The declaration's defaults are the form's values, which for an add
    // means the values a new content type starts from.
    $this->assertSame('Title', $form[DataSurfaceProviderForm::SURFACE_KEY]['title_label']['#default_value']);
    $this->assertTrue($form[DataSurfaceProviderForm::SURFACE_KEY]['status']['#default_value']);
    // The cosmetic layer ran: the tabs exist and the machine name got
    // its mirror, neither of which the generic form knows about.
    $this->assertSame('vertical_tabs', $form[DataSurfaceProviderForm::SURFACE_KEY]['additional_settings']['#type']);
    $this->assertSame('machine_name', $form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#type']);
    $this->assertSame('surface][workflow', $form[DataSurfaceProviderForm::SURFACE_KEY]['promote']['#group']);

    $form_state = $this->submitTheForm([
      'name' => 'Recipe',
      'type' => 'recipe',
      'description' => 'Cooking instructions.',
      'title_label' => 'Recipe name',
      'preview_mode' => '2',
      'promote' => 1,
    ]);

    $this->assertSame([], $form_state->getErrors());
    // The config entity half of the composite target.
    $type = NodeType::load('recipe');
    $this->assertInstanceOf(NodeType::class, $type);
    $this->assertSame('Recipe', $type->label());
    $this->assertSame('Cooking instructions.', $type->getDescription());
    $this->assertSame(2, $type->getPreviewMode(FALSE)->value);
    // And the base field override half, which the add target could only
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

    // The demo's own message and the demo's own redirect, both from the
    // cosmetic layer rather than from the form.
    $this->assertContains('The content type Recipe has been added.', $this->statusMessages());
    // A programmed submission is not redirected, and form state says so
    // by refusing to hand the redirect over, so the flag is dropped to
    // read what the cosmetic layer set.
    $form_state->setProgrammed(FALSE);
    $this->assertSame('entity.node_type.collection', $form_state->getRedirect()->getRouteName());
  }

  /**
   * Tests the edit route, including the lock against a tampered value.
   */
  public function testTheEditRouteUpdatesAndKeepsTheMachineName(): void {
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $this->serveRoute('data_surface_demo_node_type.edit', 'recipe');

    $form = $this->buildTheForm();
    // The route's type parameter reached the edit situation as the
    // content type, so the machine name is known: still advertised, its
    // value shown, and fixed.
    $this->assertSame('recipe', $form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#default_value']);
    $this->assertTrue($form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#disabled']);
    // No machine name mirror on edit: there is nothing to mirror.
    $this->assertNotSame('machine_name', $form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#type'] ?? NULL);

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
   * check that ran was the surface's. A form state keeps the FIRST
   * error set on an element and drops every later one, so an element
   * type borrowed for presentation — the machine name the cosmetic
   * layer swaps in here — answers first with a sentence of its own and
   * leaves the surface's violation nowhere to go. Asserting the element
   * alone cannot tell that apart, which is how it reached a browser
   * before anything noticed.
   */
  public function testViolationsAreFlaggedOnTheirOwnElements(): void {
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $this->serveRoute('data_surface_demo_node_type.add');

    $form_state = $this->submitTheForm([
      'name' => 'Second recipe',
      'type' => 'recipe',
      'title_label' => 'Title',
    ]);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('surface][type', $errors);
    $this->assertSame(
      'A content type with the machine name recipe already exists.',
      strip_tags((string) $errors['surface][type']),
    );
    $this->assertSame('Recipe', NodeType::load('recipe')->label());
    $this->assertSame([], $this->statusMessages());
  }

  /**
   * Tests that a forbidden answer refuses the form outright.
   *
   * The floor the generic form puts under every route: the situation's
   * answer, asked before anything is built. The demo's routes ask the
   * same thing as their requirement and core answers it first, so this
   * is about the caller that arrived some other way.
   */
  public function testRefusedSituationRefusesTheForm(): void {
    $this->serveRoute('data_surface_demo_node_type.add');
    $stranger = $this->createUser();
    $this->container->get('current_user')->setAccount($stranger);

    $this->expectException(AccessDeniedHttpException::class);
    $this->buildTheForm();
  }

  /**
   * Tests the provider spelling, which still serves an old provider.
   *
   * No module ships a provider route any more; the deprecated content
   * type provider is served here on a route this test builds, with the
   * four defaults the provider spelling reads, until step 5 deletes it.
   */
  public function testTheProviderSpellingStillServesTheOldProvider(): void {
    NodeType::create(['type' => 'recipe', 'name' => 'Recipe'])->save();
    $route = new Route('/test/node-type/{node_type}/provider-edit', [
      '_form' => DataSurfaceProviderForm::class,
      DataSurfaceProviderForm::PROVIDER => 'data_surface_demo_node_type.provider',
      DataSurfaceProviderForm::OPERATION => 'edit',
      DataSurfaceProviderForm::SUBJECT => 'node_type',
      DataSurfaceProviderForm::COSMETICS => 'data_surface_demo_node_type.form_cosmetics',
    ]);
    $this->serveRoute('data_surface_test.provider_edit', 'recipe', $route);

    $form = $this->buildTheForm();
    $this->assertSame('recipe', $form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#default_value']);
    $this->assertTrue($form[DataSurfaceProviderForm::SURFACE_KEY]['type']['#disabled']);

    $form_state = $this->submitTheForm(['name' => 'Renamed', 'type' => 'tampered', 'title_label' => 'Dish name']);
    $this->assertSame([], $form_state->getErrors());
    $this->assertNull(NodeType::load('tampered'));
    $this->assertSame('Renamed', NodeType::load('recipe')->label());
    $this->assertContains('The content type Renamed has been updated.', $this->statusMessages());
  }

}
