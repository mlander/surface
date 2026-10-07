<?php

declare(strict_types=1);

namespace Drupal\editorial\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\field\Surface\FieldInstanceSurface;
use Drupal\surface_sketch\Surface\Attribute\AltersSurface;
use Drupal\surface_sketch\Surface\Attribute\RefinesInput;
use Drupal\surface_sketch\Surface\ShapeAdditionsInterface;
use Drupal\surface_sketch\Surface\SurfaceAlterInterface;

/**
 * An editorial module's rule for EVERY field: it targets the outer surface.
 * It adds a key and tightens another, so it does both jobs.
 */
#[AltersSurface(FieldInstanceSurface::class)]
final class RequiredFieldsNeedHelpTextAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('help_link', 'uri', 'Link to editorial guidance');
  }

  /**
   * A required field must explain itself.
   */
  #[RefinesInput('description')]
  public function requiredFieldsExplainThemselves(DataDefinitionInterface $description, bool $required): DataDefinitionInterface {
    return $required ? $description->setRequired(TRUE) : $description;
  }

}
