<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

/**
 * A stage, as storage is handed it: which one, whose, and for which call.
 *
 * What ResourcesInterface::storage() wraps a resource's storage with, so
 * a write is kept in the stage until the stage is applied, and a load
 * answers what the stage holds before what is stored. Its id and owner
 * name the stage's record, which StageStore::named() reads it from; its
 * arguments are the operation's parameters by name, as the code that
 * asks for the storage handed them to ResourcesInterface::operation(),
 * which the stage keeps beside a write so apply can build the operation
 * again.
 *
 * Ownership is no fact of the value: the door asks the stage's own access
 * for the account it answers for before it hands a stage on, and apply
 * asks again for every write.
 *
 * @code
 * $stage = $stages->named($id)->withArguments(['id' => 12]);
 * $storage = $resources->storage($resource, $context, $built, $account, $stage);
 * @endcode
 *
 * Decision: see
 * docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context.
 */
final readonly class Stage {

  /**
   * Constructs a Stage.
   *
   * @param string $id
   *   The stage's id, a UUID.
   * @param int $owner
   *   The id of the account that opened it.
   * @param string|null $label
   *   What the stage is for, or NULL.
   * @param array<string, mixed> $arguments
   *   The operation's parameters by name, raw, for the call it is handed
   *   to; empty when none was said.
   * @param int|null $creating
   *   The position of the create the call writes into, for a create
   *   asked again through the stage; NULL for none. Internal: a flow
   *   sets it (creating()), and code outside this module leaves it NULL.
   */
  public function __construct(
    public string $id,
    public int $owner,
    public ?string $label = NULL,
    public array $arguments = [],
    /**
     * The position of the create the call writes into, set by a flow.
     *
     * @internal
     */
    public ?int $creating = NULL,
  ) {}

  /**
   * Gets the same stage for a call with these arguments.
   *
   * @param array<string, mixed> $arguments
   *   The operation's parameters by name, as handed to
   *   ResourcesInterface::operation().
   *
   * @return self
   *   The stage, the arguments its own.
   */
  public function withArguments(array $arguments): self {
    return new self($this->id, $this->owner, $this->label, $arguments, $this->creating);
  }

  /**
   * Gets the same stage for a create that writes into one it holds.
   *
   * A create names no thing, so a second create through the stage adds a
   * second thing. A flow's later steps over a create write into the one
   * its first step staged: ResourcesInterface::operation() builds that
   * create's context again when the stage says its position here.
   *
   * @param int $position
   *   The create's position in the stage.
   *
   * @return self
   *   The stage, writing into that create.
   *
   * @internal
   *   A flow sets it (OperationRoute::stageRequested()); code outside
   *   this module does not call it.
   */
  public function creating(int $position): self {
    return new self($this->id, $this->owner, $this->label, $this->arguments, $position);
  }

}
