<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Example 15: a venue, a config entity type this module declares.
 *
 * No form classes, no list builder and no links: the resource over it,
 * VenueResource, is what adds, edits, lists, reorders and removes a venue,
 * at every door. Its config schema is written from that resource by
 * drush surface:config-schema --write.
 */
#[ConfigEntityType(
  id: 'event_venue',
  label: new TranslatableMarkup('Venue'),
  label_collection: new TranslatableMarkup('Venues'),
  label_singular: new TranslatableMarkup('venue'),
  label_plural: new TranslatableMarkup('venues'),
  config_prefix: 'event_venue',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'status' => 'status',
  ],
  admin_permission: 'administer event venues',
  label_count: [
    'singular' => '@count venue',
    'plural' => '@count venues',
  ],
  config_export: [
    'id',
    'label',
    'slug',
    'capacity',
    'address',
    'rooms',
    'weight',
  ],
)]
final class EventVenue extends ConfigEntityBase {

  /**
   * The names every venue's config object starts with, before its id.
   */
  public const PREFIX = 'surface_examples.event_venue';

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The name people read.
   */
  protected ?string $label = NULL;

  /**
   * The name in a link, computed from the name when the venue is added.
   */
  protected ?string $slug = NULL;

  /**
   * How many people the venue holds.
   */
  protected ?int $capacity = NULL;

  /**
   * Where it is.
   */
  protected ?string $address = NULL;

  /**
   * Its rooms, each an id, a name and its seats.
   *
   * @var array<int, array{id: string, label: string, seats: int}>|null
   */
  protected ?array $rooms = [];

  /**
   * Its place among the venues: lighter first.
   */
  protected ?int $weight = 0;

  /**
   * Gets the rooms.
   *
   * @return array<int, array{id: string, label: string, seats: int}>
   *   Each room, in the order the venue lists them.
   */
  public function rooms(): array {
    return $this->rooms ?? [];
  }

  /**
   * Gets the slug.
   *
   * @return string|null
   *   The name in a link, or NULL for a venue saved before it had one.
   */
  public function slug(): ?string {
    return $this->slug;
  }

  /**
   * Gets the weight.
   *
   * @return int
   *   Its place among the venues.
   */
  public function weight(): int {
    return (int) $this->weight;
  }

}
