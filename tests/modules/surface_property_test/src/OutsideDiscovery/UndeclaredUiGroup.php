<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\DeclaresByProperties;
use PortableSchema\Builder\Attribute\Schema;
use PortableSchema\Builder\Attribute\Ui;

/**
 * A key drawn in a group no #[Ui(groups:)] on the class declares.
 *
 * Outside discovery: SchemaAttributesTest reads it into a blueprint.
 */
#[Ui(groups: ['main' => ['label' => 'Main', 'widget' => 'details', 'options' => ['open' => FALSE], 'weight' => 2]])]
final class UndeclaredUiGroup {

  use DeclaresByProperties;

  #[Schema(title: 'Name')]
  #[Ui(group: 'main')]
  public ?string $name = NULL;

  #[Schema(title: 'Aside')]
  #[Ui(group: 'side')]
  public ?string $aside = NULL;

}
