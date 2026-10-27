<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * How a key is arranged where it is drawn, declared on its definition.
 *
 * A group to draw it in, a weight among its frame's keys, a placeholder, a
 * prefix and a suffix beside its input, and a help text under it: what a
 * form display says, said once, on the key, so every door that draws the
 * key reads it. The generated form reads it for every host (the operation
 * form, a block, a formatter, a field type), and the served contract
 * states it as `x-surface.arrangement` when a renderer asks for widget
 * hints, which the React app draws from. Nothing in it changes what is
 * accepted: no rule, no default, no requiredness, and no path moves.
 *
 * Nobody writes one. An owner and an alter alike arrange a key with the
 * blueprint's describe(), whose six arguments are this class's, after the
 * key is declared:
 *
 * @code
 * $workflow = ArrangementGroup::details('workflow', t('Publishing options'), collapsed: TRUE);
 * $inputs->add('status', 'boolean', t('Published'), default: TRUE);
 * $inputs->describe('status', group: $workflow);
 * @endcode
 *
 * describe() lays what it is given over what the key has: an alter may
 * place an owner's key and reword what the owner said, never take any of
 * it away. This is the stored form the engine reads, kept on the key's
 * definition by DefinitionMetadata::setArrangement().
 *
 * The placeholder is only ever this one: a declared example is no longer
 * drawn as a placeholder, since an empty field showing a value reads as
 * filled.
 *
 * @see \Drupal\data_surface\DefinitionMetadata::setArrangement()
 * @see docs/decisions.md#arrangement-is-declared-once
 */
final class Arrangement {

  /**
   * The texts an arrangement may carry, which an alter may reword.
   */
  public const TEXTS = ['placeholder', 'prefix', 'suffix', 'help'];

  /**
   * Constructs an Arrangement.
   *
   * @param \Drupal\data_surface\Resource\ArrangementGroup|null $group
   *   The group the key is drawn in, or NULL for none.
   * @param int|null $weight
   *   Its weight among its frame's keys (or its group's), lighter first;
   *   NULL for its place in declaration order.
   * @param string|\Stringable|null $placeholder
   *   What an empty input shows, for an input that types a value.
   * @param string|\Stringable|null $prefix
   *   A text drawn before the input, such as a currency sign.
   * @param string|\Stringable|null $suffix
   *   A text drawn after the input, such as a unit.
   * @param string|\Stringable|null $help
   *   A help text for a person filling the form, drawn after the key's
   *   description; unlike the description, it is not part of what the key
   *   means, so the tool listing does not say it.
   */
  public function __construct(
    public readonly ?ArrangementGroup $group = NULL,
    public readonly ?int $weight = NULL,
    public readonly string|\Stringable|null $placeholder = NULL,
    public readonly string|\Stringable|null $prefix = NULL,
    public readonly string|\Stringable|null $suffix = NULL,
    public readonly string|\Stringable|null $help = NULL,
  ) {}

  /**
   * Lays this arrangement over another: what this one says replaces.
   *
   * @param self|null $under
   *   The arrangement already on the key, or NULL.
   *
   * @return self
   *   The two as one, this one's words where both speak.
   */
  public function over(?self $under): self {
    if ($under === NULL) {
      return $this;
    }
    return new self(
      $this->group ?? $under->group,
      $this->weight ?? $under->weight,
      $this->placeholder ?? $under->placeholder,
      $this->prefix ?? $under->prefix,
      $this->suffix ?? $under->suffix,
      $this->help ?? $under->help,
    );
  }

  /**
   * Names what laying this arrangement over another would take away.
   *
   * A text set to the empty string where the other says one is taken
   * away, not reworded.
   *
   * @param self|null $under
   *   The arrangement already on the key, or NULL.
   *
   * @return string[]
   *   The names of the texts it would empty, in TEXTS order.
   */
  public function removes(?self $under): array {
    if ($under === NULL) {
      return [];
    }
    $removed = [];
    foreach (self::TEXTS as $text) {
      if ($this->{$text} !== NULL && (string) $this->{$text} === '' && $under->{$text} !== NULL && (string) $under->{$text} !== '') {
        $removed[] = $text;
      }
    }
    return $removed;
  }

  /**
   * States the arrangement for a renderer, as the served contract writes it.
   *
   * @return array{group?: array, weight?: int, placeholder?: string, prefix?: string, suffix?: string, help?: string}
   *   Only what was declared, texts as plain strings.
   */
  public function toArray(): array {
    $arranged = [];
    if ($this->group !== NULL) {
      $arranged['group'] = $this->group->toArray();
    }
    if ($this->weight !== NULL) {
      $arranged['weight'] = $this->weight;
    }
    foreach (self::TEXTS as $text) {
      if ($this->{$text} !== NULL && (string) $this->{$text} !== '') {
        $arranged[$text] = (string) $this->{$text};
      }
    }
    return $arranged;
  }

}
