<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The test action's configuration: a message and its level.
 *
 * Named by SurfaceTestAction with #[UsesStructure]. No refiner,
 * because no setting here depends on another.
 */
#[Structure('test.action')]
final class TestActionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('message', Schema::string()
      ->title(t('Message'))
      ->description(t('Shown to the person the action runs for.'))
      ->default('Done')
      ->maxLength(40)
      ->examples(['The article was published'])
      ->required());
    $inputs->addSchema('level', Schema::options([
      'status' => t('Status'),
      'warning' => t('Warning'),
    ])
      ->title(t('Level'))
      ->default('status'));
  }

}
