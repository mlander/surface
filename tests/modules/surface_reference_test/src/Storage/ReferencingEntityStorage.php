<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Storage;

use Drupal\surface\Storage\ConfigEntityStorage;

/**
 * Keeps the referencing resource on a config_test entity.
 *
 * The node is stored in its style, by uuid, and the role in its size, by
 * id: two string properties of the test entity.
 */
final class ReferencingEntityStorage extends ConfigEntityStorage {

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
    'page' => 'style',
    'role' => 'size',
  ];

}
