<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Which operation is being asked, and what it knows. Never the values.
 *
 * Built by an #[Operation] method, so the ways a resource is asked for
 * sit beside its identity keys; a host builds one for its own verb
 * (`configure`), which is no declared operation. Its id is the
 * operation's id. Neither defineInputs() nor defineOutputs() receives
 * it. The framework applies it, once, when the resource or structure is
 * built:
 *   - each identity key it knows is locked to that value;
 *   - each constraint it carries narrows that key;
 *   - each starting value is the initial value, when it creates;
 *   - each child context goes to the structure attached at that key, and
 *     each child operation it names is the one that child is invoked
 *     through; a child named in neither is invoked through its own
 *     operation with the parent's id, else its default one, else sees
 *     its parent's context and identity.
 * The storage reads it to load by identity and to create or update.
 *
 * The rule: what depends on where you are goes here. What depends on
 * what was entered is a #[RefinesInput] method.
 */
final class OperationContext {

  /**
   * Constructs an OperationContext.
   *
   * @param string $id
   *   The operation id, or a host's own verb.
   * @param bool $creates
   *   Whether submitting makes a new thing or changes one that exists.
   * @param array<string, mixed> $known
   *   Identity the caller already has. Each becomes a lock.
   * @param array<string, array<string, array>> $constraints
   *   Narrowing the operation imposes: key => constraint name => options.
   *   Core constraints only; checked narrower like any refinement.
   * @param array<string, mixed> $starting
   *   Initial values for a creating operation: a clone starts from its
   *   source. Not locks; the caller may change them.
   * @param array<string, \Drupal\data_surface\Resource\OperationContext> $children
   *   A context for the child at a key, when it differs.
   * @param array<string, array{operation: string, known: array<string, mixed>}> $childOperations
   *   The operation the child at a key is invoked through, by id, with
   *   the identity handed to it.
   */
  public function __construct(
    public readonly string $id,
    public readonly bool $creates = FALSE,
    public readonly array $known = [],
    public readonly array $constraints = [],
    public readonly array $starting = [],
    public readonly array $children = [],
    public readonly array $childOperations = [],
  ) {}

  /**
   * Returns a copy under another operation id.
   *
   * @param string $id
   *   The operation id.
   * @param bool|null $creates
   *   Whether submitting creates; NULL keeps this context's answer.
   *
   * @return self
   *   The new context.
   */
  public function withId(string $id, ?bool $creates = NULL): self {
    return new self($id, $creates ?? $this->creates, $this->known, $this->constraints, $this->starting, $this->children, $this->childOperations);
  }

  /**
   * Returns a copy that knows more identity.
   *
   * @param array<string, mixed> $known
   *   Identity keys and their values; these win over what is known.
   *
   * @return self
   *   The new context.
   */
  public function withKnown(array $known): self {
    return new self($this->id, $this->creates, $known + $this->known, $this->constraints, $this->starting, $this->children, $this->childOperations);
  }

  /**
   * Returns a copy that narrows one key with one more constraint.
   *
   * @param string $key
   *   The input key.
   * @param string $constraint
   *   The constraint plugin ID.
   * @param array $options
   *   The constraint options.
   *
   * @return self
   *   The new context.
   */
  public function withConstraint(string $key, string $constraint, array $options = []): self {
    $constraints = $this->constraints;
    $constraints[$key][$constraint] = $options;
    return new self($this->id, $this->creates, $this->known, $constraints, $this->starting, $this->children, $this->childOperations);
  }

  /**
   * Returns a copy with more starting values.
   *
   * @param array<string, mixed> $values
   *   Starting values; these win over the ones already given.
   *
   * @return self
   *   The new context.
   */
  public function withStarting(array $values): self {
    return new self($this->id, $this->creates, $this->known, $this->constraints, $values + $this->starting, $this->children, $this->childOperations);
  }

  /**
   * Returns a copy that says how the child at a key is invoked.
   *
   * Either hand it a context built here, as an operation of the child's
   * called directly builds one:
   *
   * @code
   * ->withChild('storage', FieldStorageResource::edit($storage))
   * @endcode
   *
   * or name one of the child's own operations, which the build invokes
   * with the arguments it declares, taken by name from $known and then
   * from this context's known identity:
   *
   * @code
   * ->withChild('contact', operation: 'enter', known: ['email' => $email])
   * @endcode
   *
   * Naming is the spelling that keeps the child's operation in charge of
   * its own permission and alters, and it is checked: an operation the
   * child does not declare is refused when the parent is built. A context
   * handed whole asks no operation's permission, the parent having taken
   * responsibility for entering it; the child's access class answers
   * either way.
   *
   * @param string $key
   *   The key the child sits at.
   * @param \Drupal\data_surface\Resource\OperationContext|null $context
   *   The child's context, or NULL when naming an operation.
   * @param string|null $operation
   *   The id of the child's operation to invoke, or NULL when handing a
   *   context.
   * @param array<string, mixed> $known
   *   With an operation: the identity handed to the child, which its
   *   operation's arguments are read from and its context then knows.
   *
   * @return self
   *   The new context.
   *
   * @throws \InvalidArgumentException
   *   When both or neither of a context and an operation are given, or
   *   identity is handed beside a context, which already knows its own.
   */
  public function withChild(string $key, ?OperationContext $context = NULL, ?string $operation = NULL, array $known = []): self {
    if (($context === NULL) === ($operation === NULL)) {
      throw new \InvalidArgumentException(sprintf('withChild(\'%s\', ...) takes a context for the child or the id of one of its operations, and exactly one of the two.', $key));
    }
    if ($context !== NULL && $known !== []) {
      throw new \InvalidArgumentException(sprintf('withChild(\'%s\', ...) hands identity only beside an operation: a context handed whole already knows its own.', $key));
    }
    $children = $this->children;
    $operations = $this->childOperations;
    unset($children[$key], $operations[$key]);
    if ($context !== NULL) {
      $children = [$key => $context] + $children;
    }
    else {
      $operations = [$key => ['operation' => (string) $operation, 'known' => $known]] + $operations;
    }
    return new self($this->id, $this->creates, $this->known, $this->constraints, $this->starting, $children, $operations);
  }

  /**
   * Gets the operation this context names for the child at $key.
   *
   * @param string $key
   *   The key the child sits at.
   *
   * @return array{operation: string, known: array<string, mixed>}|null
   *   The operation id and the identity handed with it, or NULL when this
   *   context names none there.
   */
  public function childOperation(string $key): ?array {
    return $this->childOperations[$key] ?? NULL;
  }

  /**
   * What the child at $key sees.
   *
   * @param string $key
   *   The key the child sits at.
   *
   * @return self
   *   Its own context, or this one: a child named by operation has no
   *   context until the build invokes that operation.
   */
  public function forChild(string $key): self {
    return $this->children[$key] ?? $this;
  }

}
