<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Stall;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A stall's awning: an anonymous map framed by a rule alone.
 *
 * Its shade declares plain keys and one rule between them, so the map is
 * a structure with no id for the rule's sake only: no child, no mixin, no
 * constraint on the frame. SchemaRoundTripTest reads it back.
 */
#[Structure('resource_test.awning')]
final class AwningStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addMap('shade', t('Shade'), static function (BlueprintInterface $shade): void {
      $shade->addSchema('kind', Schema::string()
        ->title(t('Kind')));
      $shade->addSchema('width', Schema::integer()
        ->title(t('Width')));
      $shade->narrow('width')
        ->when(Condition::equals('kind', 'wide'))
        ->require();
    });
  }

}
