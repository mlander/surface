<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Pipeline\Violation;

/**
 * The gates an operation asks once per item of a collection.
 *
 * A bulk call is many things, so each item's own thing is asked about:
 * the entity its reference key names. A gate's refusal lands on that
 * key's path in that item, with the gate's reason, and the other items
 * are not refused for it. Asked by the operation's storage before its own
 * prepare(), so the storage only ever sees items every gate allowed
 * (StorageAdapter::prepare()).
 *
 * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
 *
 * @internal
 */
final class ItemGates {

  use DependencySerializationTrait;

  /**
   * Constructs ItemGates.
   *
   * @param \Drupal\surface\ResourceBuild\Gates $gates
   *   What answers each gate.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers an operation gate.
   * @param array<string, array{item: string, type: string, gates: \Drupal\surface\ResourceBuild\Gate[]}> $lists
   *   By the collection's key: the key in each item that names its thing,
   *   the entity type it names, and the gates asked about it.
   */
  public function __construct(
    protected readonly Gates $gates,
    protected readonly ResourcesInterface $resources,
    protected readonly array $lists,
  ) {}

  /**
   * Asks every gate about each item's thing, and lists the refusals.
   *
   * @param array $values
   *   The accepted values, validated.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the call is made as.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   One per gate that refused an item, at the path of the item's key
   *   that names its thing; none when every gate allowed every item.
   */
  public function refusals(array $values, AccountInterface $account): array {
    $refused = [];
    foreach ($this->lists as $key => ['item' => $item, 'type' => $type, 'gates' => $gates]) {
      $list = $values[$key] ?? [];
      if (!is_array($list)) {
        continue;
      }
      foreach (array_values($list) as $delta => $value) {
        $id = is_array($value) ? ($value[$item] ?? NULL) : NULL;
        if ($id === NULL || $id === '') {
          // Nothing named: what the item requires refused it already.
          continue;
        }
        $subject = $this->gates->load($type, $id);
        if ($subject === NULL) {
          $refused[] = new Violation((string) $key, $delta . '.' . $item, sprintf('There is no %s %s.', $type, is_scalar($id) ? (string) $id : get_debug_type($id)), Violation::NOT_FOUND);
          continue;
        }
        foreach ($gates as $gate) {
          $answer = $this->gates->answer($gate, $subject, $account, $this->resources);
          if (!$answer->isAllowed()) {
            $refused[] = new Violation((string) $key, $delta . '.' . $item, $this->gates->refusal($gate, $subject, $answer), Violation::ACCESS);
          }
        }
      }
    }
    return $refused;
  }

}
