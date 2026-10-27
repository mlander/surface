<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Plugin\DataSurfaceOptionsResolver;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;
use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;
use Drupal\data_surface\Options\OptionSet;
use Drupal\data_surface_examples\Plugin\Validation\Constraint\EventVenueRoomConstraint;
use Drupal\data_surface_examples\VenueChoices;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Example 2: reads the EventVenueRoom constraint as the rooms.
 *
 * The rooms of one venue, or of every venue, by room id, each under its
 * name, cacheable by the venues' list tag. The list is every value the
 * constraint accepts, so a schema stating it states the constraint whole.
 */
#[DataSurfaceOptionsResolver(
  id: 'event_venue_room',
  label: new TranslatableMarkup('Room of an event venue'),
  constraint: 'EventVenueRoom',
  exact: TRUE,
)]
final class EventVenueRoomOptions extends DataSurfaceOptionsResolverBase {

  /**
   * Constructs the resolver.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraint_manager
   *   The constraint plugin manager.
   * @param \Drupal\data_surface_examples\VenueChoices $choices
   *   The venues' rooms.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ConstraintManager $constraint_manager,
    protected readonly VenueChoices $choices,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $constraint_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('validation.constraint'),
      $container->get('data_surface_examples.venue_choices'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    assert($constraint instanceof EventVenueRoomConstraint);
    return new OptionSet($this->choices->rooms($constraint->venue), [], $this->choices->cacheability());
  }

}
