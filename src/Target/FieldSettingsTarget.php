<?php

declare(strict_types=1);

namespace Drupal\data_surface\Target;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Pipeline\PreparedValues;

/**
 * Stores a surface's values in one field's instance settings.
 *
 * The target for ADOPTION.md group C, the field settings half: a field
 * type's per instance settings, which live in the 'settings' property of
 * a field config entity and are described today by nothing more than the
 * form that collects them.
 *
 * The optional shape is the point of the class. A field type's stored
 * settings are routinely not the shape a person or an agent should be
 * asked for: the address field type stores its countries as a map of
 * each code to itself, wraps every field override in a single-key array,
 * and keeps a deprecated key that silently wins over the current one.
 * Those transforms exist already, scattered between a form's validate
 * handler and the item class's accessors. Given to this target as a
 * SettingsShapeInterface they are one named object with one name on each
 * direction, which is what lets the surface describe the input shape
 * rather than the storage shape.
 *
 * Preparing touches nothing: the artifact is a plain settings array, so
 * a dry run leaves the field config exactly as it found it, and the form
 * path can take the artifact and hand it to Field UI to copy onto the
 * entity rather than saving behind the form's back.
 *
 * Encryption rides that same shape. A surface that declares a secret key
 * has its shape wrapped in an EncryptedSettingsShape before either
 * direction runs, so the field's stored settings hold ciphertext and the
 * pipeline is handed plaintext — which is what makes a partial update of
 * the settings beside a secret leave the secret intact. A surface with
 * no secret key is not wrapped and nothing about it changes.
 *
 * @see \Drupal\data_surface\Target\SettingsShapeInterface
 * @see \Drupal\data_surface\Target\EncryptedSettingsShape
 * @see docs/targets.md
 */
final class FieldSettingsTarget implements DataSurfaceTargetInterface {

  use DependencySerializationTrait;

  /**
   * The service id of the site's secret codec.
   */
  protected const CODEC_SERVICE = 'data_surface.secret_codec';

  /**
   * Constructs a FieldSettingsTarget.
   *
   * @param \Drupal\Core\Field\FieldConfigInterface $field
   *   The field config entity whose settings the surface describes.
   * @param \Drupal\data_surface\Target\SettingsShapeInterface|null $shape
   *   The translation between surface shape and storage shape. Omitted
   *   when the surface shape is the storage shape.
   * @param \Drupal\data_surface\Target\SecretCodecInterface|null $codec
   *   The codec a surface's secret keys are encrypted with. Omitted, as
   *   every host but a test omits it, means the site's own codec service
   *   when the container offers one: a host constructs this target by
   *   hand, so the alternative would be every field type in the world
   *   having to learn about a service it has no opinion about.
   */
  public function __construct(
    protected readonly FieldConfigInterface $field,
    protected readonly ?SettingsShapeInterface $shape = NULL,
    protected readonly ?SecretCodecInterface $codec = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function load(DataSurfaceInterface $surface): array {
    $settings = $this->field->getSettings();
    $shape = $this->shapeFor($surface);
    return $shape === NULL ? $settings : $shape->fromStorage($settings);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues {
    $shape = $this->shapeFor($surface);
    $settings = $shape === NULL ? $values : $shape->toStorage($values);
    // Every key the surface owns is produced by the transform, so what
    // the stored settings still hold beyond that is by definition not
    // the surface's business: a setting some other code wrote, or a key
    // this field type keeps for itself. It survives.
    return new PreparedValues($values, $settings + $this->field->getSettings());
  }

  /**
   * Gets the shape to translate through, encryption included.
   *
   * The surface is what says which keys are secret, and a target is
   * handed the surface on both of the calls that translate, so this is
   * where the two meet. A surface that declares no secret key gets the
   * shape it was constructed with, unchanged and unwrapped.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface being read or written.
   *
   * @return \Drupal\data_surface\Target\SettingsShapeInterface|null
   *   The shape, or NULL when there is nothing to translate.
   */
  protected function shapeFor(DataSurfaceInterface $surface): ?SettingsShapeInterface {
    $codec = $this->secretCodec();
    return $codec === NULL
      ? $this->shape
      : EncryptedSettingsShape::forSurface($surface, $codec, $this->shape);
  }

  /**
   * Gets the codec: the one given, or the site's own.
   *
   * @return \Drupal\data_surface\Target\SecretCodecInterface|null
   *   The codec, or NULL when none was given and there is no container
   *   to ask — which is an ordinary unit test, and a surface with a
   *   secret key then stores it in the clear, so a kernel test is what
   *   proves the wiring.
   */
  protected function secretCodec(): ?SecretCodecInterface {
    if ($this->codec !== NULL) {
      return $this->codec;
    }
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    if (!\Drupal::hasService(self::CODEC_SERVICE)) {
      return NULL;
    }
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $codec = \Drupal::service(self::CODEC_SERVICE);
    return $codec instanceof SecretCodecInterface ? $codec : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(PreparedValues $prepared): void {
    if (!is_array($prepared->artifact)) {
      throw new \InvalidArgumentException('The prepared values did not come from a field settings target.');
    }
    $this->field->setSettings($prepared->artifact)->save();
  }

}
