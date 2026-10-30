<?php

declare(strict_types=1);

namespace Drupal\surface_mount_test\Storage;

use Drupal\surface\Storage\StoredSchemaInterface;

/**
 * Stores a number of minutes as an ISO 8601 duration.
 *
 * Asked for as `15`, stored as `PT15M`.
 */
final class MinutesLayout implements StoredSchemaInterface {

  /**
   * Constructs a MinutesLayout.
   *
   * @param string $key
   *   The key asked for in minutes.
   */
  public function __construct(
    protected readonly string $key,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toStorage(array $values): array {
    if (is_int($values[$this->key] ?? NULL)) {
      $values[$this->key] = 'PT' . $values[$this->key] . 'M';
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function fromStorage(array $settings): array {
    if (is_string($settings[$this->key] ?? NULL) && preg_match('/^PT(\d+)M$/D', $settings[$this->key], $matches)) {
      $settings[$this->key] = (int) $matches[1];
    }
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storedSchema(): array {
    return [$this->key => ['type' => 'string']];
  }

}
