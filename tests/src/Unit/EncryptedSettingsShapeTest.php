<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Target\EncryptedSettingsShape;
use Drupal\data_surface\Target\SecretCodecException;
use Drupal\data_surface\Target\SettingsShapeInterface;
use Drupal\data_surface\Target\SodiumSecretCodec;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the shape decorator that keeps plaintext out of storage.
 *
 * What it has to prove is narrow and load-bearing: the declared secret
 * is enciphered and everything beside it is not touched at all, the
 * round trip is exact so a partial update can leave a secret alone, an
 * inner shape still gets to do its own translation, and a value written
 * before the key was declared secret is recognized as plaintext and
 * enciphered on the next write rather than double-enciphered or
 * refused.
 *
 * @see \Drupal\data_surface\Target\EncryptedSettingsShape
 */
#[Group('data_surface')]
class EncryptedSettingsShapeTest extends UnitTestCase {

  /**
   * Builds the surface the shape reads its secret keys from.
   *
   * One secret key, one ordinary one beside it, because "the ordinary
   * one is untouched" is half of what is being tested.
   *
   * @return \Drupal\data_surface\DataSurface
   *   The surface.
   */
  protected function surface(): DataSurface {
    $token = DataDefinition::create('string')->setLabel('API key');
    DefinitionMetadata::setSecret($token);
    return new DataSurface(DefinitionMap::fromArrays(definitions: [
      'endpoint' => DataDefinition::create('string')->setLabel('Endpoint'),
      'token' => $token,
    ]));
  }

  /**
   * Builds the codec the shape encrypts with.
   *
   * @return \Drupal\data_surface\Target\SodiumSecretCodec
   *   The codec.
   */
  protected function codec(): SodiumSecretCodec {
    return new SodiumSecretCodec('a-test-hash-salt-long-enough-to-be-one');
  }

  /**
   * Tests that the secret is enciphered and the key beside it is not.
   */
  public function testSecretKeysAreEncrypted(): void {
    $codec = $this->codec();
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $codec);
    $this->assertInstanceOf(EncryptedSettingsShape::class, $shape);

    $stored = $shape->toStorage(['endpoint' => 'https://example.com', 'token' => 'sk-1234']);

    $this->assertSame('https://example.com', $stored['endpoint']);
    $this->assertNotSame('sk-1234', $stored['token']);
    $this->assertStringNotContainsString('sk-1234', $stored['token']);
    $this->assertTrue($codec->isEncrypted($stored['token']));
    // And the pipeline gets the plaintext back, which is what makes a
    // partial update of the endpoint leave the token intact.
    $this->assertSame(
      ['endpoint' => 'https://example.com', 'token' => 'sk-1234'],
      $shape->fromStorage($stored),
    );
  }

  /**
   * Tests that a surface with no secret gets no decorator at all.
   *
   * The no-behavior-change rule, stated as a test: a site whose field
   * types declare no secret must not grow a layer that does nothing.
   */
  public function testSurfaceWithoutSecretsIsNotWrapped(): void {
    $surface = new DataSurface(DefinitionMap::fromArrays(definitions: [
      'endpoint' => DataDefinition::create('string'),
    ]));
    $inner = $this->passThroughShape();

    $this->assertNull(EncryptedSettingsShape::forSurface($surface, $this->codec()));
    $this->assertSame($inner, EncryptedSettingsShape::forSurface($surface, $this->codec(), $inner));
    $this->assertSame(['token'], EncryptedSettingsShape::secretKeys($this->surface()));
  }

  /**
   * Tests that nothing is enciphered when there is no secret to keep.
   */
  public function testEmptyValuesAreStoredAsThemselves(): void {
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $this->codec());

    $this->assertSame(['token' => NULL], $shape->toStorage(['token' => NULL]));
    $this->assertSame(['token' => ''], $shape->toStorage(['token' => '']));
    // A key the values do not carry is not invented.
    $this->assertSame(['endpoint' => 'https://example.com'], $shape->toStorage(['endpoint' => 'https://example.com']));
    $this->assertSame(['token' => NULL], $shape->fromStorage(['token' => NULL]));
  }

  /**
   * Tests that plaintext already in storage is read and then enciphered.
   *
   * The migration path for a key declared secret after it had values:
   * reading is unchanged, and the next write is where the plaintext
   * stops being plaintext.
   */
  public function testPreexistingPlaintextIsDetected(): void {
    $codec = $this->codec();
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $codec);

    $values = $shape->fromStorage(['endpoint' => 'https://example.com', 'token' => 'sk-legacy']);
    $this->assertSame('sk-legacy', $values['token']);

    $stored = $shape->toStorage($values);
    $this->assertTrue($codec->isEncrypted($stored['token']));
    $this->assertSame('sk-legacy', $shape->fromStorage($stored)['token']);
  }

  /**
   * Tests that a value already enciphered is not enciphered again.
   */
  public function testCiphertextIsNotDoubleEncrypted(): void {
    $codec = $this->codec();
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $codec);
    $ciphertext = $codec->encrypt('sk-1234');

    $this->assertSame($ciphertext, $shape->toStorage(['token' => $ciphertext])['token']);
  }

  /**
   * Tests that the inner shape's own translation still happens.
   *
   * Encryption goes on first and comes off last, so the inner shape
   * moves an opaque string around exactly as it moved the plaintext.
   */
  public function testInnerShapeStillTranslates(): void {
    $codec = $this->codec();
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $codec, $this->wrappingShape());
    $this->assertInstanceOf(EncryptedSettingsShape::class, $shape);

    $stored = $shape->toStorage(['endpoint' => 'https://example.com', 'token' => 'sk-1234']);

    // The inner shape's wrapping is intact, and what it wrapped is
    // ciphertext.
    $this->assertSame(['value' => 'https://example.com'], $stored['endpoint']);
    $this->assertTrue($codec->isEncrypted($stored['token']['value']));
    $this->assertSame(
      ['endpoint' => 'https://example.com', 'token' => 'sk-1234'],
      $shape->fromStorage($stored),
    );
  }

  /**
   * Tests that a secret that is not a string is refused, not stored.
   *
   * Storing it in the clear because it arrived in an unexpected shape is
   * exactly the silent failure the flag exists to prevent.
   */
  public function testNonStringSecretIsRefused(): void {
    $shape = EncryptedSettingsShape::forSurface($this->surface(), $this->codec());

    $this->expectException(SecretCodecException::class);
    $this->expectExceptionMessage('"token"');
    $shape->toStorage(['token' => ['sk-1234']]);
  }

  /**
   * Builds a shape that translates nothing, to be identical by identity.
   *
   * @return \Drupal\data_surface\Target\SettingsShapeInterface
   *   The shape.
   */
  protected function passThroughShape(): SettingsShapeInterface {
    return new class implements SettingsShapeInterface {

      /**
       * {@inheritdoc}
       */
      public function toStorage(array $values): array {
        return $values;
      }

      /**
       * {@inheritdoc}
       */
      public function fromStorage(array $settings): array {
        return $settings;
      }

    };
  }

  /**
   * Builds a shape that wraps every value the way a real one might.
   *
   * Modelled on the address field's overrides, which storage keeps in a
   * single-key array each: a translation that moves values without
   * looking into them.
   *
   * @return \Drupal\data_surface\Target\SettingsShapeInterface
   *   The shape.
   */
  protected function wrappingShape(): SettingsShapeInterface {
    return new class implements SettingsShapeInterface {

      /**
       * {@inheritdoc}
       */
      public function toStorage(array $values): array {
        return array_map(static fn (mixed $value): array => ['value' => $value], $values);
      }

      /**
       * {@inheritdoc}
       */
      public function fromStorage(array $settings): array {
        return array_map(
          static fn (mixed $setting): mixed => is_array($setting) ? ($setting['value'] ?? NULL) : $setting,
          $settings,
        );
      }

    };
  }

}
