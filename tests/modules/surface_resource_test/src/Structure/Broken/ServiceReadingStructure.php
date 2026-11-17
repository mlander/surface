<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Has two static refiners: one translates, one reads State.
 *
 * Built like any structure; only refining the size, once a shelf is given,
 * is refused, and only while assertions are on: a static refiner runs
 * with the global container guarded, and the State is a service. The
 * name's refiner translates with t(), which builds a lazy markup and
 * asks the container for nothing, so it passes.
 */
#[Structure('resource_test.broken.service_reading')]
final class ServiceReadingStructure implements StructureInterface {

  /**
   * The State key the size refiner reads.
   */
  public const LIMIT = 'surface_resource_test.size_limit';

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
  public static function nameOfKind(string $kind): Schema {
    if ($kind !== 'short') {
      return Schema::any();
    }
    return Schema::any()->maxLength(10)
      ->description(t('Up to @max characters for a @kind name.', ['@max' => 10, '@kind' => $kind]));
  }

  /**
   * Reads a limit from State, which a static refiner may not.
   */
  #[RefinesInput('size')]
  public static function sizeOnShelf(string $shelf): Schema {
    return Schema::any()->maximum((int) \Drupal::service('state')->get(self::LIMIT, 100));
  }

}
