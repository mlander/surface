<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\data_surface_examples\VenueChoices;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\ChoiceValidator;

/**
 * Refuses a venue or a room an event may not be held in, as Choice does.
 *
 * The list is read from the venues when the value is checked, and the
 * value is then held to it by Choice's own validator, so what these
 * constraints accept is what a Choice of that list accepts.
 */
final class VenueChoiceValidator extends ChoiceValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\data_surface_examples\VenueChoices $choices
   *   The venues and their rooms.
   */
  public function __construct(
    protected readonly VenueChoices $choices,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('data_surface_examples.venue_choices'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof Choice);
    $listed = clone $constraint;
    $listed->choices = array_map('strval', array_keys($constraint instanceof EventVenueRoomConstraint ? $this->choices->rooms($constraint->venue) : $this->choices->venues()));
    parent::validate($value, $listed);
  }

}
