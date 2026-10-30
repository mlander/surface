<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\Http\ServedOperationAccessCheck;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * A sealed build is made once per request, and again when what it read changes.
 *
 * Kept by resource, context, kind and account kind, dropped when a tag it
 * recorded is invalidated in the same request; a tool call or a schema
 * request builds each kind once, and the route's access check builds
 * nothing at all.
 *
 * Decision: see docs/decisions.md#a-build-is-not-cached-across-requests.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BuildMemoTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Runs something and says how many builds it made and reused.
   *
   * @param callable $run
   *   What to run.
   *
   * @return array{0: int, 1: int}
   *   The builds made, and the builds answered from the memo.
   */
  protected function counted(callable $run): array {
    $before = $this->resources()->buildCounts();
    $run();
    $after = $this->resources()->buildCounts();
    return [$after['built'] - $before['built'], $after['reused'] - $before['reused']];
  }

  /**
   * Tests that a build asked twice in a request is made once.
   */
  public function testBuildIsMadeOncePerRequest(): void {
    $context = $this->resources()->operation('entity.content:node.page', 'create');
    $first = NULL;
    $second = NULL;
    $this->assertSame([1, 1], $this->counted(function () use ($context, &$first, &$second): void {
      $first = $this->resources()->build('entity.content:node.page', $context);
      // The same context made again, asked by the id's class-less name.
      $second = $this->resources()->build('entity.content:node.page', $this->resources()->operation('entity.content:node.page', 'create'));
    }));
    $this->assertSame($first, $second);
  }

  /**
   * Tests that a tag a build read makes it again, and no other tag does.
   */
  public function testWhatItReadInvalidatedBuildsAgain(): void {
    $context = $this->resources()->operation('entity.content:node.page', 'create');
    $build = fn () => $this->resources()->build('entity.content:node.page', $context);
    $build();
    $invalidator = $this->container->get('cache_tags.invalidator');
    $invalidator->invalidateTags(['config:node.type.kitchen']);
    $this->assertSame([0, 1], $this->counted($build), 'Another bundle\'s tag leaves it.');
    $invalidator->invalidateTags(['config:field.field.node.page.body']);
    $this->assertSame([1, 0], $this->counted($build), 'Its own field\'s tag builds it again.');
  }

  /**
   * Tests that each kind and each account kind is a build of its own.
   */
  public function testEachKindAndAccountKindIsItsOwn(): void {
    $resources = $this->resources();
    $context = $resources->operation('entity.content:node.page', 'create');
    $all = function () use ($resources, $context): void {
      $resources->build('entity.content:node.page', $context);
      $resources->buildStatic('entity.content:node.page', $context);
      $resources->buildDeclared('entity.content:node.page', $context);
    };
    $this->assertSame([3, 0], $this->counted($all));
    $this->assertSame([0, 3], $this->counted($all));

    $admin = $this->container->get('current_user')->getAccount();
    $this->setCurrentUser($this->createUser(['access content']));
    // For no account it is the same build; for another account kind not.
    $this->assertSame([2, 1], $this->counted($all));
    $this->setCurrentUser($admin);
    $this->assertSame([0, 3], $this->counted($all));
  }

  /**
   * Tests that a dry-run tool call builds each kind once.
   *
   * The listing for the account (for the account alone), then the values
   * refined and the run (every answer applied), which share one build.
   */
  public function testToolCallBuildsEachKindOnce(): void {
    $node = Node::create(['type' => 'page', 'title' => 'One', 'field_description' => 'First']);
    $node->save();
    $tools = $this->container->get('plugin.manager.tool');
    $tools->getDefinitions();
    $this->resources()->forgetBuilds();
    [$built, $reused] = $this->counted(function () use ($tools, $node): void {
      $tool = $tools->createInstance('surface:entity.content:node.page:update');
      $tool->setInputValue('id', (int) $node->id());
      $tool->setInputValue('values', [
        'field_description' => ['value' => 'Second'],
      ]);
      $tool->setInputValue('dry_run', TRUE);
      $this->assertTrue($tool->access($this->container->get('current_user'), TRUE)->isAllowed());
      $tool->execute();
      $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    });
    $this->assertSame(2, $built);
    $this->assertGreaterThanOrEqual(1, $reused);
  }

  /**
   * Tests that the schema route's access check builds nothing.
   *
   * It answers from the operation's context: the permission, the access
   * class, each alter's answer. A response the page cache answers pays no
   * build for its access check.
   */
  public function testTheRouteAccessCheckBuildsNothing(): void {
    $node = Node::create(['type' => 'page', 'title' => 'One', 'field_description' => 'First']);
    $node->save();
    $check = $this->container->get('surface.served_access');
    assert($check instanceof ServedOperationAccessCheck);
    // The API's route, the page's id the path's one identity segment.
    $ids = [
      'resource' => 'entity.content:node.page',
      's1' => (string) $node->id(),
      's2' => '',
      's3' => '',
      's4' => '',
      's5' => '',
    ];
    $route_match = new RouteMatch('surface.api', new Route('/surface-api/{resource}/{s1}/{s2}/{s3}/{s4}/{s5}'), $ids, $ids);
    $this->resources()->forgetBuilds();
    $this->assertSame([0, 0], $this->counted(function () use ($check, $route_match, $node): void {
      $request = Request::create('/surface-api/entity.content:node.page/' . $node->id());
      $this->assertTrue($check->access($route_match, $this->container->get('current_user'), $request)->isAllowed());
    }));
  }

}
