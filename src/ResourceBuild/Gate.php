<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * One gate an operation borrows, as discovery read it.
 *
 * Access another thing's owner decides, named on `#[Operation(gate:)]` in
 * one of three spellings:
 *
 * - `<resource id>:<operation>`, another resource's operation, answered as
 *   that operation is answered when it is asked on its own;
 * - `entity:<entity type>:<operation>`, core's access for an entity,
 *   `EntityAccessControlHandlerInterface::access()`;
 * - `field:<entity type>.<field>:<operation>`, core's access for one field
 *   of an entity, `fieldAccess()`, whose operation is view or edit.
 *
 * A resource id is dotted and never starts with `entity:` or `field:`, so
 * the three cannot be mistaken for each other.
 *
 * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
 *
 * @internal
 */
final class Gate {

  /**
   * A gate naming another resource's operation.
   */
  public const OPERATION = 'operation';

  /**
   * A gate naming core's access for an entity.
   */
  public const ENTITY = 'entity';

  /**
   * A gate naming core's access for a field of an entity.
   */
  public const FIELD = 'field';

  /**
   * The operations core's field access takes.
   */
  public const FIELD_OPERATIONS = ['view', 'edit'];

  /**
   * Constructs a Gate.
   *
   * @param string $spelled
   *   The gate as written.
   * @param string $kind
   *   self::OPERATION, self::ENTITY or self::FIELD.
   * @param string $operation
   *   The operation asked: the resource's operation id, or core's entity
   *   or field operation.
   * @param string|null $resource
   *   For an operation gate, the resource id it names.
   * @param string|null $entityType
   *   For an entity or a field gate, the entity type id.
   * @param string|null $field
   *   For a field gate, the field name.
   */
  public function __construct(
    public readonly string $spelled,
    public readonly string $kind,
    public readonly string $operation,
    public readonly ?string $resource = NULL,
    public readonly ?string $entityType = NULL,
    public readonly ?string $field = NULL,
  ) {}

  /**
   * Reads one gate as written.
   *
   * @param string $gate
   *   The gate, in one of the three spellings.
   * @param string $where
   *   The operation it is on, for the message.
   *
   * @return self
   *   The gate.
   *
   * @throws \LogicException
   *   When it is none of the three, or a field gate names an operation
   *   field access does not take.
   */
  public static function parse(string $gate, string $where): self {
    if (preg_match('/^entity:([a-z0-9_]+):([A-Za-z0-9_ ]+)$/', $gate, $match)) {
      return new self($gate, self::ENTITY, $match[2], entityType: $match[1]);
    }
    if (preg_match('/^field:([a-z0-9_]+)\.([a-z0-9_]+):([a-z]+)$/', $gate, $match)) {
      if (!in_array($match[3], self::FIELD_OPERATIONS, TRUE)) {
        throw new \LogicException(sprintf(
          'The gate "%s" on %s asks field access for "%s"; core\'s field access takes %s.',
          $gate,
          $where,
          $match[3],
          implode(' or ', self::FIELD_OPERATIONS),
        ));
      }
      return new self($gate, self::FIELD, $match[3], entityType: $match[1], field: $match[2]);
    }
    $position = strrpos($gate, ':');
    if (!str_starts_with($gate, 'entity:') && !str_starts_with($gate, 'field:') && $position !== FALSE && $position > 0 && $position < strlen($gate) - 1) {
      return new self($gate, self::OPERATION, substr($gate, $position + 1), resource: substr($gate, 0, $position));
    }
    throw new \LogicException(sprintf(
      'The gate "%s" on %s is none of the three a gate may be: another resource\'s operation, "<resource id>:<operation>"; core\'s access on an entity, "entity:<entity type>:<operation>"; or on a field, "field:<entity type>.<field>:view" or ":edit".',
      $gate,
      $where,
    ));
  }

  /**
   * Reads what #[Operation(gate:)] says.
   *
   * @param string|string[] $gates
   *   One gate, or a list of them.
   * @param string $where
   *   The operation they are on, for a message.
   *
   * @return self[]
   *   The gates, in the order written.
   */
  public static function parseAll(string|array $gates, string $where): array {
    return array_values(array_map(static fn (string $gate): self => self::parse($gate, $where), (array) $gates));
  }

}
