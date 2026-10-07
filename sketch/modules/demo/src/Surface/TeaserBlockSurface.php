<?php

declare(strict_types=1);

namespace Drupal\demo\Surface;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\demo\Options\BundleFieldOptions;
use Drupal\demo\Options\BundleOptions;
use Drupal\demo\Options\ContentEntityTypeOptions;
use Drupal\surface_sketch\Surface\Attribute\RefinesInput;
use Drupal\surface_sketch\Surface\Attribute\Surface;
use Drupal\surface_sketch\Surface\ShapeInterface;
use Drupal\surface_sketch\Surface\SurfaceInterface;

/**
 * What the teaser block can be configured with.
 *
 * No target and no situations: the block host supplies both, because
 * only it holds the plugin instance. No services either: the bundle
 * list needs the entity type, so the refiner points at a list and
 * hands it the value. The list holds the service.
 */
#[Surface('block.teaser')]
final class TeaserBlockSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('headline', 'string', 'Headline')->setRequired(TRUE);
    $inputs->add('entity_type', 'string', 'Entity type')->setRequired(TRUE)
      ->addConstraint('OptionsList', ['source' => ContentEntityTypeOptions::class]);
    $inputs->add('bundle', 'string', 'Bundle');
    $inputs->add('field', 'string', 'Highlight field');
    $inputs->add('limit', 'integer', 'Number of items', default: 3)
      ->addConstraint('Range', ['min' => 1, 'max' => 20]);
  }

  /**
   * Bundles of the chosen entity type. Pointing, not fetching.
   */
  #[RefinesInput('bundle')]
  public function bundlesOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('OptionsList', [
      'source' => BundleOptions::class,
      'arguments' => ['entity_type_id' => $entity_type],
    ]);
  }

  /**
   * Fields of the chosen bundle. Watches two siblings, named on the
   * attribute so a renamed parameter is caught. Runs once both have a
   * value, and again when either changes.
   */
  #[RefinesInput('field', watches: ['entity_type', 'bundle'])]
  public function fieldsOfBundle(DataDefinitionInterface $field, string $entity_type, string $bundle): DataDefinitionInterface {
    return $field->addConstraint('OptionsList', [
      'source' => BundleFieldOptions::class,
      'arguments' => ['entity_type_id' => $entity_type, 'bundle' => $bundle],
    ]);
  }

}
