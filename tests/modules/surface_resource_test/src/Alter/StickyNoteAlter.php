<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Alter;

use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_resource_test\Plugin\Block\StickyNoteBlock;

/**
 * Alters a plugin that is its own structure, naming the plugin class.
 */
#[Alters(StickyNoteBlock::class)]
final class StickyNoteAlter implements AltersInputsInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->describe('note', description: 'Written on the note.');
  }

}
