<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has two static refiners: one translates, one reads State.
 *
 * Built like any structure; only refining the size, once a shelf is given,
 * is refused, and only while assertions are on: a static refiner runs
 * with the global container guarded, and the State is a service. The
 * name's refiner translates with t(), which builds a lazy markup and
 * asks the container for nothing, so it passes.
 */
#[Structure('surface_test.broken.service_reading')]
final class ServiceReadingStructure implements StructureInterface {

  /**
   * The State key the size refiner reads.
   */
  public const LIMIT = 'data_surface_surface_test.size_limit';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind')->addConstraint('Choice', ['choices' => ['short', 'long']]);
    $inputs->add('shelf', 'string', 'Shelf');
    $inputs->add('name', 'string', 'Name');
    $inputs->add('size', 'integer', 'Size');
  }

  /**
   * A short kind has a short name, said in a translatable description.
   */
  #[RefinesInput('name')]
  public static function nameOfKind(DataDefinitionInterface $name, string $kind): DataDefinitionInterface {
    if ($kind !== 'short' || !$name instanceof DataDefinition) {
      return $name;
    }
    return $name->addConstraint('Length', ['max' => 10])
      ->setDescription(t('Up to @max characters for a @kind name.', ['@max' => 10, '@kind' => $kind]));
  }

  /**
   * Reads a limit from State, which a static refiner may not.
   */
  #[RefinesInput('size')]
  public static function sizeOnShelf(DataDefinitionInterface $size, string $shelf): DataDefinitionInterface {
    return $size->addConstraint('Range', ['max' => (int) \Drupal::service('state')->get(self::LIMIT, 100)]);
  }

}
