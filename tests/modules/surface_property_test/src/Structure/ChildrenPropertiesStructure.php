<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\Attribute\AttachEach;
use Drupal\surface\Resource\Attribute\AttachList;
use Drupal\surface\Resource\Attribute\Each;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variants;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Keys\Letters;

/**
 * Children of every kind, as properties: ChildrenStructure's twin.
 */
#[Structure('property_test.children.properties')]
final class ChildrenPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Public')]
  public bool $public = FALSE;

  #[Input('Pricing', required: TRUE)]
  public string $pricing = 'free';

  #[Attach(label: 'Contact', reads: ['listed' => 'public', 'anything' => 'pricing'])]
  public ContactPropertiesStructure $contact;

  #[Attach(ContactPropertiesStructure::class, label: 'Backup contact', description: 'Asked when the contact is away.', reads: ['anything' => 'pricing'])]
  public array $backup;

  #[AttachList(EntryPropertiesStructure::class, label: 'Rows', min: 1, max: 3)]
  public array $rows;

  #[Variants(by: 'pricing', label: 'Ticket')]
  public array $ticket;

  #[Each(from: Letters::class, type: 'boolean', label: 'On sale')]
  public array $on_sale;

  #[AttachEach(EntryPropertiesStructure::class, from: Letters::class, label: 'Limits', reads: ['selling' => 'on_sale'])]
  public array $limits;

}
