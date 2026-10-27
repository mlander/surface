<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * Example 14: the code that uses the webhook, signing each post.
 *
 * It reads the webhook's values as the code that uses them needs them,
 * Resources::resolved(), and knows nothing of managed keys: `api_key` is
 * the pasted key, or, once a module that manages keys put one in its
 * place, that key's value.
 *
 * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
 */
final class WebhookSigner {

  /**
   * The webhook.
   */
  public const RESOURCE = 'event.webhook';

  /**
   * Constructs the signer.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which reads the webhook's values.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
  ) {}

  /**
   * Signs a post.
   *
   * @param array $payload
   *   What is posted.
   *
   * @return string
   *   The HMAC-SHA256 of the payload's JSON, keyed by the webhook's key.
   *
   * @throws \RuntimeException
   *   When the webhook has no key to sign with.
   */
  public function sign(array $payload): string {
    $key = $this->resources->resolved(self::RESOURCE)['api_key'] ?? NULL;
    if (!is_string($key) || $key === '') {
      throw new \RuntimeException('The webhook has no key to sign a post with.');
    }
    return hash_hmac('sha256', (string) json_encode($payload), $key);
  }

  /**
   * Gets the address each post is sent to.
   *
   * @return string
   *   The endpoint.
   */
  public function endpoint(): string {
    return (string) ($this->resources->resolved(self::RESOURCE)['endpoint'] ?? '');
  }

}
