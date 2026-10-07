<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * One #[Situation] method, as discovery read it.
 *
 * @internal
 */
final class SituationDefinition {

  /**
   * Constructs a SituationDefinition.
   *
   * @param string $id
   *   The situation id, which is the operation of the context it builds.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param class-string $surface
   *   The surface it is a situation of.
   * @param class-string $class
   *   The class the static method is on.
   * @param string $method
   *   The static method that builds the context.
   * @param string|null $permission
   *   The static part of access, `%key` placeholders unresolved.
   * @param string $module
   *   The module that provides it.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $surface,
    public readonly string $class,
    public readonly string $method,
    public readonly ?string $permission,
    public readonly string $module,
  ) {}

  /**
   * Names the provider the way a message about it should.
   *
   * @return string
   *   Class::method() and the module.
   */
  public function describe(): string {
    return sprintf('%s::%s() in %s', $this->class, $this->method, $this->module);
  }

}
