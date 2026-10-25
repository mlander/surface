<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_retitle\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\DefinesOutputsInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples_retitle\Storage\RetitleStorage;
use Drupal\data_surface_examples_retitle\Structure\RetitleItemStructure;
use Drupal\data_surface_examples_retitle\TitleRewriter;

/**
 * Example 17: a bulk retitle that borrows each node's access.
 *
 * Its keys, its storage and its permission are its own. Which nodes it
 * may retitle is not: each item's node is asked core's update access and
 * its title field's edit access, the two answers node's form asks, with
 * every module that alters them, and a node either refuses is refused on
 * its own item. Nothing here copies a check.
 */
#[Resource('ai.retitle', storage: RetitleStorage::class)]
#[Operation('retitle',
  label: new TranslatableMarkup('Retitle nodes'),
  description: new TranslatableMarkup('Rewrites the titles of one to fifty nodes in the style chosen, each node one the account may update and whose title it may edit; a new title sent is written as sent.'),
  permission: 'retitle nodes',
  gate: ['entity:node:update', 'field:node.title:edit'],
)]
final class RetitleResource implements ResourceInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('items', RetitleItemStructure::class, min: 1, max: 50)
      ->setLabel(t('Nodes'))
      ->setDescription(t('Each node to retitle, one to fifty.'))
      ->setRequired(TRUE);
    $inputs->add('style', 'string', t('Style'), default: TitleRewriter::TITLE)
      ->setDescription(t('How the title of a node sent no new title is rewritten.'))
      ->addConstraint('LabeledChoice', ['choices' => TitleRewriter::styles()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $title = MapDataDefinition::create()
      ->setLabel(t('Title'))
      ->setPropertyDefinition('node', DataDefinition::create('integer')->setLabel(t('Node'))->setRequired(TRUE))
      ->setPropertyDefinition('old', DataDefinition::create('string')->setLabel(t('Old title'))->setRequired(TRUE))
      ->setPropertyDefinition('new', DataDefinition::create('string')->setLabel(t('New title'))->setRequired(TRUE));
    $outputs->addList('titles', $title, t('Titles'))
      ->setDescription(t("Each node's title before and after, in the order sent: what a dry run would write, or what was written."))
      ->setRequired(TRUE);
  }

}
