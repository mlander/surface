<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Structure\Workshop\BenchStructure;

/**
 * Broken: an anonymous map whose callable declares other keys each run.
 *
 * The map holds a structure, so the build runs the declaration again to
 * fill it, and the callable then declares another key: refused when the
 * map is filled.
 */
#[Structure('resource_test.broken.changing_map')]
final class ChangingMapStructure implements StructureInterface {

  /**
   * How many times the map's callable has run.
   */
  public static int $runs = 0;

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addMap('kit', 'Kit', static function (BlueprintInterface $kit): void {
      $kit->add(self::$runs++ === 0 ? 'tent' : 'awning', 'boolean', 'Cover');
      $kit->attach('bench', BenchStructure::class);
    });
  }

}
