<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Compute\MachineName;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\Compute;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples\Access\VenueAccess;
use Drupal\data_surface_examples\Entity\EventVenue;
use Drupal\data_surface_examples\Slugify;
use Drupal\data_surface_examples\Storage\VenueStorage;
use Drupal\data_surface_examples\Structure\VenueListingStructure;
use Drupal\data_surface_examples\Structure\VenueReorderStructure;
use Drupal\data_surface_examples\Structure\VenueRoomStructure;

/**
 * Example 15: a venue, a config entity this module declares, from the start.
 *
 * The resource is the venue entity, `data_surface_examples.event_venue.<id>`,
 * by its machine name, computed from the name unless one is given and
 * held once across the venues. Add, edit and remove are about one venue.
 * Listing and reordering are about the venues together, so they name no
 * venue: each takes the keys of a structure of its own, a page and a
 * filter, or a list of ids and weights, and the venue storage reads or
 * writes every venue they name.
 */
#[Resource('event.venue',
  identity: ['id'],
  storage: VenueStorage::class,
  access: VenueAccess::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a venue'),
  description: new TranslatableMarkup('Adds a venue under a machine name the caller chooses, or one computed from its name, with its capacity, address and rooms; a machine name already in use is refused.'),
  permission: self::ADMINISTER,
  creates: TRUE,
  idempotent: TRUE,
)]
#[Operation('list',
  label: new TranslatableMarkup('List the venues'),
  description: new TranslatableMarkup('Answers a page of the venues, lighter first, each one the account may read, and how many the filter matched.'),
  permission: self::VIEW,
  readOnly: TRUE,
  structure: VenueListingStructure::class,
)]
#[Operation('reorder',
  label: new TranslatableMarkup('Reorder the venues'),
  description: new TranslatableMarkup('Gives each venue named its new weight, all in one write; a venue left out keeps its own, and an unknown or repeated venue is refused.'),
  permission: self::ADMINISTER,
  structure: VenueReorderStructure::class,
)]
final class VenueResource implements ResourceInterface {

  /**
   * The permission that adds, edits, reorders and removes the venues.
   */
  public const ADMINISTER = 'administer event venues';

  /**
   * The permission that lists them.
   */
  public const VIEW = 'view event venues';

  /**
   * Edits a venue, named by its machine name, which stays as it is.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a venue'),
    description: new TranslatableMarkup('Changes a venue, named by its machine name, which stays as it is: its name, capacity, address, rooms, weight and status.'),
    permission: self::ADMINISTER,
  )]
  public static function update(EventVenue $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * Removes a venue, named by its machine name.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Remove a venue'),
    description: new TranslatableMarkup('Removes a venue, named by its machine name, with its rooms.'),
    permission: self::ADMINISTER,
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(EventVenue $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);

    $inputs->compute('id', 'machine_name', t('Machine name'),
      from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)
      ->setDescription(t('A unique name for the venue: lowercase letters, numbers and underscores, computed from its name unless one is given.'))
      ->addConstraint('Length', ['max' => 32]);

    $inputs->compute('slug', 'string', t('Slug'), from: ['label'], using: Slugify::class)
      ->setDescription(t('The name in a link: lowercase letters, numbers and dashes, set when the venue is added.'))
      ->addConstraint('Regex', [
        'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D',
        'message' => t('The slug may hold only lowercase letters, numbers and single dashes between them.'),
      ])
      ->addConstraint('Length', ['max' => 128]);

    $inputs->add('capacity', 'integer', t('Capacity'))
      ->setDescription(t('How many people the venue holds.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 100000]);

    $inputs->add('address', 'string', t('Address'))
      ->addConstraint('Length', ['max' => 255]);

    $inputs->attachList('rooms', VenueRoomStructure::class, max: 20)
      ->setLabel(t('Rooms'))
      ->setDescription(t('The rooms an event at the venue may be held in.'));

    $inputs->add('weight', 'integer', t('Weight'), default: 0)
      ->setDescription(t('Its place among the venues: lighter first.'))
      ->addConstraint('Range', ['min' => -1000, 'max' => 1000]);

    $inputs->add('status', 'boolean', t('Enabled'), default: TRUE)
      ->setDescription(t('Whether events may be held there; a disabled venue is listed only for an account that administers the venues.'));
  }

}
