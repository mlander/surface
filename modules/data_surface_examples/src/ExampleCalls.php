<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

/**
 * Example 5's three calls to the example 3 tool, written down once.
 *
 * The kernel test makes them, scripts/examples-dry-run.php prints what
 * they answer, and the example 5 page and the README show them as Drush
 * commands. Every call says where the event is, so none depends on what
 * an earlier one wrote, and every capacity is a hundred or fewer, so the
 * compliance module of example 4 does not change the answers.
 */
final class ExampleCalls {

  /**
   * The tool every call is made to.
   */
  public const TOOL = 'data_surface:registration.step3:configure';

  /**
   * The calls: what each sends, and whether it is a dry run.
   */
  public const CALLS = [
    'valid' => [
      'label' => 'Valid values',
      'values' => [
        'title' => 'Autumn meetup',
        'capacity' => 90,
        'venue' => 'harbour',
        'room' => 'harbour_deck',
        'pricing' => 'paid',
        'ticket' => ['price' => 12.5, 'currency' => 'EUR'],
      ],
      'dry_run' => FALSE,
    ],
    'over_capacity' => [
      'label' => 'A capacity above the room\'s',
      'values' => [
        'venue' => 'library',
        'room' => 'library_garden',
        'capacity' => 45,
      ],
      'dry_run' => FALSE,
    ],
    'dry_run' => [
      'label' => 'A dry run',
      'values' => [
        'title' => 'Winter social',
        'capacity' => 100,
        'venue' => 'riverside',
        'room' => 'riverside_east',
        'pricing' => 'free',
        'ticket' => ['note' => 'Donations welcome'],
      ],
      'dry_run' => TRUE,
    ],
  ];

  /**
   * Spells one call as the Tool API's Drush command.
   *
   * Run as user 1, because tool:run runs as anonymous unless told
   * otherwise, and the situation asks for "administer site
   * configuration". The dry run is a second input, which tool:run reads
   * as name=value and decodes as JSON, so `true` is a boolean.
   *
   * @param string $call
   *   The call's key in self::CALLS.
   *
   * @return string
   *   The command.
   */
  public static function drush(string $call): string {
    $input = json_encode(['values' => self::CALLS[$call]['values']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return sprintf(
      "drush tool:run %s --uid=1 --input='%s'%s",
      self::TOOL,
      str_replace("'", "'\\''", (string) $input),
      self::CALLS[$call]['dry_run'] ? ' --input=dry_run=true' : '',
    );
  }

}
