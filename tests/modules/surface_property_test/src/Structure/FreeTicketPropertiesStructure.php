<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * The free ticket, as properties: a variant of the twin's ticket.
 */
#[Structure('property_test.ticket.free.properties')]
#[Variant(of: ChildrenPropertiesStructure::class, at: 'ticket', for: 'free', label: new TranslatableMarkup('Free'))]
final class FreeTicketPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Note')]
  public ?string $note = NULL;

}
