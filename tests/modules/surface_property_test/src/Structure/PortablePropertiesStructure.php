<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;
use PortableSchema\Builder\Attribute\Check;
use PortableSchema\Builder\Attribute\Of;
use PortableSchema\Builder\Attribute\Required;
use PortableSchema\Builder\Attribute\Schema;
use PortableSchema\Builder\Attribute\Ui;

/**
 * Keys spelled with the package's attributes: PortableStructure's twin.
 *
 * #[Of] to a type of the library and to a shape, #[Schema] keywords,
 * #[Check], #[Ui] on a key and the groups on the class, #[Required] on a
 * key with a default.
 */
#[Structure('property_test.portable.properties')]
#[Ui(groups: ['about' => ['label' => 'About']])]
final class PortablePropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Schema(title: 'Name', description: 'As printed on the badge.', maxLength: 64)]
  #[Ui(group: 'about', weight: 1, options: ['placeholder' => 'Full name'])]
  public string $name;

  #[Of('portable:email')]
  #[Schema(title: 'Email')]
  public ?string $email = NULL;

  #[Schema(
    title: 'Frequency',
    oneOf: [
      ['const' => 'weekly', 'title' => 'Weekly'],
      ['const' => 'monthly', 'title' => 'Monthly'],
    ],
  )]
  #[Ui(group: 'about')]
  #[Required]
  public string $frequency = 'weekly';

  #[Of(Code::class)]
  #[Schema(title: 'Code')]
  public ?string $code = NULL;

  #[Schema(title: 'Module')]
  #[Check('drupal:ExtensionExists', ['type' => 'module'])]
  public ?string $module = NULL;

  #[Schema(title: 'Note')]
  #[Ui(widget: 'textarea')]
  public ?string $note = NULL;

  #[Schema(title: 'Tags', items: ['type' => 'string', 'maxLength' => 10], maxItems: 5)]
  public array $tags = [];

  #[Schema(title: 'Count', minimum: 1, maximum: 10)]
  public int $count = 3;

}
