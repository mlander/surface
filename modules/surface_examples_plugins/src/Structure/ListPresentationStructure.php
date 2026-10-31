<?php

declare(strict_types=1);

namespace Drupal\surface_examples_plugins\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * What the example block needs to know when it renders a list.
 *
 * One of the two variants of the block's presentation settings, the one
 * for `list`, declared with #[Variant]; the grid is the other. Nothing here
 * depends on anything, and nothing here can see the block's own keys.
 *
 * @see \Drupal\surface_examples_plugins\Structure\ExampleBlockStructure
 */
#[Structure('block.surface_examples_plugins.presentation.list',
  label: new TranslatableMarkup('List presentation'),
  description: new TranslatableMarkup('What the example block needs to render a list: whether summaries show.'),
)]
#[Variant(of: ExampleBlockStructure::class, at: 'presentation_settings', for: 'list', label: new TranslatableMarkup('List'))]
final class ListPresentationStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('show_summary', 'boolean', t('Show summaries'), default: TRUE)
      ->setDescription(t('Whether item summaries render.'));
  }

}
