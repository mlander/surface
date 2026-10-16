<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Target;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

/**
 * Keeps a cellar in state, whole: its lists as they are handed over.
 */
final class CellarTarget implements SurfaceTargetInterface {

  /**
   * Constructs a CellarTarget.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * Gets the state key a cellar is kept under.
   *
   * @param string $larder
   *   The larder the cellar is.
   *
   * @return string
   *   The key.
   */
  public static function key(string $larder): string {
    return 'data_surface_surface_test.cellar.' . $larder;
  }

  /**
   * {@inheritdoc}
   */
  public function load(SurfaceContext $context): array {
    $larder = $context->known['larder'] ?? NULL;
    return is_string($larder) ? $this->state->get(self::key($larder), []) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(SurfaceContext $context, array $values): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $larder = $context->known['larder'] ?? $prepared['larder'] ?? NULL;
    if (!is_string($larder)) {
      throw new \LogicException('A cellar was committed with no larder known.');
    }
    $this->state->set(self::key($larder), $prepared);
  }

}
