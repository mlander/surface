<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * Where a surface is being asked for. Never the values being submitted.
 *
 * Built by a #[Situation] method, so the ways a surface is asked for sit
 * beside its identity keys. No surface method receives it. The
 * framework applies it, once, when the surface is built:
 *   - each identity key it knows is locked to that value;
 *   - each constraint it carries narrows that key;
 *   - each starting value is the initial value, when it creates;
 *   - each child context goes to that subsurface, which otherwise sees
 *     its parent's.
 * The target reads it to load by identity and to create or update.
 *
 * The rule: what depends on where you are goes here. What depends on
 * what was entered is a #[RefinesInput] method.
 */
final class SurfaceContext {

  /**
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
   * @param array<string, \Drupal\surface_sketch\Surface\SurfaceContext> $children
   *   A context for the subsurface at a key, when it differs.
   */
  public function __construct(
    public readonly string $operation,
    public readonly bool $creates = FALSE,
    public readonly array $known = [],
    public readonly array $constraints = [],
    public readonly array $starting = [],
    public readonly array $children = [],
  ) {}

  public function withOperation(string $operation, ?bool $creates = NULL): self {
    return new self($operation, $creates ?? $this->creates, $this->known, $this->constraints, $this->starting, $this->children);
  }

  public function withKnown(array $known): self {
    return new self($this->operation, $this->creates, $known + $this->known, $this->constraints, $this->starting, $this->children);
  }

  public function withConstraint(string $key, string $constraint, array $options = []): self {
    $constraints = $this->constraints;
    $constraints[$key][$constraint] = $options;
    return new self($this->operation, $this->creates, $this->known, $constraints, $this->starting, $this->children);
  }

  public function withStarting(array $values): self {
    return new self($this->operation, $this->creates, $this->known, $this->constraints, $values + $this->starting, $this->children);
  }

  public function withChild(string $key, SurfaceContext $context): self {
    return new self($this->operation, $this->creates, $this->known, $this->constraints, $this->starting, [$key => $context] + $this->children);
  }

  /**
   * What the subsurface at $key sees.
   */
  public function forChild(string $key): self {
    return $this->children[$key] ?? $this;
  }

}
