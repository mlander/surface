<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\data_surface\Resource\ResourceCallable;

/**
 * One #[Operation] method, as discovery read it.
 *
 * @internal
 */
final class OperationDefinition {

  /**
   * Constructs an OperationDefinition.
   *
   * @param string $id
   *   The operation id, which is the id of the context it builds.
   * @param string|\Stringable $label
   *   For people and for tool descriptions.
   * @param string $resource
   *   The resource or structure it is an operation of: its class, or a
   *   derived resource's id.
   * @param class-string $class
   *   The class the method is on.
   * @param string $method
   *   The method that builds the context: static, for an #[Operation].
   * @param string|null $permission
   *   The static part of access, `%key` placeholders unresolved.
   * @param string $module
   *   The module that provides it.
   * @param \Drupal\data_surface\ResourceBuild\OperationParameter[] $parameters
   *   The method's parameters, in order: what a route or a tool has to
   *   supply, by name.
   * @param bool $default
   *   Whether it is the operation a child is invoked through when nothing
   *   else picks one.
   * @param \Drupal\data_surface\Resource\ResourceCallable|null $context
   *   For a derived resource's operation, the method and the arguments it
   *   is bound to, called with the resolved arguments by name first;
   *   NULL for an #[Operation] method, called with them as named
   *   arguments.
   */
  public function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $resource,
    public readonly string $class,
    public readonly string $method,
    public readonly ?string $permission,
    public readonly string $module,
    public readonly array $parameters = [],
    public readonly bool $default = FALSE,
    public readonly ?ResourceCallable $context = NULL,
  ) {}

  /**
   * Answers whether the operation can be asked for with no subject.
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
   * Answers whether its parameters name one whole thing, read off them.
   *
   * Whether an operation creates is on the context it returns, which an
   * operation that needs a subject cannot return before it is given one,
   * so this reads the signature instead, the way a tool's static
   * definition reads the identity an operation knows: one entity
   * parameter, with no scalar beside it named for an identity key, is the
   * thing itself, loaded; scalar parameters named for every identity key
   * name it whole. An entity beside a scalar identity key is what a new
   * thing is made from (a storage reused on another bundle), and
   * parameters that leave an identity key unnamed leave room for the
   * thing to be new.
   *
   * @param string[] $identity
   *   The resource's identity keys.
   *
   * @return bool
   *   TRUE when the parameters name one thing whole.
   */
  public function namesOneThing(array $identity): bool {
    $entities = 0;
    $named = [];
    foreach ($this->parameters as $parameter) {
      if ($parameter->takesObject()) {
        $entities++;
      }
      elseif (in_array($parameter->name, $identity, TRUE)) {
        $named[] = $parameter->name;
      }
    }
    if ($entities > 0) {
      return $entities === 1 && $named === [];
    }
    return $identity !== [] && array_diff($identity, $named) === [];
  }

  /**
   * Gets the permission when the account alone decides it.
   *
   * Access is static or runtime by what it depends on, and an operation's
   * permission is its first answer: one permission as written depends on
   * the account's permissions and nothing else, so it is static, asked
   * before anything is loaded and stated wherever the account asks (the
   * schema route's refusal, a tool listing's declared permission). One
   * with a `%key` placeholder names a different permission for each thing
   * it is asked of, filled from the identity the context knows, so which
   * permission is asked waits for the subject: it is never stated ahead.
   *
   * Decision: see
   * docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability.
   *
   * @return string|null
   *   The permission as written, or NULL when there is none or it names a
   *   placeholder.
   */
  public function staticPermission(): ?string {
    return $this->permission === NULL || $this->permission === '' || str_contains($this->permission, '%') ? NULL : $this->permission;
  }

  /**
   * Lists the permission's placeholders no parameter can supply.
   *
   * A `%key` placeholder is filled from the identity the operation's
   * context knows, and that context is built from the parameters, so a
   * placeholder can be filled only when a parameter could supply it: one
   * named for it, or an entity, whose operation reads identity off it.
   * An operation with one that none can is never allowed on its own; it
   * is asked only as the child of a parent that hands it that identity.
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
