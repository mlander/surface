<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A child whose shape cannot be enumerated statically: the escape hatch.
 *
 * No slot and no verb: `detail` is declared `any`, and a #[RefinesInput]
 * method returns the narrower definition, a map, once `kind` is known,
 * under the one narrowing rule that lets `any` become anything.
 */
#[Surface('surface_test.dynamic_child')]
final class DynamicChildSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['box', 'tube']]);
    $inputs->add('detail', 'any', 'Detail');
  }

  /**
   * A box has a width; a tube has a length.
   */
  #[RefinesInput('detail')]
  public static function detailOfKind(DataDefinitionInterface $detail, string $kind): DataDefinitionInterface {
    $map = MapDataDefinition::create()->setLabel('Detail');
    return $map->setPropertyDefinition(
      $kind === 'box' ? 'width' : 'length',
      DataDefinition::create('integer')->setLabel('Size')->setRequired(TRUE),
    );
  }

}
