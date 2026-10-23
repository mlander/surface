<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

/**
 * The tool calls examples 7 to 13 show, written down once.
 *
 * None writes anything: every call is a dry run, which accepts, validates
 * and prepares the values, answers with what would be stored and stops,
 * except a call to an operation that changes nothing, which stops there
 * by itself and is sent as it would be for real. So each
 * example's panel can make its calls whenever it is drawn and show what
 * the tool answered, ExamplesConceptsToolTest makes them on the shipped
 * settings and holds the README to their answers, and the README and the
 * panels spell them as Drush commands.
 *
 * Each call names the tool by its resource and operation, and sends every
 * key its resource requires, which the Tool API asks for on every call.
 */
final class ConceptCalls {

  /**
   * A value that stands for the site's default theme, read when it is sent.
   */
  public const DEFAULT_THEME = '@default_theme';

  /**
   * The calls, by resource id and then by name.
   *
   * Each says what it shows, the operation it calls (configure unless it
   * says), the parameters it names the thing by, if any, the values it
   * sends (none for a delete), and whether it is a dry run (unless it
   * says). A call that shows another module's part names the module, made
   * only where it is enabled, and the State it is made in, set for the
   * call and put back after it. A value DEFAULT_THEME is the site's
   * default theme when the call is made (values()).
   */
  public const CALLS = [
    'event.bulletin' => [
      'monthly_with_day' => [
        'label' => 'A send day for a monthly bulletin',
        'values' => ['frequency' => 'monthly', 'send_day' => 'monday'],
      ],
      'weekly_items' => [
        'label' => 'Eight items in a weekly issue',
        'values' => ['frequency' => 'weekly', 'items' => 8],
      ],
      'monthly_items' => [
        'label' => 'Eight items in a monthly issue, and no send day',
        'values' => ['frequency' => 'monthly', 'items' => 8],
      ],
    ],
    'event.press' => [
      'listed_without_email' => [
        'label' => 'A public contact with the email cleared',
        'values' => ['public' => TRUE, 'contact' => ['name' => 'Robin Lee', 'email' => NULL]],
      ],
      'unlisted_without_email' => [
        'label' => 'The same contact, not shown on the press page',
        'values' => ['public' => FALSE, 'contact' => ['name' => 'Robin Lee', 'email' => NULL]],
      ],
      'reviewed_by_nobody' => [
        'label' => 'A review date with no reviewer',
        'values' => ['reviewed_on' => '2026-10-19', 'reviewed_by' => NULL],
      ],
    ],
    'event.desk' => [
      'phone_with_dashes' => [
        'label' => 'A desk phone the shape refuses',
        'values' => ['phone' => '555-0199'],
      ],
    ],
    'event.sales' => [
      'speaker_on_sale' => [
        'label' => 'Speaker tickets put on sale',
        'values' => ['on_sale' => ['standard' => TRUE, 'speaker' => TRUE]],
      ],
      'limit_off_sale' => [
        'label' => 'Student tickets taken off sale, with a new limit for them',
        'values' => ['on_sale' => ['student' => FALSE], 'limits' => ['student' => ['per_order' => 2]]],
      ],
    ],
    'event.program' => [
      'check' => [
        'label' => 'A program checked',
        'operation' => 'check',
        'values' => ['headline' => 'Three days of talks', 'sessions' => 12],
        'dry_run' => FALSE,
      ],
      'check_too_many' => [
        'label' => 'A program with too many sessions, checked',
        'operation' => 'check',
        'values' => ['headline' => 'Three days of talks', 'sessions' => 40],
        'dry_run' => FALSE,
      ],
      'published_in_workflow' => [
        'label' => 'A new headline, once the workflow module has published the program',
        'values' => ['headline' => 'Four days of talks'],
        'module' => 'data_surface_examples_workflow',
        'state' => ['data_surface_examples_workflow.state' => 'published'],
      ],
    ],
    'event.doors' => [
      'left_out' => [
        'label' => 'The notes left out: kept',
        'values' => ['opens' => '19:00', 'days' => ['fri']],
      ],
      'null' => [
        'label' => 'The notes sent null: cleared',
        'values' => ['opens' => '19:00', 'days' => ['fri'], 'notes' => NULL],
      ],
      'value' => [
        'label' => 'The notes sent a value: replaced',
        'values' => ['opens' => '19:00', 'days' => ['fri'], 'notes' => 'Doors close at ten.'],
      ],
      'always_held' => [
        'label' => 'The badges sent null: refused, since they are always held',
        'values' => ['opens' => '19:00', 'days' => ['fri'], 'badges' => NULL],
      ],
    ],
    'event.spotlight' => [
      'hero' => [
        'label' => 'The hero, with props it takes',
        'values' => [
          'component' => 'data_surface_examples:hero',
          'props' => ['title' => 'Doors open at half past six', 'align' => 'start'],
        ],
      ],
      'wrong_component' => [
        'label' => 'A card sent the props of a hero',
        'values' => [
          'component' => 'data_surface_examples:card',
          'props' => ['align' => 'start', 'overlay' => 60],
        ],
      ],
      'out_of_bounds' => [
        'label' => 'A hero darker than its schema allows',
        'values' => [
          'component' => 'data_surface_examples:hero',
          'props' => ['title' => 'Doors open at half past six', 'overlay' => 140],
        ],
      ],
    ],
    'block.placement' => [
      'place' => [
        'label' => 'The Spotlight block placed through add, made again: refused for the machine name it took',
        'operation' => 'add',
        'module' => 'data_surface_block',
        'values' => [
          'label' => 'Spotlight',
          'id' => 'data_surface_examples_spotlight',
          'label_display' => TRUE,
          'plugin' => 'data_surface_component',
          'settings' => [
            'component' => 'data_surface_examples:card',
            'props' => [
              'heading' => 'Doors open at half past six',
              'body' => 'Bring your ticket, on your phone or printed.',
              'tone' => 'highlight',
              'link' => [
                'url' => 'https://example.com/tickets',
                'label' => 'Find your ticket',
              ],
            ],
          ],
          'theme' => self::DEFAULT_THEME,
          'region' => 'content',
          'weight' => 100,
          'visibility' => [
            [
              'id' => 'request_path',
              'settings' => ['pages' => '/surface-examples/13'],
            ],
          ],
        ],
      ],
      'props' => [
        'label' => 'The Spotlight block set to the hero',
        'operation' => 'edit',
        'module' => 'data_surface_block',
        'parameters' => ['id' => 'data_surface_examples_spotlight'],
        'values' => [
          'settings' => [
            'component' => 'data_surface_examples:hero',
            'props' => ['title' => 'Doors open at half past six', 'align' => 'start'],
          ],
        ],
      ],
      'remove' => [
        'label' => 'The Spotlight block removed, rehearsed',
        'operation' => 'remove',
        'module' => 'data_surface_block',
        'parameters' => ['id' => 'data_surface_examples_spotlight'],
      ],
    ],
  ];

  /**
   * Gets the calls of one resource.
   *
   * @param string $resource
   *   The resource id.
   * @param string[]|null $modules
   *   The modules enabled, to leave out a call whose module is not; NULL
   *   for every call.
   *
   * @return array<string, array{label: string, operation?: string, parameters?: array<string, string>, values?: array, dry_run?: bool, module?: string, state?: array<string, mixed>}>
   *   Its calls, by name; none for a resource with no calls.
   */
  public static function of(string $resource, ?array $modules = NULL): array {
    return array_filter(self::CALLS[$resource] ?? [], static fn (array $call): bool => $modules === NULL || !isset($call['module']) || in_array($call['module'], $modules, TRUE));
  }

  /**
   * Gets the values a call sends, the default theme filled in.
   *
   * @param string $resource
   *   The resource id.
   * @param string $call
   *   The call's name.
   *
   * @return array|null
   *   The values, each DEFAULT_THEME the site's default theme; NULL for a
   *   call that sends none, a delete.
   */
  public static function values(string $resource, string $call): ?array {
    $values = self::CALLS[$resource][$call]['values'] ?? NULL;
    if ($values === NULL) {
      return NULL;
    }
    array_walk_recursive($values, static function (mixed &$value): void {
      if ($value === self::DEFAULT_THEME) {
        $value = (string) \Drupal::config('system.theme')->get('default');
      }
    });
    return $values;
  }

  /**
   * Names the tool a call is made to.
   *
   * @param string $resource
   *   The resource id.
   * @param string $call
   *   The call's name.
   *
   * @return string
   *   The tool id.
   */
  public static function tool(string $resource, string $call): string {
    return 'data_surface:' . $resource . ':' . (self::CALLS[$resource][$call]['operation'] ?? 'configure');
  }

  /**
   * Spells one call as the Tool API's Drush command.
   *
   * Run as user 1, because tool:run runs as anonymous unless told
   * otherwise, and every operation here asks for "administer site
   * configuration" or "administer blocks". The parameters go in the same
   * JSON as the values, before them. The dry run is a second input, which
   * tool:run reads as name=value and decodes as JSON, so `true` is a
   * boolean. A call made in a State of its own sets it first and deletes it
   * after.
   *
   * @param string $resource
   *   The resource id.
   * @param string $call
   *   The call's name.
   *
   * @return string
   *   The command.
   */
  public static function drush(string $resource, string $call): string {
    $values = self::values($resource, $call);
    $input = json_encode((self::CALLS[$resource][$call]['parameters'] ?? []) + ($values === NULL ? [] : ['values' => $values]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $command = sprintf(
      "drush tool:run %s --uid=1 --input='%s'%s",
      self::tool($resource, $call),
      str_replace("'", "'\\''", (string) $input),
      (self::CALLS[$resource][$call]['dry_run'] ?? TRUE) ? ' --input=dry_run=true' : '',
    );
    $state = self::CALLS[$resource][$call]['state'] ?? [];
    foreach (array_reverse($state, TRUE) as $key => $value) {
      $command = sprintf("drush state:set %s %s\n%s\ndrush state:delete %s", $key, $value, $command, $key);
    }
    return $command;
  }

}
