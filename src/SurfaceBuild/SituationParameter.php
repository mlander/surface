<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * One parameter of a #[Situation] method, as discovery read it.
 *
 * What a situation needs is its own signature, so this is the whole of
 * what a route, a tool or a catalogue knows about how to ask for it: a
 * route parameter or a tool input of the same name, of this type.
 *
 * @internal
 */
final class SituationParameter {

  /**
   * Constructs a SituationParameter.
   *
   * @param string $name
   *   The parameter name, which is the route parameter and the tool input
   *   it is supplied by.
   * @param string|null $type
   *   The declared type: a builtin such as 'string' or 'int', a class or
   *   interface, or NULL when the parameter is untyped or typed with a
   *   union the situation alone can read.
   * @param bool $builtin
   *   Whether the type is a builtin rather than a class or interface.
   * @param bool $optional
   *   Whether the parameter may be left out.
   * @param bool $nullable
   *   Whether NULL is a value it takes.
   */
  public function __construct(
    public readonly string $name,
    public readonly ?string $type,
    public readonly bool $builtin,
    public readonly bool $optional,
    public readonly bool $nullable,
  ) {}

  /**
   * Reads one parameter of a situation method.
   *
   * @param \ReflectionParameter $parameter
   *   The parameter.
   *
   * @return self
   *   What a caller needs to know about it.
   */
  public static function fromReflection(\ReflectionParameter $parameter): self {
    $type = $parameter->getType();
    $named = $type instanceof \ReflectionNamedType ? $type : NULL;
    return new self(
      name: $parameter->getName(),
      type: $named?->getName(),
      builtin: $named?->isBuiltin() ?? TRUE,
      optional: $parameter->isOptional(),
      nullable: $type === NULL || $type->allowsNull(),
    );
  }

  /**
   * Answers whether the parameter takes an object rather than a value.
   *
   * @return bool
   *   TRUE for a class or interface type: the subject a situation is
   *   about, such as the entity being edited.
   */
  public function takesObject(): bool {
    return $this->type !== NULL && !$this->builtin;
  }

  /**
   * Says the parameter the way a signature does.
   *
   * @return string
   *   For example "NodeTypeInterface $type" or "string $bundle".
   */
  public function describe(): string {
    $type = $this->type === NULL ? '' : (($this->nullable && $this->type !== 'mixed' ? '?' : '') . self::shortName($this->type) . ' ');
    return $type . '$' . $this->name;
  }

  /**
   * Shortens a class name to its last segment.
   *
   * @param string $type
   *   A builtin or a class name.
   *
   * @return string
   *   The unqualified name.
   */
  protected static function shortName(string $type): string {
    $position = strrpos($type, '\\');
    return $position === FALSE ? $type : substr($type, $position + 1);
  }

}
