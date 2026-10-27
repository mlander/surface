<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface_examples\Entity\EventVenue;

/**
 * The rooms an event may be held in: those of example 15's venues.
 *
 * Every venue's rooms, the venues lighter first and each venue's rooms in
 * the order it lists them, read under the venues' list tag, so a venue
 * added, changed or removed is in the list at the next request. Example
 * 2's room is chosen from them, through EventVenueRoomOptions, which reads
 * the constraint the room declares. Every venue, enabled or not, since the
 * venue is a reference whose candidates are core's selection's, which
 * lists every venue.
 */
final class VenueChoices {

  /**
   * Constructs VenueChoices.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the venues.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the rooms an event may be held in.
   *
   * @param string|null $venue
   *   Only the rooms of this venue, or NULL for every room.
   *
   * @return array<string, string>
   *   The room names, by room id.
   */
  public function rooms(?string $venue = NULL): array {
    $rooms = [];
    foreach ($this->venues() as $id => $entity) {
      if ($venue !== NULL && $id !== $venue) {
        continue;
      }
      foreach ($entity->rooms() as $room) {
        $rooms[(string) $room['id']] = (string) $room['label'];
      }
    }
    return $rooms;
  }

  /**
   * Says what the list is read from.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The venues' list tag.
   */
  public function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())->addCacheTags($this->entityTypeManager->getDefinition('event_venue')->getListCacheTags());
  }

  /**
   * Loads the venues, lighter first.
   *
   * @return array<string, \Drupal\data_surface_examples\Entity\EventVenue>
   *   The venues, by machine name.
   */
  protected function venues(): array {
    $venues = array_filter($this->entityTypeManager->getStorage('event_venue')->loadMultiple(), static fn ($venue): bool => $venue instanceof EventVenue);
    uasort($venues, [EventVenue::class, 'sort']);
    return $venues;
  }

}
