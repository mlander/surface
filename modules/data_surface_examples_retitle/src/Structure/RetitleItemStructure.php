<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_retitle\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

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
    $inputs->add('node', 'entity:node', t('Node'))
      ->setDescription(t('The node to retitle.'))
      ->setRequired(TRUE);
    $inputs->add('new_title', 'string', t('New title'))
      ->setDescription(t("Written as sent; left out, the node's title is rewritten in the style chosen."))
      ->addConstraint('Length', ['max' => 255]);
  }

}
