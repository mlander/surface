<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Collection;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;

/**
 * Builds a data surface with every kind of shell, by hand.
 *
 * An attachment whose child holds an attachment (a shell inside a
 * shell), a collection whose item holds that attachment (a shell inside
 * a collection's item), variants whose deciding key is locked (chosen at
 * the seal) and variants nothing has chosen, and a mount whose structure
 * holds an attachment (a mounted key that is a shell); the attached shell
 * and the collection each hold a default of their own. The builder's own
 * shells are kept, so a test can build what the seal built from them
 * before 2026-11-15 (Attachment::mapOf(), Collection::listOf(),
 * Variants::definitionFor(), Variants::placeholder()).
 */
trait SealedShellsTrait {

  /**
   * Builds the surface, its children and the shells the builder held.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: array<string, \Drupal\Core\TypedData\DataDefinitionInterface>, 2: array<string, \Drupal\surface\SurfaceInterface>}
   *   The sealed surface; each shell as the builder held it before the
   *   seal, by key; the children, by name.
   */
  protected static function everyShell(): array {
    $leaf = (new SurfaceBuilder([
      'code' => DataDefinition::create('string')->setLabel('Code')->setRequired(TRUE)->addConstraint('Length', ['max' => 8]),
      'count' => DataDefinition::create('integer')->setLabel('Count'),
    ]))->seal();
    $other = (new SurfaceBuilder([
      'note' => DataDefinition::create('string')->setLabel('Note'),
    ]))->seal();
    $nested = new SurfaceBuilder([
      'title' => DataDefinition::create('string')->setLabel('Title')->setRequired(TRUE),
      'inner' => MapDataDefinition::create()->setLabel('Inner')->setDescription('A shell inside a shell.'),
    ]);
    $nested->attach('inner', new Attachment($leaf));
    $holder = $nested->seal();

    $builder = new SurfaceBuilder([
      'place' => MapDataDefinition::create()->setLabel('Place')->setRequired(TRUE),
      'rows' => (new ListDataDefinition([], MapDataDefinition::create()))->setLabel('Rows')->addConstraint('Count', ['max' => 3]),
      'kind' => DataDefinition::create('string')->setLabel('Kind'),
      'pick' => MapDataDefinition::create()->setLabel('Pick')->setDescription('Chosen at the seal.'),
      'shape' => DataDefinition::create('string')->setLabel('Shape'),
      'open' => MapDataDefinition::create()->setLabel('Open'),
    ]);
    $builder->attach('place', new Attachment($holder));
    // A shell's own default, which the seal keeps with its own facts.
    $builder->setDefault('place', ['title' => 'Home']);
    $builder->attachList('rows', new Collection(new Attachment($holder)));
    $builder->setDefault('rows', [['title' => 'First']]);
    $builder->attachVariants('pick', 'kind', ['x' => new Attachment($leaf), 'y' => new Attachment($other)]);
    $builder->setDefault('kind', 'x');
    $builder->lock('kind');
    $builder->attachVariants('open', 'shape', ['x' => new Attachment($leaf), 'y' => new Attachment($other)]);
    $builder->mountStructure('surface', new Attachment($holder));
    $shells = [];
    foreach (['place', 'rows', 'pick', 'open'] as $key) {
      $shells[$key] = $builder->getDefinition($key);
    }
    return [$builder->seal(), $shells, ['leaf' => $leaf, 'other' => $other, 'holder' => $holder]];
  }

  /**
   * Builds what the seal built before 2026-11-15 for one shell.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The sealed surface.
   * @param string $key
   *   The shell's key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $shell
   *   The shell as the builder held it.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected static function oldSeal(SurfaceInterface $surface, string $key, DataDefinitionInterface $shell): DataDefinitionInterface {
    $entry = $surface->getDefinitions()->entry($key);
    return match (TRUE) {
      $entry->attachment !== NULL && $shell instanceof MapDataDefinition => Attachment::mapOf($shell, $entry->attachment->child),
      $entry->list !== NULL && $shell instanceof ListDataDefinition => Collection::listOf($shell, $entry->list->item->child),
      $key === 'pick' => $entry->variants->definitionFor('x'),
      default => $entry->variants->placeholder(),
    };
  }

}
