<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * One #[Shape] class, as discovery read it, with the alters naming it.
 *
 * @internal
 */
final class ShapeDefinition {

  /**
   * Constructs a ShapeDefinition.
   *
   * @param class-string<\Drupal\surface\Resource\ShapeInterface> $class
   *   The shape's class.
   * @param string $id
   *   Its id, from #[Shape].
   * @param string $module
   *   The module it is in.
   * @param \Drupal\surface\ResourceBuild\AlterDefinition[] $alters
   *   Every alter of it, in discovery order.
   */
  public function __construct(
    public readonly string $class,
    public readonly string $id,
    public readonly string $module,
    public readonly array $alters = [],
  ) {}

  /**
   * Returns a copy with its alters.
   *
   * @param \Drupal\surface\ResourceBuild\AlterDefinition[] $alters
   *   The alters.
   *
   * @return static
   *   The copy.
   */
  public function withAlters(array $alters): static {
    return new static($this->class, $this->id, $this->module, $alters);
  }

}
