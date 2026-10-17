<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a static refiner that answers differently each time it is asked.
 *
 * It reads no service, so the container guard lets it through; it
 * counts its own calls, which is a dependency on something besides what
 * it watches all the same. Enumerating the size, the emitter asks it
 * twice for one kind and, with assertions on, refuses the second answer.
 */
#[Structure('surface_test.broken.fickle')]
final class FickleStructure implements StructureInterface {

  /**
   * How many times the size has been refined.
   *
   * @var int
   */
  public static int $calls = 0;

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['small', 'large']]);
    $inputs->add('size', 'integer', 'Size');
  }

  /**
   * A maximum that moves every time it is asked.
   */
  #[RefinesInput('size')]
  public static function sizeOfKind(DataDefinitionInterface $size, string $kind): DataDefinitionInterface {
    return $size->addConstraint('Range', ['max' => 100 - ++self::$calls]);
  }

}
