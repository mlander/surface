<?php

declare(strict_types=1);

namespace Drupal\demo\Options;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

/**
 * Configurable fields of one bundle. Both arguments come from a refiner.
 */
final class BundleFieldOptions implements OptionsSourceInterface {

  public function __construct(private readonly EntityFieldManagerInterface $fields) {}

  public function getOptions(array $arguments = []): array {
    $options = [];
    foreach ($this->fields->getFieldDefinitions($arguments['entity_type_id'], $arguments['bundle']) as $name => $definition) {
      if (!$definition->getFieldStorageDefinition()->isBaseField()) {
        $options[$name] = $definition->getLabel();
      }
    }
    return $options;
  }

}
