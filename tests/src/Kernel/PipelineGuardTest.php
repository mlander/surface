<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface\Pipeline\PipelineGuard;
use Drupal\data_surface_door_test\Resource\Broken\CurrentUserStorageResource;
use Drupal\data_surface_door_test\Resource\Broken\RequestReadingStorageResource;
use Drupal\data_surface_door_test\Resource\Broken\RouteReadingAccessResource;
use Drupal\data_surface_door_test\Resource\Broken\StaticAccountRefinerResource;
use Drupal\data_surface_door_test\Resource\Broken\ThemeReadingKeysResource;
use Drupal\data_surface_door_test\Resource\Broken\UserReadingRefinerResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Nothing an operation runs reads the request, held under assert().
 *
 * Each fixture of the door test module reads a service bound to the
 * request a door answers, from code the build or the pipeline runs on an
 * operation's behalf: a runtime refiner reading the current user, a
 * storage reading the request, a storage built with the current user, an
 * access class reading the route, a provider of keys reading the theme.
 * Each is refused, naming the service, the class and the method. A
 * static refiner may not take the account at all.
 *
 * @see docs/decisions.md#nothing-an-operation-runs-reads-the-request
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class PipelineGuardTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'data_surface', 'data_surface_door_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $on = FALSE;
    assert(PipelineGuard::asserting($on));
    if (!$on) {
      $this->markTestSkipped('The guard runs only with assertions on.');
    }
  }

  /**
   * Runs values through a resource's configure, dry, as every door does.
   *
   * @param class-string $resource
   *   The resource.
   * @param array $values
   *   The values.
   */
  protected function dry(string $resource, array $values = []): void {
    $resources = $this->container->get('data_surface.resources');
    $context = $resources->operation($resource, 'configure');
    $surface = $resources->build($resource, $context);
    $this->pipeline()->submit($surface, $values, $resources->storage($resource, $context, $surface), TRUE, $resources->access($resource, $context));
  }

  /**
   * Asserts a call is refused for a door-specific service, by name.
   *
   * @param callable $call
   *   The call.
   * @param string[] $named
   *   What the message names: the kind and the class, the service, why.
   */
  protected function assertRefused(callable $call, array $named): void {
    try {
      $call();
      $this->fail('A door-specific service is refused: ' . implode(', ', $named));
    }
    catch (\LogicException $e) {
      foreach ($named as $part) {
        $this->assertStringContainsString($part, $e->getMessage());
      }
      $this->assertStringContainsString('a door-specific service makes the doors disagree', $e->getMessage());
    }
  }

  /**
   * Tests a runtime refiner reading the current user is refused.
   */
  public function testRuntimeRefinerIsRefusedTheCurrentUser(): void {
    $this->assertRefused(fn () => $this->dry(UserReadingRefinerResource::class, ['note' => 'x']), [
      'The runtime refiner ' . UserReadingRefinerResource::class . '::requiredWhenSignedIn() asked for the service current_user',
      'the account a request signed in as',
      'a runtime refiner\'s parameter typed AccountInterface',
    ]);
  }

  /**
   * Tests a static refiner may not take the account.
   */
  public function testStaticRefinerMayNotTakeTheAccount(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage(StaticAccountRefinerResource::class . '::requiredWhenSignedIn() takes the account and does not declare runtime: TRUE.');
    $this->dry(StaticAccountRefinerResource::class);
  }

  /**
   * Tests a storage reading the request is refused, in its prepare().
   */
  public function testStorageIsRefusedTheRequest(): void {
    $this->assertRefused(fn () => $this->dry(RequestReadingStorageResource::class, ['note' => 'x']), [
      'The storage Drupal\data_surface_door_test\Storage\Broken\RequestReadingStorage::prepare() asked for the service request_stack',
      'the request a door is answering',
    ]);
  }

  /**
   * Tests a storage built with the current user is refused when first used.
   */
  public function testStorageMayNotHoldTheCurrentUser(): void {
    $this->assertRefused(fn () => $this->dry(CurrentUserStorageResource::class, ['note' => 'x']), [
      'The storage Drupal\data_surface_door_test\Storage\Broken\CurrentUserStorage holds the service current_user in $currentUser',
      'a storage verb\'s $account',
    ]);
  }

  /**
   * Tests an access class reading the route is refused.
   */
  public function testAnAccessClassIsRefusedTheRoute(): void {
    $this->assertRefused(fn () => $this->dry(RouteReadingAccessResource::class), [
      'The access class Drupal\data_surface_door_test\Access\Broken\RouteReadingAccess::access() asked for the service current_route_match',
    ]);
  }

  /**
   * Tests a provider of keys reading the theme is refused.
   */
  public function testProviderIsRefusedTheTheme(): void {
    $this->assertRefused(fn () => $this->dry(ThemeReadingKeysResource::class), [
      'The provider Drupal\data_surface_door_test\Keys\Broken\ThemeReadingKeys::list() asked for the service theme.manager',
      'the theme a page renders in',
    ]);
  }

  /**
   * Tests the guard is put back, and passes the caller's own reads.
   *
   * Code that is not the guarded class's reads what it reads: the module's
   * own build reads the current user to hand it on, and core's code a
   * storage calls is core's.
   */
  public function testOnlyTheGuardedClassIsRefused(): void {
    $container = \Drupal::getContainer();
    $account = PipelineGuard::onBehalf(PipelineGuard::STORAGE, RequestReadingStorageResource::class, 'load', static fn () => \Drupal::currentUser());
    $this->assertSame($this->container->get('current_user'), $account);
    $this->assertSame($container, \Drupal::getContainer(), 'The real container is put back.');
  }

}
