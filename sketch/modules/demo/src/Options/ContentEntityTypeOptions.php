<?php

declare(strict_types=1);

namespace Drupal\demo\Options;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\surface_sketch\Options\OptionsSourceInterface;

final class ContentEntityTypeOptions implements OptionsSourceInterface {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypes) {}

  public function getOptions(array $arguments = []): array {
    $options = [];
    foreach ($this->entityTypes->getDefinitions() as $id => $type) {
      if ($type->entityClassImplements(ContentEntityInterface::class)) {
        $options[$id] = $type->getLabel();
      }
    }
    return $options;
  }

}
