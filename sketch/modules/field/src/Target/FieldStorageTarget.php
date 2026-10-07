<?php

declare(strict_types=1);

namespace Drupal\field\Target;

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\surface_sketch\Surface\SurfaceContext;
use Drupal\surface_sketch\Surface\SurfaceTargetInterface;

/**
 * Storage values live on the field storage config entity. The parent's
 * situation handed this subsurface its own context, so the identity
 * here is the storage's own.
 */
final class FieldStorageTarget implements SurfaceTargetInterface {

  public function load(SurfaceContext $context): array {
    $storage = $this->storage($context);
    return $storage === NULL ? [] : [
      'cardinality' => $storage->getCardinality(),
      'translatable' => $storage->isTranslatable(),
    ];
  }

  public function commit(SurfaceContext $context, array $values): void {
    $storage = $this->storage($context) ?? FieldStorageConfig::create([
      'entity_type' => $context->known['entity_type_id'],
      'field_name' => $context->known['field_name'],
      'type' => $context->known['field_type'],
    ]);
    $storage->setCardinality($values['cardinality'])
      ->setTranslatable($values['translatable'])
      ->save();
  }

  private function storage(SurfaceContext $context): ?FieldStorageConfig {
    $known = $context->known;
    return isset($known['entity_type_id'], $known['field_name'])
      ? FieldStorageConfig::loadByName($known['entity_type_id'], $known['field_name'])
      : NULL;
  }

}
