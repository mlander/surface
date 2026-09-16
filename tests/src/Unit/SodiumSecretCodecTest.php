<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\data_surface\Target\SecretCodecException;
use Drupal\data_surface\Target\SodiumSecretCodec;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the reference secret codec.
 *
 * Four properties, and every one of them is the difference between
 * encryption and the appearance of it: a round trip that returns exactly
 * what went in, an envelope that says "this is ciphertext" without
 * needing the key, a refusal when the bytes have been altered, and a
 * fresh nonce per value so the same secret never looks the same twice.
 *
 * @see \Drupal\data_surface\Target\SodiumSecretCodec
 */
#[Group('data_surface')]
class SodiumSecretCodecTest extends UnitTestCase {

  /**
   * The salt the codec under test derives its key from.
   */
  protected const SALT = 'a-test-hash-salt-long-enough-to-be-one';

  /**
   * Builds the codec under test.
   *
   * Its own salt rather than the site's: the constructor takes one so a
   * unit test needs no settings.php and no container.
   *
   * @param string $salt
   *   The salt to derive the key from.
   *
   * @return \Drupal\data_surface\Target\SodiumSecretCodec
   *   The codec.
   */
  protected function codec(string $salt = self::SALT): SodiumSecretCodec {
    return new SodiumSecretCodec($salt);
  }

  /**
   * Tests that a secret comes back exactly as it went in.
   */
  public function testRoundTrip(): void {
    $codec = $this->codec();
    // The last two are the ones worth having: arbitrary bytes, which is
    // what a binary-safe codec has to survive, and a long value.
    foreach (['token', '', '0', ' ', "line\nbreak", random_bytes(64), str_repeat('x', 4096)] as $plaintext) {
      $this->assertSame($plaintext, $codec->decrypt($codec->encrypt($plaintext)));
    }
  }

  /**
   * Tests that ciphertext is recognizable without the key.
   *
   * The question a migration asks of every stored value, which is why it
   * is answered from the envelope alone.
   */
  public function testEnvelopeIsDetectable(): void {
    $codec = $this->codec();
    $ciphertext = $codec->encrypt('token');

    $this->assertStringStartsWith(SodiumSecretCodec::PREFIX, $ciphertext);
    $this->assertTrue($codec->isEncrypted($ciphertext));
    // A codec with a different key still recognizes the envelope: the
    // answer is about the shape, not about whether this key can open it.
    $this->assertTrue($this->codec('another-salt-entirely')->isEncrypted($ciphertext));

    // Plaintext, including plaintext that looks like it is trying.
    $this->assertFalse($codec->isEncrypted(''));
    $this->assertFalse($codec->isEncrypted('token'));
    $this->assertFalse($codec->isEncrypted(SodiumSecretCodec::PREFIX));
    $this->assertFalse($codec->isEncrypted(SodiumSecretCodec::PREFIX . 'not base64 at all!'));
    $this->assertFalse($codec->isEncrypted(SodiumSecretCodec::PREFIX . base64_encode('short')));
  }

  /**
   * Tests that altered ciphertext is refused rather than decrypted.
   */
  public function testTamperingIsRefused(): void {
    $codec = $this->codec();
    $ciphertext = $codec->encrypt('token');
    $payload = base64_decode(substr($ciphertext, strlen(SodiumSecretCodec::PREFIX)), TRUE);
    $this->assertIsString($payload);

    // One flipped bit in the last byte, which is inside the ciphertext
    // rather than the nonce, and the authentication tag catches it.
    $payload[strlen($payload) - 1] = chr(ord($payload[strlen($payload) - 1]) ^ 0x01);
    $this->expectException(SecretCodecException::class);
    $codec->decrypt(SodiumSecretCodec::PREFIX . base64_encode($payload));
  }

  /**
   * Tests that another site's key cannot open this site's secret.
   */
  public function testAnotherKeyCannotOpenIt(): void {
    $ciphertext = $this->codec()->encrypt('token');

    $this->expectException(SecretCodecException::class);
    $this->codec('a-different-hash-salt-entirely-yes')->decrypt($ciphertext);
  }

  /**
   * Tests that plaintext handed to decrypt() is refused, not returned.
   */
  public function testPlaintextIsNotDecrypted(): void {
    $this->expectException(SecretCodecException::class);
    $this->codec()->decrypt('token');
  }

  /**
   * Tests that the same secret never looks the same twice.
   *
   * A deterministic codec would let anybody reading storage see which
   * two fields hold the same password without decrypting either.
   */
  public function testNoncesDiffer(): void {
    $codec = $this->codec();
    $ciphertexts = [];
    for ($i = 0; $i < 8; $i++) {
      $ciphertexts[] = $codec->encrypt('token');
    }

    $this->assertCount(8, array_unique($ciphertexts));
    foreach ($ciphertexts as $ciphertext) {
      $this->assertSame('token', $codec->decrypt($ciphertext));
    }
  }

  /**
   * Tests that a site with no salt is told so rather than encrypting.
   */
  public function testEmptySaltIsRefused(): void {
    $this->expectException(SecretCodecException::class);
    $this->codec('')->encrypt('token');
  }

}
