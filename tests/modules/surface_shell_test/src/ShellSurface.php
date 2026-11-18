<?php

declare(strict_types=1);

namespace Drupal\surface_shell_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\Computed;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Reference\References;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Arrangement;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\Compute;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;

/**
 * A data surface whose keys that hold a child frame carry facts of their own.
 *
 * A key that holds a child frame (an attached child, a collection or a
 * mount) has a schema composed of its own facts and the child's
 * (Entry::isShell()). No example declares such a key that is computed,
 * refers to an entity, is grouped, holds a default, is ruled out or is
 * always held; this one does, built by hand, since a declaration refuses
 * some of them (a computed map). A secret one is refused here too.
 *
 * Each key, by name:
 * - `src`: a string the computed keys are computed from;
 * - `c_sent`, `c_change`: attached, computed from `src` unless sent and
 *   on change (NameFromSource);
 * - `refers`: attached, holding the reference check to users;
 * - `targets`: attached, with the setting that names the entity types an
 *   id may be of (References::TARGETS);
 * - `grouped`: attached, in the group `g`;
 * - `held`: attached, with a default;
 * - `ruled`: attached, ruled out;
 * - `rows`: a collection, neither required nor nullable;
 * - `rows_held`: a collection with a default;
 * - `rows_ref`: a collection holding the reference check and the setting;
 * - and a structure mounted by this module.
 */
final class ShellSurface {

  /**
   * Builds the data surface.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface, sealed.
   */
  public static function build(): SurfaceInterface {
    $builder = new SurfaceBuilder();
    $builder->setDefinition('src', DataDefinition::create('string')->setLabel('Source'));
    $computed = [
      'c_sent' => Compute::UNLESS_SENT,
      'c_change' => Compute::ON_CHANGE,
    ];
    foreach ($computed as $key => $when) {
      $shell = MapDataDefinition::create()->setLabel("Computed $when");
      DefinitionMetadata::setComputed($shell, new Computed(['src'], NameFromSource::class, $when));
      $builder->setDefinition($key, $shell)->attach($key, new Attachment(static::child()));
    }
    $builder->setDefinition('refers', MapDataDefinition::create()->setLabel('Refers')->addConstraint(References::CONSTRAINT, ['entityTypeId' => 'user']))
      ->attach('refers', new Attachment(static::child()));
    $builder->setDefinition('targets', MapDataDefinition::create()->setLabel('Targets')->setSetting(References::TARGETS, ['user' => []]))
      ->attach('targets', new Attachment(static::child()));
    $grouped = MapDataDefinition::create()->setLabel('Grouped');
    DefinitionMetadata::setArrangement($grouped, new Arrangement(ArrangementGroup::fieldset('g', 'Group')));
    $builder->setDefinition('grouped', $grouped)->attach('grouped', new Attachment(static::child()));
    $builder->setDefinition('held', MapDataDefinition::create()->setLabel('Held'))
      ->attach('held', new Attachment(static::child()))
      ->setDefault('held', ['name' => 'Kept']);
    $builder->setDefinition('ruled', DoesNotApply::marked(MapDataDefinition::create()->setLabel('Ruled')))
      ->attach('ruled', new Attachment(static::child()));
    $builder->attachList('rows', new Collection(new Attachment(static::child())));
    $builder->attachList('rows_held', new Collection(new Attachment(static::child())))
      ->setDefault('rows_held', [['name' => 'First']]);
    $builder->setDefinition('rows_ref', (new ListDataDefinition(['label' => 'Rows that refer'], MapDataDefinition::create()))
      ->addConstraint(References::CONSTRAINT, ['entityTypeId' => 'user'])
      ->setSetting(References::TARGETS, ['user' => []]));
    $builder->attachList('rows_ref', new Collection(new Attachment(static::child())));
    $builder->mountStructure('surface_shell_test', new Attachment(static::child()));
    return $builder->seal();
  }

  /**
   * Builds the child every key above holds: one required string.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The child, sealed.
   */
  public static function child(): SurfaceInterface {
    return (new SurfaceBuilder())
      ->setDefinition('name', DataDefinition::create('string')->setLabel('Name')->setRequired(TRUE))
      ->seal();
  }

}
