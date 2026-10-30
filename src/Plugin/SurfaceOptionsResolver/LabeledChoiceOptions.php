<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceOptionsResolver;
use Drupal\surface\Options\SurfaceOptionsResolverBase;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use Symfony\Component\Validator\Constraint;

/**
 * Reads a LabeledChoice constraint as its own value and label map.
 *
 * The direct case: the constraint already carries everything a consumer
 * needs, so the list is permanent — nothing outside the definition can
 * change it. The allowed values come from the Choice half of the
 * constraint, which is what validates them, so the list offered and the
 * list enforced cannot be two lists; a value the constraint gave no
 * label is offered under its own name.
 */
#[SurfaceOptionsResolver(
  id: 'labeled_choice',
  label: new TranslatableMarkup('Labeled choice'),
  constraint: 'LabeledChoice',
)]
final class LabeledChoiceOptions extends SurfaceOptionsResolverBase {

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof LabeledChoiceConstraint);
    $options = [];
    foreach ($constraint->choices ?? [] as $choice) {
      $options[$choice] = $constraint->labels[$choice] ?? $choice;
    }
    return new OptionSet($options, $constraint->descriptions);
  }

}
