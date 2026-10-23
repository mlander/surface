<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface_examples\ConceptCalls;

/**
 * Example 13: places the Spotlight block through the placement resource.
 *
 * The component block lives in data_surface_component_block and the
 * placement resource in data_surface_block, neither of which the examples
 * depend on. When both are on with this module, whichever came last, one
 * block is placed through the resource's add operation, with the values
 * example 13's first call sends: Spotlight, the card this module ships,
 * in the default theme's content region, shown on example 13's page
 * alone. Nothing is placed during a configuration import, which brings its
 * own blocks, when the block is placed already, or when the resource
 * refuses the values (a default theme with no content region).
 */
final class SpotlightBlockHooks {

  /**
   * The module the component block lives in.
   */
  public const MODULE = 'data_surface_component_block';

  /**
   * The module the placement resource lives in.
   */
  public const PLACEMENT = 'data_surface_block';

  /**
   * The block's machine name.
   */
  public const BLOCK = 'data_surface_examples_spotlight';

  /**
   * The page the block is shown on.
   */
  public const PAGE = '/surface-examples/13';

  /**
   * Constructs a SpotlightBlockHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the block storage.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The resources, which build the placement's add operation.
   * @param \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface $pipeline
   *   The pipeline the values are submitted through.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ResourcesInterface $resources,
    protected readonly DataSurfacePipelineInterface $pipeline,
  ) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || array_intersect($modules, ['data_surface_examples', self::MODULE, self::PLACEMENT]) === []) {
      return;
    }
    if ($this->moduleHandler->moduleExists(self::MODULE) && $this->moduleHandler->moduleExists(self::PLACEMENT)) {
      $this->place();
    }
  }

  /**
   * Places the Spotlight block, unless it is placed or is refused.
   *
   * @return bool
   *   TRUE when it was placed now.
   */
  public function place(): bool {
    if ($this->entityTypeManager->getStorage('block')->load(self::BLOCK) !== NULL) {
      return FALSE;
    }
    $context = $this->resources->operation('block.placement', 'add');
    $built = $this->resources->build('block.placement', $context);
    $result = $this->pipeline->submit($built, ConceptCalls::values('block.placement', 'place') ?? [], $this->resources->storage('block.placement', $context, $built));
    return $result->committed;
  }

}
