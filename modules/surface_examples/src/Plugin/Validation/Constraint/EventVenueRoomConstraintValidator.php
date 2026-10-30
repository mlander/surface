<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface_examples\VenueChoices;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\ChoiceValidator;

/**
 * Refuses a room no venue of the site has, as Choice does.
 *
 * The rooms are read from the venues when the value is checked, and the
 * value is then held to them by Choice's own validator, so what the
 * constraint accepts is what a Choice of that list accepts.
 */
final class EventVenueRoomConstraintValidator extends ChoiceValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\surface_examples\VenueChoices $choices
   *   The venues' rooms.
   */
  public function __construct(
    protected readonly VenueChoices $choices,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface_examples.venue_choices'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof EventVenueRoomConstraint);
    $listed = clone $constraint;
    $listed->choices = array_map('strval', array_keys($this->choices->rooms($constraint->venue)));
    parent::validate($value, $listed);
  }

}
