<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Target;

use Drupal\Core\State\StateInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;

/**
 * Keeps the one pinned note in state.
 */
final class PinnedNoteTarget implements SurfaceTargetInterface {

  /**
   * The state key the note is kept under.
   */
  public const KEY = 'data_surface_surface_test.pinned_note';

  /**
   * Constructs a PinnedNoteTarget.
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
