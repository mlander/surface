<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

/**
 * The venues and rooms the examples choose from: two fixed lists.
 *
 * Fixed on purpose. The examples are about how one answer narrows the
 * next, not about where a list comes from, so both lists live here and
 * a fresh site has them the moment the module is enabled.
 */
final class Venues {

  /**
   * The venues, keyed by machine name.
   */
  public const VENUES = [
    'riverside' => 'Riverside Hall',
    'library' => 'Old Library',
    'harbour' => 'Harbour Centre',
  ];

  /**
   * The rooms: which venue each is in, its name, and how many it seats.
   */
  public const ROOMS = [
    'riverside_main' => ['venue' => 'riverside', 'label' => 'Main hall', 'seats' => 400],
    'riverside_east' => ['venue' => 'riverside', 'label' => 'East room', 'seats' => 120],
    'library_reading' => ['venue' => 'library', 'label' => 'Reading room', 'seats' => 60],
    'library_garden' => ['venue' => 'library', 'label' => 'Garden room', 'seats' => 30],
    'harbour_auditorium' => ['venue' => 'harbour', 'label' => 'Auditorium', 'seats' => 800],
    'harbour_deck' => ['venue' => 'harbour', 'label' => 'Upper deck', 'seats' => 150],
  ];

  /**
   * Gets the rooms, as choices: labels keyed by machine name.
   *
   * @param string|null $venue
   *   Only the rooms of this venue, or NULL for every room.
   *
   * @return array<string, string>
   *   The room labels, keyed by room.
   */
  public static function rooms(?string $venue = NULL): array {
    $rooms = [];
    foreach (self::ROOMS as $room => $info) {
      if ($venue === NULL || $info['venue'] === $venue) {
        $rooms[$room] = $info['label'];
      }
    }
    return $rooms;
  }

  /**
   * Gets how many a room seats.
   *
   * @param string $room
   *   The room.
   *
   * @return int|null
   *   The seats, or NULL for a room that is not on the list.
   */
  public static function seats(string $room): ?int {
    return self::ROOMS[$room]['seats'] ?? NULL;
  }

}
