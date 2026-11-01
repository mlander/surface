<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What the example block needs to know when it renders a grid.
 *
 * The other variant of the block's presentation settings.
 *
 * @see \Drupal\surface_examples_plugins\Structure\ExampleBlockStructure
 */
#[Structure('block.surface_examples_plugins.presentation.grid',
  label: new TranslatableMarkup('Grid presentation'),
  description: new TranslatableMarkup('What the example block needs to render a grid: how many columns, and whether summaries show.'),
)]
#[Variant(of: ExampleBlockStructure::class, at: 'presentation_settings', for: 'grid', label: new TranslatableMarkup('Grid'))]
final class GridPresentationStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('columns', 'integer', t('Columns'), default: 3)
      ->setDescription(t('How many items sit side by side.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 6]);
  }

}
