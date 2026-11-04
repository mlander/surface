<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Structure;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\surface\Resource\Attribute\Structure;

/**
 * What the sponsorship is linked to: a venue or a role.
 *
 * Node is among the targets and is no config entity type, so the
 * interface leaves it out: both filters hold.
 */
#[Structure('event.sponsorship.setting')]
final class SettingStructure extends AnyEntityStructure {

  /**
   * {@inheritdoc}
   */
  protected const ENTITY_TYPES = [
    'interface' => ConfigEntityInterface::class,
    'targets' => ['event_venue' => [], 'user_role' => [], 'node' => []],
  ];

}
