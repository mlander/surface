<?php

declare(strict_types=1);

namespace Drupal\surface\Variants;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The components the site offers to choose: all, or those it allows.
 *
 * `surface.settings:components_offered` is a list: an entry with a
 * colon is a component id (`provider:name`), one without is a provider,
 * meaning every component it provides; an empty list offers every
 * component the site has. ComponentProps and ComponentSlots read the
 * components through this class, so the two lists offer the same ones,
 * and each carries the settings' cache tag beside the extensions'.
 *
 * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
 */
final class ComponentsOffered implements ContainerInjectionInterface {

  /**
   * The setting that lists what is offered.
   */
  public const SETTING = 'components_offered';

  /**
   * What the lists depend on: the extensions installed, and the setting.
   */
  public const CACHE_TAGS = ['config:core.extension', 'config:surface.settings'];

  /**
   * Constructs the components offered.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $components
   *   The single-directory components the site has.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, which holds the setting.
   */
  public function __construct(
    protected readonly ComponentPluginManager $components,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('plugin.manager.sdc'), $container->get('config.factory'));
  }

  /**
   * Gets every component the site has.
   *
   * @return array<string, array>
   *   Each component's definition, by id, in the order of their ids.
   */
  public function all(): array {
    $all = [];
    foreach ($this->components->getDefinitions() as $id => $component) {
      $all[(string) $id] = $component;
    }
    ksort($all);
    return $all;
  }

  /**
   * Gets the components the setting offers.
   *
   * @return array<string, array>
   *   Each component's definition, by id, in the order of their ids.
   */
  public function offered(): array {
    $entries = $this->configFactory->get('surface.settings')->get(self::SETTING);
    $entries = is_array($entries) ? array_values(array_filter($entries, 'is_string')) : [];
    $all = $this->all();
    if ($entries === []) {
      return $all;
    }
    return array_filter($all, static function (array $component, string $id) use ($entries): bool {
      $provider = $component['provider'] ?? strstr($id, ':', TRUE);
      return in_array($id, $entries, TRUE) || in_array($provider, $entries, TRUE);
    }, ARRAY_FILTER_USE_BOTH);
  }

}
