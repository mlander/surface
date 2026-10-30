<?php

declare(strict_types=1);

namespace Drupal\surface_schema_test\Storage;

use Drupal\surface\Storage\StoredSchemaInterface;

/**
 * Stores a map of checkboxes as the list of the ids checked.
 *
 * Asked for as `{node: true, user: false}`, stored as `[node]`: the way
 * pathauto stores its enabled entity types. An id the list leaves out
 * reads as left out of the map, so it starts from its entry's default.
 */
final class CheckedListLayout implements StoredSchemaInterface {

  /**
   * Constructs a CheckedListLayout.
   *
   * @param string $key
   *   The key asked for as a map and stored as a list.
   */
  public function __construct(
    protected readonly string $key,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toStorage(array $values): array {
    if (is_array($values[$this->key] ?? NULL)) {
      $values[$this->key] = array_map('strval', array_keys(array_filter($values[$this->key])));
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function fromStorage(array $settings): array {
    if (is_array($settings[$this->key] ?? NULL)) {
      $settings[$this->key] = array_fill_keys(array_map('strval', $settings[$this->key]), TRUE);
    }
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function storedSchema(): array {
    return [
      $this->key => [
        'type' => 'sequence',
        'sequence' => ['type' => 'string', 'label' => 'Type'],
      ],
    ];
  }

}
