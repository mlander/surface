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
   * @param \Drupal\data_surface\SurfaceBuild\SituationParameter[] $parameters
   *   The method's parameters, in order: what a route or a tool has to
   *   supply, by name.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $surface,
    public readonly string $class,
    public readonly string $method,
    public readonly ?string $permission,
    public readonly string $module,
    public readonly array $parameters = [],
  ) {}

  /**
   * Answers whether the situation can be asked for with no subject.
   *
   * @return bool
   *   TRUE when every parameter is optional, so the context can be built
   *   before anything is known: what a generated tool or a catalogue can
   *   do without a caller.
   */
  public function needsNothing(): bool {
    foreach ($this->parameters as $parameter) {
      if (!$parameter->optional) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Lists the permission's placeholders no parameter can supply.
   *
   * A `%key` placeholder is filled from the identity the situation's
   * context knows, and that context is built from the parameters, so a
   * placeholder can be filled only when a parameter could supply it: one
   * named for it, or an entity, whose situation reads identity off it.
   * A situation with one that none can is never allowed on its own; it
   * is asked only as the child of a surface that hands it that identity.
   *
   * @return string[]
   *   The placeholder keys, in the order the permission names them;
   *   empty when every one can be supplied, or there is no permission.
   */
  public function unresolvablePlaceholders(): array {
    if ($this->permission === NULL || !preg_match_all('/%([A-Za-z0-9_]+)/', $this->permission, $matches)) {
      return [];
    }
    $names = [];
    foreach ($this->parameters as $parameter) {
      if ($parameter->takesObject()) {
        return [];
      }
      $names[] = $parameter->name;
    }
    return array_values(array_diff(array_unique($matches[1]), $names));
  }

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
