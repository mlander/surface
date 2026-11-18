<?php

declare(strict_types=1);

namespace Drupal\surface_examples_retitle\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Example 17: one node to retitle, an item of the retitle's list.
 *
 * Its node is the thing the retitle's gates are asked about, for this
 * item alone.
 */
#[Structure('ai.retitle.item')]
final class RetitleItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('node', Schema::integer()
      ->title(t('Node'))
      ->description(t('The node to retitle.'))
      ->check('drupal:SurfaceValidReference', ['entityTypeId' => 'node'])
      ->required());

    $inputs->addSchema('new_title', Schema::string()
      ->title(t('New title'))
      ->description(t("Written as sent; left out, the node's title is rewritten in the style chosen."))
      ->maxLength(255));
  }

}
