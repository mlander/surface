<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * Reads a core Choice constraint as a list without meaning.
 *
 * Choice holds bare values, so each value is its own label. That is the
 * gap LabeledChoice closes; this resolver exists so a definition written
 * before it, or by a module that has no labels to offer, still produces
 * a usable list.
 */
#[DataSurfaceOptionsResolver(
  id: 'choice',
  label: new TranslatableMarkup('Choice'),
  constraint: 'Choice',
)]
final class ChoiceOptions extends DataSurfaceOptionsResolverBase {

  /**
   * {@inheritdoc}
   */
  public function applies(Constraint $constraint): bool {
    // A Choice whose values are resolved at validation time, as the
    // AllowedValues subclass does from the data object, names no list
    // that a definition alone can read.
    //
    // A labeled choice is a Choice too, and would be read here as a list
    // without meaning. Its own resolver knows the labels, so this one
    // stands aside rather than racing it for the same constraint.
    return parent::applies($constraint)
      && $constraint instanceof Choice
      && !$constraint instanceof LabeledChoiceConstraint
      && !empty($constraint->choices);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof Choice);
    $options = [];
    foreach ($constraint->choices ?? [] as $choice) {
      $options[$choice] = $choice;
    }
    return new OptionSet($options);
  }

}
