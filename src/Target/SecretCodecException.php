<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

/**
 * A secret could not be encrypted or decrypted.
 *
 * Its own class so a host can tell "this stored secret cannot be read
 * any more" — a rotated hash salt, a restored database, a value somebody
 * edited by hand — from every other kind of failure, and degrade instead
 * of showing a stack trace. Nothing in this module catches it: a secret
 * that cannot be decrypted is not a violation of the surface, it is a
 * broken deployment, and quietly handing back an empty string would let
 * the next save overwrite the stored secret with nothing.
 *
 * The message never carries the value, plaintext or ciphertext, because
 * an exception message reaches logs.
 *
 * @see \Drupal\data_surface\Target\SecretCodecInterface
 */
final class SecretCodecException extends \RuntimeException {

}
