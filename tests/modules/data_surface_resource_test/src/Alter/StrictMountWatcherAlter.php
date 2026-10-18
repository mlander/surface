<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_resource_test\Structure\Broken\StrictMountWatcherStructure;

/**
 * Watches a key it mounted with a parameter that cannot take NULL.
 *
 * A mounted key is handed as it stands, NULL until it is answered, so
 * this is refused when the structure is built rather than failing on the
 * first empty value.
 */
#[Alters(StrictMountWatcherStructure::class)]
final class StrictMountWatcherAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
  }

  /**
   * Watches the kind, which may be NULL, as a string.
   */
  #[RefinesInput('size')]
  public static function sizeOfKind(DataDefinitionInterface $size, string $kind): DataDefinitionInterface {
    return $size;
  }

}
