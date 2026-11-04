<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Describe;
use Drupal\surface\Resource\Attribute\Examples;
use Drupal\surface\Resource\Attribute\Group;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Setting;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Widget;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Shape\Code;

/**
 * Keys of one value, declared as properties: the twin of KeysStructure.
 */
#[Structure('property_test.keys.properties')]
#[Group('about', 'About')]
#[Group('more', 'More', details: TRUE, collapsed: TRUE, weight: 5, description: 'Rarely needed.')]
final class KeysPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Name', description: 'As printed on the badge.')]
  #[Constraint('Length', ['max' => 64])]
  #[Constraint('Regex', ['pattern' => '/^\S/', 'message' => new TranslatableMarkup('Start the name with a letter.')])]
  #[Examples('Ada Lovelace')]
  #[Describe(group: 'about', weight: 1, placeholder: 'Full name', help: 'Shown under the photo.')]
  public string $name;

  #[Input('Frequency', required: TRUE)]
  #[Constraint('LabeledChoice', [
    'choices' => ['weekly' => new TranslatableMarkup('Weekly'), 'monthly' => new TranslatableMarkup('Monthly')],
  ])]
  #[Describe(group: 'about', weight: 2)]
  public string $frequency = 'weekly';

  #[Input('Email', type: 'email')]
  #[Rule(whenEquals: ['frequency', 'monthly'], require: TRUE, because: 'A monthly issue is sent by email.')]
  public ?string $email = NULL;

  #[Input('Handle', type: 'machine_name')]
  #[Rule(whenPresent: 'note', doesNotApply: TRUE)]
  public ?string $id = NULL;

  #[Input('Code')]
  #[Rule(whenAbsent: 'email', constrain: ['Length', ['max' => 4]], because: 'Up to 4 letters without an email.')]
  public ?Code $code = NULL;

  #[Input('Note')]
  #[Setting('multiline', TRUE)]
  #[Describe(group: 'more', prefix: 'Note:', suffix: '(optional)')]
  public ?string $note = NULL;

  #[Input('Day')]
  #[Widget('date')]
  #[Rule(whenPresent: 'name', whenIn: ['frequency', ['weekly']], require: TRUE)]
  public ?string $day = NULL;

  #[Input('Tags', list: 'string')]
  #[Constraint('Count', ['max' => 5])]
  #[Constraint('Length', ['max' => 10], item: TRUE)]
  public array $tags = [];

  #[Input('Count')]
  #[Constraint('Range', ['min' => 1, 'max' => 10])]
  public int $count = 3;

}
