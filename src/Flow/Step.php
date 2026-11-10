<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * One step a flow class declares, as it declares it.
 *
 * @internal
 */
final class Step implements StepInterface {

  /**
   * The keys the step draws, or NULL.
   *
   * @var list<string>|null
   */
  public ?array $keys = NULL;

  /**
   * The group the step draws, or NULL.
   */
  public ?string $group = NULL;

  /**
   * The step's heading, or NULL.
   */
  public \Stringable|string|null $label = NULL;

  /**
   * The line under the heading, or NULL.
   */
  public \Stringable|string|null $description = NULL;

  /**
   * The condition the step is drawn under, or NULL to draw it always.
   *
   * @var (callable(\Drupal\surface\Flow\StagedInterface): bool)|null
   */
  public $when = NULL;

  /**
   * Constructs a Step.
   *
   * @param string $id
   *   The step's id.
   * @param string $resource
   *   The resource's id or class.
   * @param string $operation
   *   The operation's id.
   * @param array<array-key, mixed> $arguments
   *   The operation's arguments, by name (Steps::step() refuses a list).
   */
  public function __construct(
    public readonly string $id,
    public readonly string $resource,
    public readonly string $operation,
    public readonly array $arguments,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function keys(string ...$keys): static {
    if ($this->keys !== NULL) {
      throw new \LogicException(sprintf('The step "%s" of a flow lists its keys twice: call keys() once, with every key the step draws.', $this->id));
    }
    if ($keys === []) {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow lists no key: name at least one, or list none and the step draws the whole operation.', $this->id));
    }
    if (count(array_unique($keys)) !== count($keys)) {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow lists a key twice: %s.', $this->id, implode(', ', $keys)));
    }
    if ($this->group !== NULL) {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow names the group "%s" and lists keys: a step draws a group or keys, not both.', $this->id, $this->group));
    }
    $this->keys = array_values($keys);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function group(string $id): static {
    if ($this->group !== NULL) {
      throw new \LogicException(sprintf('The step "%s" of a flow names a group twice, "%s" and "%s": a step draws one group.', $this->id, $this->group, $id));
    }
    if ($id === '') {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow names an empty group.', $this->id));
    }
    if ($this->keys !== NULL) {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow lists keys and names the group "%s": a step draws a group or keys, not both.', $this->id, $id));
    }
    $this->group = $id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function label(\Stringable|string $label): static {
    $this->label = $label;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function description(\Stringable|string $description): static {
    $this->description = $description;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function when(callable $when): static {
    if ($this->when !== NULL) {
      throw new \LogicException(sprintf('The step "%s" of a flow is given a condition twice: call when() once, with one callable that answers for both.', $this->id));
    }
    $this->when = $when;
    return $this;
  }

}
