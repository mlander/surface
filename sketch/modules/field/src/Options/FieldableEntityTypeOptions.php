<?php

declare(strict_types=1);

namespace Drupal\field\Options;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

/**
 * Entity types a field can be added to.
 */
final class FieldableEntityTypeOptions implements OptionsSourceInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypes) {}

  public function getOptions(array $arguments = []): array {
    $options = [];
    foreach ($this->entityTypes->getDefinitions() as $id => $type) {
      if ($type->entityClassImplements(FieldableEntityInterface::class)) {
        $options[$id] = $type->getLabel();
      }
    }
    return $options;
  }

}
