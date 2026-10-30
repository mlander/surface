<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Resource\BlueprintInterface;

/**
 * A #[Resource] naming no storage, no identity and no host.
 *
 * Everything it says, a structure says, so discovery refuses it.
 * Outside src/Resource, as every class refused at discovery is: the test
 * hands it to a registry of its own.
 */
#[Resource('resource_test.broken.homeless')]
final class HomelessResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
