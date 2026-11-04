<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Describe;
use Drupal\surface\Resource\Attribute\Examples;
use Drupal\surface\Resource\Attribute\Group;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Setting;
use Drupal\surface\Resource\Attribute\Widget;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples\Storage\CateringStorage;
use Drupal\surface_examples\Structure\PersonStructure;

/**
 * Example 18: the same keys as properties, each carrying an attribute.
 *
 * Nothing here is new to the series: a choice list, bounds, a pattern, a
 * widget, a group, a rule and a port wired to a child, each declared with
 * calls by an earlier example. Here each key is a property instead, and
 * DeclaresByProperties answers defineInputs() by calling the same verbs.
 * PHP says what it can: the key's name, its type, its default (the
 * initializer) and whether it must be given (a type that is not nullable,
 * with no initializer). The attributes say the rest. Their texts are bare
 * strings, which the reader makes translatable; the one text it does not
 * see is inside a constraint's options, so the dietary labels are
 * `new TranslatableMarkup()`.
 */
#[Resource('event.catering', storage: CateringStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the catering'),
  description: new TranslatableMarkup('Sets who caters the event, for how many, the budget, the dietary needs, when food is served and who to contact.'),
  permission: 'administer site configuration',
)]
#[Group('details', 'Details')]
final class CateringResource implements ResourceInterface {

  use DeclaresByProperties;

  #[Input('Show the caterer on the event page')]
  public bool $public = FALSE;

  #[Input('Caterer')]
  #[Constraint('Length', ['max' => 128])]
  #[Examples('Harbour Kitchen')]
  #[Describe(placeholder: 'The name on the contract')]
  public string $provider;

  #[Input('Headcount', description: 'How many people to cater for.')]
  #[Constraint('Range', ['min' => 1, 'max' => 5000])]
  public int $headcount;

  #[Input('Budget')]
  #[Constraint('Range', ['min' => 0])]
  #[Describe(group: 'details', suffix: 'EUR')]
  public ?float $budget = NULL;

  #[Input('Dietary needs', list: 'string')]
  #[Constraint('LabeledChoice', [
    'choices' => [
      'vegetarian' => new TranslatableMarkup('Vegetarian'),
      'vegan' => new TranslatableMarkup('Vegan'),
      'gluten_free' => new TranslatableMarkup('Gluten free'),
      'halal' => new TranslatableMarkup('Halal'),
      'kosher' => new TranslatableMarkup('Kosher'),
    ],
  ], item: TRUE)]
  public array $dietary = [];

  #[Input('Served at', description: 'HH:MM, on a 24-hour clock.')]
  #[Widget('time')]
  #[Constraint('Regex', [
    'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
    'message' => new TranslatableMarkup('Write the time as HH:MM, such as 18:30.'),
  ])]
  #[Rule(whenEquals: ['public', TRUE], require: TRUE, because: 'Say when food is served while the caterer is shown.')]
  public ?string $served_at = NULL;

  #[Input('Notes for the caterer')]
  #[Setting('multiline', TRUE)]
  #[Describe(group: 'details')]
  public ?string $notes = NULL;

  #[Attach(label: 'Caterer contact', reads: ['listed' => 'public'])]
  public PersonStructure $contact;

}
