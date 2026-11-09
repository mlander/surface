<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use Drupal\surface\Pipeline\Fingerprint;
use Drupal\surface_paths_test\Resource\BoardResource;
use Drupal\surface_paths_test\Storage\NoteStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the HTTP door's `ETag` is the shared conflict token, unchanged.
 *
 * ServedOperations::fingerprint() is Fingerprint::of() over the resource's
 * id and what its storage loaded, narrowed to the data surface's keys, and
 * both are the keyed hash every `ETag` was served as before the token was
 * lifted out of the door. A change to either side, or to what the hash
 * reads, fails here before a client's `If-Match` stops matching.
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
    'surface_paths_test',
  ];

  /**
   * Tests the `ETag` of a served update is the token, byte for byte.
   */
  public function testTheEtagIsByteIdentical(): void {
    \Drupal::state()->set(NoteStorage::KEY, ['note' => 'Pinned']);
    $served_operations = $this->container->get('surface.served_operations');
    $served = $served_operations->resolve(BoardResource::class, 'update', []);
    $stored = $served_operations->stored($served);
    $this->assertSame(['note' => 'Pinned'], $stored);

    $etag = $served_operations->fingerprint($served);
    $this->assertSame(Fingerprint::of('paths_test.board', $stored), $etag);
    $this->assertSame(Crypt::hmacBase64(serialize(['paths_test.board', ['note' => 'Pinned']]), Settings::getHashSalt()), $etag);

    // What storage holds is what the token covers.
    \Drupal::state()->set(NoteStorage::KEY, ['note' => 'Moved']);
    $this->assertNotSame($etag, $served_operations->fingerprint($served));
  }

}
