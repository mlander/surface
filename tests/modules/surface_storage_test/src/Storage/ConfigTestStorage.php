<?php

declare(strict_types=1);

namespace Drupal\surface_storage_test\Storage;

use Drupal\surface\Storage\ConfigEntityResourceStorage;

/**
 * Keeps the test resource on a config_test entity.
 *
 * Its id, label, weight and style are properties of the same name; its
 * protected property is set through the entity's own setter.
 */
final class ConfigTestStorage extends ConfigEntityResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const ENTITY_TYPE_ID = 'config_test';

  /**
   * {@inheritdoc}
   */
  public const PROPERTIES = [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'style' => 'style',
    'protected_property' => [self::METHOD => 'setProtectedProperty'],
  ];

}
