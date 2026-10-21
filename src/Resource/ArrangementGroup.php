<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * A named group the keys of one frame are drawn in: a fieldset or a details.
 *
 * Part of an Arrangement: each key that belongs to the group names it, and
 * the group is drawn where its first key is declared, its keys inside it.
 * Keys of one frame that name the same id name one group, so they agree on
 * its label, its kind and whether it starts collapsed, which the build
 * checks. Only drawing: every key inside it is posted, stored and sent at
 * its own path, as though no group were there.
 *
 * @see \Drupal\data_surface\Resource\Arrangement
 * @see docs/decisions.md#arrangement-is-declared-once
 */
final class ArrangementGroup {

  /**
   * A fieldset: a legend over its keys, always open.
   */
  public const FIELDSET = 'fieldset';

  /**
   * A details: a summary over its keys, which a person may close.
   */
  public const DETAILS = 'details';

  /**
   * Constructs an ArrangementGroup; use fieldset() or details().
   *
   * @param string $id
   *   The group's name in its frame: no dot, no colon, not empty.
   * @param string|\Stringable $label
   *   The legend or summary a person reads.
   * @param string $as
   *   self::FIELDSET or self::DETAILS.
   * @param bool $collapsed
   *   For a details, whether it starts closed.
   * @param int|null $weight
   *   Where the group sits among its frame's keys, as a key's weight;
   *   NULL for where its first key is declared. A key's own weight orders
   *   it inside its group.
   */
  protected function __construct(
    public readonly string $id,
    public readonly string|\Stringable $label,
    public readonly string $as,
    public readonly bool $collapsed = FALSE,
    public readonly ?int $weight = NULL,
  ) {
    if ($id === '' || strpbrk($id, '.:') !== FALSE) {
      throw new \InvalidArgumentException(sprintf('"%s" cannot name a group: a group is named in its frame, not empty and with no dot or colon.', $id));
    }
  }

  /**
   * A fieldset of keys, always open.
   *
   * @param string $id
   *   The group's name in its frame.
   * @param string|\Stringable $label
   *   Its legend.
   * @param int|null $weight
   *   Its weight among the frame's keys, or NULL.
   *
   * @return static
   *   The group.
   */
  public static function fieldset(string $id, string|\Stringable $label, ?int $weight = NULL): static {
    return new static($id, $label, self::FIELDSET, FALSE, $weight);
  }

  /**
   * A details of keys, which a person may close.
   *
   * @param string $id
   *   The group's name in its frame.
   * @param string|\Stringable $label
   *   Its summary.
   * @param bool $collapsed
   *   Whether it starts closed.
   * @param int|null $weight
   *   Its weight among the frame's keys, or NULL.
   *
   * @return static
   *   The group.
   */
  public static function details(string $id, string|\Stringable $label, bool $collapsed = FALSE, ?int $weight = NULL): static {
    return new static($id, $label, self::DETAILS, $collapsed, $weight);
  }

  /**
   * Whether another group is this one: the same name, label and drawing.
   *
   * @param self $other
   *   The other group.
   *
   * @return bool
   *   TRUE when both draw the same group.
   */
  public function sameAs(self $other): bool {
    return $this->id === $other->id
      && (string) $this->label === (string) $other->label
      && $this->as === $other->as
      && $this->collapsed === $other->collapsed
      && $this->weight === $other->weight;
  }

  /**
   * States the group for a renderer, as the served contract writes it.
   *
   * @return array{id: string, label: string, as: string, collapsed?: true, weight?: int}
   *   The group: `collapsed` only when it starts closed, `weight` only
   *   when declared.
   */
  public function toArray(): array {
    $group = ['id' => $this->id, 'label' => (string) $this->label, 'as' => $this->as];
    if ($this->collapsed) {
      $group['collapsed'] = TRUE;
    }
    if ($this->weight !== NULL) {
      $group['weight'] = $this->weight;
    }
    return $group;
  }

}
