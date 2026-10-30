<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\Resources;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The declared build of four operations, timed and sized, held to bounds.
 *
 * What a request pays for a build for no account once it is kept: read
 * from the build cache, the request's memo forgotten. Each is held to a
 * bound set from what this kernel site measured, with headroom, so a
 * change that makes the kept build slow to read again, or large, fails
 * here. The bounds and the numbers they were set from are in
 * docs/performance.md; SURFACE_BENCHMARK=<file> writes what this
 * machine measures to that file.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BuildBenchmarkTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * The operations timed: resource id, operation, and the bounds.
   *
   * The time a build read from the build cache takes, the median of
   * RUNS, in milliseconds; and the size of what the cache keeps for it,
   * in bytes.
   */
  public const BOUNDS = [
    'event.spotlight:configure' => ['ms' => 1.0, 'bytes' => 48 * 1024],
    'block.placement:create' => ['ms' => 1.0, 'bytes' => 56 * 1024],
    'registration.step3:configure' => ['ms' => 1.0, 'bytes' => 40 * 1024],
    'first.newsletter:configure' => ['ms' => 0.5, 'bytes' => 8 * 1024],
  ];

  /**
   * How many times each is read back, the median taken.
   */
  protected const RUNS = 9;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_first',
    'block',
    'surface_examples_component_block',
    'surface_examples_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples', 'surface_examples_first']);
    $this->container->get('theme_installer')->install(['stark']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests each declared build, read back from the cache, within its bounds.
   */
  public function testDeclaredBuildsStayWithinBounds(): void {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    $bin = $this->container->get('cache.surface_build');
    $memo = new \ReflectionMethod(Resources::class, 'memoKey');
    $cid = new \ReflectionMethod(Resources::class, 'cacheId');
    $measured = [];
    foreach (self::BOUNDS as $operation => $bound) {
      [$resource, $id] = explode(':', $operation);
      $context = $resources->operation($resource, $id);
      // Cold: nothing kept, nothing in the memo.
      $bin->deleteAll();
      $resources->forgetBuilds();
      $start = hrtime(TRUE);
      $resources->buildDeclared($resource, $context);
      $cold = (hrtime(TRUE) - $start) / 1e6;
      $kept = $bin->get($cid->invoke($resources, $resource, $memo->invoke($resources, $resource, $context, TRUE, TRUE)));
      $this->assertNotFalse($kept, $operation . ' is kept.');
      $bytes = strlen($kept->data);
      // Warm: read back, as a later request reads it.
      $runs = [];
      for ($run = 0; $run < self::RUNS; $run++) {
        $resources->forgetBuilds();
        $cached = $resources->buildCounts()['cached'];
        $start = hrtime(TRUE);
        $resources->buildDeclared($resource, $context);
        $runs[] = (hrtime(TRUE) - $start) / 1e6;
        $this->assertSame($cached + 1, $resources->buildCounts()['cached'], $operation . ' is read from the cache.');
      }
      sort($runs);
      $warm = $runs[intdiv(self::RUNS, 2)];
      $measured[$operation] = sprintf('cold %.2f ms, warm %.3f ms, kept %d bytes', $cold, $warm, $bytes);
      $this->assertLessThan($bound['ms'], $warm, sprintf('%s is read back in under %.1f ms (%s).', $operation, $bound['ms'], $measured[$operation]));
      $this->assertLessThan($bound['bytes'], $bytes, sprintf('%s is kept in under %d bytes (%s).', $operation, $bound['bytes'], $measured[$operation]));
    }
    $file = getenv('SURFACE_BENCHMARK');
    if (is_string($file) && $file !== '') {
      file_put_contents($file, implode("\n", array_map(static fn (string $operation, string $line): string => $operation . ': ' . $line, array_keys($measured), $measured)) . "\n", FILE_APPEND);
    }
  }

}
