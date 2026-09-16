<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

/**
 * Encrypts and decrypts the values a surface declared secret.
 *
 * One small interface with one job: turn a secret into something storage
 * may hold and turn it back. It exists as a service rather than as a
 * static so a deployment can replace it — the shipped implementation
 * derives its key from the site's hash salt, which is a reasonable
 * default and is not key management, and a site with a key management
 * system swaps the service for one that talks to it without touching a
 * surface, a target or a form.
 *
 * The envelope is the other reason this is an interface. A value already
 * in storage before a key was declared secret is plaintext, and nothing
 * can find it unless ciphertext is recognizable, so isEncrypted() is
 * part of the contract rather than an implementation detail: an
 * implementation that cannot answer it cannot be migrated to.
 *
 * Three rules every implementation keeps:
 * - encrypt() is not deterministic. Two calls with the same plaintext
 *   produce different ciphertext, so storage never reveals that two
 *   fields hold the same secret.
 * - decrypt() refuses tampered or truncated ciphertext rather than
 *   returning whatever it can make of it.
 * - isEncrypted() answers from the envelope alone, cheaply, without
 *   needing the key.
 *
 * @see \Drupal\data_surface\Target\SodiumSecretCodec
 * @see \Drupal\data_surface\Target\EncryptedSettingsShape
 * @see docs/targets.md
 */
interface SecretCodecInterface {

  /**
   * Encrypts one secret for storage.
   *
   * @param string $plaintext
   *   The secret as the caller sent it.
   *
   * @return string
   *   The enveloped ciphertext, which isEncrypted() recognizes.
   *
   * @throws \Drupal\data_surface\Target\SecretCodecException
   *   When the codec has no key to work with.
   */
  public function encrypt(string $plaintext): string;

  /**
   * Decrypts one stored secret.
   *
   * @param string $ciphertext
   *   The enveloped ciphertext, as isEncrypted() recognizes it.
   *
   * @return string
   *   The secret.
   *
   * @throws \Drupal\data_surface\Target\SecretCodecException
   *   When the value is not an envelope this codec wrote, when it has
   *   been tampered with or truncated, or when the key cannot open it.
   */
  public function decrypt(string $ciphertext): string;

  /**
   * Returns whether a stored value is an envelope this codec can open.
   *
   * The question a migration asks: a value that answers FALSE is
   * plaintext written before the key was declared secret, which the
   * shape hands to the pipeline as it is and encrypts on the next write.
   *
   * @param string $value
   *   The stored value.
   *
   * @return bool
   *   TRUE when the value is shaped like this codec's ciphertext. A
   *   TRUE answer says the envelope is well formed, not that the key
   *   will open it: only decrypt() can say that.
   */
  public function isEncrypted(string $value): bool;

}
