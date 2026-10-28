<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Keeps the one relay hub in state.
 *
 * Its resource is registered only by the test that says so, so nothing
 * registers this as a service: the class resolver builds it through
 * create().
 */
final class RelayHubStorage implements ResourceStorageInterface, ContainerInjectionInterface {

  /**
   * The state key the hub is kept under.
   */
  public const KEY = 'data_surface_resource_test.relay_hub';

  /**
   * Constructs a RelayHubStorage.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    return $this->state->get(self::KEY, []);
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $this->state->set(self::KEY, $prepared);
  }

}
