<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_keys\Keys;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Example 14: the keys the site manages, the Key module's list simulated.
 *
 * Each key has a machine name and a label, read from this module's
 * configuration, and the list says what it was read from, so whatever is
 * built from it is kept until that configuration changes. A key's value
 * is not here: what a resource stores is the key's name.
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
   * Says what the list was read from.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The configuration object's cacheability.
   */
  public function cacheability(): CacheableMetadata {
    return CacheableMetadata::createFromObject($this->configFactory->get(self::CONFIG));
  }

}
