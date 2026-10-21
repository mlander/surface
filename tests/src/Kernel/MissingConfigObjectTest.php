<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_first\Resource\NewsletterResource;
use Drupal\data_surface_first\Storage\NewsletterStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a resource whose config object was never installed.
 *
 * A site that enabled a module before one of its config objects shipped
 * has no such object. Its resource still works: the form starts from the
 * declared defaults, a write is held to the config schema, and the first
 * write creates the object.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class MissingConfigObjectTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'data_surface',
    'data_surface_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // No installConfig(): the newsletter settings were never installed.
    $this->setUpCurrentUser(permissions: ['administer data_surface_first']);
  }

  /**
   * Builds the newsletter settings form as its route serves it.
   *
   * @return array
   *   The form's data surface container.
   */
  protected function form(): array {
    $route_name = 'data_surface_first.newsletter';
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    return $this->container->get('form_builder')->buildForm(DataSurfaceOperationForm::class, $form_state)[DataSurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Tests the form starts from the defaults and the first write creates.
   */
  public function testTheFirstWriteCreatesTheObject(): void {
    $this->assertTrue($this->config(NewsletterStorage::CONFIG)->isNew());

    $form = $this->form();
    $this->assertSame('weekly', $form['frequency']['#default_value']);
    $this->assertSame(500, $form['max_recipients']['#default_value']);
    $this->assertEmpty($form['sender_name']['#default_value'] ?? NULL);

    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation(NewsletterResource::class, 'configure');
    $surface = $resources->build(NewsletterResource::class, $context);
    $storage = $resources->storage(NewsletterResource::class, $context, $surface);
    $this->assertSame([], $storage->load($surface), 'Nothing stored, so every key starts from its default.');

    $raw = $this->container->get('class_resolver')->getInstanceFromDefinition(NewsletterStorage::class);
    $this->assertFalse($raw->mayClear($context, 'frequency'), 'The schema answers for an object that does not exist yet.');
    $this->assertTrue($raw->mayClear($context, 'max_recipients'));

    // Held to the config schema: a recipient count above its bound is
    // refused, and nothing is written.
    $refused = $this->pipeline()->submit($surface, [
      'sender_name' => 'The newsletter',
      'sender_email' => 'news@example.com',
      'max_recipients' => 20000,
    ], $storage);
    $this->assertFalse($refused->isValid());
    $this->assertSame(['max_recipients'], $refused->violations->keys());
    $this->assertTrue($this->config(NewsletterStorage::CONFIG)->isNew());

    $result = $this->pipeline()->submit($surface, [
      'sender_name' => 'The newsletter',
      'sender_email' => 'news@example.com',
    ], $storage);
    $this->assertSame([], $result->violations->keys());
    $this->assertTrue($result->committed);
    $this->container->get('config.factory')->reset();
    $this->assertSame([
      'sender_name' => 'The newsletter',
      'sender_email' => 'news@example.com',
      'frequency' => 'weekly',
      'max_recipients' => 500,
    ], $this->config(NewsletterStorage::CONFIG)->getRawData());
  }

}
