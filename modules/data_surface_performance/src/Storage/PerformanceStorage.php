<?php

declare(strict_types=1);

namespace Drupal\data_surface_performance\Storage;

use Drupal\Core\Asset\AssetCollectionOptimizerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigObjectStorage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps three of the performance settings in core's system.performance.
 *
 * Each key at the path core keeps it at, beside the settings no resource
 * here declares (css.compress, js.compress, fast_404), which a write keeps.
 * After a write it deletes the aggregated CSS and JavaScript files, as
 * core's performance form does, so the next page builds them under the
 * new settings.
 */
final class PerformanceStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'system.performance';

  /**
   * {@inheritdoc}
   */
  public const STORED_AT = [
    'max_age' => 'cache.page.max_age',
    'css_preprocess' => 'css.preprocess',
    'js_preprocess' => 'js.preprocess',
  ];

  /**
   * Constructs a PerformanceStorage.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Asset\AssetCollectionOptimizerInterface $cssOptimizer
   *   The CSS collection optimizer, whose aggregates a write deletes.
   * @param \Drupal\Core\Asset\AssetCollectionOptimizerInterface $jsOptimizer
   *   The JavaScript collection optimizer, whose aggregates a write
   *   deletes.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfig,
    LanguageManagerInterface $languageManager,
    TranslationInterface $string_translation,
    #[Autowire(service: 'asset.css.collection_optimizer')]
    protected readonly AssetCollectionOptimizerInterface $cssOptimizer,
    #[Autowire(service: 'asset.js.collection_optimizer')]
    protected readonly AssetCollectionOptimizerInterface $jsOptimizer,
  ) {
    parent::__construct($configFactory, $typedConfig, $languageManager, $string_translation);
  }

  /**
   * {@inheritdoc}
   *
   * Then deletes the aggregated CSS and JavaScript files, as core's
   * performance form does after it saves.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    parent::commit($context, $prepared, $account);
    $this->cssOptimizer->deleteAll();
    $this->jsOptimizer->deleteAll();
  }

}
