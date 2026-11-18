<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\surface\Storage\SecretCodecInterface;

/**
 * Writes a secret the same way every time, for the door corpus.
 *
 * Not encryption: the text, base64 encoded behind a marker, so what the
 * encrypted storage layout stores is the same on every run and a digest of it
 * is stable.
 */
final class FixedCodec implements SecretCodecInterface {

  /**
   * The marker every stored secret starts with.
   */
  public const MARKER = 'HAND:';

  /**
   * {@inheritdoc}
   */
  public function encrypt(string $plaintext): string {
    return self::MARKER . base64_encode($plaintext);
  }

  /**
   * {@inheritdoc}
   */
  public function decrypt(string $ciphertext): string {
    return (string) base64_decode(substr($ciphertext, strlen(self::MARKER)));
  }

  /**
   * {@inheritdoc}
   */
  public function isEncrypted(string $value): bool {
    return str_starts_with($value, self::MARKER);
  }

}
