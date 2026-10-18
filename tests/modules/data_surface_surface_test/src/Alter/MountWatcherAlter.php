<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Alter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\BlueprintAdditionsInterface;
use Drupal\data_surface\Resource\AltersInputsInterface;
use Drupal\data_surface_surface_test\Structure\MountWatcherStructure;

/**
 * Refines a key it mounted, and the owner's, by watching a key it mounted.
 *
 * Both are allowed: the kind is read at its path inside the mount, and
 * handed as it stands, NULL while it is unanswered.
 */
#[Alters(MountWatcherStructure::class)]
final class MountWatcherAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('detail', 'string', 'Detail');
  }

  /**
   * Without a kind, the detail is short.
   */
  #[RefinesInput('detail')]
  public static function detailOfKind(DataDefinitionInterface $detail, ?string $kind): DataDefinitionInterface {
    return $kind === NULL ? $detail->addConstraint('Length', ['max' => 10]) : $detail;
  }

  /**
   * A large kind allows no more than a hundred.
   */
  #[RefinesInput('size')]
  public static function sizeOfKind(DataDefinitionInterface $size, ?string $kind): DataDefinitionInterface {
    return $kind === 'large' ? $size->addConstraint('Range', ['max' => 100]) : $size;
  }

}
