<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_http\Kernel;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Site\Settings;
use Drupal\surface\Operation\Door;
use Drupal\surface\Operation\OperationRun;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface_paths_test\Resource\BoardResource;
use Drupal\surface_paths_test\Storage\NoteStorage;
use Drupal\surface_http\ServedOperation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;

/**
 * Tests the HTTP door's `ETag` is the shared conflict token, unchanged.
 *
 * ServedOperations::fingerprint() is Fingerprint::of() over the resource's
 * id and what its storage loaded, narrowed to the data surface's keys, and
 * both are the keyed hash every `ETag` was served as before the token was
 * lifted out of the door. A change to either side, or to what the hash
 * reads, fails here before a client's `If-Match` stops matching.
 *
 * Its subject is the HTTP door's `ETag`, so it is surface_http's test.
 * Pipeline\Fingerprint itself is the base module's; the stage's tests
 * there (StagedUpdateTest, StagedDeleteTest, StageApplyTest) use it as
 * the token a staged write and an apply check.
 *
 * @see docs/served-contract.md#if-match
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class FingerprintTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_http',
    'surface_paths_test',
  ];

  /**
   * Tests the `ETag` of a served update is the token, byte for byte.
   */
  public function testTheEtagIsByteIdentical(): void {
    \Drupal::state()->set(NoteStorage::KEY, ['note' => 'Pinned']);
    $served_operations = $this->container->get('surface.served_operations');
    $served = $this->served();
    $stored = $served_operations->stored($served);
    $this->assertSame(['note' => 'Pinned'], $stored);

    $etag = $served_operations->fingerprint($served);
    $this->assertSame(Fingerprint::of('paths_test.board', $stored), $etag);
    $this->assertSame(Crypt::hmacBase64(serialize(['paths_test.board', ['note' => 'Pinned']]), Settings::getHashSalt()), $etag);

    // What storage holds is what the token covers.
    \Drupal::state()->set(NoteStorage::KEY, ['note' => 'Moved']);
    $this->assertNotSame($etag, $served_operations->fingerprint($served));
  }

  /**
   * Builds the board's update as the door serves it, past its access.
   *
   * The board declares no permission and no access class, which no
   * account passes; the token is the subject here, so the run is made as
   * the runner makes a prepare that access allowed.
   *
   * @return \Drupal\surface_http\ServedOperation
   *   The operation, built, its storage composed.
   */
  protected function served(): ServedOperation {
    $registry = $this->container->get('surface.resource_registry');
    $resources = $this->container->get('surface.resources');
    $definition = $registry->getDefinition(BoardResource::class);
    $operation = $registry->getOperation(BoardResource::class, 'update');
    $context = $resources->operation(BoardResource::class, 'update');
    $built = $resources->build(BoardResource::class, $context);
    $gated = OperationRun::gated(new Door('test'), $definition, $operation, NULL, $context, AccessResult::allowed(), new AnonymousUserSession());
    return new ServedOperation(OperationRun::prepared($gated, $built, $resources->storage(BoardResource::class, $context, $built)), []);
  }

}
