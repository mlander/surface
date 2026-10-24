<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

/**
 * The tool calls examples 7 to 16 show, written down once.
 *
 * None leaves anything written: every call is a dry run, which accepts,
 * validates and prepares the values, answers with what would be stored
 * and stops, except a call to an operation that changes nothing, which
 * stops there by itself and is sent as it would be for real, and
 * example 15's, which adds a fourth venue, changes it and removes it
 * again with its last call, so the three venues the module ships are
 * what is left. So each
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
   * Example 15: the fourth venue its calls add and remove again.
   */
  public const TOWN_HALL = [
    'label' => 'Town Hall',
    'id' => 'town_hall',
    'capacity' => 300,
    'address' => '1 Market Square',
    'rooms' => [
      ['id' => 'town_hall_council', 'label' => 'Council chamber', 'seats' => 120],
      ['id' => 'town_hall_foyer', 'label' => 'Foyer', 'seats' => 80],
    ],
    'weight' => 3,
  ];

  /**
   * A value that stands for the site's default theme, read when it is sent.
   */
  public const DEFAULT_THEME = '@default_theme';

  /**
   * Example 16: a value that stands for one of its nodes, by name.
   *
   * `@node:article`, `@node:draft` or `@node:page`: the id the references
   * module wrote it under when it was installed, read from State
   * (NODES) when the call is made.
   */
  public const NODE = '@node:';

  /**
   * Example 16: the State key its nodes' ids are kept under, by name.
   *
   * \Drupal\data_surface_examples_references\Sponsorship::NODES, spelled
   * here so this class needs nothing of a module that may be off.
   */
  public const NODES = 'data_surface_examples_references.nodes';

  /**
   * Example 16: the module its resource is in.
   */
  public const REFERENCES = 'data_surface_examples_references';

  /**
   * Example 16: the role of the account a call is made as, when it says.
   */
  public const EDITOR = 'event_sponsorship_editor';

  /**
   * The calls, by resource id and then by name.
   *
   * Each says what it shows, the operation it calls (configure unless it
   * says), the parameters it names the thing by, if any, the values it
   * sends (none for a delete), and whether it is a dry run (unless it
   * says). A call that shows another module's part names the module, made
   * only where it is enabled, and the State it is made in, set for the
   * call and put back after it. A call that says an account is made as an
   * account whose only role, beside authenticated, is that one. A value
   * DEFAULT_THEME is the site's default theme when the call is made, and
   * a value NODE and a name one of example 16's nodes (values()).
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
    'event.mailer' => [
      'pasted' => [
        'label' => 'A key pasted, rehearsed: the secret is in no answer',
        'values' => ['source' => 'pasted', 'credential' => ['api_key' => 'mk-example-7f3a91']],
      ],
      'managed' => [
        'label' => 'A managed key chosen, rehearsed',
        'module' => 'data_surface_examples_keys',
        'values' => ['source' => 'managed', 'credential' => ['key_id' => 'mail_service']],
      ],
      'pasted_while_managed' => [
        'label' => 'A pasted key sent while the managed source is chosen: refused, naming the source',
        'module' => 'data_surface_examples_keys',
        'values' => [
          'source' => 'managed',
          'credential' => ['key_id' => 'mail_service', 'api_key' => 'mk-example-7f3a91'],
        ],
      ],
      'read' => [
        'label' => 'The mailer read: the pasted key is left out',
        'operation' => 'configure:read',
        'dry_run' => FALSE,
      ],
    ],
    'event.webhook' => [
      'pasted' => [
        'label' => 'A key pasted, rehearsed',
        'values' => ['api_key' => 'wh-example-02c4d8'],
      ],
      'managed' => [
        'label' => 'A managed key chosen, rehearsed',
        'module' => 'data_surface_examples_keys',
        'values' => ['third_party_settings' => ['data_surface_examples_keys' => ['key_id' => 'webhook_signing']]],
      ],
      'pasted_beside_managed' => [
        'label' => 'A pasted key sent beside a managed key: refused, naming the key it depends on',
        'module' => 'data_surface_examples_keys',
        'values' => [
          'api_key' => 'wh-example-02c4d8',
          'third_party_settings' => ['data_surface_examples_keys' => ['key_id' => 'webhook_signing']],
        ],
      ],
    ],
    'event.venue' => [
      'list' => [
        'label' => 'The venues listed, two to a page',
        'operation' => 'list',
        'values' => ['limit' => 2],
        'dry_run' => FALSE,
      ],
      'create_rehearsed' => [
        'label' => 'A fourth venue, Town Hall, added: rehearsed',
        'operation' => 'create',
        'values' => self::TOWN_HALL,
      ],
      'create' => [
        'label' => 'Town Hall added: written, so the next calls find it',
        'operation' => 'create',
        'values' => self::TOWN_HALL,
        'dry_run' => FALSE,
      ],
      'update' => [
        'label' => "Town Hall's capacity changed: written",
        'operation' => 'update',
        'parameters' => ['id' => 'town_hall'],
        'values' => ['capacity' => 350],
        'dry_run' => FALSE,
      ],
      'reorder' => [
        'label' => 'Town Hall moved first and Riverside Hall last, in one write: rehearsed',
        'operation' => 'reorder',
        'values' => ['venues' => [['id' => 'town_hall', 'weight' => -1], ['id' => 'riverside', 'weight' => 4]]],
      ],
      'reorder_unknown' => [
        'label' => 'A reorder naming a venue the site does not have, and one twice: refused',
        'operation' => 'reorder',
        'values' => [
          'venues' => [
            ['id' => 'concert_hall', 'weight' => 0],
            ['id' => 'library', 'weight' => 1],
            ['id' => 'library', 'weight' => 2],
          ],
        ],
      ],
      'delete_rehearsed' => [
        'label' => 'Town Hall removed: rehearsed',
        'operation' => 'delete',
        'parameters' => ['id' => 'town_hall'],
      ],
      'delete' => [
        'label' => 'Town Hall removed: written, so the three venues shipped are what is left',
        'operation' => 'delete',
        'parameters' => ['id' => 'town_hall'],
        'dry_run' => FALSE,
      ],
    ],
    'event.sponsorship' => [
      'valid' => [
        'label' => 'A venue, a contact, an article and a node of any content type: accepted',
        'module' => self::REFERENCES,
        'values' => [
          'venue' => 'library',
          'contact' => 1,
          'page' => self::NODE . 'article',
          'thing' => ['entity_type' => 'node', 'id' => self::NODE . 'article'],
        ],
      ],
      'wrong_bundle' => [
        'label' => 'A page where an article is asked for: refused',
        'module' => self::REFERENCES,
        'values' => ['page' => self::NODE . 'page'],
      ],
      'not_referenceable' => [
        'label' => 'The draft article, as an account that may not see unpublished content: refused',
        'module' => self::REFERENCES,
        'account' => self::EDITOR,
        'values' => ['page' => self::NODE . 'draft'],
      ],
      'not_config' => [
        'label' => 'A node where a config entity is asked for: refused',
        'module' => self::REFERENCES,
        'values' => ['setting' => ['entity_type' => 'node', 'id' => self::NODE . 'article']],
      ],
    ],
    'block.placement' => [
      'place' => [
        'label' => 'The Spotlight block placed through create, made again: refused for the machine name it took',
        'operation' => 'create',
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
              'tone' => 'highlight',
              'link' => [
                'url' => 'https://example.com/tickets',
                'label' => 'Find your ticket',
              ],
            ],
            'slots' => [
              'body' => ['kind' => 'text', 'text' => 'Bring your ticket, on your phone or printed.'],
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
      'section' => [
        'label' => 'The Tonight block, a section holding a card, placed through create, made again: refused for the machine name it took',
        'operation' => 'create',
        'module' => 'data_surface_block',
        'values' => [
          'label' => 'Tonight',
          'id' => 'data_surface_examples_section',
          'label_display' => FALSE,
          'plugin' => 'data_surface_component',
          'settings' => [
            'component' => 'data_surface_examples:section',
            'props' => ['heading' => 'Tonight'],
            'slots' => [
              'content' => [
                'kind' => 'component',
                'component' => [
                  'component' => 'data_surface_examples:card',
                  'props' => ['heading' => 'Talks start at seven'],
                  'slots' => [
                    'body' => ['kind' => 'text', 'text' => 'In the main hall, first floor.'],
                  ],
                ],
              ],
            ],
          ],
          'theme' => self::DEFAULT_THEME,
          'region' => 'content',
          'weight' => 101,
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
        'operation' => 'update',
        'module' => 'data_surface_block',
        'parameters' => ['id' => 'data_surface_examples_spotlight'],
        'values' => [
          'settings' => [
            'component' => 'data_surface_examples:hero',
            'props' => ['title' => 'Doors open at half past six', 'align' => 'start'],
          ],
        ],
      ],
      'wrong_slot' => [
        'label' => "A hero in the hero's actions, which expect a call to action: refused, naming the one it expects",
        'operation' => 'update',
        'module' => 'data_surface_block',
        'parameters' => ['id' => 'data_surface_examples_spotlight'],
        'values' => [
          'settings' => [
            'component' => 'data_surface_examples:hero',
            'props' => ['title' => 'Doors open at half past six'],
            'slots' => [
              'actions' => [
                'kind' => 'component',
                'component' => [
                  'component' => 'data_surface_examples:hero',
                  'props' => ['title' => 'Find your ticket'],
                ],
              ],
            ],
          ],
        ],
      ],
      'delete' => [
        'label' => 'The Spotlight block removed, rehearsed',
        'operation' => 'delete',
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
   * @return array<string, array{label: string, operation?: string, parameters?: array<string, string>, values?: array, dry_run?: bool, module?: string, state?: array<string, mixed>, account?: string}>
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
      elseif (is_string($value) && str_starts_with($value, self::NODE)) {
        $value = (int) (\Drupal::state()->get(self::NODES, [])[substr($value, strlen(self::NODE))] ?? 0);
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
   * configuration", "administer blocks" or a permission of the venues.
   * The parameters go in the same JSON as the values, before them. The dry
   * run is a second input, which tool:run reads as name=value and decodes
   * as JSON, so `true` is a boolean. A call made in a State of its own sets
   * it first and deletes it after.
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
    $sent = (self::CALLS[$resource][$call]['parameters'] ?? []) + ($values === NULL ? [] : ['values' => $values]);
    $input = json_encode($sent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    // A call made as an account of a role names that account's id, which
    // the site chose when it made the account.
    $account = isset(self::CALLS[$resource][$call]['account']) ? '"$UID"' : '1';
    $command = sprintf(
      "drush tool:run %s --uid=%s%s%s",
      self::tool($resource, $call),
      $account,
      // A read takes nothing: it names no thing and sends no values.
      $sent === [] ? '' : sprintf(" --input='%s'", str_replace("'", "'\\''", (string) $input)),
      (self::CALLS[$resource][$call]['dry_run'] ?? TRUE) ? ' --input=dry_run=true' : '',
    );
    $state = self::CALLS[$resource][$call]['state'] ?? [];
    foreach (array_reverse($state, TRUE) as $key => $value) {
      $command = sprintf("drush state:set %s %s\n%s\ndrush state:delete %s", $key, $value, $command, $key);
    }
    if (isset(self::CALLS[$resource][$call]['account'])) {
      $command = sprintf("# UID: an account whose only role, beside authenticated, is %s.\n%s", self::CALLS[$resource][$call]['account'], $command);
    }
    return $command;
  }

}
