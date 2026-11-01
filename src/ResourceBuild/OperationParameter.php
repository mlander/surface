<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * One parameter of an operation, as discovery read it.
 *
 * What an operation needs is its own signature, so this is the whole of
 * what a route, a tool or a catalog knows about how to ask for it: a
 * route parameter or a tool input of the same name. Every parameter is a
 * value, never an object: a method's is typed with a scalar, a derived
 * operation's names a key, and the operation loads what a value names
 * through its resource's storage.
 *
 * Decision: see
 * docs/decisions.md#an-operation-is-handed-values-never-objects.
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
   *   The declared type: a builtin such as 'string' or 'int', or NULL when
   *   the parameter is untyped, typed with a union, or names a key.
   * @param bool $optional
   *   Whether the parameter may be left out.
   * @param bool $nullable
   *   Whether NULL is a value it takes.
   * @param bool $namesKey
   *   Whether it is a derived operation's parameter, which names a key of
   *   its resource and takes that key's value: its definition is the
   *   key's, and it carries no type of its own.
   */
  public function __construct(
    public readonly string $name,
    public readonly ?string $type,
    public readonly bool $optional,
    public readonly bool $nullable,
    public readonly bool $namesKey = FALSE,
  ) {}

  /**
   * Reads one parameter a derived operation declares: a key's name.
   *
   * @param int|string $position
   *   Where the declaration has it: a list's position.
   * @param mixed $name
   *   The name of a key the resource declares, `id`.
   * @param string $operation
   *   The operation and its resource, for the message.
   *
   * @return self
   *   The parameter, required and not nullable, typed by its key.
   *
   * @throws \LogicException
   *   For a name typed as `name => type`, `entity:<type>` among them, or
   *   anything that is no key's name.
   */
  public static function fromDeclaration(int|string $position, mixed $name, string $operation): self {
    if (!is_int($position) || !is_string($name) || preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
      throw new \LogicException(sprintf(
        '%s declares the parameter %s. A parameter names a key the resource declares, parameters: [\'id\'], and its definition is that key\'s: it carries no type, and an entity is no parameter. The operation receives the value, and the storage loads what it names.',
        $operation,
        is_string($position) ? sprintf('"%s" => %s', $position, json_encode($name)) : json_encode($name),
      ));
    }
    return new self($name, NULL, FALSE, FALSE, namesKey: TRUE);
  }

  /**
   * Reads one parameter of an operation method.
   *
   * @param \ReflectionParameter $parameter
   *   The parameter.
   *
   * @return self
   *   What a caller needs to know about it.
   *
   * @throws \LogicException
   *   For a parameter typed with a class or an interface, or `object`: an
   *   operation is handed values, and loads what they name through its
   *   resource's storage.
   */
  public static function fromReflection(\ReflectionParameter $parameter): self {
    $type = $parameter->getType();
    $named = $type instanceof \ReflectionNamedType ? $type : NULL;
    foreach ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : array_filter([$named]) as $member) {
      if (!$member instanceof \ReflectionNamedType || !$member->isBuiltin() || $member->getName() === 'object') {
        $function = $parameter->getDeclaringFunction();
        $method = $function instanceof \ReflectionMethod ? $function->getDeclaringClass()->getName() . '::' . $function->getName() : $function->getName();
        throw new \LogicException(sprintf(
          '%s() takes %s $%s, an object. An operation is handed values, never objects: type it as the key\'s scalar, string $%s, and load the thing it names through the resource\'s storage, which refuses a value that names nothing.',
          $method,
          (string) $type,
          $parameter->getName(),
          $parameter->getName(),
        ));
      }
    }
    return new self(
      name: $parameter->getName(),
      type: $named?->getName(),
      optional: $parameter->isOptional(),
      nullable: $type === NULL || $type->allowsNull(),
    );
  }

  /**
   * Says the parameter the way a signature does.
   *
   * @return string
   *   For example "string $bundle", "?string $to", or for a derived
   *   operation's parameter, which names a key, "key $id".
   */
  public function describe(): string {
    if ($this->namesKey) {
      return 'key $' . $this->name;
    }
    $type = $this->type === NULL ? '' : (($this->nullable && $this->type !== 'mixed' ? '?' : '') . $this->type . ' ');
    return $type . '$' . $this->name;
  }

}
