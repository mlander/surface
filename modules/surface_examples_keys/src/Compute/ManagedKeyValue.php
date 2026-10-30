<?php

declare(strict_types=1);

namespace Drupal\surface_examples_keys\Compute;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * Example 14: a managed key's value, for the key that names it.
 *
 * Named by WebhookKeyAlter's resolve(), never by compute(): it runs where
 * the webhook is used, through Resources::resolved(), and never ahead, so
 * it may ask the keys' store, as the Key module's getKeyValue() does. It
 * reads no request and no account.
 *
 * Decision: see docs/decisions.md#a-key-may-stand-for-another-at-use.
 */
final class ManagedKeyValue implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   *
   * @return string|null
   *   The value of the key `key_id` names, or NULL for a name the site
   *   does not manage.
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    $id = $from['key_id'] ?? NULL;
    // A resolve runs at use, so reading the store the value is kept in is
    // what it is for; a computed key's class may not.
    // @phpstan-ignore surface.compute
    return is_string($id) ? \Drupal::service('surface_examples_keys.keys')->value($id) : NULL;
  }

}
