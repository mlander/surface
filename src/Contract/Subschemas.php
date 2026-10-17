<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\data_surface\DataSurfaceInterface;

/**
 * The subschemas one emission writes under `$defs`, as it writes them.
 *
 * One per child: an attached child or a slot's variant, keyed by the
 * child's id. The same child reached again, at another key, in
 * another frame, or by the instance pass after the declaration pass, is
 * the same def. Two children claiming one id with different declarations
 * (built in contexts that gave them different rules) each keep their own,
 * the second under `<id>@2`, because a reference site may only narrow
 * its def, and one of them would not be a narrowing of the other.
 *
 * Held for one emit() or emitFull() call and dropped with it.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter
 */
final class Subschemas {

  /**
   * The defs written so far, by key, in the order they were finished.
   *
   * @var array<string, array>
   */
  protected array $defs = [];

  /**
   * The children whose def is being written now.
   *
   * @var \SplObjectStorage<\Drupal\data_surface\DataSurfaceInterface, string>
   */
  protected \SplObjectStorage $writing;

  /**
   * The key each child's def was filed under.
   *
   * @var \SplObjectStorage<\Drupal\data_surface\DataSurfaceInterface, string>
   */
  protected \SplObjectStorage $keys;

  /**
   * Constructs the collector.
   *
   * @param bool $full
   *   Whether the document is the full schema (emitFull()).
   * @param string[] $transient
   *   The `x-surface` keywords the document strips once it is written:
   *   what is only true of a moment, in the full schema, and the widget
   *   hints, when they were not asked for. A reference site differing
   *   from its def in nothing but these differs in nothing.
   */
  public function __construct(
    public readonly bool $full,
    public readonly array $transient,
  ) {
    $this->keys = new \SplObjectStorage();
    $this->writing = new \SplObjectStorage();
  }

  /**
   * Gets the key a child's def was filed under, if it was.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The built child.
   *
   * @return string|null
   *   The key, or NULL when its def is not written yet.
   */
  public function keyOf(DataSurfaceInterface $child): ?string {
    return $this->keys->contains($child) ? $this->keys[$child] : NULL;
  }

  /**
   * Gets the id a child's def is being written under, if it is.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The built child.
   *
   * @return string|null
   *   The id, inside that def's own declaration; NULL elsewhere.
   */
  public function writing(DataSurfaceInterface $child): ?string {
    return $this->writing->contains($child) ? $this->writing[$child] : NULL;
  }

  /**
   * Marks a child's def as being written.
   *
   * @param string $id
   *   The id it is written under.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child.
   */
  public function begin(string $id, DataSurfaceInterface $child): void {
    $this->writing[$child] = $id;
  }

  /**
   * Files a finished def.
   *
   * @param string $id
   *   The child's id.
   * @param array $def
   *   Its declaration, as its own frame describes it for no values.
   * @param \Drupal\data_surface\DataSurfaceInterface $child
   *   The child it was written from.
   *
   * @return string
   *   The key it is filed under: the id, unless the id holds a different
   *   declaration already.
   */
  public function add(string $id, array $def, DataSurfaceInterface $child): string {
    $this->writing->detach($child);
    $key = $id;
    for ($n = 2; isset($this->defs[$key]) && !self::same($this->defs[$key], $def); $n++) {
      $key = $id . '@' . $n;
    }
    $this->defs[$key] ??= $def;
    $this->keys[$child] = $key;
    return $key;
  }

  /**
   * Gets a finished def.
   *
   * @param string $key
   *   Its key.
   *
   * @return array|null
   *   The def, or NULL while it is being written.
   */
  public function get(string $key): ?array {
    return $this->defs[$key] ?? NULL;
  }

  /**
   * Gets every def, for the document's `$defs`.
   *
   * @return array<string, array>
   *   The defs, by key.
   */
  public function all(): array {
    return $this->defs;
  }

  /**
   * Answers whether two pieces of a schema say the same.
   *
   * By their JSON: an empty object is written as an `\stdClass`, which
   * PHP compares by identity.
   *
   * @param mixed $a
   *   One.
   * @param mixed $b
   *   The other.
   *
   * @return bool
   *   TRUE when they encode the same.
   */
  public static function same(mixed $a, mixed $b): bool {
    return $a === $b || json_encode($a) === json_encode($b);
  }

}
