<?php

declare(strict_types=1);

namespace Drupal\surface\Contract;

use Drupal\surface\SurfaceInterface;

/**
 * The subschemas one emission writes under `$defs`, as it writes them.
 *
 * One per child: an attached child or a variant, keyed by the
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
 * @see \Drupal\surface\Contract\ContractEmitter
 */
final class Subschemas {

  /**
   * The defs written so far, by key, in the order they were finished.
   *
   * @var array<string, array>
   */
  protected array $defs = [];

  /**
   * What each def's structure is called and is for, by key.
   *
   * Kept beside the defs rather than in them, so a site compares with its
   * def by what the def declares, and laid on each def only when the
   * document is given its `$defs` (named()).
   *
   * @var array<string, array{title?: string, description?: string}>
   */
  protected array $names = [];

  /**
   * The children whose def is being written now.
   *
   * @var \SplObjectStorage<\Drupal\surface\SurfaceInterface, string>
   */
  protected \SplObjectStorage $writing;

  /**
   * The key each child's def was filed under.
   *
   * @var \SplObjectStorage<\Drupal\surface\SurfaceInterface, string>
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
   * @param string[] $open
   *   The ids of the structures the document mixes in somewhere: each
   *   one's def is written open, so an owner can compose it with keys of
   *   its own, and every other place it is used closes it.
   */
  public function __construct(
    public readonly bool $full,
    public readonly array $transient,
    protected array $open = [],
  ) {
    $this->keys = new \SplObjectStorage();
    $this->writing = new \SplObjectStorage();
  }

  /**
   * Gets the key a child's def was filed under, if it was.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The built child.
   *
   * @return string|null
   *   The key, or NULL when its def is not written yet.
   */
  public function keyOf(SurfaceInterface $child): ?string {
    return $this->keys->contains($child) ? $this->keys[$child] : NULL;
  }

  /**
   * Gets the id a child's def is being written under, if it is.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The built child.
   *
   * @return string|null
   *   The id, inside that def's own declaration; NULL elsewhere.
   */
  public function writing(SurfaceInterface $child): ?string {
    return $this->writing->contains($child) ? $this->writing[$child] : NULL;
  }

  /**
   * Marks a child's def as being written.
   *
   * @param string $id
   *   The id it is written under.
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child.
   */
  public function begin(string $id, SurfaceInterface $child): void {
    $this->writing[$child] = $id;
  }

  /**
   * Files a finished def.
   *
   * @param string $id
   *   The child's id.
   * @param array $def
   *   Its declaration, as its own frame describes it for no values.
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child it was written from.
   * @param string|\Stringable|null $label
   *   What the child's structure is called, its def's `title`; NULL titles
   *   the def by its key.
   * @param string|\Stringable|null $description
   *   What it is for, its def's `description`, or NULL.
   *
   * @return string
   *   The key it is filed under: the id, unless the id holds a different
   *   declaration already.
   */
  public function add(string $id, array $def, SurfaceInterface $child, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL): string {
    $this->writing->detach($child);
    $key = $id;
    for ($n = 2; isset($this->defs[$key]) && !self::same($this->defs[$key], $def); $n++) {
      $key = $id . '@' . $n;
    }
    $this->defs[$key] ??= $def;
    $this->keys[$child] = $key;
    if (!isset($this->names[$key])) {
      $names = [];
      if ($label !== NULL && (string) $label !== '') {
        $names['title'] = PlainText::of($label);
      }
      if ($description !== NULL && (string) $description !== '') {
        $names['description'] = PlainText::of($description);
      }
      $this->names[$key] = $names;
    }
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
   * Answers whether a def is written open, for an owner to mix it in.
   *
   * Decision: see docs/decisions.md#a-mixin-is-merged-flat.
   *
   * @param string $key
   *   The def's key: its id, or `<id>@<n>`.
   *
   * @return bool
   *   TRUE for a structure the document mixes in somewhere.
   */
  public function isOpen(string $key): bool {
    return in_array(preg_replace('/@\d+$/', '', $key), $this->open, TRUE);
  }

  /**
   * Adds structures the document mixes in, found where it states them.
   *
   * A variant of a list the site decides past its budget is built when
   * the document states it, a door's choice, so what it mixes in is known
   * then, before its def is written.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param string[] $ids
   *   Their ids.
   */
  public function openAlso(array $ids): void {
    $this->open = array_values(array_unique([...$this->open, ...$ids]));
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
   * Gets every def as the document states it: titled and described.
   *
   * Each def that its structure names leads with that structure's label
   * as its `title` and its description as its `description`; DocumentHead
   * titles any other by its key.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @return array<string, array>
   *   The defs, by key.
   */
  public function named(): array {
    $defs = [];
    foreach ($this->defs as $key => $def) {
      $defs[$key] = ($this->names[$key] ?? []) + $def;
    }
    return $defs;
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
