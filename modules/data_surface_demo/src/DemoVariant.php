<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The display variants this demo owns, written down once.
 *
 * A vocabulary is wanted in at least two places: the declaration, which
 * says what the key allows and what each value means, and the code that
 * acts on a stored value. Written out twice they drift, so the cases are
 * the one spelling of both. `choices()` hands the declaration exactly the
 * value-to-label map the short spelling of `LabeledChoice` takes, and the
 * cases themselves answer which casing offers what.
 *
 * Modeled on core's `NodePreviewMode`, which keeps its words in a
 * `label()` method and projects them with `asOptions()`. The projection is
 * named `choices()` here because that is the constraint option it fills.
 *
 * An enum is not a fence around the key. What the key allows is the
 * constraint, and a contributing module widens it at build time with a
 * value of its own: `data_surface_demo_extras` contributes `ribbon`,
 * which is deliberately not a case here. A contributor answers for its
 * own value, and the owner's enum is not where that value lives — that
 * is the point of a contribution, not an oversight. So code acting on a
 * stored variant takes the string it was handed rather than
 * `DemoVariant::from()`-ing it, and only the owner's own half of the
 * vocabulary is enumerated.
 *
 * @see \Drupal\data_surface_demo\Plugin\Field\FieldFormatter\DataSurfaceDemoFormatter
 * @see \Drupal\data_surface_demo_extras\DemoExtrasVariantRefiner
 */
enum DemoVariant: string {

  case Bold = 'bold';
  case Strong = 'strong';
  case Quiet = 'quiet';
  case Muted = 'muted';

  /**
   * Gets what this variant is called.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Bold => new TranslatableMarkup('Bold'),
      self::Strong => new TranslatableMarkup('Strong'),
      self::Quiet => new TranslatableMarkup('Quiet'),
      self::Muted => new TranslatableMarkup('Muted'),
    };
  }

  /**
   * Gets the casing that offers this variant.
   *
   * @return string
   *   The casing value.
   */
  public function casing(): string {
    return match ($this) {
      self::Bold, self::Strong => 'uppercase',
      self::Quiet, self::Muted => 'lowercase',
    };
  }

  /**
   * Gets every variant as the value-to-label map a declaration takes.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to.
   */
  public static function choices(): array {
    return self::choicesOf(self::cases());
  }

  /**
   * Gets the variants one casing offers, in the same shape.
   *
   * @param string $casing
   *   The casing to ask about.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to; empty when that
   *   casing offers none of its own, which is not the same as a casing
   *   saying that nothing is offered.
   */
  public static function choicesFor(string $casing): array {
    return self::choicesOf(array_filter(
      self::cases(),
      static fn (self $case): bool => $case->casing() === $casing,
    ));
  }

  /**
   * Maps cases to their labels, keyed by value.
   *
   * @param \Drupal\data_surface_demo\DemoVariant[] $cases
   *   The cases to project.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by the value they belong to.
   */
  protected static function choicesOf(array $cases): array {
    $choices = [];
    foreach ($cases as $case) {
      $choices[$case->value] = $case->label();
    }
    return $choices;
  }

}
