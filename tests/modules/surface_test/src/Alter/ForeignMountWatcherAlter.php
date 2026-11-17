<?php

declare(strict_types=1);

namespace Drupal\surface_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\MountWatcherStructure;
use PortableSchema\Builder\Schema;

/**
 * Watches a key another module mounted, which is refused.
 *
 * The kind is surface_resource_test's. Applies only where that module
 * is enabled too, since the structure it names is that module's.
 */
#[Alters(MountWatcherStructure::class)]
final class ForeignMountWatcherAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->addSchema('note', Schema::string()
      ->title('Note'));
  }

  /**
   * Watches the other module's kind.
   */
  #[RefinesInput('note')]
  public static function noteOfKind(?string $kind): Schema {
    return Schema::any();
  }

}
