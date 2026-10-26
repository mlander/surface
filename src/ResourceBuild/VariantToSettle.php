<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\SettlesChildInterface;

/**
 * A variant the site lists, built by the build step the first time it is read.
 *
 * Holds what the build step needs to build it as it would have with its
 * parent: the variant, the context it is invoked in, whether its access
 * locked it whole, and, shared by every variant at the key, where it sits
 * and the build it was held in (VariantsToSettle). Built once, then kept.
 *
 * Stored with a build for no account (buildDeclared(), which the build
 * cache keeps) it is stored as what builds it, so what was never read is
 * not written: a request that reads the cached build builds a variant
 * only when a door reads that variant. Any other is built first and
 * stored built, since it was built for an account that is not stored
 * with it.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @internal
 */
final class VariantToSettle implements SettlesChildInterface {

  /**
   * The variant, once built.
   */
  private ?DataSurfaceInterface $surface = NULL;

  /**
   * Constructs a VariantToSettle.
   *
   * @param \Drupal\data_surface\ResourceBuild\VariantsToSettle $at
   *   Where it sits and the build it was held in.
   * @param string $name
   *   The variant, by the name it is built by.
   * @param \Drupal\data_surface\Resource\OperationContext|null $context
   *   The context it is invoked in.
   * @param string|null $locked
   *   Why its access locked it whole, or NULL.
   * @param bool $read
   *   Whether it is a structure read from a schema, built once per
   *   request wherever it is attached.
   */
  public function __construct(
    private VariantsToSettle $at,
    private readonly string $name,
    private ?OperationContext $context,
    private readonly ?string $locked,
    private readonly bool $read,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function settle(): DataSurfaceInterface {
    if ($this->surface === NULL) {
      assert($this->context !== NULL);
      $this->surface = $this->at->settle($this->name, $this->context, $this->locked, $this->read);
      $this->context = NULL;
    }
    return $this->surface;
  }

  /**
   * {@inheritdoc}
   */
  public function settled(): ?DataSurfaceInterface {
    return $this->surface;
  }

  /**
   * Stores what builds a variant held for no account, else the variant.
   *
   * @return array
   *   What builds it, or the built variant.
   */
  public function __serialize(): array {
    if ($this->surface === NULL && $this->at->storesWhatBuilds()) {
      return [
        'at' => $this->at,
        'name' => $this->name,
        'context' => $this->context,
        'locked' => $this->locked,
        'read' => $this->read,
      ];
    }
    return [
      'at' => $this->at,
      'surface' => $this->settle(),
      'name' => $this->name,
      'locked' => $this->locked,
      'read' => $this->read,
    ];
  }

  /**
   * Restores what builds the variant, or the built variant.
   *
   * @param array $data
   *   What __serialize() stored.
   */
  public function __unserialize(array $data): void {
    $this->surface = $data['surface'] ?? NULL;
    $this->at = $data['at'];
    $this->name = $data['name'];
    $this->context = $data['context'] ?? NULL;
    $this->locked = $data['locked'];
    $this->read = $data['read'];
  }

}
