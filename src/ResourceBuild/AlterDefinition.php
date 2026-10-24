<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * One #[Alters] class, as discovery read it.
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
   * @param string[] $operations
   *   The operations it applies in; empty for all.
   * @param \Drupal\data_surface\ResourceBuild\RefinerDefinition[] $refiners
   *   Its #[RefinesInput] methods.
   * @param bool $answersAccess
   *   Whether it answers access too (OperationAccessInterface).
   */
  public function __construct(
    public readonly string $class,
    public readonly string $module,
    public readonly array $operations,
    public readonly array $refiners,
    public readonly bool $answersAccess = FALSE,
  ) {}

  /**
   * Answers whether the alter applies in one operation.
   *
   * @param string $operation
   *   The context's id.
   *
   * @return bool
   *   TRUE when it lists no operations, or lists this one.
   */
  public function appliesIn(string $operation): bool {
    return $this->operations === [] || in_array($operation, $this->operations, TRUE);
  }

}
