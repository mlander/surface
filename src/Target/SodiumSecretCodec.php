<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\Site\Settings;

/**
 * The reference secret codec: libsodium, keyed from the site hash salt.
 *
 * **This is a reference implementation, and a deployment that holds real
 * secrets should replace it.** It is here so that the flag on a
 * definition does something correct out of the box on every PHP 7.2 and
 * later, with no contributed module and no extension to install: sodium
 * is bundled with PHP, and authenticated encryption with a per value
 * nonce is not the part anybody should be writing by hand. What it is
 * not is key management. The key is derived from the site's hash salt,
 * which lives in settings.php beside the database credentials, so an
 * attacker who can read settings.php can read the secrets, and rotating
 * the hash salt makes every stored secret unreadable. A site that needs
 * better replaces the data_surface.secret_codec service with one backed
 * by a key manager, an HSM or a cloud KMS, and nothing else changes: no
 * surface, no target, no form.
 *
 * What it does:
 * - derives a 32 byte key from the hash salt with HKDF-SHA256 and a
 *   fixed context string, so the key used here is not the hash salt
 *   itself and is not the key any other consumer of the salt derives;
 * - encrypts with sodium_crypto_secretbox, which is XSalsa20-Poly1305:
 *   authenticated, so tampering is detected rather than decrypted into
 *   nonsense;
 * - draws a fresh random nonce per value and stores it in the envelope,
 *   so encrypting the same secret twice produces different ciphertext
 *   and storage reveals nothing by comparison.
 *
 * The envelope is `data_surface:sodium:v1:` followed by the base64 of
 * the nonce and the ciphertext concatenated. The prefix carries the
 * scheme and a version because both will change: a site migrating to a
 * real key manager needs to tell its own ciphertext from this one, and a
 * value with no prefix at all is plaintext written before the key was
 * declared secret. The version number is this envelope's, not the
 * module's.
 *
 * @see \Drupal\data_surface\Target\SecretCodecInterface
 * @see \Drupal\data_surface\Target\EncryptedSettingsShape
 */
final class SodiumSecretCodec implements SecretCodecInterface {

  /**
   * The envelope prefix, naming the scheme and its version.
   */
  public const PREFIX = 'data_surface:sodium:v1:';

  /**
   * The HKDF context, so this key is only ever this key.
   *
   * Derivation is what keeps the hash salt out of the cipher: two
   * consumers deriving from the same salt with different contexts get
   * unrelated keys, so breaking one tells an attacker nothing about the
   * other.
   */
  protected const KEY_CONTEXT = 'Drupal\\data_surface secret codec v1';

  /**
   * The derived key, once it has been derived.
   */
  private ?string $key = NULL;

  /**
   * Constructs a SodiumSecretCodec.
   *
   * @param string|null $hashSalt
   *   The secret the key is derived from. NULL, which is what the
   *   service definition passes, means the site's own hash salt, read
   *   when the first value is encrypted rather than at construction so
   *   that building the service never fails. A test passes its own.
   */
  public function __construct(
    private readonly ?string $hashSalt = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function encrypt(string $plaintext): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key()));
  }

  /**
   * {@inheritdoc}
   */
  public function decrypt(string $ciphertext): string {
    $envelope = $this->envelope($ciphertext);
    if ($envelope === NULL) {
      throw new SecretCodecException('A stored secret is not an envelope this codec wrote, so it cannot be decrypted. Ask isEncrypted() first: a value that answers FALSE is plaintext from before the key was declared secret.');
    }
    $plaintext = sodium_crypto_secretbox_open(
      substr($envelope, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
      substr($envelope, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
      $this->key(),
    );
    if (!is_string($plaintext)) {
      throw new SecretCodecException('A stored secret did not survive its authentication check, so it has been altered since it was written or the key that wrote it is gone. Rotating the site hash salt makes every secret this codec wrote unreadable.');
    }
    return $plaintext;
  }

  /**
   * {@inheritdoc}
   */
  public function isEncrypted(string $value): bool {
    return $this->envelope($value) !== NULL;
  }

  /**
   * Unwraps a stored value into the nonce and ciphertext it carries.
   *
   * Everything about the answer comes from the envelope and nothing from
   * the key, which is what lets a migration ask the question on a site
   * whose key is not configured yet.
   *
   * @param string $value
   *   The stored value.
   *
   * @return string|null
   *   The nonce followed by the ciphertext, or NULL when the value is
   *   not an envelope: no prefix, not base64 after it, or too short to
   *   hold a nonce and an authentication tag.
   */
  private function envelope(string $value): ?string {
    if (!str_starts_with($value, self::PREFIX)) {
      return NULL;
    }
    $binary = base64_decode(substr($value, strlen(self::PREFIX)), TRUE);
    if ($binary === FALSE) {
      return NULL;
    }
    $minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    return strlen($binary) >= $minimum ? $binary : NULL;
  }

  /**
   * Derives the encryption key, once.
   *
   * @return string
   *   The 32 byte key.
   *
   * @throws \Drupal\data_surface\Target\SecretCodecException
   *   When the site has no hash salt to derive from.
   */
  private function key(): string {
    if ($this->key !== NULL) {
      return $this->key;
    }
    $salt = $this->hashSalt;
    if ($salt === NULL) {
      try {
        $salt = Settings::getHashSalt();
      }
      catch (\RuntimeException $e) {
        throw new SecretCodecException('This site has no hash salt, so the reference secret codec has no key to derive. Set $settings["hash_salt"] in settings.php, or replace the data_surface.secret_codec service with one that holds its own key.', 0, $e);
      }
    }
    if ($salt === '') {
      throw new SecretCodecException('This site has no hash salt, so the reference secret codec has no key to derive. Set $settings["hash_salt"] in settings.php, or replace the data_surface.secret_codec service with one that holds its own key.');
    }
    return $this->key = hash_hkdf('sha256', $salt, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_CONTEXT);
  }

}
