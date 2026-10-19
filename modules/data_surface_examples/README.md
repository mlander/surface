# Data Surface Examples

One resource that grows, one idea per example. The thing being described is
the settings of an event registration: a title, how many people, where,
what it costs, who to contact. Each example adds one idea, each example with a
form is a page of its own, and under each form, collapsed, is the contract the
form was built from: every key, what it allows right now, and the JSON
Schema a caller with no form is handed. Below that, collapsed too, is
the before picture: what the same caller could be told today, from
config schema alone.

Examples 7 to 11 leave the registration behind: each is a small resource
of its own for one or two later concepts, a rule written as data, a part
used twice, keys that come from the site, access, and the stock widgets
([below](#one-small-resource-per-concept)).

```bash
drush pm:install data_surface_examples
```

Then visit `/surface-examples` as an administrator. Every page asks for
"administer site configuration". Nothing else is needed: the venues and
rooms are fixed lists inside the module, and each example keeps its
settings in a config object of its own
(`data_surface_examples.registration_step1` to `_step3`, then
`data_surface_examples.bulletin`, `.press`, `.desk`, `.sales`,
`.program` and `.doors`), so the examples never write over each other.

To take the examples again from the start, follow **Reset to defaults**
on the landing page (`/surface-examples/reset`). It puts every config
object back to the files the module ships in `config/install`, the
ticket types example 9 reads among them,
including anything another module stored on them, such as the event
licence example 4's module adds under example 3, and it opens again
every room example 4 lets a site close.

## What a resource is, in one paragraph

A resource is a class that says what a piece of software accepts: each
value's name, type, label, and what it may hold, and the storage that
keeps it. It says nothing about forms. The form is generated from it,
the validation is its, and the same class is a tool an agent can call.
A part a resource holds, with no storage of its own, is a structure,
declared the same way. The rule that makes this work is that a resource
may only ever *narrow* what it said: once a caller has read it, nothing
it accepts later contradicts what it advertised.

## Without a resource: what config schema can say

Under the contract panel of examples 1 to 3 is a second one, collapsed
as "Without a resource: what config schema can say". It is what a client
could be told about the same settings today, when the options, the
limits and what depends on what are written in Form API code and the
only declarative description is the config schema. It holds two
documents that read no resource, and a table that sets the second beside
the resource's contract:

- **What core answers when asked for a JSON Schema.** The example's
  typed config, normalized by core's serializer in the `json_schema`
  format. It is asked each time, not written down. The answer is one
  `$comment`, saying no schema is defined for a mapping.
- **The most a plain mapping of config schema could say.**
  `ConfigSchemaContract` reads the schema definition and translates it
  one to one: a label is a `title`, a type a `type`, a mapping an
  object, and a constraint the schema declares the keyword JSON Schema
  has for it. The examples' schema declares types and labels, so types
  and titles are what come out. Example 1:

<!-- json: data_surface_examples.registration_step1 -->

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "title": "Registration settings, example 1",
    "type": "object",
    "properties": {
        "title": {
            "title": "Event title",
            "type": "string"
        },
        "capacity": {
            "title": "Capacity",
            "type": "integer"
        },
        "open": {
            "title": "Registration open",
            "type": "boolean"
        }
    },
    "additionalProperties": false
}
```

No bounds on the capacity, and nothing required. The one rule in it is
`additionalProperties: false`, because a mapping in config schema
refuses a key it does not list. In example 3 the
venue, the room and the pricing are strings with no list of values, and
the ticket has a title and a `$comment`: its type is
`data_surface_examples.ticket.[%parent.pricing]`, which only a stored
value resolves, and this reads none.

- **Key by key.** A table counted from two documents, one row per key
  the resource declares: the JSON Schema keywords the mapping states,
  beside those the resource's full schema states, the one the contract
  panel shows as "The full schema, for any answers". The venue reads
  `title, type` beside `title, type, oneOf (3)`, the room `title, type`
  beside `title, type, oneOf (6), if/then on venue (3 cases)`, the
  ticket `title, $comment` beside
  `title, type, if/then on pricing (2 variants: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))`,
  the contact `title, type, properties, additionalProperties` beside
  `title, type, properties, additionalProperties, required, $ref (registration.contact)`,
  and the last row lists what is required: "not stated" beside
  `title, venue, room, pricing`. The resource writes each part once,
  under `$defs`, and refers to it where it is used; the table counts
  what a reference resolves to and names the reference beside it.

None of them reads a value, so nothing in this panel moves while the
form is filled in. What follows the answers is the contract panel above
it: its table, and "The schema for these answers".

The classic twin of example 1 carries the same two documents under its
form, and no table, having no resource to compare with. Its form requires
the title and holds the capacity between 1 and 1000. Neither rule is in
the config schema, so neither is in either document.

## Example 1: declare what you accept

`/surface-examples/1`

Three settings, each on one line: its name, its type, its label, and
what it allows. The operation at the top, `configure`, is how the
resource is asked for: it needs nothing, and only an account that may
administer site configuration may ask.

<!-- code: src/Resource/RegistrationStep1Resource.php -->

```php
#[Resource('registration.step1', storage: RegistrationStep1Storage::class)]
final class RegistrationStep1Resource implements ResourceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure registration'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
  }

}
```

That class is the whole of the form at `/surface-examples/1`, its
validation (try a capacity of 1001), and what is written: the storage it
names keeps the values in config, and checks them against the config
schema before anything is saved.

**The classic twin**, at `/surface-examples/1/classic`, is the same
three settings written as a `ConfigFormBase`, as well as core allows
today: `#config_target` reads and writes each setting with no submit
handler, and the elements' own `#required`, `#min` and `#max` are its
validation. It edits the same config object. The landing page counts
both. What the classic form cannot do is be *asked*: nothing outside it
knows what its three settings accept, which is what the panel under it
shows ([above](#without-a-resource-what-config-schema-can-say)).

<!-- code: src/Form/RegistrationStep1ClassicForm.php -->

```php
final class RegistrationStep1ClassicForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'data_surface_examples_registration_step1_classic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['data_surface_examples.registration_step1'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#config_target' => 'data_surface_examples.registration_step1:title',
    ];
    $form['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#config_target' => 'data_surface_examples.registration_step1:capacity',
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration open'),
      '#config_target' => 'data_surface_examples.registration_step1:open',
    ];
    return parent::buildForm($form, $form_state);
  }

}
```

## Example 2: answers depend on answers

`/surface-examples/2`

A venue, and a room in it. Which rooms are on offer depends on the
venue, and how many people fit depends on the room. Each rule is one
method with `#[RefinesInput]`: it is handed the key's definition, and,
by parameter name, the value of the key it reads. Its signature is its
dependency. Declaration order is form order, so the capacity is
declared after the room it depends on.

<!-- code: src/Resource/RegistrationStep2Resource.php -->

```php
#[Resource('registration.step2', storage: RegistrationStep2Storage::class)]
final class RegistrationStep2Resource implements ResourceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure registration'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', t('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', t('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   */
  #[RefinesInput('capacity')]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats ?? 1000]);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->setDescription(t('Up to @seats for the @room.', [
        '@seats' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    return $capacity;
  }

}
```

The example ships at Riverside Hall's main hall, so the text under the
capacity says "Up to 400 for the Main hall." Change the venue to the Old
Library: the room list rebuilds over AJAX, and so
does the panel under it, whose room row now lists the new venue's
rooms, *narrowed* from the six declared. The room saved before is not
one of them, so the room select comes up on its empty option rather
than on some other room. The saved room has not gone anywhere: Save
refuses it, since it belongs to the venue you just moved away from,
until a room of the new venue is chosen, and putting the venue back
shows it chosen again.
Choose the garden room and the capacity's row says "from 1 to 30", and
the text under the capacity field, rebuilt with it, says "Up to 30 for
the Garden room." A refiner can only tighten: the framework checks
every result is narrower than what was declared, so the panel's first
answer stays true.

## Example 3: made of parts

`/surface-examples/3`

Two parts, each a structure of its own. The **ticket** is a *slot*: the
pricing chooses which structure fills it. Example 3 names neither ticket;
each ticket names example 3, with `#[Variant]`, so a third kind of
ticket is a new class and example 3 never changes. The **contact** is a
fixed part, attached with `attach()`: always there, and validated in
its own frame, so its email is checked by its own rules.

<!-- code: src/Resource/RegistrationStep3Resource.php -->

```php
#[Resource('registration.step3', storage: RegistrationStep3Storage::class)]
final class RegistrationStep3Resource implements ResourceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure registration'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', t('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', t('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('pricing', 'string', t('Pricing'), default: 'free')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['free', 'paid']]);
    $inputs->attachBy('ticket', by: 'pricing')
      ->setLabel(t('Ticket'));
    $inputs->attach('contact', ContactStructure::class)
      ->setLabel(t('Contact'));
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   */
  #[RefinesInput('capacity')]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats ?? 1000]);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->setDescription(t('Up to @seats for the @room.', [
        '@seats' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    return $capacity;
  }

}
```

The two tickets:

<!-- code: src/Structure/FreeTicketStructure.php -->

```php
#[Structure('registration.step3.ticket.free')]
#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'free')]
final class FreeTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('Shown beside the register button, for example "Donations welcome".'));
  }

}
```

<!-- code: src/Structure/PaidTicketStructure.php -->

```php
#[Structure('registration.step3.ticket.paid')]
#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'paid')]
final class PaidTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('price', 'float', t('Price'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0.01]);
    $inputs->add('currency', 'string', t('Currency'), default: 'EUR')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['EUR', 'GBP', 'USD']]);
  }

}
```

And the contact:

<!-- code: src/Structure/ContactStructure.php -->

```php
#[Structure('registration.contact')]
final class ContactStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('email', 'email', t('Email'))
      ->setRequired(TRUE);
    $inputs->add('phone', 'string', t('Phone'));
  }

}
```

Switch the pricing to paid: the ticket rebuilds with a price and a
currency, and the panel shows the paid variant's keys. A paid ticket
with no price is refused as `ticket.price`, an email that is no email as
`contact.email`.

## Example 4: others get a say

`/surface-examples/4`, which says what to do:

```bash
drush pm:install data_surface_examples_compliance
```

then reload example 3. A second module, which example 3 does not know,
changes it with three classes. One is a shape, what an event licence is
wherever it is asked for: four digits, explained in words, declared
once. One is a structure, what compliance asks of any event: a licence
of that shape and a number of stewards. The third is an alter of
example 3, an adapter: it attaches the structure at its own mount, so
the two keys are stored under the module's own name (at
`third_party_settings.data_surface_examples_compliance.licence` and
`.stewards`, so example 3's storage never has to know them), and it
rewords the title's label. Its rules and its methods are what you see
move. A rule is data, a condition and an effect, which the served
contract writes down as it is; a static method is a pure function of
what it watches, so the served contract states its answers ahead of
time where it can; a method that reads State is a runtime refiner, the
only kind an instance method may be:

- **The licence lifts a ceiling on example 3's own key.** The alter's
  rule on `capacity`, `narrow('capacity')->when(Condition::absent('licence'))`,
  reads the structure's `licence` at the alter's mount. With no licence
  it caps the capacity at 100 and says so under the field; with one it
  leaves the capacity alone, so the room's limit, from example 3's own
  method, is what applies. Both narrow the same key, the method first
  and the rule after, and each only narrows: the lower ceiling wins. The
  full schema states the rule exactly, as an `if` that the licence holds
  nothing.
- **The stewards follow the capacity.** The structure cannot name
  example 3's capacity, since it is meant for any event: it reads a
  `capacity` port, `$inputs->reads('capacity', 'integer', required:
  TRUE)`, and its method on `stewards` watches the port, one steward per
  fifty people, at least one. The alter wires the port to example 3's
  key, `reads: ['capacity' => 'capacity']`; another module attaching the
  structure would wire it to a key of its own.
- **A closed room is not offered.** The alter's method on example 3's
  `room` is a runtime refiner (`runtime: TRUE`): it reads the rooms a
  site has closed from State, which no schema can state ahead of time.
  Nothing is closed until a site says so (`drush state:set
  data_surface_examples_compliance.closed_rooms '["riverside_east"]'
  --input-format=json`); then the room leaves the East room out and says
  "The East room is closed.", the submit refuses it, and the full schema
  still lists it under Riverside Hall, marked `runtime`. Reset to
  defaults opens every room again.

No form alter, no hook.

<!-- code: ../data_surface_examples_compliance/src/Shape/Licence.php -->

```php
#[Shape('event.licence')]
final class Licence implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^\d{4}$/D', 'message' => 'A licence number is four digits, such as 2048.'];
    $licence = DataDefinition::create('string')
      ->setLabel(t('Event licence'))
      ->setDescription(t('The four digits after EV- on the licence, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($licence, ['2048']);
    return $licence;
  }

}
```

<!-- code: ../data_surface_examples_compliance/src/Structure/ComplianceStructure.php -->

```php
#[Structure('registration.compliance')]
final class ComplianceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('capacity', 'integer', required: TRUE);
    $inputs->add('licence', Licence::class, t('Event licence'));
    $inputs->add('stewards', 'integer', t('Stewards'), default: 1)->setRequired(TRUE);
  }

  /**
   * One steward for every fifty people, at least one.
   */
  #[RefinesInput('stewards')]
  public static function stewardsForCapacity(DataDefinition $stewards, int $capacity): DataDefinition {
    $n = max(1, (int) ceil($capacity / 50));
    return $stewards->addConstraint('Range', ['min' => $n])
      ->setDescription(new PluralTranslatableMarkup(
        $n,
        'At least 1 steward for @capacity attendees.',
        'At least @count stewards for @capacity attendees.',
        ['@capacity' => $capacity],
      ));
  }

}
```

<!-- code: ../data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php -->

```php
#[Alters(RegistrationStep3Resource::class)]
final class RegistrationComplianceAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * The State key listing the rooms that are closed, by room id.
   */
  public const CLOSED_ROOMS = 'data_surface_examples_compliance.closed_rooms';

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where the closed rooms are listed.
   */
  public function __construct(TranslationInterface $string_translation, protected readonly StateInterface $state) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    // The structure's capacity is example 3's.
    $inputs->attach('third_party_settings.data_surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
    $inputs->describe('title', label: $this->t('Public event title'));
    $inputs->describe('third_party_settings.data_surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
    // Without a licence, no more than a hundred, whatever the room seats.
    // The licence is the structure's, which this alter reads at its mount.
    // A licence its own pattern refuses is no licence: a rule never sees
    // an invalid sibling.
    $inputs->narrow('capacity')
      ->when(Condition::absent('licence'))
      ->constrain('Range', ['max' => 100])
      ->describe($this->t('Up to 100 without an event licence.'));
  }

  /**
   * No room the site has closed, which only the site knows when asked.
   *
   * A runtime refiner: what it answers is read from State when the
   * values are held to it, so no schema can state it ahead of time. It
   * watches the venue so it narrows the venue's rooms, which the owner's
   * refiner has already listed; the full schema still offers every room
   * per venue, and the pipeline refuses a closed one. The one refiner
   * here that is an instance method, which only a runtime refiner may
   * be: it reads the State this alter was built with.
   *
   * @see docs/decisions.md#static-by-default-runtime-by-declaration
   */
  #[RefinesInput('room', runtime: TRUE)]
  public function openRooms(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    $rooms = $room->getConstraints()['LabeledChoice']['choices'] ?? [];
    $closed = array_intersect_key($rooms, array_flip((array) $this->state->get(self::CLOSED_ROOMS, [])));
    if ($closed === [] || !$room instanceof DataDefinition) {
      return $room;
    }
    $room->addConstraint('LabeledChoice', ['choices' => array_diff_key($rooms, $closed)]);
    return $room->setDescription($this->formatPlural(
      count($closed),
      'The @rooms is closed.',
      'The @rooms are closed.',
      ['@rooms' => implode(' and the ', $closed)],
    ));
  }

}
```

The two keys sit in one fieldset, titled "Compliance" by the alter,
which also places it: `after: 'capacity'` draws the fieldset right after
the capacity its licence lifts, on the form and in React, instead of
last. Only where it is drawn moves; the keys are still stored and posted
at `third_party_settings.data_surface_examples_compliance`, and the Tool
API and the config schema see nothing new. In the served contract the
fieldset is the structure's def, `registration.compliance`, referred to
from the mount, which says which of example 3's keys the port reads
(`x-surface.reads`); the licence inside it is the shape's def,
`event.licence`, written once and referred to from the key, which says
its own title beside the reference. Example 3 ships at Riverside Hall's main hall, which
seats 400. The capacity stops at 100, and its help text says why: "Up
to 100 without an event licence." The
licence asks for the four digits after EV- only. Type `2048` and the
capacity goes back to the owner's "Up to 400 for the Main hall."; type
`204` or `EV-2048` and the licence is refused, in the structure's words,
under the field, and the capacity stops at 100 again: a rule never
sees a value its own key refuses, so a licence in the wrong format is no
licence. In a room that seats 100 or fewer the licence changes nothing:
the room's own ceiling is the lower one, and the owner's help text
stands, since a rule says why only where it tightened. The help text says the format before
anything is typed, and the box shows
`2048` as its placeholder: a pattern explains nothing to a person, so
the Regex carries a message, and the panel's "Allows" column says that.
Under the stewards it says "At least 1 steward for 50 attendees."; set
the capacity to 150 and it asks for "At least 3 stewards for 150
attendees." In the panel, the capacity's row now depends on `room` and
on the licence's path. An alter can add and tighten. It can never take
away what the owner declared.

## Example 5: same contract, no form

`/surface-examples/5`

Nothing to write. Example 3 names its storage and has an operation that needs
nothing, so the Tool API bridge (`data_surface_tool`) derives a tool
from it, `data_surface:registration.step3:configure`. It takes what
example 3's form takes, refuses what it refuses, with the same messages,
and can rehearse a write without making it. Beside it is a read tool,
`data_surface:registration.step3:configure:read`, which takes nothing
and answers with what is stored, in the shape the write tool takes, so
a caller can see what is there before it changes it.

The script makes three calls to the tool and prints what each answered:

```bash
ddev exec php web/modules/custom/data_surface/scripts/examples-dry-run.php
```

```
1. Valid values
Success: Configure registration: the values were accepted and written.
  values: {"title":"Autumn meetup","open":true,"venue":"harbour","room":"harbour_deck","capacity":50,"pricing":"paid","ticket":{"price":12.5,"currency":"EUR"},"contact":{"email":"events@example.com","phone":""}}
  committed: true

2. A capacity above the room's
Failed: The values were refused: capacity: This value should be between 1 and 30 when Room is Garden room.

3. A dry run
Success: Dry run of Configure registration: the values were accepted and prepared, and nothing was written.
  values: {"title":"Winter social","open":true,"venue":"riverside","room":"riverside_east","capacity":40,"pricing":"free","ticket":{"note":"Donations welcome"},"contact":{"email":"events@example.com","phone":""}}
  committed: false
  prepared: {"title":"Winter social","open":true,"venue":"riverside","room":"riverside_east","capacity":40,"pricing":"free","ticket":{"note":"Donations welcome"},"contact":{"email":"events@example.com","phone":""}}
```

The same tool and the same three calls, from Drush, after a read of
what is stored:

```bash
drush tool:info data_surface:registration.step3:configure

drush tool:run data_surface:registration.step3:configure:read --uid=1

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Autumn meetup","capacity":50,"venue":"harbour","room":"harbour_deck","pricing":"paid","ticket":{"price":12.5,"currency":"EUR"}}}'

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Garden party","venue":"library","room":"library_garden","capacity":45,"pricing":"free"}}'

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Winter social","capacity":40,"venue":"riverside","room":"riverside_east","pricing":"free","ticket":{"note":"Donations welcome"}}}' --input=dry_run=true
```

`--uid=1` because `tool:run` runs as anonymous unless told otherwise,
and the operation asks for "administer site configuration", for a read
as for a write. `tool:info` lists no defaults: configuring changes what
exists, an optional key left out keeps its current value, and the
description names the read tool, the second command, for what that value is. The dry run
is a second input, which `tool:run` reads as `name=value` and decodes as
JSON, so `true` is a boolean.

The script and the Drush commands exercise the same tool, through the
same pipeline, against the same resource. The difference is where they
run: the script runs the calls inside a kernel test,
`ExamplesToolTest`, on a fresh database it throws away, so it can be
run any number of times and is what the test suite verifies; the Drush
commands run against your site, and the first one writes example 3's
settings. The calls are written down once, in `ExampleCalls`, and both
are generated from it. Every call says where the event is, so none
depends on an earlier one, and every capacity is fifty or fewer, which
needs no licence and one steward, the default, so example 4's module
does not change the answers. Enable it and send the first call with a
capacity of 150, which the upper deck seats, and no licence to see it
refuse.

### When a call is refused

The listing names what each key depends on, once, in its description,
by the path a caller sends it under, and says nothing about what is
stored. With example 4's module enabled, `tool:info` shows, among the
`values` input's properties:

```
|   └ room                               | string  | Yes      | No       |        | Room                      | riverside_main|riverside_east|library_reading|library_garden|harbour_auditorium|harbour_deck | Room: Depends on venue.                  |
|   └ capacity                           | integer | No       | No       |        | Capacity                  | minimum 1, maximum 1000                                                                      | Capacity: Depends on room, third_party…  |
|       └ stewards                       | integer | Yes      | No       |        | Stewards                  |                                                                                              | Stewards: Depends on capacity.           |
```

`tool:info` cuts a long description short; the capacity's reads in full
"Depends on room, third_party_settings.data_surface_examples_compliance.licence."

A refusal says what is allowed for the values sent, in one sentence per
key: what the key depends on and what that holds, and for a value not
on its list, the list as it stands for them, each label with the value
to send. An agent fixes each key in one retry. `ToolFeedbackTest` plays
that loop as a dry run through the Tool API's runner, the way MCP and
ECA call a tool: the agent sends the venue by name; then moves the
event to a room of another venue; then doubles the capacity to 240,
leaving the stewards at three. The three refusals:

```
Failed: The values were refused: venue: The value you selected is not a valid choice; choose Riverside Hall (riverside), Old Library (library) or Harbour Centre (harbour).
Failed: The values were refused: room: The value you selected is not a valid choice when Venue is Riverside Hall; choose Main hall (riverside_main) or East room (riverside_east).
Failed: The values were refused: third_party_settings.data_surface_examples_compliance.stewards: This value should be 5 or more when Capacity is 240.
```

Each time the agent reads the fix from the refusal alone and the next
call is accepted. The Tool API holds `values` to its shape before the
tool runs (types, the keys it requires, its nesting) and the pipeline
holds it to every rule, against the definitions the values sent narrow,
so a value outside the listing's list is answered with the list that
applies rather than refused with none
([decision](../../docs/decisions.md#a-refusal-names-what-it-depends-on)).

## In React

Examples 1 to 3 again, with no Form API: the same operation served as a
JSON contract and rendered by a React app, from the experimental
submodule `data_surface_react`.

```bash
drush pm:install data_surface_react
```

The landing page then links each example "In React":
`/surface-react/registration.step1/configure`,
`/surface-react/registration.step2/configure` and
`/surface-react/registration.step3/configure`. Each is the same resource
as the form beside it, read from `/surface-api/registration.stepN/configure`:
JSON Schema with the labels as `oneOf` titles, the bounds as `minimum`
and `maximum`, the ticket slot as a conditional on the pricing, and an
`x-surface` note on every key saying what the form knows about it.

Change the venue on example 2: the app posts the answers to `/refine`,
which re-narrows the contract the way the form's AJAX rebuild does, and
the room comes back on its empty option, standing for the stored room,
which stays on the server. Press Submit to save: the answers go to
`/submit`, which writes what the form's Save writes, refuses what it
refuses (each refusal beside its field and in a summary, with nothing
written), and answers with the
contract as now stored, which the app re-renders from. If someone saved
the example since the page loaded, the submit is refused rather than
overwriting them. The collapsed Contract panel under the form is the
contract as it stands.
[The served contract](../../docs/served-contract.md) has the format.

## Example 6: every door

The same contract through every door. The decoupled page is the one
above, [In React](#in-react): the contract served as JSON and drawn by a
React app. ECA and an AI agent, through the tool of example 5, are yours
to show on your own site.

## One small resource per concept

Examples 7 to 11 leave the registration behind. Each is a small
resource of its own, for one or two concepts the registration form does
not need, served the way examples 1 to 3 are: the generic operation form,
the contract panel and what config schema can say. Above them, open, a
third panel says what the example shows, where the docs explain it, the
part of its documents the concept is about, and what its tool answers.
Those calls are written down once, in `ConceptCalls`; none of them writes
anything, so the panel makes them each time it is drawn, and
`ExamplesConceptsToolTest` makes them on the shipped settings and holds
this page to their answers.

## Example 7: a rule written as data

`/surface-examples/7`

The event's bulletin goes out weekly or monthly. Two rules read the
frequency, and each is a condition and an effect, declared beside the
keys with `narrow()->when()`: a weekly issue holds at most five items,
and a monthly bulletin has no send day. The send day is declared
required, as it is wherever it applies; the rule says where it does not
([Declaring a rule](../../docs/refinement.md#declaring-a-rule),
[When a key does not apply](../../docs/refinement.md#when-a-key-does-not-apply)).

<!-- code: src/Resource/BulletinResource.php -->

```php
#[Resource('event.bulletin', storage: BulletinStorage::class)]
final class BulletinResource implements ResourceInterface {

  /**
   * Configures the bulletin, whose settings always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the bulletin'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => ['weekly' => t('Weekly'), 'monthly' => t('Monthly')],
      ]);
    $inputs->add('send_day', 'string', t('Send day'), default: 'monday')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => ['monday' => t('Monday'), 'wednesday' => t('Wednesday'), 'friday' => t('Friday')],
      ]);
    $inputs->add('items', 'integer', t('Items per issue'), default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 20]);

    $inputs->narrow('send_day')
      ->when(Condition::equals('frequency', 'monthly'))
      ->doesNotApply();
    $inputs->narrow('items')
      ->when(Condition::equals('frequency', 'weekly'))
      ->constrain('Range', ['max' => 5])
      ->describe(t('Up to 5 in a weekly issue.'));
  }

}
```

Nothing is run to learn either rule. The full schema states each as
written, an `if`/`then` marked `x-surface.declared`, and the send day as
`false` under the monthly conditional and `required` under the other:
the panel shows the rules beside those three conditionals. Choose
Monthly and the send day is gone from the form, over AJAX; the stored
Friday is kept for the day the bulletin goes weekly again, which the
third call's `prepared` shows. Choose Weekly and the items stop at five,
said under the field: "Up to 5 in a weekly issue."

```bash
drush tool:run data_surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"monthly","send_day":"monday"}}' --input=dry_run=true

drush tool:run data_surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"weekly","items":8}}' --input=dry_run=true

drush tool:run data_surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"monthly","items":8}}' --input=dry_run=true
```

```
1. A send day for a monthly bulletin
Failed: The values were refused: send_day: Send day does not apply when Frequency is Monthly.

2. Eight items in a weekly issue
Failed: The values were refused: items: This value should be between 1 and 5 when Frequency is Weekly.

3. Eight items in a monthly issue, and no send day
Success: Dry run of Configure the bulletin: the values were accepted and prepared, and nothing was written.
  prepared: {"frequency":"monthly","send_day":"friday","items":8}
```

## Example 8: one part, used twice

`/surface-examples/8`, and `/surface-examples/8/desk`

A person to contact is one structure, attached by two resources, the
press page and the information desk. Whether the person is listed
publicly is the container's to know, so the structure reads it through
a port, `listed`, and asks for an email while it holds. Each container
wires the port to a key of its own
([Ports](../../docs/resources.md#ports)). The phone is a named shape,
asked for by the person and by the desk itself
([Named shapes](../../docs/declaring-a-resource.md#named-shapes)).

<!-- code: src/Structure/PersonStructure.php -->

```php
#[Structure('event.person')]
final class PersonStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('listed', 'boolean');
    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);
    $inputs->add('email', 'email', t('Email'));
    $inputs->add('phone', PhoneNumber::class, t('Phone'));
    $inputs->narrow('email')
      ->when(Condition::equals('listed', TRUE))
      ->require()
      ->describe(t('Required while this person is listed publicly.'));
  }

}
```

<!-- code: src/Shape/PhoneNumber.php -->

```php
#[Shape('event.phone')]
final class PhoneNumber implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $phone = DataDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setDescription(t('Digits, with spaces if you like, and a leading + for a country code.'))
      ->addConstraint('Regex', [
        'pattern' => '/^\+?[0-9 ]{7,20}$/D',
        'message' => 'Write the number in digits, such as +1 555 0100.',
      ]);
    DefinitionMetadata::setExamples($phone, ['+1 555 0100']);
    return $phone;
  }

}
```

The press page wires the port to `public`, and mixes the review in, so
the review's two keys are the page's own: stored flat beside `public`,
drawn among its keys, and named in a refusal as `reviewed_by`, not under
a parent ([Mixins](../../docs/resources.md#mixins)).

<!-- code: src/Structure/ReviewStructure.php -->

```php
#[Structure('event.review')]
final class ReviewStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('reviewed_on', 'string', t('Reviewed on'))
      ->setDescription(t('YYYY-MM-DD.'))
      ->addConstraint('Regex', [
        'pattern' => '/^\d{4}-\d{2}-\d{2}$/D',
        'message' => 'Write the date as YYYY-MM-DD, such as 2026-10-19.',
      ]);
    $inputs->add('reviewed_by', 'string', t('Reviewed by'));
    $inputs->narrow('reviewed_by')
      ->when(Condition::present('reviewed_on'))
      ->require()
      ->describe(t('Required once a review date is set.'));
  }

}
```

<!-- code: src/Resource/PressResource.php -->

```php
#[Resource('event.press', storage: PressStorage::class)]
final class PressResource implements ResourceInterface {

  /**
   * Configures the press page, which always exists.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the press page'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('public', 'boolean', t('Show on the press page'), default: TRUE);
    $inputs->attach('contact', PersonStructure::class, reads: ['listed' => 'public'])
      ->setLabel(t('Press contact'));
    $inputs->with(ReviewStructure::class);
  }

}
```

The desk wires the port to `in_program`, and holds a collection, up to
four volunteers, each a person in its own frame, added and removed on
the form over AJAX. A collection's item is wired by nothing, so a
volunteer is never asked for an email
([Collections](../../docs/resources.md#collections)).

<!-- code: src/Resource/InfoDeskResource.php -->

```php
#[Resource('event.desk', storage: InfoDeskStorage::class)]
final class InfoDeskResource implements ResourceInterface {

  /**
   * Configures the information desk, which always exists.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the information desk'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('phone', PhoneNumber::class, t('Desk phone'))
      ->setRequired(TRUE);
    $inputs->add('in_program', 'boolean', t('Listed in the printed program'), default: FALSE);
    $inputs->attach('lead', PersonStructure::class, reads: ['listed' => 'in_program'])
      ->setLabel(t('Desk lead'));
    $inputs->attachList('volunteers', PersonStructure::class, max: 4)
      ->setLabel(t('Volunteers'));
  }

}
```

Both documents write the person once, as the def `event.person`, which
names no container and lists its port; each site refers to it, says
which key the port reads (`x-surface.reads`), and states the email rule
on the container, where that key is. The phone is the def `event.phone`
in both, and the review the def `event.review`, which the press page's
top refers to from its `allOf`. The panel shows the two sites side by
side and what each class costs.

```bash
drush tool:run data_surface:event.press:configure --uid=1 --input='{"values":{"public":true,"contact":{"name":"Robin Lee","email":null}}}' --input=dry_run=true

drush tool:run data_surface:event.press:configure --uid=1 --input='{"values":{"public":false,"contact":{"name":"Robin Lee","email":null}}}' --input=dry_run=true

drush tool:run data_surface:event.press:configure --uid=1 --input='{"values":{"reviewed_on":"2026-10-19","reviewed_by":null}}' --input=dry_run=true
```

```
1. A public contact with the email cleared
Failed: The values were refused: contact.email: Email is required when Show on the press page is on.

2. The same contact, not shown on the press page
Success: Dry run of Configure the press page: the values were accepted and prepared, and nothing was written.
  prepared: {"public":false,"contact":{"name":"Robin Lee","email":null,"phone":"+1 555 0100"},"reviewed_on":"2026-10-01","reviewed_by":"Sam Ortiz"}

3. A review date with no reviewer
Failed: The values were refused: reviewed_by: Reviewed by is required when Reviewed on is set.
```

```bash
drush tool:run data_surface:event.desk:configure --uid=1 --input='{"values":{"phone":"555-0199"}}' --input=dry_run=true
```

```
1. A desk phone the shape refuses
Failed: The values were refused: phone: Write the number in digits, such as +1 555 0100.
```

## Example 9: keys that come from the site

`/surface-examples/9`

One checkbox and one set of limits per ticket type the site sells. The
class cannot list the ticket types: the site's configuration does, in
`data_surface_examples.ticket_types`, and a provider reads it
([Keys that come from the site](../../docs/resources.md#keys-that-come-from-the-site)).

<!-- code: src/Resource/TicketSalesResource.php -->

```php
#[Resource('event.sales', storage: TicketSalesStorage::class)]
final class TicketSalesResource implements ResourceInterface {

  /**
   * Configures ticket sales, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure ticket sales'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('on_sale', 'boolean', from: TicketTypes::class, default: FALSE)
      ->setLabel(t('On sale'))
      ->setDescription(t('Which ticket types can be bought.'));
    $inputs->attachEach('limits', TicketLimitStructure::class, from: TicketTypes::class)
      ->setLabel(t('Limits'));
  }

}
```

<!-- code: src/Keys/TicketTypes.php -->

```php
final class TicketTypes implements KeysFromSiteInterface, ContainerInjectionInterface {

  /**
   * The configuration object the ticket types are listed in.
   */
  public const CONFIG = 'data_surface_examples.ticket_types';

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    $config = $this->configFactory->get(self::CONFIG);
    $keys = (new KeyedList())->addCacheableDependency($config);
    foreach ($config->get('types') ?? [] as $id => $type) {
      $keys->add((string) $id, (string) $type['label'], locked: (bool) ($type['locked'] ?? FALSE));
    }
    return $keys;
  }

}
```

The provider declares what it read, the configuration's cache tag, so
the built resource, the form, both documents and the tool listing are
cached by it. Add a type and reload, with no cache rebuild:

```bash
drush config:set data_surface_examples.ticket_types types.vip '{label: VIP, locked: false}' --input-format=yaml
drush config:delete data_surface_examples.ticket_types types.vip
```

The form has a VIP checkbox and VIP limits at once; the second command
takes them away again. The speaker type is locked in the configuration:
its checkbox and its limits are drawn fixed, `readOnly` in both
documents, and a value a tool sends for it is not taken, which the
answer says in a note. The panel shows the keys the provider answered,
its cache tag, and the locked type in both documents.

```bash
drush tool:run data_surface:event.sales:configure --uid=1 --input='{"values":{"on_sale":{"standard":true,"speaker":true}}}' --input=dry_run=true
```

```
1. Speaker tickets put on sale
Success: Dry run of Configure ticket sales: the values were accepted and prepared, and nothing was written. on_sale.speaker: Speaker is locked and kept as stored; the value sent was not taken.
  prepared: {"on_sale":{"standard":true,"student":true,"speaker":false},"limits":{"standard":{"per_order":4,"waiting_list":false},"student":{"per_order":1,"waiting_list":true},"speaker":{"per_order":1,"waiting_list":false}}}
```

## Example 10: who may change what

`/surface-examples/10`, and `/surface-examples/10/check`

The program's fees are a structure whose access class answers whether
they may be changed. Its answer's kind is read from its cacheability,
never declared
([Static and runtime access](../../docs/resources.md#static-and-runtime-access)):

- **Static**, for an account without "set event fees", the module's
  permission: the fees are locked, read from the account's permissions
  alone, so the full schema and the tool listing state the lock for that
  account.
- **Runtime**, for any other account: once the program is published the
  fees are fixed, which only what is stored says, read under the
  program's config tag. The full schema marks the fees `access:
  "runtime"`, and the schema for these answers, the form and the
  pipeline settle it. Save the program published and reload: the fees
  are drawn disabled.

<!-- code: src/Access/FeesAccess.php -->

```php
final class FeesAccess implements OperationAccessInterface {

  /**
   * The permission that may change the fees.
   */
  public const PERMISSION = 'set event fees';

  /**
   * Constructs the access class, which the container builds as a service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, for the program's stored status.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if (!$account->hasPermission(self::PERMISSION)) {
      // Static: the account alone.
      return AccessAnswer::locked('Only an account that may set event fees changes them.')->addCacheableDependency($read);
    }
    $program = $this->configFactory->get(ProgramStorage::CONFIG);
    $read->addCacheableDependency($program);
    if ($program->get('published') === TRUE) {
      // Runtime: a stored value, under its config tag.
      return AccessAnswer::locked('The fees are fixed once the program is published.')->addCacheableDependency($read);
    }
    return AccessAnswer::open()->addCacheableDependency($read);
  }

}
```

<!-- code: src/Structure/FeesStructure.php -->

```php
#[Structure('event.fees', access: FeesAccess::class)]
final class FeesStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('standard', 'float', t('Standard fee'), default: 20.0)
      ->addConstraint('Range', ['min' => 0]);
    $inputs->add('concession', 'float', t('Concession fee'), default: 10.0)
      ->addConstraint('Range', ['min' => 0]);
  }

}
```

<!-- code: src/Resource/ProgramResource.php -->

```php
#[Resource('event.program', storage: ProgramStorage::class)]
final class ProgramResource implements ResourceInterface {

  /**
   * Configures the program, which always exists.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the program'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * Checks a program against every rule, and writes nothing.
   */
  #[Operation('check', label: new TranslatableMarkup('Check a program'), permission: 'administer site configuration', readOnly: TRUE)]
  public static function check(): OperationContext {
    return new OperationContext('check');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', 'string', t('Headline'))
      ->setRequired(TRUE);
    $inputs->add('sessions', 'integer', t('Sessions'), default: 8)
      ->addConstraint('Range', ['min' => 1, 'max' => 30]);
    $inputs->add('published', 'boolean', t('Published'), default: FALSE);
    $inputs->attach('fees', FeesStructure::class)
      ->setLabel(t('Fees'));
  }

}
```

Each operation also states how a call behaves
([An operation states how it behaves](../../docs/decisions.md#an-operation-states-how-it-behaves)).
Configuring changes what exists and may be repeated; checking changes
nothing, `readOnly: TRUE`, so its form at `/surface-examples/10/check`
and its tool accept and validate and write nothing, and it has no read
tool beside it. Both documents state the facts under
`x-surface.operation`. The panel shows the fees' part of the full schema
for you and for an account without the permission, and a table of the
two operations: their facts, the Tool API kind each tool is given, and
the hints an MCP client reads from that kind. Configuring reaches an MCP
client as not idempotent: the Tool API has idempotence on the kind, and
no kind that writes is idempotent.

```bash
drush tool:run data_surface:event.program:check --uid=1 --input='{"values":{"headline":"Three days of talks","sessions":12}}'

drush tool:run data_surface:event.program:check --uid=1 --input='{"values":{"headline":"Three days of talks","sessions":40}}'
```

```
1. A program checked
Success: Check a program changes nothing: the values were accepted and prepared, and nothing was written.
  prepared: {"headline":"Three days of talks","sessions":12,"published":false,"fees":{"standard":20.0,"concession":10.0}}

2. A program with too many sessions, checked
Failed: The values were refused: sessions: This value should be between 1 and 30.
```

## Example 11: the stock widgets, and what nothing means

`/surface-examples/11`

The live companion of [Common settings recipes](../../docs/recipes.md):
every stock widget, each chosen by what its key declares. A time input
for a string that asks for one; checkboxes for a list of three choices;
radios for one choice that asks for them; a text area for a multiline
string; and rows with "Add another" for a list of free values
([Widgets](../../docs/widgets.md#the-stock-widgets)).

<!-- code: src/Resource/DoorsResource.php -->

```php
#[Resource('event.doors', storage: DoorsStorage::class)]
final class DoorsResource implements ResourceInterface {

  /**
   * Configures the doors, which always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the doors'), permission: 'administer site configuration')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('opens', 'string', t('Doors open at'), default: '18:30')
      ->setRequired(TRUE)
      ->setDescription(t('HH:MM, on a 24-hour clock.'))
      ->setSetting('widget', 'time')
      ->addConstraint('Regex', [
        'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
        'message' => 'Write the time as HH:MM, such as 18:30.',
      ]);

    $day = DataDefinition::create('string')
      ->addConstraint('LabeledChoice', [
        'choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')],
      ]);
    $days = (new ListDataDefinition(['type' => 'list'], $day))
      ->setLabel(t('Days'))
      ->setRequired(TRUE);
    $inputs->addDefinition('days', $days, default: ['fri', 'sat']);

    $inputs->add('badges', 'string', t('Badges'), default: 'printed')
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', [
        'choices' => ['printed' => t('Printed at the door'), 'digital' => t('On the phone'), 'none' => t('No badges')],
      ]);

    $inputs->add('notes', 'string', t('Notes for the door team'))
      ->setSetting('multiline', TRUE);

    $greeters = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))
      ->setLabel(t('Greeters'));
    $inputs->addDefinition('greeters', $greeters, default: []);
  }

}
```

The config schema is fully validatable
([A fully validatable schema](../../docs/storage.md#a-fully-validatable-schema)):
`FullyValidatable` at its root, every key stored, and `nullable: true`
on the notes alone. So the storage knows the notes may hold nothing and
the badges may not: the badges are always held, their radios offer no
"- None -", and both schemas leave `null` out of their type.

What a call sends for a key means one of three things
([Absent, null, or a value](../../docs/semantics.md#absent-null-or-a-value)):
left out, the key keeps what is stored; `null`, it is cleared; a value
replaces it. The four dry runs show each, and the fourth what null does
to a key the storage always holds: it is refused, naming the default.
The panel shows them, and a table of each key's widget and type.

```bash
drush tool:run data_surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"]}}' --input=dry_run=true

drush tool:run data_surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"notes":null}}' --input=dry_run=true

drush tool:run data_surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"notes":"Doors close at ten."}}' --input=dry_run=true

drush tool:run data_surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"badges":null}}' --input=dry_run=true
```

```
1. The notes left out: kept
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  prepared: {"opens":"19:00","days":["fri"],"badges":"printed","notes":"Hand out the maps.","greeters":["Ana","Ben"],"langcode":"en"}

2. The notes sent null: cleared
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  prepared: {"opens":"19:00","days":["fri"],"badges":"printed","notes":null,"greeters":["Ana","Ben"],"langcode":"en"}

3. The notes sent a value: replaced
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  prepared: {"opens":"19:00","days":["fri"],"badges":"printed","notes":"Doors close at ten.","greeters":["Ana","Ben"],"langcode":"en"}

4. The badges sent null: refused, since they are always held
Failed: The values were refused: badges: Badges cannot be cleared: it always holds a value, Printed at the door (printed) by default. Leave it out to keep what it holds.
```

## How this page is kept true

Every code block above is the file it names, from its first attribute
or its class line to its closing brace, and `ExamplesReadmeTest` fails
when one drifts. The Drush commands and answers of examples 7 to 11 are
asserted by `ExamplesConceptsToolTest`. The JSON of example 1's config schema is asserted by
`ConfigSchemaContractTest`, which also checks this page quotes it. The tool's three answers are asserted by
`ExamplesToolTest`, which also checks this page quotes them, and the
Drush commands are checked against `ExampleCalls`.

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesStepsTest` | Examples 1 to 3: each form builds and saves, example 1 as its classic twin does; the venue narrows the room and the room the capacity; the ticket slot resolves by pricing; the contact validates its email; the panel's rows as the answers move; each example's class stays screen sized. |
| `Kernel\ExamplesComplianceTest` | Example 4: the alter's keys and label; its keys in one fieldset titled "Compliance", drawn between the capacity and the pricing; the capacity capped at a hundred without a licence and the room's limit with one; the licence's pattern; the stewards' minimum following the capacity; what the licence and the capacity replace on the form; a room closed in State left out, said in the room's help, and refused on submit; example 5's calls unchanged. |
| `Kernel\StaticRefinerEnforcementTest` | Examples 2 and 3, without example 4 and with it: every conditional the served contract enumerates, in the full schema and in the schema for no values and for the defaults, is what refining afresh for its values answers, and example 4's declared licence rule is what refining with the rules alone answers for sampled values. |
| `Kernel\ExamplesToolTest` | Example 5: the three calls and their answers; what the script prints. |
| `Kernel\ConfigSchemaContractTest` | What config schema can say: the plain mapping of examples 1 and 3, whole, with no list, no bound, nothing required and no keys for the ticket, the same whatever is stored or mounted; core's answer, a comment and no property; a constraint a schema does declare is said; the JSON this page quotes. |
| `Kernel\ExamplesTodayPanelTest` | The second panel under examples 1 to 3: its two documents and its table, row by row; its rows read from the resource's full schema, so the pricing, the venue and what is stored leave every row and both documents as they were; example 4's keys on the resource's side only; the classic twin's page. |
| `Unit\ExamplesReadmeTest` | This page against the files it quotes, and its Drush commands. |
| `Functional\ExamplesRoutesTest` | Every route answers an administrator and refuses anonymous; the landing page; both panels under example 3, and a save through it; the classic twin's page with its two documents, and a save through it. |
| `FunctionalJavascript\ExamplesAjaxRefreshTest` | In a browser: example 2's venue replaces the room and the capacity and leaves the venue the same node, the orphaned room on its empty option with the stale marker and the capacity back at 1000; a room then narrows the capacity and loses `- Select -`. Example 3's paid pricing swaps the note for a price and a currency, and leaves the second panel's table and its two documents as they were. |
| `FunctionalJavascript\ExamplesComplianceRefreshTest` | Example 4, in a browser: one fieldset titled "Compliance", the ceiling of 100 lifted to the main hall's 400 by `2048`, and the stewards at 1 for 50 and 3 for 150; a malformed licence on blur is said once, under the licence, with `aria-invalid` and nothing at the top, and caps the capacity at a hundred; corrected, the error goes and the room's limit comes back. A refused capacity left by a click on text keeps no focus, and left by Tab lands on the Compliance fieldset after it. The fieldset is drawn below the capacity and above the pricing, and stays there when `2048` and Tab lift the capacity, which, with the stewards, is all that is replaced. With the East room closed in State, choosing Riverside Hall offers the main hall alone and says why. |
| `Functional\ServedContractEndpointsTest` | In React: the landing page's links, and example 2's contract, refine and validate over HTTP. |
| `Functional\ServedSubmitEndpointTest` | In React: example 2 saved over HTTP, refused, and refused after someone else saved. |
| `Kernel\ExamplesResetTest` | Reset to defaults puts every example, and what another module stored on example 3, back to the shipped files, and opens every room example 4 closed. |
| `Kernel\ExamplesConceptsTest` | Examples 7 to 11: each form builds and saves; example 7's rules in the full schema as declared, the send day gone for a monthly bulletin and kept in storage; example 8's one def at two sites with each wiring, the mixed-in keys flat, the shape at two keys, the volunteers' collection; example 9's keys from the configuration, a type added without a cache rebuild, the locked type in both documents and on the form; example 10's fees for two accounts, locked once published, and both operations' facts and tools; example 11's widgets, the always held badges and the notes that clear; the panel above each, and none above examples 1 to 3; each class stays screen sized. |
| `Kernel\ExamplesConceptsToolTest` | Examples 7 to 11: every call `ConceptCalls` writes down, its answer, nothing written, and this page quoting each command and answer. |
| `Functional\ExamplesConceptsRoutesTest` | Every route of examples 7 to 11 answers an administrator and refuses anonymous; the landing page lists them; each page shows its panel and saves through its form; example 10's check writes nothing. |
