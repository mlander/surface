<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Target;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

/**
 * Keeps a larder in state, whole: what it is handed is what it stores.
 *
 * It writes exactly the array it is handed, so a rack the caller could
 * not see survives a save only because the build step hands back what
 * was stored under it.
 */
final class LarderTarget implements SurfaceTargetInterface {

  /**
   * Constructs a LarderTarget.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(protected readonly StateInterface $state) {}

  /**
   * Gets the state key a larder is kept under.
   *
   * @param string $larder
   *   The larder.
   *
   * @return string
   *   The key.
   */
  public static function key(string $larder): string {
    return 'data_surface_surface_test.larder.' . $larder;
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
      throw new \LogicException('A larder was committed with no larder known.');
    }
    $this->state->set(self::key($larder), $prepared);
  }

}
