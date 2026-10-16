<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * Where a surface is being asked for. Never the values being submitted.
 *
 * Built by a #[Situation] method, so the ways a surface is asked for sit
 * beside its identity keys. No surface method receives it. The
 * framework applies it, once, when the surface is built:
 *   - each identity key it knows is locked to that value;
 *   - each constraint it carries narrows that key;
 *   - each starting value is the initial value, when it creates;
 *   - each child context goes to that subsurface, and each child
 *     situation it names is the one that subsurface is invoked through;
 *     a subsurface named in neither is invoked through its own situation
 *     for the parent's operation, else its default one, else sees its
 *     parent's operation and identity.
 * The target reads it to load by identity and to create or update.
 *
 * The rule: what depends on where you are goes here. What depends on
 * what was entered is a #[RefinesInput] method.
 */
final class SurfaceContext {

  /**
   * Constructs a SurfaceContext.
   *
   * @param string $operation
   *   The situation id.
   * @param bool $creates
   *   Whether submitting makes a new thing or changes one that exists.
   * @param array<string, mixed> $known
   *   Identity the caller already has. Each becomes a lock.
   * @param array<string, array<string, array>> $constraints
   *   Narrowing the situation imposes: key => constraint name => options.
   *   Core constraints only; checked narrower like any refinement.
   * @param array<string, mixed> $starting
   *   Initial values for a creating situation: a clone starts from its
   *   source. Not locks; the caller may change them.
   * @param array<string, \Drupal\data_surface\Surface\SurfaceContext> $children
   *   A context for the subsurface at a key, when it differs.
   * @param array<string, array{situation: string, known: array<string, mixed>}> $childSituations
   *   The situation the subsurface at a key is invoked through, by id,
   *   with the identity handed to it.
   */
  public function __construct(
    public readonly string $operation,
    public readonly bool $creates = FALSE,
    public readonly array $known = [],
    public readonly array $constraints = [],
    public readonly array $starting = [],
    public readonly array $children = [],
    public readonly array $childSituations = [],
  ) {}

  /**
   * Returns a copy under another operation.
   *
   * @param string $operation
   *   The situation id.
   * @param bool|null $creates
   *   Whether submitting creates; NULL keeps this context's answer.
   *
   * @return self
   *   The new context.
   */
  public function withOperation(string $operation, ?bool $creates = NULL): self {
    return new self($operation, $creates ?? $this->creates, $this->known, $this->constraints, $this->starting, $this->children, $this->childSituations);
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
    return new self($this->operation, $this->creates, $known + $this->known, $this->constraints, $this->starting, $this->children, $this->childSituations);
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
    return new self($this->operation, $this->creates, $this->known, $constraints, $this->starting, $this->children, $this->childSituations);
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
    return new self($this->operation, $this->creates, $this->known, $this->constraints, $values + $this->starting, $this->children, $this->childSituations);
  }

  /**
   * Returns a copy that says how a subsurface is invoked.
   *
   * Either hand it a context built here, as a situation of the child's
   * called directly builds one:
   *
   * @code
   * ->withChild('storage', FieldStorageSurface::edit($storage))
   * @endcode
   *
   * or name one of the child's own situations, which the build invokes
   * with the arguments it declares, taken by name from $known and then
   * from this context's known identity:
   *
   * @code
   * ->withChild('contact', situation: 'enter', known: ['email' => $email])
   * @endcode
   *
   * Naming is the spelling that keeps the child's situation in charge of
   * its own access and alters, and it is checked: a situation the child
   * does not declare is refused when the parent is built.
   *
   * @param string $key
   *   The key the subsurface sits at.
   * @param \Drupal\data_surface\Surface\SurfaceContext|null $context
   *   The subsurface's context, or NULL when naming a situation.
   * @param string|null $situation
   *   The id of the child's situation to invoke, or NULL when handing a
   *   context.
   * @param array<string, mixed> $known
   *   With a situation: the identity handed to the child, which its
   *   situation's arguments are read from and its context then knows.
   *
   * @return self
   *   The new context.
   *
   * @throws \InvalidArgumentException
   *   When both or neither of a context and a situation are given, or
   *   identity is handed beside a context, which already knows its own.
   */
  public function withChild(string $key, ?SurfaceContext $context = NULL, ?string $situation = NULL, array $known = []): self {
    if (($context === NULL) === ($situation === NULL)) {
      throw new \InvalidArgumentException(sprintf('withChild(\'%s\', ...) takes a context for the child or the id of one of its situations, and exactly one of the two.', $key));
    }
    if ($context !== NULL && $known !== []) {
      throw new \InvalidArgumentException(sprintf('withChild(\'%s\', ...) hands identity only beside a situation: a context handed whole already knows its own.', $key));
    }
    $children = $this->children;
    $situations = $this->childSituations;
    unset($children[$key], $situations[$key]);
    if ($context !== NULL) {
      $children = [$key => $context] + $children;
    }
    else {
      $situations = [$key => ['situation' => (string) $situation, 'known' => $known]] + $situations;
    }
    return new self($this->operation, $this->creates, $this->known, $this->constraints, $this->starting, $children, $situations);
  }

  /**
   * Gets the situation this context names for the subsurface at $key.
   *
   * @param string $key
   *   The key the subsurface sits at.
   *
   * @return array{situation: string, known: array<string, mixed>}|null
   *   The situation id and the identity handed with it, or NULL when this
   *   context names none there.
   */
  public function childSituation(string $key): ?array {
    return $this->childSituations[$key] ?? NULL;
  }

  /**
   * What the subsurface at $key sees.
   *
   * @param string $key
   *   The key the subsurface sits at.
   *
   * @return self
   *   Its own context, or this one: a child named by situation has no
   *   context until the build invokes that situation.
   */
  public function forChild(string $key): self {
    return $this->children[$key] ?? $this;
  }

}
