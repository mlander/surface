<?php

declare(strict_types=1);

namespace Drupal\surface_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\MountWatcherStructure;

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
