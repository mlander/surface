<?php

declare(strict_types=1);

namespace Drupal\surface_tool_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_tool_test\Storage\LogbookStorage;

/**
 * A logbook asked for in five ways, each stating how it behaves.
 *
 * Configuring it says nothing and is derived idempotent; starting one
 * creates, and is derived not idempotent; appending says it is not
 * idempotent, though it creates nothing; clearing says it may destroy
 * what is there; inspecting says it changes nothing. A shelf on a list
 * longer than a refusal names in full, and a page count with bounds.
 */
#[Resource('tool_test.logbook', storage: LogbookStorage::class)]
#[Operation('configure',
  label: 'Configure the logbook',
  description: 'Configures the logbook, which always exists.',
  permission: 'administer site configuration',
)]
#[Operation('start',
  label: 'Start a logbook',
  description: 'Starts a logbook.',
  permission: 'administer site configuration',
  creates: TRUE,
)]
#[Operation('append',
  label: 'Append to the logbook',
  description: 'Appends an entry: the same call twice appends twice.',
  permission: 'administer site configuration',
  idempotent: FALSE,
)]
#[Operation('clear',
  label: 'Clear the logbook',
  description: 'Clears the logbook: what was there is gone.',
  permission: 'administer site configuration',
  destructive: TRUE,
)]
#[Operation('inspect',
  label: 'Inspect the logbook',
  description: 'Inspects the logbook, and changes nothing.',
  permission: 'administer site configuration',
  readOnly: TRUE,
)]
final class LogbookResource implements ResourceInterface {

  /**
   * The shelves a logbook may stand on: more than a refusal lists.
   */
  public const SHELVES = ['a1', 'a2', 'a3', 'b1', 'b2', 'b3', 'c1', 'c2', 'c3', 'd1', 'd2', 'd3'];

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('entry', 'string', t('Entry'));
    $inputs->add('shelf', 'string', t('Shelf'))
      ->addConstraint('Choice', ['choices' => self::SHELVES]);
    $inputs->add('pages', 'integer', t('Pages'))
      ->addConstraint('Range', ['min' => 1, 'max' => 500]);
  }

}
