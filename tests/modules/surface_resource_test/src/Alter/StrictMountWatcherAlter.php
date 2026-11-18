<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\Broken\StrictMountWatcherStructure;
use PortableSchema\Builder\Schema;

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
    $inputs->add('kind', Schema::string()
      ->title('Kind'));
  }

  /**
   * Watches the kind, which may be NULL, as a string.
   */
  #[RefinesInput('size')]
  public static function sizeOfKind(string $kind): Schema {
    return Schema::any();
  }

}
