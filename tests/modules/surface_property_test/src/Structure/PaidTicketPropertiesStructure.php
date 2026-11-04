<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * The paid ticket, as properties: a variant of the twin's ticket.
 */
#[Structure('property_test.ticket.paid.properties')]
#[Variant(of: ChildrenPropertiesStructure::class, at: 'ticket', for: 'paid', label: new TranslatableMarkup('Paid'))]
final class PaidTicketPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Price')]
  #[Constraint('Range', ['min' => 0])]
  public float $price;

}
