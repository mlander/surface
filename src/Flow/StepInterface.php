<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * One step of a flow class: what it draws, its heading, and when.
 *
 * A step with neither keys nor a group draws its operation's whole
 * surface. Keys and a group together are refused.
 *
 * Experimental, as every flow is.
 *
 * @see docs/staging.md#flows
 */
interface StepInterface {

  /**
   * Draws these keys of the operation at this step.
   *
   * Top-level keys of the operation, each drawn at one step of the flow.
   * A key no step lists and in no group is drawn at the first step over
   * its operation; one in a group no step draws is not drawn.
   *
   * @param string ...$keys
   *   The keys, at least one.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When no key is given, one is given twice, or the step names a group.
   * @throws \LogicException
   *   When the step lists its keys already.
   */
  public function keys(string ...$keys): static;

  /**
   * Draws one group of the operation at this step.
   *
   * @param string $id
   *   The id of a group the operation's top level declares.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the id is empty, or the step lists keys.
   * @throws \LogicException
   *   When the step names a group already.
   */
  public function group(string $id): static;

  /**
   * Titles the step: a heading above its fields.
   *
   * @param \Stringable|string $label
   *   The heading.
   *
   * @return $this
   */
  public function label(\Stringable|string $label): static;

  /**
   * Describes the step: a line under its heading.
   *
   * @param \Stringable|string $description
   *   The line.
   *
   * @return $this
   */
  public function description(\Stringable|string $description): static;

  /**
   * Draws the step only while a condition holds.
   *
   * The callable is handed a StagedInterface over the flow's stage and
   * answers whether the step is drawn. It is asked on every page: Next
   * and Back pass over a step it answers FALSE for, and a request for
   * that step goes on to the next step drawn. It governs what is drawn,
   * not what the stage holds: what the step staged before its condition
   * turned false stays in the stage and is applied with the rest, and a
   * post into the step while it is passed over stages nothing. Not
   * allowed on the first step, which opens the stage. A condition that
   * throws is an error in the flow class, and surfaces as one.
   *
   * @param callable(\Drupal\surface\Flow\StagedInterface): bool $when
   *   The condition.
   *
   * @return $this
   *
   * @throws \LogicException
   *   When the step has a condition already.
   */
  public function when(callable $when): static;

}
