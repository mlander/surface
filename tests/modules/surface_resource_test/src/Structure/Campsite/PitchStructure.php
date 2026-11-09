<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Campsite;

use Drupal\surface\Resource\Attribute\AttachVariants;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Setting;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Variants;

/**
 * A pitch: its name, its kind, and the gear its kind chooses, stored flat.
 *
 * Declared as properties, so the setting is #[Setting] beside
 * #[AttachVariants].
 */
#[Structure('resource_test.campsite.pitch')]
final class PitchStructure implements StructureInterface {

  use DeclaresByProperties;

  /**
   * The pitch's name.
   */
  #[Input('Name')]
  public string $name;

  /**
   * What is pitched there, which chooses the gear.
   */
  #[Input('Kind')]
  public string $kind;

  /**
   * The gear the kind chooses, stored beside the kind.
   *
   * @var array<string, mixed>
   */
  #[AttachVariants(by: 'kind', label: 'Gear')]
  #[Setting(Variants::STORED_AS, Variants::FLAT)]
  public array $gear;

}
