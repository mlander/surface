<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Group;
use Drupal\surface\Resource\DeclaresByProperties;
use PortableSchema\Builder\Attribute\Schema;
use PortableSchema\Builder\Attribute\Ui;

/**
 * A class declaring its groups with #[Group] and with #[Ui(groups:)].
 *
 * Outside discovery: SchemaAttributesTest reads it into a blueprint.
 */
#[Group('main', 'Main')]
#[Ui(groups: ['main' => ['label' => 'Main']])]
final class GroupsTwice {

  use DeclaresByProperties;

  #[Schema(title: 'Name')]
  #[Ui(group: 'main')]
  public ?string $name = NULL;

}
