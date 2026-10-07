<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * One #[AltersSurface] class, as discovery read it.
 *
 * @internal
 */
final class AlterDefinition {

  /**
   * Constructs an AlterDefinition.
   *
   * @param class-string $class
   *   The alter class, registered as an autowired service of that name.
   * @param string $module
   *   The module it is in, which is the provider its additions are
   *   mounted under.
   * @param string[] $situations
   *   The situations it applies in; empty for all.
   * @param \Drupal\data_surface\SurfaceBuild\RefinerDefinition[] $refiners
   *   Its #[RefinesInput] methods.
   */
  public function __construct(
    public readonly string $class,
    public readonly string $module,
    public readonly array $situations,
    public readonly array $refiners,
  ) {}

  /**
   * Answers whether the alter applies in one situation.
   *
   * @param string $operation
   *   The context's operation.
   *
   * @return bool
   *   TRUE when it lists no situations, or lists this one.
   */
  public function appliesIn(string $operation): bool {
    return $this->situations === [] || in_array($operation, $this->situations, TRUE);
  }

}
