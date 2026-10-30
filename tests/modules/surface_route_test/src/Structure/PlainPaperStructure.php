<?php

declare(strict_types=1);

namespace Drupal\surface_route_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_route_test\Resource\CardResource;

/**
 * What plain paper takes: its weight.
 */
#[Structure('route_test.card.paper.plain')]
#[Variant(of: CardResource::class, at: 'paper_settings', for: 'plain', label: 'Plain')]
final class PlainPaperStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('weight', 'integer', 'Weight', default: 120);
  }

}
