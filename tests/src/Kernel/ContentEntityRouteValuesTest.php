<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * A basic page's add page with its title fixed and its body filled.
 *
 * The generic `create` of `entity.content:node.page`, served at a route
 * that fixes `title.value` and fills `body.value`: each a key of a
 * field's structure, attached at the field's name and invoked through
 * the field's own create, so each reaches the field's context.
 *
 * @see docs/forms.md#a-route-that-fixes-or-fills-keys
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityRouteValuesTest extends DataSurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'data_surface_route_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], [
      'access content',
      'create page content',
      'administer nodes',
      'use text format basic',
    ]);
    $name = 'data_surface_route_test.add_welcome_page';
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
  }

  /**
   * The title is drawn fixed, the body starts filled, and both are saved.
   */
  public function testTheTitleIsFixedAndTheBodyFilled(): void {
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('item', $form['title']['value']['#type']);
    $this->assertSame('Welcome', $form['title']['value']['#value']);
    $this->assertSame('Start here.', $form['body']['value']['#default_value']);

    $form_state = new FormState();
    $form_state->setValues([
      DataSurfaceOperationForm::SURFACE_KEY => [
        'field_description' => ['value' => 'Described.'],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(DataSurfaceOperationForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    $this->assertCount(1, $ids);
    $node = $storage->load(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame('Welcome', $node->label());
    $this->assertSame('Start here.', $node->get('body')->value);
  }

}
