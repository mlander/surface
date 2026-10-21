<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

/**
 * One #[RefinesInput] method, as discovery read it.
 *
 * What the method watches is read here and checked when its class is
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
   *   The class the method is on: the resource or structure, or an alter
   *   of it.
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
   *   Whether the method is static, as the owner's must be; an alter's
   *   may be either. Not to be confused with the refiner's kind.
   * @param bool $runtime
   *   Whether the attribute declares it a runtime refiner: one whose
   *   answer nothing may compute ahead of time.
   * @param bool $mayNotApply
   *   Whether its return type names DoesNotApply: it may answer that the
   *   key does not apply.
   */
  public function __construct(
    public readonly string $class,
    public readonly string $method,
    public readonly string $key,
    public readonly ?array $watches,
    public readonly array $parameters,
    public readonly bool $takesDefinition,
    public readonly bool $isStatic = FALSE,
    public readonly bool $runtime = FALSE,
    public readonly bool $mayNotApply = FALSE,
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
