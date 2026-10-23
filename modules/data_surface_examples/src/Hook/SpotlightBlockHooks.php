<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Example 13: places the Spotlight block once its plugin is there.
 *
 * The component block lives in data_surface_component_block, which the
 * examples do not depend on. When that module and this one are both on,
 * whichever came second, one block is placed: Spotlight, the card this
 * module ships, in the default theme's content region, shown on example
 * 13's page alone, below what the page says. Nothing is placed during a
 * configuration import, which brings its own blocks, or where the default
 * theme has no content region.
 */
final class SpotlightBlockHooks {

  /**
   * The module the component block lives in.
   */
  public const MODULE = 'data_surface_component_block';

  /**
   * The block's machine name.
   */
  public const BLOCK = 'data_surface_examples_spotlight';

  /**
   * The page the block is shown on.
   */
  public const PAGE = '/surface-examples/13';

  /**
   * The block's settings as placed: the card, as example 12 ships it.
   */
  public const SETTINGS = [
    'label' => 'Spotlight',
    'label_display' => 'visible',
    'component' => 'data_surface_examples:card',
    'props' => [
      'heading' => 'Doors open at half past six',
      'body' => 'Bring your ticket, on your phone or printed.',
      'tone' => 'highlight',
      'link' => [
        'url' => 'https://example.com/tickets',
        'label' => 'Find your ticket',
      ],
    ],
  ];

  /**
   * Constructs a SpotlightBlockHooks object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the block storage.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configs
   *   The config factory, for the default theme.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themes
   *   The theme handler, for the default theme's regions.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configs,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ThemeHandlerInterface $themes,
  ) {}

  /**
   * Implements hook_modules_installed().
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || array_intersect($modules, ['data_surface_examples', self::MODULE]) === [] || !$this->moduleHandler->moduleExists(self::MODULE)) {
      return;
    }
    $this->place();
  }

  /**
   * Places the Spotlight block, unless it is placed or has nowhere to go.
   */
  public function place(): void {
    $storage = $this->entityTypeManager->getStorage('block');
    $theme = (string) $this->configs->get('system.theme')->get('default');
    if ($storage->load(self::BLOCK) !== NULL || !$this->themes->themeExists($theme) || !isset($this->themes->getTheme($theme)->info['regions']['content'])) {
      return;
    }
    $storage->create([
      'id' => self::BLOCK,
      'theme' => $theme,
      'region' => 'content',
      'weight' => 100,
      'plugin' => 'data_surface_component',
      'settings' => self::SETTINGS,
      'visibility' => [
        'request_path' => [
          'id' => 'request_path',
          'negate' => FALSE,
          'pages' => self::PAGE,
        ],
      ],
    ])->save();
  }

}
