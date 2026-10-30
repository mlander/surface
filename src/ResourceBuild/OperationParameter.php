<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\Entity\EntityInterface;

/**
 * One parameter of an #[Operation] method, as discovery read it.
 *
 * What an operation needs is its own signature, so this is the whole of
 * what a route, a tool or a catalog knows about how to ask for it: a
 * route parameter or a tool input of the same name, of this type.
 *
 * @internal
 */
final class OperationParameter {

  /**
   * Constructs an OperationParameter.
   *
   * @param string $name
   *   The parameter name, which is the route parameter and the tool input
   *   it is supplied by.
   * @param string|null $type
   *   The declared type: a builtin such as 'string' or 'int', a class or
   *   interface, or NULL when the parameter is untyped or typed with a
   *   union the operation alone can read.
   * @param bool $builtin
   *   Whether the type is a builtin rather than a class or interface.
   * @param bool $optional
   *   Whether the parameter may be left out.
   * @param bool $nullable
   *   Whether NULL is a value it takes.
   * @param string|null $entityType
   *   The entity type it takes, when that is said rather than read off
   *   the type: a derived operation's entity parameter, whose type is
   *   any entity, names its entity type.
   */
  public function __construct(
    public readonly string $name,
    public readonly ?string $type,
    public readonly bool $builtin,
    public readonly bool $optional,
    public readonly bool $nullable,
    public readonly ?string $entityType = NULL,
  ) {}

  /**
   * Reads one parameter a derived operation declares.
   *
   * @param string $name
   *   The parameter name.
   * @param string $type
   *   A builtin type, or `entity:<entity type id>`.
   *
   * @return self
   *   The parameter, required and not nullable.
   */
  public static function fromDeclaration(string $name, string $type): self {
    if (str_starts_with($type, 'entity:')) {
      return new self($name, EntityInterface::class, FALSE, FALSE, FALSE, substr($type, 7));
    }
    return new self($name, $type, TRUE, FALSE, FALSE);
  }

  /**
   * Reads one parameter of an operation method.
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
   *   TRUE for a class or interface type: the subject an operation is
   *   about, such as the entity being edited.
   */
  public function takesObject(): bool {
    return $this->type !== NULL && !$this->builtin;
  }

  /**
   * Says the parameter the way a signature does.
   *
   * @return string
   *   For example "NodeTypeInterface $type", "string $bundle", or for a
   *   derived operation's entity parameter "entity:node $id".
   */
  public function describe(): string {
    if ($this->entityType !== NULL) {
      return 'entity:' . $this->entityType . ' $' . $this->name;
    }
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
