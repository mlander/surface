<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_values_test\Shape\ValuesCode;
use Drupal\surface_values_test\Storage\ValuesStorage;
use Drupal\surface_values_test\Structure\MemberStructure;
use Drupal\surface_values_test\Variants\AccessKinds;
use PortableSchema\Builder\Schema;

/**
 * A resource of the keys the value baseline needs that no example declares.
 *
 * One key for each casting branch and each accept, keep and serve rule
 * the examples leave unreached, and a second key for a rule one example
 * alone reaches:
 * - every leaf type, a string with the date widget and one with an Email
 *   constraint;
 * - a choice of numeric strings, a choice two of whose values differ in
 *   case alone, and a list with a multiple choice;
 * - dates and times inside a plain map and inside a plain list of maps,
 *   timestamps inside a map and inside a collection item (not in a list
 *   of their own: a list's one item in a value set is 'a', which a
 *   timestamp reads as the current time);
 * - secrets that are required, inside a map and listed;
 * - computed keys that are required and that take a sent value;
 * - a key of a shape;
 * - variants with a secret in one variant only, declared here and listed
 *   by the site (twice, under two deciding keys);
 * - two collections whose items hold a secret.
 * ValuesKeysAlter mounts two keys in it. What a blueprint cannot declare
 * is in HandBuiltSurfaces. It is kept in State, declares no operation and
 * is built alone; the baseline never writes it.
 */
#[Resource('values_test.keys', storage: ValuesStorage::class, label: new TranslatableMarkup('Values'))]
final class ValuesResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', Schema::string()
      ->title(t('Name'))
      ->default('Values'));
    $inputs->addDefinition('stamp', DataDefinition::create('timestamp')->setLabel(t('Stamp')));
    $inputs->add('moment', Schema::string()->format('date-time')
      ->title(t('Moment')));
    $inputs->addDefinition('day', DataDefinition::create('datetime_iso8601')->setLabel(t('Day'))->addConstraint('DateOnly', []));
    $inputs->add('mail', Schema::string()->format('email')
      ->title(t('Mail')));
    $inputs->add('link', Schema::string()->format('uri')
      ->title(t('Link')));
    $inputs->add('ratio', Schema::number()
      ->title(t('Ratio')));
    $inputs->add('flag', Schema::bool()
      ->title(t('Flag')));
    $inputs->add('picked', Schema::string()
      ->title(t('Picked'))
      ->ui(widget: 'date'));
    $inputs->add('contact', Schema::string()
      ->title(t('Contact'))
      ->check('drupal:Email'));
    $inputs->add('level', Schema::enum(['1', '2', '3'])->type('string')
      ->title(t('Level')));
    $inputs->add('sizes', Schema::list(Schema::string())
      ->title(t('Sizes'))
      ->check('drupal:Choice', ['choices' => ['s', 'm', 'l'], 'multiple' => TRUE]));
    $inputs->addMap('window', t('Window'), function (BlueprintInterface $map): void {
      $map->add('opens', Schema::string()->format('date-time')
        ->title(t('Opens')));
      $map->addDefinition('closes', DataDefinition::create('timestamp')->setLabel(t('Closes')));
      $map->add('note', Schema::string()
        ->title(t('Note')));
    });
    $inputs->add('pair', Schema::enum(['a', 'A', 'b'])
      ->title(t('Pair')));
    // A list whose item is a plain map, which no schema states at an
    // input: a list of objects there is a collection.
    $slot = MapDataDefinition::create()
      ->setPropertyDefinition('at', DataDefinition::create('datetime_iso8601')->setLabel('At'));
    $inputs->addList('slots', $slot, t('Slots'));
    $inputs->add('pin', Schema::string()
      ->title(t('PIN'))
      ->writeOnly(TRUE)
      ->required());
    $inputs->addMap('vault', t('Vault'), function (BlueprintInterface $map): void {
      $map->add('label', Schema::string()
        ->title(t('Label')));
      $map->add('key', Schema::string()
        ->title(t('Key'))
        ->writeOnly(TRUE));
    });
    $inputs->add('codes', Schema::list(Schema::string()->title('Code')->writeOnly(TRUE))
      ->title(t('Codes')));
    $inputs->compute('handle', Schema::of('drupal:config/machine_name')
      ->title(t('Handle'))
      ->required(), from: ['name'], using: MachineName::class);
    $inputs->compute('alias', Schema::of('drupal:config/machine_name')
      ->title(t('Alias')), from: ['name'], using: MachineName::class, when: Compute::UNLESS_SENT);
    $inputs->add('code', Schema::of(ValuesCode::class)
      ->title(t('Code')));
    $inputs->add('tier', Schema::enum(['basic', 'premium'])
      ->title(t('Tier'))
      ->default('basic'));
    $inputs->attachVariants('plan', by: 'tier', label: t('Plan'));
    $inputs->attachList('members', MemberStructure::class, t('Members'), max: 5);
    $inputs->attachList('guests', MemberStructure::class, t('Guests'), max: 5);
    $inputs->add('access_kind', Schema::string()
      ->title(t('Access kind'))
      ->default('open'));
    $inputs->attachVariants('access', by: 'access_kind', label: t('Access'), from: AccessKinds::class);
    $inputs->add('backup_kind', Schema::string()
      ->title(t('Backup kind'))
      ->default('keyed'));
    $inputs->attachVariants('backup', by: 'backup_kind', label: t('Backup'), from: AccessKinds::class);
  }

}
