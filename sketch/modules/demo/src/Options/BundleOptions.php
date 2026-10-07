<?php

declare(strict_types=1);

namespace Drupal\demo\Options;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

/**
 * Bundles of one entity type. The argument comes from refine().
 */
final class BundleOptions implements OptionsSourceInterface {

  public function __construct(private readonly EntityTypeBundleInfoInterface $bundles) {}

  public function getOptions(array $arguments = []): array {
    $options = [];
    foreach ($this->bundles->getBundleInfo($arguments['entity_type_id']) as $id => $info) {
      $options[$id] = $info['label'];
    }
    return $options;
  }

}
