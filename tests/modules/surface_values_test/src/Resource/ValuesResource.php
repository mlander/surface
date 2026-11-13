<?php

declare(strict_types=1);

namespace Drupal\surface_values_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Compute\MachineName;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_values_test\Shape\ValuesCode;
use Drupal\surface_values_test\Storage\ValuesStorage;
use Drupal\surface_values_test\Structure\MemberStructure;
use Drupal\surface_values_test\Variants\AccessKinds;

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
    $inputs->add('name', 'string', t('Name'), default: 'Values');
    $inputs->add('stamp', 'timestamp', t('Stamp'));
    $inputs->add('moment', 'datetime_iso8601', t('Moment'));
    $inputs->add('day', 'datetime_iso8601', t('Day'))
      ->addConstraint('DateOnly', []);
    $inputs->add('mail', 'email', t('Mail'));
    $inputs->add('link', 'uri', t('Link'));
    $inputs->add('ratio', 'float', t('Ratio'));
    $inputs->add('flag', 'boolean', t('Flag'));
    $inputs->add('picked', 'string', t('Picked'))
      ->setSetting('widget', 'date');
    $inputs->add('contact', 'string', t('Contact'))
      ->addConstraint('Email', []);
    $inputs->add('level', 'string', t('Level'))
      ->addConstraint('Choice', ['choices' => ['1', '2', '3']]);
    $inputs->addList('sizes', 'string', t('Sizes'))
      ->addConstraint('Choice', ['choices' => ['s', 'm', 'l'], 'multiple' => TRUE]);
    $inputs->addMap('window', t('Window'), function (BlueprintInterface $map): void {
      $map->add('opens', 'datetime_iso8601', t('Opens'));
      $map->add('closes', 'timestamp', t('Closes'));
      $map->add('note', 'string', t('Note'));
    });
    $inputs->add('pair', 'string', t('Pair'))
      ->addConstraint('Choice', ['choices' => ['a', 'A', 'b']]);
    $slot = MapDataDefinition::create()
      ->setPropertyDefinition('at', DataDefinition::create('datetime_iso8601')->setLabel('At'));
    $inputs->addList('slots', $slot, t('Slots'));
    $pin = $inputs->add('pin', 'string', t('PIN'))->setRequired(TRUE);
    DefinitionMetadata::setSecret($pin);
    $inputs->addMap('vault', t('Vault'), function (BlueprintInterface $map): void {
      $map->add('label', 'string', t('Label'));
      $key = $map->add('key', 'string', t('Key'));
      DefinitionMetadata::setSecret($key);
    });
    $code = DataDefinition::create('string')->setLabel('Code');
    DefinitionMetadata::setSecret($code);
    $inputs->addList('codes', $code, t('Codes'));
    $inputs->compute('handle', 'machine_name', t('Handle'), from: ['name'], using: MachineName::class)
      ->setRequired(TRUE);
    $inputs->compute('alias', 'machine_name', t('Alias'), from: ['name'], using: MachineName::class, when: Compute::UNLESS_SENT);
    $inputs->add('code', ValuesCode::class, t('Code'));
    $inputs->add('tier', 'string', t('Tier'), default: 'basic')
      ->addConstraint('Choice', ['choices' => ['basic', 'premium']]);
    $inputs->attachVariants('plan', by: 'tier', label: t('Plan'));
    $inputs->attachList('members', MemberStructure::class, t('Members'), max: 5);
    $inputs->attachList('guests', MemberStructure::class, t('Guests'), max: 5);
    $inputs->add('access_kind', 'string', t('Access kind'), default: 'open');
    $inputs->attachVariants('access', by: 'access_kind', label: t('Access'), from: AccessKinds::class);
    $inputs->add('backup_kind', 'string', t('Backup kind'), default: 'keyed');
    $inputs->attachVariants('backup', by: 'backup_kind', label: t('Backup'), from: AccessKinds::class);
  }

}
