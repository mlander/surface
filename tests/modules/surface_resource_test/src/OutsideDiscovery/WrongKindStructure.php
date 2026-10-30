<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Resource\BlueprintInterface;

/**
 * Carries #[Structure] and implements the other kind's interface.
 *
 * A class carries the attribute of its kind and implements the interface
 * of the same kind, so discovery refuses this one. Outside src/Structure,
 * as every class refused at discovery is: the test hands it to a
 * registry of its own.
 */
#[Structure('resource_test.broken.wrong_kind')]
final class WrongKindStructure implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
