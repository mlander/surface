<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity_test\Alter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;

/**
 * Alters every content entity resource at once, by the deriver's base id.
 */
#[Alters('entity.content')]
final class EntityContentAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('reviewer_note', 'string', $this->t('Reviewer note'));
  }

}
