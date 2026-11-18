<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Mixin;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * When a thing was created and last changed: shared members, mixed in.
 *
 * An ordinary structure: the journal mixes it in, its two keys the
 * journal's own; the ledger and the journal's archive attach it, under a
 * key. Changed is at least created, a coded refiner, since a declared
 * rule compares a key with a value and not with another key; and once
 * the created time is set the changed time is required too, a rule
 * declared as data.
 *
 * @see docs/resources.md#mixins
 */
#[Structure('resource_test.timestamps')]
final class TimestampsStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('created', Schema::integer()
      ->title(t('Created'))
      ->minimum(0));
    $inputs->add('changed', Schema::integer()
      ->title(t('Changed'))
      ->minimum(0));
    $inputs->narrow('changed')
      ->when(Condition::present('created'))
      ->require()
      ->because(t('Required once the created time is set.'));
  }

  /**
   * Changed is never before created.
   */
  #[RefinesInput('changed')]
  public static function changedSinceCreated(int $created): Schema {
    return Schema::any()->minimum($created);
  }

}
