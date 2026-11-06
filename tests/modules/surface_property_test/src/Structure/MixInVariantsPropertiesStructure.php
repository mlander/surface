<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\MixInVariants;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Variants\Lamps;

/**
 * Variants mixed in, as properties: MixInVariantsStructure's twin.
 */
#[Structure('property_test.mixin_variants.properties')]
#[MixInVariants(by: 'kind', from: Lamps::class)]
final class MixInVariantsPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Kind')]
  public string $kind;

  #[Input('Title')]
  public ?string $title = NULL;

}
