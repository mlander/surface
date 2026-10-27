<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Storage;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the alias settings, the types as the list of those checked.
 */
final class AliasSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_schema_test.aliases';

  /**
   * The key asked for as a map of checkboxes and stored as a list of ids.
   */
  public const LISTED = 'types';

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $values = parent::load($context);
    if (is_array($values[self::LISTED] ?? NULL)) {
      $values[self::LISTED] = array_fill_keys(array_map('strval', $values[self::LISTED]), TRUE);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    if (is_array($values[self::LISTED] ?? NULL)) {
      $values[self::LISTED] = array_map('strval', array_keys(array_filter($values[self::LISTED])));
    }
    return parent::prepare($context, $values, $account);
  }

  /**
   * {@inheritdoc}
   *
   * The types are a map of booleans to the resource and a list of ids to
   * the schema; load() and prepare() translate, so they are not compared.
   */
  public function widerThanSchema(DataSurfaceInterface $built): array {
    return array_filter(
      parent::widerThanSchema($built),
      static fn (string $path): bool => $path !== self::LISTED && !str_starts_with($path, self::LISTED . '.'),
      ARRAY_FILTER_USE_KEY,
    );
  }

}
