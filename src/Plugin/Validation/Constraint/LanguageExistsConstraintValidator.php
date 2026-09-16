<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the LanguageExists constraint.
 *
 * No value given is not a failure, which is how core's own existence
 * validators read an absent value; any other value has to name one of
 * the languages the constraint accepts, and the value it named is
 * reported in the message's placeholder the way PluginExists reports a
 * plugin ID.
 */
final class LanguageExistsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a LanguageExistsConstraintValidator.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager, which knows the languages the site has.
   */
  public function __construct(
    protected readonly LanguageManagerInterface $languageManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('language_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof LanguageExistsConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $languages = $this->languageManager->getLanguages($constraint->languageStates());
    if (!is_string($value) || !isset($languages[$value])) {
      $this->context->addViolation($constraint->message, [
        '@langcode' => is_scalar($value) ? (string) $value : $this->formatValue($value),
      ]);
    }
  }

}
