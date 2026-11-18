<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Access\VenueAccess;
use Drupal\surface_examples\Slugify;
use Drupal\surface_examples\Storage\VenueResourceStorage;
use Drupal\surface_examples\Structure\VenueListingStructure;
use Drupal\surface_examples\Structure\VenueReorderStructure;
use Drupal\surface_examples\Structure\VenueRoomStructure;
use PortableSchema\Builder\Schema;

/**
 * Example 15: a venue, a config entity this module declares, from the start.
 *
 * The resource is the venue entity, `surface_examples.event_venue.<id>`,
 * by its machine name, computed from the name unless one is given and
 * held once across the venues. Add, edit and remove are about one venue.
 * Listing and reordering are about the venues together, so they name no
 * venue: each takes the keys of a structure of its own, a page and a
 * filter, or a list of ids and weights, and the venue storage reads or
 * writes every venue they name.
 */
#[Resource('event.venue',
  identity: ['id'],
  storage: VenueResourceStorage::class,
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
   *
   * Handed the machine name; the venue storage loads the venue, and
   * refuses a name no venue has.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a venue'),
    description: new TranslatableMarkup('Changes a venue, named by its machine name, which stays as it is: its name, capacity, address, rooms, weight and status.'),
    permission: self::ADMINISTER,
  )]
  public static function update(string $id): OperationContext {
    $venue = \Drupal::classResolver(VenueResourceStorage::class)->named($id, 'The "update" operation of event.venue');
    return new OperationContext(known: ['id' => (string) $venue->id()]);
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
  public static function delete(string $id): OperationContext {
    $venue = \Drupal::classResolver(VenueResourceStorage::class)->named($id, 'The "delete" operation of event.venue');
    return new OperationContext(known: ['id' => (string) $venue->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', Schema::string()
      ->title(t('Name'))
      ->maxLength(128)
      ->required());

    $inputs->compute(
      'id',
      Schema::of('drupal:config/machine_name')
        ->title(t('Machine name'))
        ->description(t('A unique name for the venue: lowercase letters, numbers and underscores, computed from its name unless one is given.'))
        ->check('drupal:Length', ['max' => 32]),
      from: ['label'],
      using: MachineName::class,
      when: Compute::UNLESS_SENT,
    );

    $inputs->compute(
      'slug',
      Schema::string()
        ->title(t('Slug'))
        ->description(t('The name in a link: lowercase letters, numbers and dashes, set when the venue is added.'))
        ->check('drupal:Regex', [
          'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D',
          'message' => t('The slug may hold only lowercase letters, numbers and single dashes between them.'),
        ])
        ->maxLength(128),
      from: ['label'],
      using: Slugify::class,
    );

    $inputs->add('capacity', Schema::integer()
      ->title(t('Capacity'))
      ->description(t('How many people the venue holds.'))
      ->minimum(1)
      ->maximum(100000)
      ->required());

    $inputs->add('address', Schema::string()
      ->title(t('Address'))
      ->maxLength(255));

    $inputs->attachList(
      'rooms',
      VenueRoomStructure::class,
      t('Rooms'),
      max: 20,
      description: t('The rooms an event at the venue may be held in.'),
    );

    $inputs->add('weight', Schema::integer()
      ->title(t('Weight'))
      ->description(t('Its place among the venues: lighter first.'))
      ->default(0)
      ->minimum(-1000)
      ->maximum(1000));

    $inputs->add('status', Schema::bool()
      ->title(t('Enabled'))
      ->description(t('Whether events may be held there; a disabled venue is listed only for an account that administers the venues.'))
      ->default(TRUE));
  }

}
