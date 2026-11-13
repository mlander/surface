<?php

declare(strict_types=1);

namespace Drupal\surface_values_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;

/**
 * Data surfaces of keys no blueprint declares, built by hand.
 *
 * The method surface() holds facts the blueprint never sets itself: a key its
 * storage always holds, a map that only groups maps, an empty label, a
 * map below a map with a default of its own, a property whose default is
 * NULL, a locked key, a locked secret and a locked map holding one, and
 * two maps that declare no properties, one at the top and one inside a
 * map. unwritten() holds the types the blueprint
 * refuses, `decimal` and a bare `any`, which no document is written for
 * (the emitter refuses them as the blueprint does), so the value baseline
 * reads its places from UNWRITTEN, a document stated by hand.
 */
final class HandBuiltSurfaces {

  /**
   * The id the value baseline names the surface by.
   */
  public const ID = 'values_test.hand_built';

  /**
   * The id the value baseline names the unwritten surface by.
   */
  public const UNWRITTEN_ID = 'values_test.unwritten';

  /**
   * The places of the unwritten surface, as a document would state them.
   */
  public const UNWRITTEN = [
    'type' => 'object',
    'properties' => [
      'amount' => ['type' => ['string', 'null']],
      'anything' => [],
    ],
  ];

  /**
   * Builds the data surface.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface, sealed.
   */
  public static function surface(): SurfaceInterface {
    $builder = new SurfaceBuilder();
    $kept = DataDefinition::create('string')->setLabel('Kept');
    DefinitionMetadata::setAlwaysHeld($kept);
    $builder->setDefinition('kept', $kept)->setDefault('kept', 'held');

    $builder->setDefinition('blank', DataDefinition::create('string')->setLabel(''));

    $settings = MapDataDefinition::create()->setLabel('Settings')
      ->setPropertyDefinition('size', DataDefinition::create('integer')->setLabel('Size'));
    $mounted = MapDataDefinition::create()->setLabel('Mounted')
      ->setPropertyDefinition('module', $settings);
    DefinitionMetadata::setGrouping($mounted);
    $builder->setDefinition('mounted', $mounted);

    $inner = MapDataDefinition::create()->setLabel('Inner')
      ->setPropertyDefinition('a', DataDefinition::create('string')->setLabel('A'))
      ->setPropertyDefinition('b', DataDefinition::create('string')->setLabel('B'));
    DefinitionMetadata::setDefaultValue($inner, ['a' => 'own']);
    $none = DataDefinition::create('string')->setLabel('None');
    DefinitionMetadata::setDefaultValue($none, NULL);
    $nested = MapDataDefinition::create()->setLabel('Nested')
      ->setPropertyDefinition('inner', $inner)
      ->setPropertyDefinition('none', $none)
      ->setPropertyDefinition('spare', MapDataDefinition::create()->setLabel('Spare'));
    $builder->setDefinition('nested', $nested);

    $builder->setDefinition('frozen', DataDefinition::create('string')->setLabel('Frozen'))
      ->setDefault('frozen', 'cold')
      ->lock('frozen', 'Fixed by the test.');

    $builder->setDefinition('loose', MapDataDefinition::create()->setLabel('Loose'));

    $sealed = DataDefinition::create('string')->setLabel('Sealed');
    DefinitionMetadata::setSecret($sealed);
    $builder->setDefinition('sealed', $sealed)->lock('sealed', 'Fixed by the test.');

    $pin = DataDefinition::create('string')->setLabel('PIN');
    DefinitionMetadata::setSecret($pin);
    $guarded = MapDataDefinition::create()->setLabel('Guarded')
      ->setPropertyDefinition('owner', DataDefinition::create('string')->setLabel('Owner'))
      ->setPropertyDefinition('pin', $pin);
    $builder->setDefinition('guarded', $guarded)
      ->setDefault('guarded', ['owner' => 'me'])
      ->lock('guarded', 'Fixed by the test.');
    return $builder->seal();
  }

  /**
   * Builds the data surface of the types no document is written for.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface, sealed.
   */
  public static function unwritten(): SurfaceInterface {
    return (new SurfaceBuilder())
      ->setDefinition('amount', DataDefinition::create('decimal')->setLabel('Amount'))
      ->setDefinition('anything', DataDefinition::create('any')->setLabel('Anything'))
      ->seal();
  }

}
