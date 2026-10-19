<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_tool_test\Storage\LogbookStorage;

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
final class LogbookResource implements ResourceInterface {

  /**
   * The shelves a logbook may stand on: more than a refusal lists.
   */
  public const SHELVES = ['a1', 'a2', 'a3', 'b1', 'b2', 'b3', 'c1', 'c2', 'c3', 'd1', 'd2', 'd3'];

  /**
   * Configures the logbook, which always exists.
   */
  #[Operation('configure', label: 'Configure the logbook', permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * Starts a logbook: creates.
   */
  #[Operation('start', label: 'Start a logbook', permission: 'administer site configuration')]
  public static function start(): OperationContext {
    return new OperationContext('start', creates: TRUE);
  }

  /**
   * Appends an entry: the same call twice appends twice.
   */
  #[Operation('append', label: 'Append to the logbook', permission: 'administer site configuration', idempotent: FALSE)]
  public static function append(): OperationContext {
    return new OperationContext('append');
  }

  /**
   * Clears the logbook: what was there is gone.
   */
  #[Operation('clear', label: 'Clear the logbook', permission: 'administer site configuration', destructive: TRUE)]
  public static function clear(): OperationContext {
    return new OperationContext('clear');
  }

  /**
   * Inspects the logbook: changes nothing.
   */
  #[Operation('inspect', label: 'Inspect the logbook', permission: 'administer site configuration', readOnly: TRUE)]
  public static function inspect(): OperationContext {
    return new OperationContext('inspect');
  }

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
