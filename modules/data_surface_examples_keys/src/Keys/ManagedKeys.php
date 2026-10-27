<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_keys\Keys;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Example 14: the keys the site manages, the Key module's repository simulated.
 *
 * Each key has a machine name, a label and a value, read from this
 * module's configuration, and the list says what it was read from, so
 * whatever is built from it is kept until that configuration changes. A
 * resource that uses a key stores its name; the value is asked for here
 * where it is used, as the Key module's getKeyValue() answers it.
 */
final class ManagedKeys {

  /**
   * The configuration object the keys are listed in.
   */
  public const CONFIG = 'data_surface_examples_keys.keys';

  /**
   * Constructs the list.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Lists the keys.
   *
   * @return array<string, string>
   *   Each key's label, by its machine name, in the order listed.
   */
  public function labels(): array {
    $labels = [];
    foreach ($this->configFactory->get(self::CONFIG)->get('keys') ?? [] as $key) {
      $labels[(string) $key['id']] = (string) $key['label'];
    }
    return $labels;
  }

  /**
   * Gets a key's value.
   *
   * @param string $id
   *   The key's machine name.
   *
   * @return string|null
   *   Its value, or NULL when the site manages no key of that name.
   */
  public function value(string $id): ?string {
    foreach ($this->configFactory->get(self::CONFIG)->get('keys') ?? [] as $key) {
      if ((string) $key['id'] === $id) {
        return isset($key['value']) ? (string) $key['value'] : NULL;
      }
    }
    return NULL;
  }

  /**
   * Says what the list was read from.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The configuration object's cacheability.
   */
  public function cacheability(): CacheableMetadata {
    return CacheableMetadata::createFromObject($this->configFactory->get(self::CONFIG));
  }

}
