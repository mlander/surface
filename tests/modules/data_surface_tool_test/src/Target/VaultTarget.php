<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Target;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

/**
 * Keeps the vault's settings in state, secrets and all.
 */
final class VaultTarget implements SurfaceTargetInterface {

  /**
   * The state key the settings are kept under.
   */
  public const KEY = 'data_surface_tool_test.vault';

  /**
   * Constructs a VaultTarget.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceContext $context): array {
    return $this->state->get(self::KEY, []);
  }

  /**
   * {@inheritdoc}
   *
   * State has no schema and refuses nothing.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $this->state->set(self::KEY, $prepared);
  }

}
