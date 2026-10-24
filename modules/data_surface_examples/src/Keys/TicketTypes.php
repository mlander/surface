<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Keys;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Example 9: the ticket types the site sells, one key each.
 *
 * Read from the site's configuration whenever the resource is built, and
 * cacheable by that configuration's tag, so a type added there is a key at
 * the next request with no cache rebuild. A type marked locked there is
 * shown and kept as stored.
 */
final class TicketTypes implements SiteListInterface, ContainerInjectionInterface {

  /**
   * The configuration object the ticket types are listed in.
   */
  public const CONFIG = 'data_surface_examples.ticket_types';

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
  public function list(): SiteList {
    $config = $this->configFactory->get(self::CONFIG);
    $keys = (new SiteList())->addCacheableDependency($config);
    foreach ($config->get('types') ?? [] as $id => $type) {
      $keys->add((string) $id, (string) $type['label'], locked: (bool) ($type['locked'] ?? FALSE));
    }
    return $keys;
  }

}
