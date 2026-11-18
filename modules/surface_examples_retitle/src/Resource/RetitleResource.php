<?php

declare(strict_types=1);

namespace Drupal\surface_examples_retitle\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_retitle\Storage\RetitleStorage;
use Drupal\surface_examples_retitle\Structure\RetitleItemStructure;
use Drupal\surface_examples_retitle\TitleRewriter;
use PortableSchema\Builder\Schema;

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
    $inputs->attachList(
      'items',
      RetitleItemStructure::class,
      t('Nodes'),
      min: 1,
      max: 50,
      description: t('Each node to retitle, one to fifty.'),
      required: TRUE,
    );

    $inputs->add('style', Schema::options(TitleRewriter::styles())->type('string')
      ->title(t('Style'))
      ->description(t('How the title of a node sent no new title is rewritten.'))
      ->default(TitleRewriter::TITLE));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('titles', Schema::list(Schema::object([
      'node' => Schema::integer()->title(t('Node'))->required(),
      'old' => Schema::string()->title(t('Old title'))->required(),
      'new' => Schema::string()->title(t('New title'))->required(),
    ])->title(t('Title')))
      ->title(t('Titles'))
      ->description(t("Each node's title before and after, in the order sent: what a dry run would write, or what was written."))
      ->required());
  }

}
