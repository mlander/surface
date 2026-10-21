<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * What the demo block needs to know when it renders a list.
 *
 * One of the two variants of the block's presentation settings, the one
 * for `list`, declared with #[Variant]; the grid is the other. Nothing here
 * depends on anything, and nothing here can see the block's own keys.
 *
 * @see \Drupal\data_surface_demo\Structure\DemoBlockStructure
 */
#[Structure('block.data_surface_demo.presentation.list')]
#[Variant(of: DemoBlockStructure::class, at: 'presentation_settings', for: 'list', label: new TranslatableMarkup('List'))]
final class ListPresentationStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('show_summary', 'boolean', t('Show summaries'), default: TRUE)
      ->setDescription(t('Whether item summaries render.'));
  }

}
