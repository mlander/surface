<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StructureInterface;

/**
 * A structure whose operations leave their id and creating to the attribute.
 *
 * One on the class, with no method; one method that leaves both out and
 * says only what it knows; one whose docblock summary is its description.
 * Outside src/Structure: the test hands it to a registry of its own.
 */
#[Structure('resource_test.stamped')]
#[Operation('look', label: 'Look in the jar', readOnly: TRUE)]
final class StampedStructure implements StructureInterface {

  /**
   * Fills a new jar with the named jam.
   *
   * Only the first paragraph is the description.
   */
  #[Operation('fill', label: 'Fill a jar', creates: TRUE)]
  public static function fill(): OperationContext {
    return new OperationContext(known: ['jam' => 'plum']);
  }

  /**
   * Relabels a jar.
   */
  #[Operation('relabel', label: 'Relabel a jar', description: 'Says what the label says, not the docblock.')]
  public static function relabel(string $jam): OperationContext {
    return new OperationContext(known: ['jam' => $jam]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('jam', 'string', 'Jam');
    $inputs->add('label', 'string', 'Label');
  }

}
