<?php

declare(strict_types=1);

namespace Drupal\surface\Operation;

/**
 * Says which door a call to the runner comes through, and what it needs.
 *
 * A value, never a service: an id, such as `php` (the default) or
 * `drush`, and the capabilities the door asks for. A capability is any
 * enum case, so a module that opens a door may define its own. This
 * module defines no capability yet: every door that runs through the
 * runner today asks for the same thing, and an enum is added when a
 * shipped door first needs the runner to treat it differently.
 *
 * Nothing reads it yet: the runner records it on the OperationRun and
 * does not branch on it. It is reserved for the audit record the runner
 * marks an attach point for, which names the door a call came through,
 * and for the first capability, such as a door that can ask the person
 * to confirm a destructive operation.
 *
 * Modeled on the Tool API's Invoker.
 *
 * Decision: see docs/decisions.md#one-runner-runs-an-operation.
 */
final class Door implements \Stringable {

  /**
   * The capabilities this door asked for.
   *
   * @var \UnitEnum[]
   */
  private readonly array $capabilities;

  /**
   * Constructs a Door.
   *
   * @param string $id
   *   The door's id, for example "drush".
   * @param \UnitEnum ...$capabilities
   *   What the door asks the runner to do at its boundary. Any enum case
   *   is accepted, so another module may define capabilities of its own.
   */
  public function __construct(
    public readonly string $id,
    \UnitEnum ...$capabilities,
  ) {
    $this->capabilities = $capabilities;
  }

  /**
   * Builds a door from its id, or passes a door through.
   *
   * @param string|self $door
   *   The id, or a door.
   *
   * @return self
   *   The door.
   */
  public static function from(string|self $door): self {
    return $door instanceof self ? $door : new self($door);
  }

  /**
   * Answers whether this door asked for a capability.
   *
   * @param \UnitEnum $capability
   *   The capability.
   *
   * @return bool
   *   TRUE when the door was made with it.
   */
  public function has(\UnitEnum $capability): bool {
    return \in_array($capability, $this->capabilities, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return $this->id;
  }

}
