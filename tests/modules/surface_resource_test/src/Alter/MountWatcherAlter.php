<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Structure\MountWatcherStructure;
use PortableSchema\Builder\Schema;

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
    $inputs->add('kind', Schema::string()
      ->title('Kind'));
    $inputs->add('detail', Schema::string()
      ->title('Detail'));
  }

  /**
   * Without a kind, the detail is short.
   */
  #[RefinesInput('detail')]
  public static function detailOfKind(?string $kind): Schema {
    return $kind === NULL ? Schema::any()->maxLength(10) : Schema::any();
  }

  /**
   * A large kind allows no more than a hundred.
   */
  #[RefinesInput('size')]
  public static function sizeOfKind(?string $kind): Schema {
    return $kind === 'large' ? Schema::any()->maximum(100) : Schema::any();
  }

}
