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
 * value is called and `descriptions` what it is for, and neither plays
 * any part in validation.
 *
 * Because it is a Choice, every Choice-aware consumer already sees it —
 * core's own Choice reading, a schema emitter, a form builder — and the
 * upstream ask is "labels on Choice" rather than a second constraint.
 * Nothing here knows about surfaces, widgets or options resolvers; the
 * resolver layer reads this constraint, never the other way round.
 *
 * There are two spellings, and which one is meant is decided by whether
 * `labels` is there:
 *
 * The canonical one, Choice's list of values with the meaning beside it.
 * It is the only one that can express integer values, because an
 * integer-keyed map of labels cannot be told from a list of values:
 * @code
 * $definition->addConstraint('LabeledChoice', [
 *   'choices' => [0, 1, 2],
 *   'labels' => [0 => t('Disabled'), 1 => t('Optional'), 2 => t('Required')],
 *   'descriptions' => [2 => t('Every request has to carry one.')],
 * ]);
 * @endcode
 *
 * And the short one, which is how a list with meaning is most often
 * written by hand: `choices` on its own is read as value => label.
 * @code
 * $definition->addConstraint('LabeledChoice', [
 *   'choices' => ['star' => t('Star'), 'flame' => t('Flame')],
 * ]);
 * @endcode
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
   *   it is not.
   * @param array|null $labels
   *   What each allowed value is called, keyed by the value.
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
      // The short spelling: the keys are the allowed values and what
      // they mean is what they are mapped to.
      $labels = $choices;
      $choices = array_keys($choices);
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
