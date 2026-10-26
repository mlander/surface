<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\data_surface\DataSurfaceInterface;

/**
 * What one walk of one document carries, in one place.
 *
 * ContractEmitter describes a document by walking its frames: the top
 * frame, then each child, variant and collection item inside it, and the
 * keys of each. Every step of that walk needs the same few things, which
 * used to travel as a dozen loose parameters of each method. An Emission
 * holds them:
 *
 * - shared by the whole walk, and mutable by design: the cacheability
 *   everything read collects into, the paths found stale, and the
 *   document's defs (`Subschemas`, which also says whether the document
 *   is the full schema). A step that descends hands the same three on, so
 *   what a deep step collects is what the document depends on;
 * - true of the frame being described, and replaced as the walk
 *   descends: its dotted path from the top with the trailing dot
 *   (`prefix`), the id it was built from (`owner`), the orphans of a
 *   refinement (`standing`) and three flags: `declaring` (the frame is a
 *   def, described for no values), `frozen` (the frame is a child its
 *   access locked, or inside one) and `blank` (the frame stands for every
 *   item of a collection).
 *
 * The object is immutable: each `with…()` method answers a copy. Only the
 * cacheability and the stale paths are written to, and those are objects
 * the copies share. How a frame is asked for, `runtime()` and `kinds()`,
 * follows from the full schema and `declaring`, so it is not held.
 *
 * It holds no service of the site and no request: a document is the same
 * at every door.
 *
 * Decision: see docs/decisions.md#one-context-per-walk-not-a-parameter-list.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Contract\ContractEmitter
 */
final readonly class Emission {

  /**
   * Constructs an Emission.
   *
   * @param \Drupal\data_surface\Contract\Subschemas $subschemas
   *   The document's defs, which every child is written to, and whether it
   *   is the full schema (emitFull()): its frames are then described from
   *   their locked keys alone, and a key that watches anything a value
   *   could change is described as declared.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the description depends on.
   * @param \ArrayObject $stale
   *   Collects the dotted paths of stale values. A step whose stale paths
   *   are not the document's takes a fresh one, withStale().
   * @param string $owner
   *   The id the frame was built from: the document's at the top, a
   *   child's own, or the key a child with no class is defined under. A
   *   child with no class of its own is defined as `<owner>.<key>`, a
   *   variant with none as `<owner>.<key>.<value>`.
   * @param string $prefix
   *   The dotted path of the frame from the top, with its trailing dot.
   * @param array<string, mixed> $standing
   *   The orphans of a refinement, by dotted path from the top, each
   *   mapped to the stored value it stands for; held unanswered in the
   *   frame's values.
   * @param bool $declaring
   *   Whether the frame is a def: the data surface as declared, for no
   *   values, with nothing locked. A locked key is then described as the
   *   key it is before a context fixes it (no `readOnly`, no `const`, and
   *   no default, which is the context's), and every child inside is a
   *   bare reference to its own def.
   * @param bool $frozen
   *   Whether the frame is a child its access locked, or is inside one:
   *   every key is then described locked (`readOnly`, `const` where held)
   *   and holds what it was given, which is what is stored.
   * @param bool $blank
   *   Whether the frame stands for every item of a collection rather than
   *   one value: it is described from the locked keys the child's
   *   operation knows alone, and a key locked only because the frame is
   *   frozen holds no `const`, since each item holds its own.
   */
  public function __construct(
    public Subschemas $subschemas,
    public CacheableMetadata $cacheability,
    public \ArrayObject $stale,
    public string $owner,
    public string $prefix = '',
    public array $standing = [],
    public bool $declaring = FALSE,
    public bool $frozen = FALSE,
    public bool $blank = FALSE,
  ) {}

  /**
   * Makes an Emission for describing one key alone, outside any document.
   *
   * It has no defs, collects its cacheability and stale paths nowhere
   * anyone reads, and sits at the top of a frame.
   *
   * @return self
   *   The Emission.
   */
  public static function detached(): self {
    return new self(new Subschemas(FALSE, []), new CacheableMetadata(), new \ArrayObject(), '');
  }

  /**
   * Answers whether the document is the full schema.
   *
   * @return bool
   *   TRUE for emitFull().
   */
  public function full(): bool {
    return $this->subschemas->full;
  }

  /**
   * Answers whether the frame is described for one set of values.
   *
   * The present moment is refined by every refiner; the full schema and a
   * def, which are for any values, by the static ones alone.
   *
   * @return bool
   *   TRUE when neither the full schema nor a def.
   */
  public function runtime(): bool {
    return !$this->full() && !$this->declaring;
  }

  /**
   * Gets the kinds of refiner a combination of the frame is answered by.
   *
   * @return int
   *   DataSurfaceInterface::STATIC for one set of values, whose declared
   *   rules are evaluated, and CODED for the full schema and a def, which
   *   state a declared rule as written.
   */
  public function kinds(): int {
    return $this->runtime() ? DataSurfaceInterface::STATIC : DataSurfaceInterface::CODED;
  }

  /**
   * Gets the dotted path of a key of the frame.
   *
   * @param string $name
   *   The key.
   *
   * @return string
   *   The path from the top.
   */
  public function at(string $name): string {
    return $this->prefix . $name;
  }

  /**
   * Records paths as stale.
   *
   * @param string ...$paths
   *   The dotted paths from the top.
   */
  public function markStale(string ...$paths): void {
    foreach ($paths as $path) {
      $this->stale->append($path);
    }
  }

  /**
   * Gets this, for a frame at another path.
   *
   * @param string $prefix
   *   The frame's dotted path from the top, with its trailing dot.
   *
   * @return self
   *   The copy.
   */
  public function withPrefix(string $prefix): self {
    return $this->with(prefix: $prefix);
  }

  /**
   * Gets this, for a frame built from another id.
   *
   * @param string $owner
   *   The id.
   *
   * @return self
   *   The copy.
   */
  public function withOwner(string $owner): self {
    return $this->with(owner: $owner);
  }

  /**
   * Gets this, with other orphans standing.
   *
   * @param array<string, mixed> $standing
   *   The orphans, by dotted path from the top.
   *
   * @return self
   *   The copy.
   */
  public function withStanding(array $standing): self {
    return $this->with(standing: $standing);
  }

  /**
   * Gets this, for a frame locked whole or not.
   *
   * @param bool $frozen
   *   Whether the frame is locked whole.
   *
   * @return self
   *   The copy.
   */
  public function withFrozen(bool $frozen): self {
    return $this->with(frozen: $frozen);
  }

  /**
   * Gets this, for a frame that stands for every item of a collection or not.
   *
   * @param bool $blank
   *   Whether it does.
   *
   * @return self
   *   The copy.
   */
  public function withBlank(bool $blank): self {
    return $this->with(blank: $blank);
  }

  /**
   * Gets this, collecting stale paths into another list.
   *
   * @param \ArrayObject $stale
   *   The list.
   *
   * @return self
   *   The copy.
   */
  public function withStale(\ArrayObject $stale): self {
    return $this->with(stale: $stale);
  }

  /**
   * Gets this, collecting stale paths into a list nobody reads.
   *
   * @return self
   *   The copy.
   */
  public function withoutStale(): self {
    return $this->with(stale: new \ArrayObject());
  }

  /**
   * Gets this, for the def of a child.
   *
   * A def is the child's frame as declared: from the top, for no values
   * and with nothing locked, no orphan standing, and no stale path kept.
   * It shares the document's defs and cacheability.
   *
   * @param string $id
   *   The id the def is filed under.
   *
   * @return self
   *   The copy.
   */
  public function forDef(string $id): self {
    return new self($this->subschemas, $this->cacheability, new \ArrayObject(), $id, '', [], TRUE);
  }

  /**
   * Makes a copy with some properties replaced.
   *
   * @param mixed ...$changes
   *   The constructor arguments to replace, by name.
   *
   * @return self
   *   The copy.
   */
  private function with(mixed ...$changes): self {
    return new self(...$changes + get_object_vars($this));
  }

}
