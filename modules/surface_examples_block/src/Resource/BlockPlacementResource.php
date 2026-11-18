<?php

declare(strict_types=1);

namespace Drupal\surface_examples_block\Resource;

use Drupal\block\BlockInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_block\Storage\BlockPlacementStorage;
use Drupal\surface_examples_block\Structure\VisibilityConditionStructure;
use Drupal\surface_examples_block\Variants\BlockPluginStructures;
use PortableSchema\Builder\Schema;

/**
 * A placed block: where it is, what it shows, and when.
 *
 * The block config entity, `block.block.<id>`, is the resource, by its
 * machine name. Its plugin chooses its settings, as a sibling key
 * chooses variants: a plugin that declares its settings as a structure
 * is that structure, and any other is read from its config schema
 * (BlockPluginStructures). The keys every block has, its label and
 * whether the label shows, are the resource's own, beside its theme,
 * region, weight and status; its visibility is a list of conditions,
 * each a condition plugin's settings chosen the same way.
 *
 * Three operations: add places a block under a machine name the caller
 * types, edit changes one in its theme, and remove takes it away. Each
 * asks "administer blocks", the block layout's permission.
 */
#[Resource('block.placement',
  label: new TranslatableMarkup('Placed block'),
  description: new TranslatableMarkup('A block placed in a region of a theme under a machine name: its label, the settings its plugin takes, its weight, its status and when it shows.'),
  identity: ['id'],
  storage: BlockPlacementStorage::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Place a block'),
  description: new TranslatableMarkup('Places a block under a machine name the caller chooses, in a region of a theme, with the settings its plugin takes; a machine name already in use is refused.'),
  permission: self::PERMISSION,
  creates: TRUE,
  idempotent: TRUE,
)]
final class BlockPlacementResource implements ResourceInterface {

  /**
   * The permission every operation asks: the block layout's.
   */
  public const PERMISSION = 'administer blocks';

  /**
   * Edits a placed block, named by its machine name, in its own theme.
   *
   * Moving a block to another theme is not offered: the theme is held to
   * the one it is in, which the placement storage reads off the block it
   * loads by the machine name.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a placed block'),
    description: new TranslatableMarkup('Changes a placed block, named by its machine name: its label, region, weight, status, plugin settings and visibility. Its theme stays as it is.'),
    permission: self::PERMISSION,
  )]
  public static function update(string $id): OperationContext {
    $block = \Drupal::classResolver(BlockPlacementStorage::class)->named($id, 'The "update" operation of block.placement');
    assert($block instanceof BlockInterface);
    return (new OperationContext(known: ['id' => (string) $block->id()]))
      ->withConstraint('theme', Schema::enum([$block->getTheme()]));
  }

  /**
   * Removes a placed block, named by its machine name.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Remove a placed block'),
    description: new TranslatableMarkup('Removes a placed block, named by its machine name, from its region.'),
    permission: self::PERMISSION,
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(string $id): OperationContext {
    $block = \Drupal::classResolver(BlockPlacementStorage::class)->named($id, 'The "delete" operation of block.placement');
    return new OperationContext(known: ['id' => (string) $block->id()]);
  }

  /**
   * {@inheritdoc}
   *
   * Core's block schema states the machine name's pattern, the label's
   * refusal of control characters and the weight's bounds; said here too,
   * so no door offers what the block's schema refuses.
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', Schema::string()
      ->title(t('Title'))
      ->description(t('The title of the block as shown to the user.'))
      ->maxLength(255)
      ->check('drupal:Regex', [
        'pattern' => '/([^\PC])/u',
        'match' => FALSE,
        'message' => t('The title may not span lines or hold control characters.'),
      ])
      ->required());

    $inputs->add('id', Schema::string()
      ->title(t('Machine name'))
      ->description(t('A unique name for the placed block: lowercase letters, numbers, underscores and periods, not starting with a period.'))
      ->maxLength(166)
      ->check('drupal:Regex', [
        'pattern' => '/^(?!\.)[a-z0-9_.]+$/D',
        'message' => t('The machine name may hold only lowercase letters, numbers, underscores and periods, and may not start with a period.'),
      ])
      ->required());

    $inputs->add('label_display', Schema::bool()
      ->title(t('Display title'))
      ->description(t('Whether the title is shown above the block.'))
      ->default(TRUE));

    $inputs->add('plugin', Schema::string()
      ->title(t('Block'))
      ->description(t('What the block shows: a block plugin the block layout offers. A block that reads a context, such as the node of the page, is not offered yet.'))
      ->required());

    $inputs->attachVariants(
      'settings',
      by: 'plugin',
      label: t('Settings'),
      from: BlockPluginStructures::class,
      description: t('The settings of the block chosen, as its plugin declares them.'),
    );

    $inputs->add('theme', Schema::string()
      ->title(t('Theme'))
      ->description(t('The theme the block is placed in.'))
      ->check('drupal:ExtensionExists', ['type' => 'theme'])
      ->required());

    // A region of any installed theme, until the theme narrows it to its
    // own: every door offers a list before a theme is chosen.
    $inputs->add('region', Schema::string()
      ->title(t('Region'))
      ->description(t('The region of the theme the block is shown in.'))
      ->check('drupal:SurfaceAnyThemeRegion')
      ->required());

    $inputs->add('weight', Schema::integer()
      ->title(t('Weight'))
      ->description(t('The order of the block in its region: lighter blocks are shown first.'))
      ->default(0)
      ->minimum(-2147483648)
      ->maximum(2147483647));

    $inputs->add('status', Schema::bool()
      ->title(t('Enabled'))
      ->description(t('Whether the block is shown at all.'))
      ->default(TRUE));

    $inputs->attachList(
      'visibility',
      VisibilityConditionStructure::class,
      t('Visibility'),
      description: t('The conditions the block is shown under, each at most once; with none, it is shown on every page.'),
    );
  }

  /**
   * The regions the chosen theme shows, as the block layout offers them.
   */
  #[RefinesInput('region')]
  public static function regionsOfTheme(string $theme): Schema {
    return Schema::any()->check('drupal:SurfaceThemeRegion', ['theme' => $theme]);
  }

}
