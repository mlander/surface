<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * Encrypts a surface's secret keys on their way into storage.
 *
 * A settings shape that wraps another one, or none. It is where the
 * secret flag stops being a statement and starts costing something: the
 * values a surface declared secret are enciphered before storage sees
 * them and deciphered when they come back, so plaintext never reaches
 * storage from any caller — a form, a config action, a test, an agent —
 * because every one of them goes through the target, and the target goes
 * through here.
 *
 * ## Where it sits in the round trip
 *
 * The decorator works in **surface shape**, always: it encrypts before
 * the inner shape runs on the way in, and decrypts after the inner shape
 * has run on the way out.
 *
 * ```
 * toStorage:   values -> encrypt secrets -> inner->toStorage() -> storage
 * fromStorage: storage -> inner->fromStorage() -> decrypt secrets -> values
 * ```
 *
 * That order is the whole reason this composes with anything. The secret
 * flag lives on the surface's definitions, so surface shape is the only
 * place the keys can be named; an inner shape that renames, wraps or
 * regroups keys then carries an opaque string around exactly as it
 * carried the plaintext. The one thing an inner shape may not do is look
 * *into* a secret value — parse it, split it, validate its format —
 * because by the time it sees one it is ciphertext. No shipped shape
 * does.
 *
 * ## Which keys are secret, and who says so
 *
 * The key list is given at construction rather than read from a surface
 * at call time, because a shape has no surface: `SettingsShapeInterface`
 * is pure shape, constructed once and reused for every instance of a
 * field type, and the two methods take values and settings rather than a
 * surface on purpose. A surface-aware variant of the interface was the
 * alternative and was not taken: it would have put a live contract
 * object inside the one layer that is meant to be a pure function, for
 * one flag that is already known wherever a shape is built.
 *
 * So the provider that knows its surface hands the list over, and
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
 * @see \Drupal\data_surface\Target\SecretCodecInterface
 * @see \Drupal\data_surface\DefinitionMetadata::setSecret()
 * @see docs/targets.md
 */
final class EncryptedSettingsShape implements SettingsShapeInterface {

  /**
   * Constructs an EncryptedSettingsShape.
   *
   * @param \Drupal\data_surface\Target\SecretCodecInterface $codec
   *   The codec that enciphers and deciphers one value.
   * @param string[] $secretKeys
   *   The surface keys whose values are secret. A key not in this list
   *   is carried through untouched, which is what keeps this decorator
   *   invisible to every surface that declares no secret at all.
   * @param \Drupal\data_surface\Target\SettingsShapeInterface|null $inner
   *   The shape this one decorates, or NULL when the surface shape is
   *   the storage shape and encryption is the only translation.
   */
  public function __construct(
    private readonly SecretCodecInterface $codec,
    private readonly array $secretKeys,
    private readonly ?SettingsShapeInterface $inner = NULL,
  ) {
  }

  /**
   * Wraps a shape for a surface, or hands the shape back untouched.
   *
   * The composition rule in one place, so every target that grows
   * encryption spells it the same way: a surface with no secret keys is
   * a surface with nothing to encrypt, and wrapping it would add a layer
   * that does nothing to every field type on the site.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface whose definitions carry the secret flags.
   * @param \Drupal\data_surface\Target\SecretCodecInterface $codec
   *   The codec to encrypt with.
   * @param \Drupal\data_surface\Target\SettingsShapeInterface|null $inner
   *   The shape to decorate, or NULL when there is none.
   *
   * @return \Drupal\data_surface\Target\SettingsShapeInterface|null
   *   The decorator, or the inner shape unchanged — NULL included — when
   *   the surface declares no secret key.
   */
  public static function forSurface(DataSurfaceInterface $surface, SecretCodecInterface $codec, ?SettingsShapeInterface $inner = NULL): ?SettingsShapeInterface {
    $keys = static::secretKeys($surface);
    return $keys === [] ? $inner : new static($codec, $keys, $inner);
  }

  /**
   * Names the keys a surface declared secret.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface to read.
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
   *   The surface key, named in a refusal so a mistake is findable.
   * @param mixed $value
   *   The accepted value.
   *
   * @return mixed
   *   The ciphertext, or the value unchanged when it holds no secret or
   *   is already enciphered.
   *
   * @throws \Drupal\data_surface\Target\SecretCodecException
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
