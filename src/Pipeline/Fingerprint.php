<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;

/**
 * A token of what a storage holds, for a write to be checked by.
 *
 * A keyed hash of the resource's id and the values its storage loaded,
 * narrowed to the keys the account's data surface holds, as the HTTP
 * door (surface_http) narrows them: a key the build left out for the
 * account is not covered, and a change to what the build leaves out
 * changes the token. Keyed by the site's hash salt, so it says nothing
 * that could be guessed back from it, a secret included.
 *
 * The HTTP door serves it as a read's `ETag` and compares it with a
 * write's `If-Match`; it is a conflict token any door can share, since
 * it reads nothing but the loaded values.
 *
 * @internal
 */
final class Fingerprint {

  /**
   * Fingerprints what a storage holds.
   *
   * @param string $resource
   *   The id of the resource or structure the data surface was built for.
   * @param array $stored
   *   The values its storage loaded, narrowed to the data surface's own
   *   keys.
   *
   * @return string
   *   The fingerprint.
   */
  public static function of(string $resource, array $stored): string {
    return Crypt::hmacBase64(serialize([$resource, $stored]), Settings::getHashSalt());
  }

}
