<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface_examples\Entity\EventVenue;

/**
 * The venues and rooms an event may be held in: example 15's entities.
 *
 * The enabled venues, lighter first, and their rooms in the order each
 * venue lists them, read under the venues' list tag, so a venue added,
 * changed or disabled is in every list at the next request. Example 2's
 * venue and room are chosen from them, through EventVenueOptions and
 * EventVenueRoomOptions, which read the constraints the keys declare.
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
   * Gets the venues an event may be held in.
   *
   * @return array<string, string>
   *   Their names, by machine name.
   */
  public function venues(): array {
    return array_map(static fn (EventVenue $venue): string => (string) $venue->label(), $this->enabled());
  }

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
    foreach ($this->enabled() as $id => $entity) {
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
   * Says what the lists are read from.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The venues' list tag.
   */
  public function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())->addCacheTags($this->entityTypeManager->getDefinition('event_venue')->getListCacheTags());
  }

  /**
   * Loads the enabled venues, lighter first.
   *
   * @return array<string, \Drupal\data_surface_examples\Entity\EventVenue>
   *   The venues, by machine name.
   */
  protected function enabled(): array {
    $venues = array_filter($this->entityTypeManager->getStorage('event_venue')->loadMultiple(), static fn ($venue): bool => $venue instanceof EventVenue && $venue->status());
    uasort($venues, [EventVenue::class, 'sort']);
    return $venues;
  }

}
