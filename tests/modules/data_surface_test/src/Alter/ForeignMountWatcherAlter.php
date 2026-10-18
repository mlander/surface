<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_surface_test\Structure\MountWatcherStructure;

/**
 * Watches a key another module mounted, which is refused.
 *
 * The kind is data_surface_surface_test's. Applies only where that module
 * is enabled too, since the structure it names is that module's.
 */
#[Alters(MountWatcherStructure::class)]
final class ForeignMountWatcherAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * Watches the other module's kind.
   */
  #[RefinesInput('note')]
  public static function noteOfKind(DataDefinitionInterface $note, ?string $kind): DataDefinitionInterface {
    return $note;
  }

}
