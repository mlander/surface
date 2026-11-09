<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Pipeline\PipelineGuard;
use Drupal\surface\Pipeline\SurfaceResult;
use Drupal\surface\ResourceBuild\OutputsStorageInterface;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface_door_test\Resource\Broken\CurrentUserStorageResource;
use Drupal\surface_door_test\Resource\Broken\RequestReadingStorageResource;
use Drupal\surface_door_test\Resource\Broken\RouteReadingAccessResource;
use Drupal\surface_door_test\Resource\Broken\StaticAccountRefinerResource;
use Drupal\surface_door_test\Resource\Broken\ThemeReadingKeysResource;
use Drupal\surface_door_test\Resource\Broken\UserReadingRefinerResource;
use Drupal\surface_stage_test\LedgerSlug;
use Drupal\surface_stage_test\Storage\LedgerStorage;
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
 * Guards nest: a storage whose prepare() runs another resource's pipeline,
 * as the stage's apply does, runs it under its own guard, the inner
 * storage's guard and a computed key's inside it. A guard onBehalf()
 * builds has no allow-list and refuses a door-specific service only when
 * the class it guards asked for it, so the inner storage is refused what
 * it asks and the outer is not blamed for it; a compute guard refuses
 * everything outside its allow-list, whoever asks.
 *
 * @see docs/decisions.md#nothing-an-operation-runs-reads-the-request
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class PipelineGuardTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface', 'surface_door_test', 'surface_stage_test'];

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
    $resources = $this->container->get('surface.resources');
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
      'The storage Drupal\surface_door_test\Storage\Broken\RequestReadingStorage::prepare() asked for the service request_stack',
      'the request a door is answering',
    ]);
  }

  /**
   * Tests a storage built with the current user is refused when first used.
   */
  public function testStorageMayNotHoldTheCurrentUser(): void {
    $this->assertRefused(fn () => $this->dry(CurrentUserStorageResource::class, ['note' => 'x']), [
      'The storage Drupal\surface_door_test\Storage\Broken\CurrentUserStorage holds the service current_user in $currentUser',
      'a storage verb\'s $account',
    ]);
  }

  /**
   * Tests an access class reading the route is refused.
   */
  public function testAnAccessClassIsRefusedTheRoute(): void {
    $this->assertRefused(fn () => $this->dry(RouteReadingAccessResource::class), [
      'The access class Drupal\surface_door_test\Access\Broken\RouteReadingAccess::access() asked for the service current_route_match',
    ]);
  }

  /**
   * Tests a provider of keys reading the theme is refused.
   */
  public function testProviderIsRefusedTheTheme(): void {
    $this->assertRefused(fn () => $this->dry(ThemeReadingKeysResource::class), [
      'The provider Drupal\surface_door_test\Keys\Broken\ThemeReadingKeys::list() asked for the service theme.manager',
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

  /**
   * Tests a storage guard holds another pipeline, a compute guard inside.
   *
   * The ledger's slug is computed from its title on every change, so a
   * dry run of a retitle inside a storage guard runs the compute guard
   * inside the ledger storage's guard inside the outer one.
   */
  public function testStorageGuardHoldsPipelineWithComputeGuardInside(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('stage_test.ledger', 'create');
    $built = $resources->build('stage_test.ledger', $context);
    $this->assertTrue($this->pipeline()->submit($built, ['id' => 'a', 'title' => 'Alpha'], $resources->storage('stage_test.ledger', $context, $built))->committed);

    $result = PipelineGuard::onBehalf(PipelineGuard::STORAGE, StageStorage::class, 'prepare', function () use ($resources): SurfaceResult {
      $context = $resources->operation('stage_test.ledger', 'update', ['id' => 'a']);
      $built = $resources->build('stage_test.ledger', $context);
      return $this->pipeline()->submit($built, ['title' => 'Beta Gamma'], $resources->storage('stage_test.ledger', $context, $built), TRUE);
    });
    $this->assertTrue($result->isValid());
    $this->assertSame('beta-gamma', $result->values['slug']);
    $ledger = $this->container->get('class_resolver')->getInstanceFromDefinition(LedgerStorage::class);
    $this->assertTrue(PipelineGuard::holdsNothingDoorSpecific($ledger, PipelineGuard::STORAGE));

    // The inner storage is refused what it asks, inside the outer guard.
    $this->assertRefused(fn () => PipelineGuard::onBehalf(PipelineGuard::STORAGE, StageStorage::class, 'prepare', fn () => $this->dry(RequestReadingStorageResource::class, ['note' => 'x'])), [
      'The storage Drupal\surface_door_test\Storage\Broken\RequestReadingStorage::prepare() asked for the service request_stack',
    ]);
    // A compute guard inside a storage guard refuses whoever asks.
    try {
      PipelineGuard::onBehalf(PipelineGuard::STORAGE, StageStorage::class, 'prepare', static fn () => PipelineGuard::guardCompute(LedgerSlug::class . '::compute()', static fn () => \Drupal::service('state')));
      $this->fail('A computed key reads no state.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('The computed key\'s ' . LedgerSlug::class . '::compute() asked for the service state', $e->getMessage());
    }
  }

  /**
   * Tests the stage's storage holds nothing bound to a request.
   *
   * It holds the build step, which holds the current user to build for;
   * the instance's own properties are what is looked through.
   */
  public function testStageStorageHoldsNothingDoorSpecific(): void {
    $storage = $this->container->get('class_resolver')->getInstanceFromDefinition(StageStorage::class);
    $this->assertInstanceOf(StageStorage::class, $storage);
    $this->assertTrue($this->container->initialized('current_user'));
    $this->assertTrue(PipelineGuard::holdsNothingDoorSpecific($storage, PipelineGuard::STORAGE));
  }

  /**
   * Tests apply runs each staged write under the guard, computing inside.
   */
  public function testApplyRunsItsWritesUnderTheGuard(): void {
    $resources = $this->container->get('surface.resources');
    $context = $resources->operation('stage_test.ledger', 'create');
    $built = $resources->build('stage_test.ledger', $context);
    $this->assertTrue($this->pipeline()->submit($built, ['id' => 'a', 'title' => 'Alpha'], $resources->storage('stage_test.ledger', $context, $built))->committed);
    $context = $resources->operation('surface.stage', 'create');
    $built = $resources->build('surface.stage', $context);
    $storage = $resources->storage('surface.stage', $context, $built);
    $this->assertTrue($this->pipeline()->submit($built, [], $storage)->committed);
    assert($storage instanceof OutputsStorageInterface);
    $stage = (string) $storage->outputs($built, [])['id'];

    $context = $resources->operation('stage_test.ledger', 'update', ['id' => 'a']);
    $built = $resources->build('stage_test.ledger', $context);
    $staged = $resources->storage('stage_test.ledger', $context, $built, NULL, $this->container->get('surface.stages')->named($stage)->withArguments(['id' => 'a']));
    $this->assertTrue($this->pipeline()->submit($built, ['title' => 'Delta Echo'], $staged)->committed);

    $context = $resources->operation('surface.stage', 'apply', ['id' => $stage]);
    $built = $resources->build('surface.stage', $context);
    $result = $this->pipeline()->submit($built, [], $resources->storage('surface.stage', $context, $built));
    $this->assertTrue($result->committed);
    $row = $this->container->get('keyvalue.database')->get(LedgerStorage::COLLECTION)->get('a');
    $this->assertSame(['Delta Echo', 'delta-echo'], [$row['title'], $row['slug']]);
  }

}
