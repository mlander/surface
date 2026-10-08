<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * One #[RefinesInput] method, as discovery read it.
 *
 * What the method watches is read here and checked when the surface is
 * built, not when it is discovered: the checks need the shape, and the
 * shape exists only once defineInputs() has run.
 *
 * @internal
 */
final class RefinerDefinition {

  /**
   * Constructs a RefinerDefinition.
   *
   * @param class-string $class
   *   The class the method is on: the surface, or an alter of it.
   * @param string $method
   *   The method name.
   * @param string $key
   *   The input key the method tightens.
   * @param string[]|null $watches
   *   The sibling keys the attribute lists, or NULL when it lists none
   *   and the parameter names say.
   * @param string[] $parameters
   *   The names of the parameters after the first, in order.
   * @param bool $takesDefinition
   *   Whether the method has a first parameter to receive the definition.
   * @param bool $isStatic
   *   Whether the method is static, as a surface's must be; an alter's
   *   may be either.
   */
  public function __construct(
    public readonly string $class,
    public readonly string $method,
    public readonly string $key,
    public readonly ?array $watches,
    public readonly array $parameters,
    public readonly bool $takesDefinition,
    public readonly bool $isStatic = FALSE,
  ) {}

  /**
   * Gets the sibling keys the method is handed, in parameter order.
   *
   * @return string[]
   *   The watched keys.
   */
  public function watched(): array {
    return $this->watches ?? $this->parameters;
  }

  /**
   * Names the method the way a message about it should.
   *
   * @return string
   *   Class::method().
   */
  public function describe(): string {
    return $this->class . '::' . $this->method . '()';
  }

}
