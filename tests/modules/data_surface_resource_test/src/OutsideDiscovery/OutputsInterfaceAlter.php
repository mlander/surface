<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\OutsideDiscovery;

use Drupal\data_surface\Resource\AltersOutputsInterface;
use Drupal\data_surface\Resource\Attribute\Alters;
use Drupal\data_surface_resource_test\Resource\RecipeResource;

/**
 * Broken: an alter still implementing the interface removed on 2026-10-24.
 *
 * The interface is gone, so the class cannot be loaded at all.
 */
#[Alters(RecipeResource::class)]
final class OutputsInterfaceAlter implements AltersOutputsInterface {

}
