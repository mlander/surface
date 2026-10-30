<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A provider of keys for DoorSpecificRuleTest, reading the request.
 */
final class DoorSpecificKeysFixture implements SiteListInterface {

  /**
   * Built with the request stack: flagged.
   */
  public function __construct(
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Asks the container for the session: flagged.
   */
  public static function create(ContainerInterface $container): self {
    $container->get('session');
    return new self($container->get('request_stack'));
  }

  /**
   * Reads the route: flagged; configuration: not flagged.
   */
  public function list(): SiteList {
    \Drupal::service('config.factory');
    return SiteList::fromLabels([(string) \Drupal::routeMatch()->getRouteName() => 'Route']);
  }

}
