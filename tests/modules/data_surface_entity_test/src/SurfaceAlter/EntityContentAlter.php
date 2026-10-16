<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity_test\SurfaceAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;

/**
 * Alters every content entity surface at once, by the deriver's base id.
 */
#[AltersSurface('entity.content')]
final class EntityContentAlter implements SurfaceAlterInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('reviewer_note', 'string', $this->t('Reviewer note'));
  }

}
