<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * Encrypts a data surface's secret keys on their way into storage.
 *
 * A settings layout that wraps another one, or none. It is where the
 * secret flag stops being a statement and starts costing something: the
 * values a data surface declared secret are enciphered before storage
 * sees them and deciphered when they come back, so plaintext never
 * reaches storage from any caller (a form, a config action, a test, an
 * agent), because every one of them goes through the storage class, and
 * the storage class goes through here.
 *
 * ## Where it sits in the round trip
 *
 * The decorator works in **input layout**, always: it encrypts before
 * the inner layout runs on the way in, and decrypts after the inner layout
 * has run on the way out.
 *
 * ```
 * toStorage:   values -> encrypt secrets -> inner->toStorage() -> storage
 * fromStorage: storage -> inner->fromStorage() -> decrypt secrets -> values
 * ```
 *
 * That order is the whole reason this composes with anything. The secret
 * flag lives on the data surface's definitions, so the input layout is
 * the only place the keys can be named; an inner layout that renames,
 * wraps or regroups keys then carries an opaque string around exactly as
 * it carried the plaintext. The one thing an inner layout may not do is
 * look *into* a secret value (parse it, split it, validate its format),
 * because by the time it sees one it is ciphertext. No shipped layout
 * does.
 *
 * ## Which keys are secret, and who says so
 *
 * The key list is given at construction rather than read from a data
 * surface at call time, because a layout has no data surface:
 * `SettingsLayoutInterface` is pure layout, constructed once and reused
 * for every instance of a field type, and the two methods take values
 * and settings rather than a data surface on purpose. A variant of the
 * interface that took the data surface was the alternative and was not
 * taken: it would have put a live contract object inside the one layer
 * that is meant to be a pure function, for one flag that is already
 * known wherever a layout is built.
 *
 * So the provider that knows its data surface hands the list over, and
 * `forSurface()` is the two-line way to read it off one.
 *
 * ## Values already in storage
 *
 * A key declared secret today has values in storage from yesterday, in
 * plaintext. `fromStorage()` hands those to the pipeline unchanged — the
 * codec's `isEncrypted()` is what tells them apart — so nothing breaks
 * on the read side, and `toStorage()` enciphers them on the next write.
 * Migration is therefore the next save of each affected thing, with no
 * update hook and no scan. What it is not is retroactive: until that
 * save, the old plaintext is still in storage, and a site that has
 * declared an existing key secret should say so to whoever administers
 * it.
 *
 * `NULL` and the empty string are stored as they are. There is no
 * secret to keep in "no secret", and enciphering nothing would only make
 * the absence of a value look like the presence of one.
 *
 * @see \Drupal\data_surface\Storage\SecretCodecInterface
 * @see \Drupal\data_surface\DefinitionMetadata::setSecret()
 * @see docs/storage.md
 */
final class EncryptedSettingsLayout implements SettingsLayoutInterface {

  /**
   * Constructs an EncryptedSettingsLayout.
   *
   * @param \Drupal\data_surface\Storage\SecretCodecInterface $codec
   *   The codec that enciphers and deciphers one value.
   * @param string[] $secretKeys
   *   The data surface keys whose values are secret. A key not in this
   *   list is carried through untouched, which is what keeps this
   *   decorator invisible to every data surface that declares no secret
   *   at all.
   * @param \Drupal\data_surface\Storage\SettingsLayoutInterface|null $inner
   *   The layout this one decorates, or NULL when the input layout is
   *   the storage layout and encryption is the only translation.
   */
  public function __construct(
    private readonly SecretCodecInterface $codec,
    private readonly array $secretKeys,
    private readonly ?SettingsLayoutInterface $inner = NULL,
  ) {
  }

  /**
   * Wraps a layout for a data surface, or hands the layout back untouched.
   *
   * The composition rule in one place, so every storage class that grows
   * encryption spells it the same way: a data surface with no secret keys
   * is a data surface with nothing to encrypt, and wrapping it would add
   * a layer that does nothing to every field type on the site.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface whose definitions carry the secret flags.
   * @param \Drupal\data_surface\Storage\SecretCodecInterface $codec
   *   The codec to encrypt with.
   * @param \Drupal\data_surface\Storage\SettingsLayoutInterface|null $inner
   *   The layout to decorate, or NULL when there is none.
   *
   * @return \Drupal\data_surface\Storage\SettingsLayoutInterface|null
   *   The decorator, or the inner layout unchanged (NULL included) when
   *   the data surface declares no secret key.
   */
  public static function forSurface(DataSurfaceInterface $surface, SecretCodecInterface $codec, ?SettingsLayoutInterface $inner = NULL): ?SettingsLayoutInterface {
    $keys = static::secretKeys($surface);
    return $keys === [] ? $inner : new static($codec, $keys, $inner);
  }

  /**
   * Names the keys a data surface declared secret.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface to read.
   *
   * @return string[]
   *   The secret keys, in declaration order.
   */
  public static function secretKeys(DataSurfaceInterface $surface): array {
    $keys = [];
    foreach ($surface->getDefinitions() as $name => $definition) {
      if (DefinitionMetadata::isSecret($definition)) {
        $keys[] = (string) $name;
      }
    }
    return $keys;
  }

  /**
   * {@inheritdoc}
   */
  public function toStorage(array $values): array {
    foreach ($this->secretKeys as $key) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $values[$key] = $this->seal($key, $values[$key]);
    }
    return $this->inner === NULL ? $values : $this->inner->toStorage($values);
  }

  /**
   * {@inheritdoc}
   */
  public function fromStorage(array $settings): array {
    $values = $this->inner === NULL ? $settings : $this->inner->fromStorage($settings);
    foreach ($this->secretKeys as $key) {
      $value = $values[$key] ?? NULL;
      if (is_string($value) && $this->codec->isEncrypted($value)) {
        $values[$key] = $this->codec->decrypt($value);
      }
    }
    return $values;
  }

  /**
   * Enciphers one secret, leaving alone what there is nothing to hide.
   *
   * @param string $key
   *   The key, named in a refusal so a mistake is findable.
   * @param mixed $value
   *   The accepted value.
   *
   * @return mixed
   *   The ciphertext, or the value unchanged when it holds no secret or
   *   is already enciphered.
   *
   * @throws \Drupal\data_surface\Storage\SecretCodecException
   *   When the value is neither a string nor empty. A secret holds one
   *   scalar string; anything else is a declaration mistake, and storing
   *   it in the clear because it was an unexpected shape is exactly the
   *   silent failure this flag exists to prevent.
   */
  private function seal(string $key, mixed $value): mixed {
    if ($value === NULL || $value === '') {
      return $value;
    }
    if (!is_string($value)) {
      throw new SecretCodecException(sprintf(
        'The secret key "%s" holds a %s, and a secret holds one string. Declare it as a string definition, or stop declaring it secret.',
        $key,
        get_debug_type($value),
      ));
    }
    return $this->codec->isEncrypted($value) ? $value : $this->codec->encrypt($value);
  }

}
