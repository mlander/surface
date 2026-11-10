<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Routing\RouteMatch;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface_http\Access\ServedOperationAccessCheck;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_entity_test\Hook\EntityTestHooks;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the content entity example's own read: view access, every door.
 *
 * Both entity resources declare `read`, in place of the one the build
 * adds, which asks what `update` asks. The entity's access class answers
 * view access for it, and each field's answers view field access: an
 * account that may view a page and not edit it reads it through the tool
 * and through `GET`, and is refused `update`; a field it may view and not
 * edit is open in the read and locked in the update; a field it may not
 * view is in neither.
 *
 * Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityReadTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_entity_test'];

  /**
   * The page every test reads.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    // The first account is the super user; every test acts as another.
    $this->setUpCurrentUser(admin: TRUE);
    $this->addField('page', EntityTestHooks::LOCKED, 'string', [], [], 'Locked');
    $this->addField('page', EntityTestHooks::HIDDEN, 'string', [], [], 'Hidden');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display !== NULL);
    foreach ([EntityTestHooks::LOCKED, EntityTestHooks::HIDDEN] as $weight => $name) {
      $display->setComponent($name, ['weight' => 50 + $weight]);
    }
    $display->save();
    $this->node = Node::create([
      'type' => 'page',
      'title' => 'Stored',
      'field_description' => 'Stored description',
      'status' => 1,
      EntityTestHooks::LOCKED => 'Stored locked',
      EntityTestHooks::HIDDEN => 'Stored hidden',
    ]);
    $this->node->save();
  }

  /**
   * Runs one tool as the current account.
   *
   * @param string $id
   *   The plugin id.
   * @param array $inputs
   *   The inputs, by name.
   *
   * @return array{access: bool, success: bool, message: string, values: mixed}
   *   Whether the account may run it, whether it succeeded, its message
   *   and its values output.
   */
  protected function runTool(string $id, array $inputs = []): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance($id);
    assert($tool instanceof ToolInterface);
    foreach ($inputs as $name => $value) {
      $tool->setInputValue($name, $value);
    }
    $access = $tool->access();
    $tool->execute();
    $result = $tool->getResult();
    return [
      'access' => $access,
      'success' => $result->isSuccess(),
      'message' => PlainTextOutput::renderFromHtml((string) $result->getMessage()),
      'values' => $result->getContextValues()[OperationInputs::VALUES] ?? NULL,
    ];
  }

  /**
   * Answers the API route's access check for a path to one page.
   *
   * @param string $resource
   *   The resource id.
   * @param string $method
   *   The method.
   *
   * @return bool
   *   Whether the route lets the current account in.
   */
  protected function routeAllows(string $resource, string $method): bool {
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    $ids = ['resource' => $resource, 's1' => (string) $this->node->id(), 's2' => '', 's3' => '', 's4' => '', 's5' => ''];
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    $request = Request::create('/surface-api/' . $resource . '/' . $this->node->id(), $method);
    return $check->access($route_match, $this->container->get('current_user'), $request)->isAllowed();
  }

  /**
   * Tests both resources declare their read, read only, taking the id.
   */
  public function testBothResourcesDeclareTheirRead(): void {
    $registry = $this->container->get('surface.resource_registry');
    foreach (['entity.content:node.page', 'entity.content:node'] as $resource) {
      $read = $registry->getOperation($resource, 'read');
      $this->assertTrue($read->readsOne(), $resource);
      $this->assertFalse($read->isDefaultRead(), $resource . ': declared, not the one the build adds.');
      $this->assertNull($read->permission, $resource . ': core\'s view access is its access, as core\'s edit access is the update\'s.');
      $this->assertSame(['id'], array_map(static fn ($parameter): string => $parameter->name, $read->parameters), $resource);
      $this->assertTrue($read->parameters[0]->namesKey, $resource . ': the identity key, whose value the storage loads the entity by.');
    }
    // Each field item reads too, so a read reaches each field's own access.
    $this->assertTrue($registry->getOperation('field.item:node.page.title', 'read')->readOnly);
  }

  /**
   * Tests an account that may view a page and not edit it reads it.
   *
   * Through the read tool of each resource, and through GET, whose route
   * lets it in; the update's tool and PATCH refuse it.
   */
  public function testViewerReadsThroughEveryDoorAndIsRefusedTheUpdate(): void {
    $this->setUpCurrentUser([], ['access content']);
    $id = (int) $this->node->id();

    $page = $this->runTool('surface:entity.content:node.page:read', ['id' => (string) $id]);
    $this->assertTrue($page['access'], $page['message']);
    $this->assertTrue($page['success'], $page['message']);
    $this->assertSame('Stored', $page['values']['title']['value']);
    $this->assertSame('Stored description', $page['values']['field_description']['value']);
    $this->assertSame('Stored locked', $page['values'][EntityTestHooks::LOCKED]['value'], 'A field it may view is read.');
    $this->assertArrayNotHasKey(EntityTestHooks::HIDDEN, $page['values'], 'A field it may not view is not.');

    // The entity type's read fixes the bundle from the page, and answers
    // the same fields under fields.
    $any = $this->runTool('surface:entity.content:node:read', ['id' => (string) $id]);
    $this->assertTrue($any['success'], $any['message']);
    $this->assertSame('page', $any['values']['bundle']);
    $this->assertSame($page['values'], $any['values']['fields']);

    $update = $this->runTool('surface:entity.content:node.page:update', [
      'id' => (string) $id,
      OperationInputs::VALUES => [],
    ]);
    $this->assertFalse($update['access']);
    $this->assertFalse($update['success']);

    // GET reads it; PATCH is refused by the route.
    $this->assertTrue($this->routeAllows('entity.content:node.page', 'GET'));
    $this->assertFalse($this->routeAllows('entity.content:node.page', 'PATCH'));
    $this->assertTrue($this->routeAllows('entity.content:node', 'GET'));
    $envelope = $this->servedJson('GET', '/surface-api/entity.content:node.page/' . $id);
    $this->assertSame('read', $envelope['operation']);
    $this->assertSame('Stored', $envelope['values']['title']['value']);
    $this->assertSame('Stored locked', $envelope['values'][EntityTestHooks::LOCKED]['value']);
    $this->assertArrayNotHasKey(EntityTestHooks::HIDDEN, $envelope['values']);
    $this->assertArrayNotHasKey(EntityTestHooks::HIDDEN, $envelope['schema']['properties']);
    $this->assertSame('read', $envelope['schema']['x-surface']['operation']['kind']);
  }

  /**
   * Tests an account that may not view the page is refused the read.
   */
  public function testAnAccountThatMayNotViewIsRefused(): void {
    $this->node->setUnpublished()->save();
    $this->setUpCurrentUser([], ['access content']);
    $read = $this->runTool('surface:entity.content:node.page:read', ['id' => (string) $this->node->id()]);
    $this->assertFalse($read['access']);
    $this->assertFalse($read['success']);
    $this->assertSame([], $read['values'], 'Nothing read, in the one result shape.');
    $this->assertFalse($this->routeAllows('entity.content:node.page', 'GET'));
  }

  /**
   * Tests a field an editor may view and not edit: open read, locked update.
   */
  public function testViewOnlyFieldIsOpenInTheReadAndLockedInTheUpdate(): void {
    $this->setUpCurrentUser([], ['access content', 'edit any page content']);
    $resources = $this->container->get('surface.resources');
    $read = $resources->buildOperation('entity.content:node.page', 'read', ['id' => $this->node->id()]);
    $update = $resources->buildOperation('entity.content:node.page', 'update', ['id' => $this->node->id()]);
    $this->assertTrue($read->getDefinitions()->has(EntityTestHooks::LOCKED));
    $this->assertFalse($read->isLocked(EntityTestHooks::LOCKED), 'A read answers what the account may view.');
    $this->assertTrue($update->isLocked(EntityTestHooks::LOCKED), 'An update takes no value for a field the account may not edit.');
    $this->assertFalse($read->getDefinitions()->has(EntityTestHooks::HIDDEN));
    $this->assertFalse($update->getDefinitions()->has(EntityTestHooks::HIDDEN));

    // The update's tool takes no value for it: one sent is noted, not taken.
    $write = $this->runTool('surface:entity.content:node.page:update', [
      'id' => (string) $this->node->id(),
      OperationInputs::VALUES => [EntityTestHooks::LOCKED => ['value' => 'Overwritten']],
    ]);
    $this->assertTrue($write['success'], $write['message']);
    $this->assertSame('Stored locked', Node::load($this->node->id())?->get(EntityTestHooks::LOCKED)->value);
  }

}
