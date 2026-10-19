<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The workshop's benches: one key per bench the site's configuration lists.
 *
 * Read from the data_surface_resource_test.benches configuration object,
 * whose cacheability is the list's: saving it changes the keys of every
 * resource that asks for one per bench. A bench marked locked there is an
 * entry shown and held to what is stored.
 */
final class WorkshopBenches implements KeysFromSiteInterface, ContainerInjectionInterface {

  /**
   * The configuration object the benches are listed in.
   */
  public const CONFIG = 'data_surface_resource_test.benches';

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    $config = $this->configFactory->get(self::CONFIG);
    $keys = (new KeyedList())->addCacheableDependency($config);
    foreach ($config->get('benches') ?? [] as $id => $bench) {
      $keys->add((string) $id, (string) $bench['label'], locked: (bool) ($bench['locked'] ?? FALSE));
    }
    return $keys;
  }

}
