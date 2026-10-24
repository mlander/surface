<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Variants;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\data_surface\Contract\ConfigSchemaDocument;
use Drupal\data_surface\Resource\Attribute\UsesStructure;
use Drupal\data_surface\Resource\VariantList;
use Drupal\data_surface\Resource\VariantsFromSiteInterface;

/**
 * One variant per block plugin the block layout offers: its settings.
 *
 * The plugins are the ones the block layout lists to place, filtered as
 * it filters them, less every plugin that needs a context: which context
 * a placed block reads is open, so such a plugin is not offered yet. Each
 * plugin is offered under its admin label, in the order of their ids.
 *
 * What each one's settings are:
 *
 * - **A structure the plugin declares.** A plugin whose class names its
 *   settings with #[UsesStructure] (the component block, the demo block)
 *   is that structure, built as declared, its refiners, children and
 *   variants with it.
 * - **Its config schema, read.** Any other plugin's settings are what
 *   `block.settings.<plugin id>` says, falling back to
 *   `block.settings.*` as typed config does, less the keys every block
 *   has, which the placement holds itself (its label, whether the label
 *   shows, the provider, the context mapping), written as JSON Schema
 *   (ConfigSchemaDocument) for the schema reader. The plugin's own
 *   defaults are each key's default.
 *
 * Which plugins there are follows from the extensions installed, from the
 * content blocks a site has and from its menus, so the list is cached by
 * those tags and by the plugin manager's own.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
final class BlockPluginStructures implements VariantsFromSiteInterface {

  /**
   * The keys of a block's settings every block has, which no variant holds.
   */
  public const BASE = ['id', 'label', 'label_display', 'provider', 'context_mapping'];

  /**
   * The plugins never offered: the one standing in for a missing plugin.
   */
  protected const NEVER = ['broken'];

  /**
   * The list, once read in this request.
   */
  protected ?VariantList $list = NULL;

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Block\BlockManagerInterface $blocks
   *   The block plugin manager.
   * @param \Drupal\data_surface\Contract\ConfigSchemaDocument $schemas
   *   Writes a plugin's settings schema as JSON Schema.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, asked which modules derive plugins from content.
   */
  public function __construct(
    protected readonly BlockManagerInterface $blocks,
    protected readonly ConfigSchemaDocument $schemas,
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function variants(): VariantList {
    if ($this->list !== NULL) {
      return $this->list;
    }
    $list = (new VariantList())->addCacheTags(['config:core.extension']);
    if ($this->blocks instanceof CacheableDependencyInterface) {
      $list->addCacheableDependency($this->blocks);
    }
    // Content blocks and menu blocks are derived from what a site has.
    if ($this->moduleHandler->moduleExists('block_content')) {
      $list->addCacheTags(['block_content_list']);
    }
    $list->addCacheTags(['config:system.menu_list']);
    $definitions = $this->placeable();
    foreach ($definitions as $id => $definition) {
      $label = $definition['admin_label'] ?? $id;
      $structure = $definition[UsesStructure::DEFINITION_KEY] ?? NULL;
      if (is_string($structure) && $structure !== '') {
        $list->addStructure($id, $label, $structure);
        continue;
      }
      $list->add($id, $label, $this->schemas->object('block.settings.' . $id, self::BASE, $this->defaults($id)));
    }
    return $this->list = $list;
  }

  /**
   * Lists the plugins the block layout offers, less those needing context.
   *
   * @return array<string, array>
   *   The plugin definitions, by id, in the order of their ids.
   */
  public function placeable(): array {
    // No context is available here, so a plugin requiring one is left out,
    // as the block layout leaves one out whose context the page lacks.
    $definitions = $this->blocks->getFilteredDefinitions('block_ui', []);
    $placeable = [];
    foreach ($definitions as $id => $definition) {
      $id = (string) $id;
      if (in_array($id, self::NEVER, TRUE) || !empty($definition['_block_ui_hidden']) || self::needsContext($definition)) {
        continue;
      }
      $placeable[$id] = $definition;
    }
    ksort($placeable);
    return $placeable;
  }

  /**
   * Answers whether a plugin declares a context it reads.
   *
   * @param array $definition
   *   The plugin definition.
   *
   * @return bool
   *   TRUE when it declares any context, required or not: a placed
   *   block's context mapping is not offered yet.
   */
  protected static function needsContext(array $definition): bool {
    foreach ($definition['context_definitions'] ?? [] as $context) {
      if ($context instanceof ContextDefinitionInterface) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads a plugin's own settings defaults, less the keys every block has.
   *
   * @param string $id
   *   The plugin id.
   *
   * @return array
   *   The defaults, by key; none when the plugin cannot be built alone.
   */
  protected function defaults(string $id): array {
    try {
      $plugin = $this->blocks->createInstance($id);
    }
    catch (\Throwable) {
      return [];
    }
    return $plugin instanceof BlockPluginInterface ? array_diff_key($plugin->getConfiguration(), array_flip(self::BASE)) : [];
  }

}
