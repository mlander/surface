<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\ChoiceValidator;

/**
 * A Choice whose allowed values carry what they mean.
 *
 * Choice holds bare values, so a definition that says what is allowed
 * cannot also say what the allowed values mean, and every consumer that
 * wants meaning has to repeat the list somewhere else, where the two can
 * drift. This says both at once: `choices` is Choice's own list and is
 * validated by Choice's own validator, `labels` says what each allowed
 * value is called and `descriptions` what it is for. Neither label nor
 * description plays any part in validation.
 *
 * Because it is a Choice, every Choice-aware consumer already sees it
 * (core's own Choice reading, a schema emitter, a form builder), and the
 * upstream ask is "labels on Choice" rather than a second constraint.
 * Nothing here knows about data surfaces, widgets or options resolvers; the
 * resolver layer reads this constraint, never the other way round.
 *
 * There are three spellings, told apart by which of `choices` and
 * `labels` are given. All three allow the same values and offer the same
 * labels; reach for the first.
 *
 * `labels` alone: the keys are the allowed values, each mapped to what it
 * is called. Use it whenever the values carry meaning, and always for
 * integer values. An integer-keyed map written in `choices` reads as a
 * list of values to Choice and to a person reading the declaration, so
 * `labels` is where an integer value's meaning goes:
 * @code
 * $definition->addConstraint('LabeledChoice', [
 *   'labels' => [0 => t('Disabled'), 1 => t('Optional'), 2 => t('Required')],
 *   'descriptions' => [2 => t('Every request has to carry one.')],
 * ]);
 * @endcode
 *
 * `choices` alone, as a map of value to label: the same thing, for string
 * values, where no key can be mistaken for a position:
 * @code
 * $definition->addConstraint('LabeledChoice', [
 *   'choices' => ['star' => t('Star'), 'flame' => t('Flame')],
 * ]);
 * @endcode
 *
 * `choices` as a list with `labels` beside it: Choice's own form, for a
 * label set that covers only some of the values (a value with no label is
 * named by its value). It is also the form this module writes back after
 * narrowing or extending a list:
 * @code
 * $definition->addConstraint('LabeledChoice', [
 *   'choices' => ['star', 'flame', 'sash'],
 *   'labels' => ['star' => t('Star')],
 * ]);
 * @endcode
 *
 * A bare list of values with no meaning attached is core's Choice, not
 * this constraint, so `choices` alone is never read as one.
 *
 * @see \Drupal\data_surface\Plugin\DataSurfaceOptionsResolver\LabeledChoiceOptions
 */
#[Constraint(
  id: 'LabeledChoice',
  label: new TranslatableMarkup('Labeled choice', [], ['context' => 'Validation']),
  type: FALSE,
)]
class LabeledChoiceConstraint extends Choice {

  /**
   * What each allowed value is called, keyed by the value.
   *
   * A label may be a plain string or a TranslatableMarkup. Partial: a
   * value with no label of its own is named by its value.
   *
   * @var array
   */
  public array $labels = [];

  /**
   * Per-value help text, keyed by the value. May be partial.
   *
   * @var array
   */
  public array $descriptions = [];

  /**
   * Constructs a LabeledChoiceConstraint.
   *
   * @param array|null $choices
   *   The allowed values as a list, exactly as Choice holds them, when
   *   $labels is given; the allowed values mapped to their labels when
   *   it is not. NULL with $labels given: the keys of $labels.
   * @param array|null $labels
   *   What each allowed value is called, keyed by the value. Given
   *   without $choices, its keys are the allowed values.
   * @param array|null $descriptions
   *   Per-value help text, keyed by the value.
   * @param mixed ...$options
   *   Every other option Choice accepts, by name.
   */
  #[HasNamedArguments]
  public function __construct(
    ?array $choices = NULL,
    ?array $labels = NULL,
    ?array $descriptions = NULL,
    mixed ...$options,
  ) {
    if ($choices !== NULL && $labels === NULL) {
      // A map in choices: the keys are the allowed values and what they
      // mean is what they are mapped to.
      $labels = $choices;
      $choices = array_keys($choices);
    }
    elseif ($choices === NULL && $labels !== NULL) {
      // Labels alone: the keys are the allowed values.
      $choices = array_keys($labels);
    }
    $this->labels = $labels ?? [];
    $this->descriptions = $descriptions ?? [];
    $options['choices'] = $choices;
    parent::__construct(...$options);
  }

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    // Choice's own validator, named rather than subclassed, so what this
    // constraint accepts is what Choice accepts, down to the comparison.
    return ChoiceValidator::class;
  }

}
