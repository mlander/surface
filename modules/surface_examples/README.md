# Surface API examples

One resource that grows, one idea per example. The thing being described is
the settings of an event registration: a title, how many people, where,
what it costs, who to contact. Each example adds one idea, each example with a
form is a page of its own, and under each form, collapsed, is the contract the
form was built from: every key, what it allows right now, and the JSON
Schema a caller with no form is handed. Below that, collapsed too, is
the before picture: what the same caller could be told today, from
config schema alone.

Examples 7 to 12 leave the registration behind: each is a small resource
of its own for one or two later concepts, a rule written as data, a part
used twice, keys that come from the site, access, the stock widgets, and
a JSON Schema read into a structure
([below](#one-small-resource-per-concept)). Example 13 is example 12's
two lines as the settings of a block, which lives in a submodule of its
own, placed through the general placement resource of another
([below](#example-13-a-block-that-embeds-a-component)). Example 14 is an
optional extension: a credential pasted as a secret, or a key the site
manages once a submodule standing in for the Key module is on, built
once by the owner and once by that submodule's alter
([below](#example-14-an-optional-extension)). Example 15 is a config
entity type of the module's own, a venue, with a resource over it from
the start: one venue added, edited and removed, the venues listed a
page at a time and reordered in one write
([below](#example-15-a-config-entity-of-your-own)). Example 16 is a key
that refers to an entity, declared by the data type string of what it
refers to, listed while the site has few and looked up past that, in a
submodule that needs node
([below](#example-16-references-three-ways)). Example 17 is a bulk
retitle of nodes, a resource of its own that borrows each node's access,
core's update and its title's edit, asked per node, in a submodule that
needs node too ([below](#example-17-retitle-in-bulk-access-borrowed)).

```bash
drush pm:install surface_examples
```

Then visit `/surface-examples` as an administrator. Every page asks for
"administer site configuration", example 15's for "administer event
venues", example 16's for "set event sponsorship", and example 17's
for "retitle nodes". Nothing else is needed: the venues and rooms are the three
venues the module ships, example 15's config entities, and each example
keeps its settings in a config object of its own
(`surface_examples.registration_step1` to `_step3`, then
`surface_examples.bulletin`, `.press`, `.desk`, `.sales`,
`.program`, `.doors`, `.spotlight`, `.mailer` and `.webhook`), so the examples never write over
each other.

Every code block on the pages is highlighted with Prism 1.30.0: the
contract panel's JSON, the commands and their answers, and each file
shown whole, by its extension. The core, the JSON, YAML, PHP, Twig and
Bash languages and the default theme come from cdnjs, each pinned and
held to its integrity hash (`surface.libraries.yml`), as the OpenAPI
document's page loads Swagger UI. The React page bundles Prism
for its JSON and takes the same theme. When the CDN cannot be reached,
the blocks show as plain text.

To take the examples again from the start, follow **Reset to defaults**
on the landing page (`/surface-examples/reset`). It puts every config
object back to the files the module ships in `config/install`, the
ticket types example 9 reads among them, puts example 15's three venues
back as shipped and removes any venue added since,
including anything another module stored on them, such as the event
license example 4's module adds under example 3, and it opens again
every room example 4 lets a site close and clears example 10's workflow
state.

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
  `$comment`, saying no schema is defined for a mapping. That is core's
  whole answer, not an error, and the panel says so above it: core has
  no JSON Schema for a config mapping, and the page the comment links says
  how code implements a schema.
- **The most a plain mapping of config schema could say.**
  `ConfigSchemaContract` reads the schema definition and translates it
  one to one: a label is a `title`, a type a `type`, a mapping an
  object, and a constraint the schema declares the keyword JSON Schema
  has for it. The examples' schema is written from their resources
  (`drush surface:config-schema`), fully validatable, so it carries
  each key's type and label, its bounds, what is always held and what
  null may clear; a list of values it leaves to the resource, which keeps
  a value a list no longer offers as stale. Example 1:

<!-- json: surface_examples.registration_step1 -->

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
            "type": [
                "integer",
                "null"
            ],
            "minimum": 1,
            "maximum": 1000
        },
        "open": {
            "title": "Registration open",
            "type": [
                "boolean",
                "null"
            ]
        }
    },
    "additionalProperties": false,
    "required": [
        "title",
        "capacity",
        "open"
    ]
}
```

The capacity's bounds are there, every key is required to be stored, and
null clears the two optional ones; `additionalProperties: false`
because a mapping in config schema refuses a key it does not list. What
a schema generated from the resource does not say is the lists of values
and what depends on what: in example 3 the venue, the room and the
pricing are strings with no list, and the ticket has
a title and a `$comment`: its type is
`surface_examples.registration_step3.ticket.[%parent.pricing]`,
which only a stored value resolves, and this reads none.

- **Key by key.** A table counted from two documents, one row per key
  the resource declares: the JSON Schema keywords the mapping states,
  beside those the resource's full schema states, the one the contract
  panel shows as "The full schema, for any answers". The venue reads
  `title, type` beside `title, type, oneOf (3)`, the room `title, type`
  beside `title, type, oneOf (6), if/then on venue (3 cases)`, the
  ticket `title, $comment` beside
  `title, anyOf, if/then on pricing (2 variants: free has note, $ref (registration.step3.ticket.free); paid has price, currency, $ref (registration.step3.ticket.paid))`,
  the contact `title, type, properties, additionalProperties, required`
  beside
  `title, type, properties, additionalProperties, required, $ref (registration.contact)`,
  and the last row lists what is required: every key, which a fully
  validatable schema asks to be stored, beside
  `title, venue, room, pricing`, which a caller must give. The resource writes each part once,
  under `$defs`, and refers to it where it is used; the table counts
  what a reference resolves to and names the reference beside it.

None of them reads a value, so nothing in this panel moves while the
form is filled in. What follows the answers is the contract panel above
it: its table, and "The schema for these answers".

The classic twin of example 1 carries the same two documents under its
form, and no table, having no resource to compare with. Its form requires
the title and holds the capacity between 1 and 1000, in its own code.
The config schema states the bounds only because it is written from
example 1's resource; written for the classic form alone, it would state
neither rule. Example 2's twin carries the same two documents too: its
form offers the chosen venue's rooms, refuses a room of another venue
and holds the capacity between 1 and the room's seats, in its own code,
and the config schema states only the lower bound, because it is written
from example 2's resource.

## Example 1: declare what you accept

`/surface-examples/1`

Three settings, each on one line: its name, its type, its label, and
what it allows. The operation at the top, `configure`, is how the
resource is asked for: it needs nothing, only an account that may
administer site configuration may ask, and its description is what a
tool's caller reads first. It needs no method: an operation is a method
only when its context needs more.

<!-- code: src/Resource/RegistrationStep1Resource.php -->

```php
#[Resource('registration.step1', storage: RegistrationStep1Storage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 1)'),
  description: new TranslatableMarkup('Sets the event title, its capacity and whether registration is open.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep1Resource implements ResourceInterface {

  public const TITLE = 'title';
  public const CAPACITY = 'capacity';
  public const OPEN = 'open';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::TITLE, 'string', t('Event title'))
      ->setRequired(TRUE);

    $inputs->add(self::CAPACITY, 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);

    $inputs->add(self::OPEN, 'boolean', t('Registration open'), default: TRUE);
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
    return 'surface_examples_registration_step1_classic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['surface_examples.registration_step1'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#config_target' => 'surface_examples.registration_step1:title',
    ];
    $form['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#config_target' => 'surface_examples.registration_step1:capacity',
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration open'),
      '#config_target' => 'surface_examples.registration_step1:open',
    ];
    return parent::buildForm($form, $form_state);
  }

}
```

### Files

What example 1 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 1 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/RegistrationStep1Resource.php`](src/Resource/RegistrationStep1Resource.php) | the resource: three keys, each with what it allows, and the operation that asks for them | 18 |
| [`modules/surface_examples/src/Storage/RegistrationStep1Storage.php`](src/Storage/RegistrationStep1Storage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step1` | the route: the generic operation form, serving the resource | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.registration_step1` | the config schema, generated from the class | 21 |
| [`modules/surface_examples/config/install/surface_examples.registration_step1.yml`](config/install/surface_examples.registration_step1.yml) | the settings the module ships with | 3 |
| [`modules/surface_examples/src/Form/RegistrationStep1ClassicForm.php`](src/Form/RegistrationStep1ClassicForm.php) | the classic twin: the same settings written by hand as a config form | 30 |

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
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 2)'),
  description: new TranslatableMarkup('Sets the event title, whether registration is open, the venue, a room at that venue and a capacity the room can hold.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep2Resource implements ResourceInterface {

  public const TITLE = 'title';
  public const OPEN = 'open';
  public const VENUE = 'venue';
  public const ROOM = 'room';
  public const CAPACITY = 'capacity';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::TITLE, 'string', t('Event title'))
      ->setRequired(TRUE);

    $inputs->add(self::OPEN, 'boolean', t('Registration open'), default: TRUE);

    $inputs->add(self::VENUE, 'entity:event_venue', t('Venue'))
      ->setRequired(TRUE);

    $inputs->add(self::ROOM, 'string', t('Room'))
      ->setRequired(TRUE)
      ->addConstraint('EventVenueRoom');

    $inputs->add(self::CAPACITY, 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1]);
  }

  /**
   * The room must be one of the chosen venue's rooms.
   *
   * The parameter $venue watches self::VENUE by its name, the one place the
   * key is spelled as a string: rename the key, rename the parameter.
   */
  #[RefinesInput(self::ROOM)]
  public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('RoomOfEventVenue', ['venue' => $venue]);
  }

  /**
   * No more people than the chosen room seats, said under the field.
   *
   * A room that seats no known number changes nothing: the capacity keeps
   * its lower bound and has no upper one. The parameter $room watches
   * self::ROOM by its name.
   */
  #[RefinesInput(self::CAPACITY)]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
        ->setDescription(t('Up to @seats for the @room.', [
          '@seats' => $seats,
          '@room' => Venues::rooms()[$room],
        ]));
    }
    return $capacity;
  }

}
```

The venue refers to one of example 15's venue entities, by the data
type string `entity:event_venue`: it holds the venue's machine name, its
candidates are core's entity reference selection handler's for the
account, and both documents mark it `x-surface.reference`. This module
writes no code for it. The list differs from the one example 2 had
before the venue was a reference: core's selection for a config entity
type lists every venue, enabled or not, sorted by name, where the old
list left a disabled venue out and put the lighter first.

The room is the one rule no stock constraint states, "a room of a venue
this site has", so it keeps a constraint of its own, `EventVenueRoom`, a
`Choice` whose list is the site's. Its refiner adds `RoomOfEventVenue`
for the venue chosen, a pure function of it. One resolver,
`EventVenueRoomOptions`, reads both as the rooms of every venue, under
the venues' list tag, so a venue added through example 15 is offered
here at the next request with no cache rebuild, and its rooms with it.
The resolver says its list is the whole of the constraint
(`exact: TRUE`), as a `Choice`'s is, so both documents state the rooms
as a `oneOf` and name nothing under `checkedOnServer` for the room. The full
schema states each venue's rooms as a conditional per venue the
reference lists.

The refiner adds a second constraint rather than `EventVenueRoom` again
with the venue because core's `addConstraint()` replaces a constraint of
the same name, and the narrowing check refuses a constraint whose
options were replaced: it cannot read what an option means. Adding a
constraint is narrower by its rule. Which constraint to write, and why
the venue needs none, is in
[Options](../../docs/options.md#which-constraint-to-write). The seats
the capacity reads are still `Venues::seats()`: a static refiner reads
only what it watches, and cannot say what site state it read, so a room
example 15 adds is offered with no cap on the capacity.

The example ships at Riverside Hall's main hall, so the text under the
capacity says "Up to 400 for the Main hall." Change the venue to the Old
Library: the room list rebuilds over AJAX, and so
does the panel under it, whose room row now lists the new venue's
rooms, *narrowed* from the six declared. The room saved before is not
one of them, so the room select comes up on its empty option rather
than on some other room. The saved room has not gone anywhere: Save
refuses it, since it belongs to the venue you just moved away from,
until a room of the new venue is chosen, and putting the venue back
shows it chosen again. While no room of the venue is chosen, the
capacity has its lower bound, 1, and no upper one: there are no seats
to stop at.
Choose the garden room and the capacity's row says "from 1 to 30", and
the text under the capacity field, rebuilt with it, says "Up to 30 for
the Garden room." A refiner can only tighten: the framework checks
every result is narrower than what was declared, so the panel's first
answer stays true.

**The classic twin**, at `/surface-examples/2/classic`, is the same five
settings written as a `ConfigFormBase` by hand, as well as core allows
today, as example 1's twin is: `#config_target` reads and writes each
setting, so the parent's `submitForm()` saves them. What depends on what
is written out. The venue and the room rebuild the room and the capacity
over `#ajax`; `buildForm()` reads the submitted venue and room from the
form state, and on a new venue drops the old venue's room from the input
and falls back to the stored room; `validateForm()` refuses a room of
another venue and a capacity outside 1 and the room's seats, in the words
the resource uses. It edits the same config object. It chooses from
`Venues`' fixed lists, so a venue example 15 adds is offered by example
2 and not by its twin.

Its page says what the two forms share and counts both, from the files,
the way the landing page counts every class: without the namespace, the
use statements, blank lines and comments. The twin is **94 lines of
code**; example 2's resource and its storage are **44**. The storage is
counted with the resource because the twin names its config object
itself, which the resource leaves to its storage.

The twin behaves the same at the form, and nowhere else: there is no
tool for it, no schema says what it accepts, and no React page draws it.
At the form it falls short of the module's rule for a room left behind
by a new venue in three places, said in a comment in `buildForm()`: its
select does not say it stands for the stored room, a save leaving it
empty is refused as required, and a stored room its venue no longer
offers is shown as "- Select -" rather than kept with a warning. Posted
without JavaScript, a room of another venue is refused on the room by
both forms, in different words: example 2's form lists the rooms of the
venue sent, so core's own choice check answers first, and the twin lists
those of the venue it was built for, so its `validateForm()` does.

<!-- code: src/Form/RegistrationStep2ClassicForm.php -->

```php
final class RegistrationStep2ClassicForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'surface_examples_registration_step2_classic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['surface_examples.registration_step2'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stored = $this->config('surface_examples.registration_step2');
    $venue = $form_state->getValue('venue') ?? $stored->get('venue');
    $room = $form_state->getValue('room') ?? $stored->get('room');
    if (($form_state->getTriggeringElement()['#name'] ?? NULL) === 'venue') {
      // A new venue drops the room chosen under the old one from the input,
      // which Form API would otherwise put back, and falls back to the
      // stored room. The module's rule goes further in three places: its
      // select says it stands for the stored room, a save leaving it empty
      // is refused on the stored room rather than as required, and a
      // stored room its venue no longer offers is kept with a warning
      // rather than shown as "- Select -".
      $input = $form_state->getUserInput();
      unset($input['room']);
      $form_state->setUserInput($input);
      // The original: the parent's validateForm() copied the input onto
      // this config object on the way to the rebuild.
      $room = $stored->getOriginal('room', FALSE);
    }
    $rooms = Venues::rooms($venue);
    // NULL, not '': a required select with no default gets "- Select -".
    $room = isset($rooms[$room]) ? $room : NULL;
    $ajax = ['callback' => '::rebuildWhere', 'wrapper' => 'registration-step2-where'];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#config_target' => 'surface_examples.registration_step2:title',
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration open'),
      '#config_target' => 'surface_examples.registration_step2:open',
    ];
    $form['venue'] = [
      '#type' => 'select',
      '#title' => $this->t('Venue'),
      '#options' => Venues::VENUES,
      '#required' => TRUE,
      '#ajax' => $ajax,
      '#config_target' => 'surface_examples.registration_step2:venue',
    ];
    $form['where'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'registration-step2-where'],
    ];
    $form['where']['room'] = [
      '#type' => 'select',
      '#title' => $this->t('Room'),
      '#options' => $rooms,
      '#default_value' => $room,
      '#required' => TRUE,
      '#ajax' => $ajax,
      '#config_target' => 'surface_examples.registration_step2:room',
    ];
    // No #min or #max: validateForm() holds the bounds, so the refusal is
    // the resource's sentence and not the number element's own.
    $form['where']['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#step' => 1,
      '#config_target' => 'surface_examples.registration_step2:capacity',
    ];
    if ($room !== NULL) {
      $form['where']['capacity']['#description'] = $this->t('Up to @seats for the @room.', [
        '@seats' => Venues::seats($room),
        '@room' => $rooms[$room],
      ]);
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Returns the room and the capacity, rebuilt for the venue and room.
   *
   * @param array $form
   *   The rebuilt form.
   *
   * @return array
   *   The part of the form the #ajax wrapper replaces.
   */
  public function rebuildWhere(array &$form): array {
    return $form['where'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $venue = (string) $form_state->getValue('venue');
    $room = (string) $form_state->getValue('room');
    $rooms = Venues::rooms($venue);
    if ($room !== '' && !isset($rooms[$room])) {
      $labels = array_values($rooms);
      $last = array_pop($labels);
      $form_state->setErrorByName('room', $this->t('The value you selected is not a valid choice. This applies when Venue is @venue. Choose @rooms or @last.', [
        '@venue' => Venues::VENUES[$venue] ?? $venue,
        '@rooms' => implode(', ', $labels),
        '@last' => $last,
      ]));
    }
    $seats = Venues::seats($room);
    $capacity = (int) $form_state->getValue('capacity');
    if ($seats !== NULL && ($capacity < 1 || $capacity > $seats)) {
      $form_state->setErrorByName('capacity', $this->t('This value should be between %min and %max. This applies when Room is @room.', [
        '%min' => 1,
        '%max' => $seats,
        '@room' => Venues::rooms()[$room],
      ]));
    }
    parent::validateForm($form, $form_state);
  }

}
```

### Files

What example 2 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 2 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/RegistrationStep2Resource.php`](src/Resource/RegistrationStep2Resource.php) | the resource: five keys and two refiners, the rooms by the venue and the capacity by the room; the venue refers to a venue entity | 41 |
| [`modules/surface_examples/src/Plugin/Validation/Constraint/EventVenueRoomConstraint.php`](src/Plugin/Validation/Constraint/EventVenueRoomConstraint.php) | the room's constraint: a room of a venue the site has, a Choice whose list is the site's | 17 |
| [`modules/surface_examples/src/Plugin/Validation/Constraint/RoomOfEventVenueConstraint.php`](src/Plugin/Validation/Constraint/RoomOfEventVenueConstraint.php) | the constraint the room refiner adds: a room of the chosen venue | 6 |
| [`modules/surface_examples/src/Plugin/Validation/Constraint/EventVenueRoomConstraintValidator.php`](src/Plugin/Validation/Constraint/EventVenueRoomConstraintValidator.php) | their validator: reads the rooms, then holds the value to them as Choice does | 14 |
| [`modules/surface_examples/src/Plugin/SurfaceOptionsResolver/EventVenueRoomOptions.php`](src/Plugin/SurfaceOptionsResolver/EventVenueRoomOptions.php) | the resolver that reads both constraints as the rooms, an exact list | 30 |
| [`modules/surface_examples/src/VenueChoices.php`](src/VenueChoices.php) | the rooms an event may be held in: those of example 15's venue entities, read under their list tag | 25 |
| [`modules/surface_examples/src/Venues.php`](src/Venues.php) | the seats of each room, which the capacity reads, beside the fixed lists of venues and rooms example 3 and the classic twin choose from | 27 |
| [`modules/surface_examples/src/Storage/RegistrationStep2Storage.php`](src/Storage/RegistrationStep2Storage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step2` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.registration_step2` | the config schema, generated from the class | 26 |
| [`modules/surface_examples/config/install/surface_examples.registration_step2.yml`](config/install/surface_examples.registration_step2.yml) | the settings the module ships with | 5 |
| [`modules/surface_examples/src/Form/RegistrationStep2ClassicForm.php`](src/Form/RegistrationStep2ClassicForm.php) | the classic twin: the same five questions and both rules, written by hand | 94 |

## Example 3: made of parts

`/surface-examples/3`

Two parts, each a structure of its own. The **ticket** has *variants*:
the pricing chooses which one it is. Example 3 names neither ticket;
each ticket names example 3, with `#[Variant]`, so a third kind of
ticket is a new class and example 3 never changes. The **contact** is a
fixed part, attached with `attach()`: always there, and validated in
its own frame, so its email is checked by its own rules.

<!-- code: src/Resource/RegistrationStep3Resource.php -->

```php
#[Resource('registration.step3', storage: RegistrationStep3Storage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure registration (step 3)'),
  description: new TranslatableMarkup('Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact.'),
  permission: 'administer site configuration',
)]
final class RegistrationStep3Resource implements ResourceInterface {

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
      ->addConstraint('Range', ['min' => 1]);

    $inputs->add('pricing', 'string', t('Pricing'), default: 'free')
      ->setRequired(TRUE);

    $inputs->attachVariants('ticket', by: 'pricing')
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
   *
   * A room that seats no known number changes nothing: the capacity keeps
   * its lower bound and has no upper one.
   */
  #[RefinesInput('capacity')]
  public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    $seats = Venues::seats($room);
    if ($seats !== NULL && $capacity instanceof DataDefinition) {
      $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
        ->setDescription(t('Up to @seats for the @room.', [
          '@seats' => $seats,
          '@room' => Venues::rooms()[$room],
        ]));
    }
    return $capacity;
  }

}
```

The pricing lists no values: its values are the tickets', and its
labels too, each ticket saying what it is offered as with `label:`. A
third ticket is offered as soon as its class exists. The two tickets:

<!-- code: src/Structure/FreeTicketStructure.php -->

```php
#[Structure('registration.step3.ticket.free',
  label: new TranslatableMarkup('Free ticket'),
  description: new TranslatableMarkup('The ticket when registration costs nothing: a note shown beside the register button.'),
)]
#[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'free', label: new TranslatableMarkup('Free'))]
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
#[Structure('registration.step3.ticket.paid',
  label: new TranslatableMarkup('Paid ticket'),
  description: new TranslatableMarkup('The ticket when registration costs money: its price and currency.'),
)]
#[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'paid', label: new TranslatableMarkup('Paid'))]
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

### Files

What example 3 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 3 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/RegistrationStep3Resource.php`](src/Resource/RegistrationStep3Resource.php) | the resource: six keys, the ticket's variants chosen by the pricing, and the contact attached | 43 |
| [`modules/surface_examples/src/Structure/FreeTicketStructure.php`](src/Structure/FreeTicketStructure.php) | the free ticket: the variant for the pricing free, a note | 11 |
| [`modules/surface_examples/src/Structure/PaidTicketStructure.php`](src/Structure/PaidTicketStructure.php) | the paid ticket: the variant for the pricing paid, a price and a currency | 15 |
| [`modules/surface_examples/src/Structure/ContactStructure.php`](src/Structure/ContactStructure.php) | the contact: a structure of two keys, attached | 8 |
| [`modules/surface_examples/src/Storage/RegistrationStep3Storage.php`](src/Storage/RegistrationStep3Storage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step3` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.registration_step3` | the config schema, generated from the class | 49 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.registration_step3.ticket.free` | the free ticket's config schema | 10 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.registration_step3.ticket.paid` | the paid ticket's config schema | 15 |
| [`modules/surface_examples/config/install/surface_examples.registration_step3.yml`](config/install/surface_examples.registration_step3.yml) | the settings the module ships with | 11 |

## Example 4: others get a say

`/surface-examples/4`, which says what to do:

```bash
drush pm:install surface_examples_compliance
```

then reload example 3. A second module, which example 3 does not know,
changes it with three classes. One is a shape, what an event license is
wherever it is asked for: four digits, explained in words, declared
once. One is a structure, what compliance asks of any event: a license
of that shape and a number of stewards. The third is an alter of
example 3, an adapter: it attaches the structure at its own mount, so
the two keys are stored under the module's own name (at
`third_party_settings.surface_examples_compliance.license` and
`.stewards`, so example 3's storage never has to know them), and it
rewords the title's label. Its rules and its methods are what you see
move. A rule is data, a condition and an effect, which the served
contract writes down as it is; a static method is a pure function of
what it watches, so the served contract states its answers ahead of
time where it can; a method that reads State is a runtime refiner, the
only kind an instance method may be:

- **The license lifts a ceiling on example 3's own key.** The alter's
  rule on `capacity`, `narrow('capacity')->when(Condition::absent('license'))`,
  reads the structure's `license` at the alter's mount. With no license
  it caps the capacity at 100 and says so under the field; with one it
  leaves the capacity alone, so the room's limit, from example 3's own
  method, is what applies. Both narrow the same key, the method first
  and the rule after, and each only narrows: the lower ceiling wins. The
  full schema states the rule exactly, as an `if` that the license holds
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
  surface_examples_compliance.closed_rooms '["riverside_east"]'
  --input-format=json`); then the room leaves the East room out and says
  "The East room is closed.", the submit refuses it, and the full schema
  still lists it under Riverside Hall, marked `live`. Reset to
  defaults opens every room again.

No form alter, no hook.

<!-- code: ../surface_examples_compliance/src/Shape/License.php -->

```php
#[Shape('event.license')]
final class License implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^\d{4}$/D', 'message' => t('A license number is four digits, such as 2048.')];
    $license = DataDefinition::create('string')
      ->setLabel(t('Event license'))
      ->setDescription(t('The four digits after EV- on the license, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($license, ['2048']);
    return $license;
  }

}
```

<!-- code: ../surface_examples_compliance/src/Structure/ComplianceStructure.php -->

```php
#[Structure('registration.compliance')]
final class ComplianceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('capacity', 'integer', required: TRUE);

    $inputs->add('license', License::class, t('Event license'));

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

<!-- code: ../surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php -->

```php
#[Alters(RegistrationStep3Resource::class)]
final class RegistrationComplianceAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * The State key listing the rooms that are closed, by room id.
   */
  public const CLOSED_ROOMS = 'surface_examples_compliance.closed_rooms';

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
    $inputs->attach('third_party_settings.surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
    $inputs->describe('title', label: $this->t('Public event title'));
    $inputs->describe('third_party_settings.surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
    // Without a license, no more than a hundred, whatever the room seats.
    // The license is the structure's, which this alter reads at its mount.
    // A license its own pattern refuses is no license: a rule never sees
    // an invalid sibling.
    $inputs->narrow('capacity')
      ->when(Condition::absent('license'))
      ->constrain('Range', ['max' => 100])
      ->because($this->t('Up to 100 without an event license.'));
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
the capacity its license lifts, on the form and in React, instead of
last. Only where it is drawn moves; the keys are still stored and posted
at `third_party_settings.surface_examples_compliance`, and the Tool
API and the config schema see nothing new. In the served contract the
fieldset is the structure's def, `registration.compliance`, referred to
from the mount, which says which of example 3's keys the port reads
(`x-surface.reads`); the license inside it is the shape's def,
`event.license`, written once and referred to from the key, which says
its own title beside the reference. Example 3 ships at Riverside Hall's main hall, which
seats 400. The capacity stops at 100, and its help text says why: "Up
to 100 without an event license." The
license asks for the four digits after EV- only. Type `2048` and the
capacity goes back to the owner's "Up to 400 for the Main hall."; type
`204` or `EV-2048` and the license is refused, in the structure's words,
under the field, and the capacity stops at 100 again: a rule never
sees a value its own key refuses, so a license in the wrong format is no
license. In a room that seats 100 or fewer the license changes nothing:
the room's own ceiling is the lower one, and the owner's help text
stands, since a rule says why only where it tightened. The help text says the format before
anything is typed, and the box shows
`2048` as its placeholder: a pattern explains nothing to a person, so
the Regex carries a message, and the panel's "Allows" column says that.
Under the stewards it says "At least 1 steward for 50 attendees."; set
the capacity to 150 and it asks for "At least 3 stewards for 150
attendees." In the panel, the capacity's row now depends on `room` and
on the license's path. An alter can add and tighten. It can never take
away what the owner declared.

### Files

What example 4 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 4 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples_compliance/surface_examples_compliance.info.yml`](../surface_examples_compliance/surface_examples_compliance.info.yml) | the module: a second one, which example 3 does not know | 9 |
| [`modules/surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php`](../surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php) | the alter: attaches the structure, rewords the title, caps the capacity without a license, and leaves closed rooms out | 32 |
| [`modules/surface_examples_compliance/src/Structure/ComplianceStructure.php`](../surface_examples_compliance/src/Structure/ComplianceStructure.php) | the structure it attaches: a license and the stewards, which read the capacity through a port | 19 |
| [`modules/surface_examples_compliance/src/Shape/License.php`](../surface_examples_compliance/src/Shape/License.php) | the license: a named shape, four digits | 12 |
| [`modules/surface_examples_compliance/config/schema/surface_examples_compliance.schema.yml`](../surface_examples_compliance/config/schema/surface_examples_compliance.schema.yml), entry `surface_examples.registration_step3.third_party.surface_examples_compliance` | the config schema of what it stores on example 3 | 18 |

## Example 5: same contract, no form

`/surface-examples/5`

Nothing to write. Example 3 names its storage and has an operation that needs
nothing, so the Tool API door derives a tool
from it, `surface:registration.step3:configure`. It takes what
example 3's form takes, refuses what it refuses, with the same messages,
and can rehearse a write without making it. Beside it is a read tool,
`surface:registration.step3:configure:read`, which takes nothing
and answers with what is stored, in the shape the write tool takes, so
a caller can see what is there before it changes it.

The script makes three calls to the tool and prints what each answered:

```bash
ddev exec php web/modules/custom/surface/scripts/examples-dry-run.php
```

```
1. Valid values
Success: Configure registration (step 3): the values were accepted and written.
  ok: true
  committed: true
  changed: true
  changes: ["/title","/venue","/room","/pricing","/ticket/price","/ticket/currency","/ticket/note"]
  values: {"title":"Autumn meetup","open":true,"venue":"harbour","room":"harbour_deck","capacity":50,"pricing":"paid","ticket":{"price":12.5,"currency":"EUR"},"contact":{"email":"events@example.com","phone":""}}
  violations: []
  notes: []
  stale: []

2. A capacity above the room's
Failed: The values were refused:
/capacity: This value should be between 1 and 30. This applies when Room is library_garden (Garden room).
  ok: false
  committed: false
  changed: false
  changes: []
  values: {"title":"Garden party","open":true,"venue":"library","room":"library_garden","capacity":45,"pricing":"free","ticket":{"note":null},"contact":{"email":"events@example.com","phone":""}}
  violations: [{"path":"/capacity","code":"not_in_range","message":"This value should be between 1 and 30. This applies when Room is library_garden (Garden room)."}]
  notes: []
  stale: []

3. A dry run
Success: Dry run of Configure registration (step 3): the values were accepted and prepared, and nothing was written.
  ok: true
  committed: false
  changed: false
  changes: ["/title","/venue","/room","/capacity","/pricing","/ticket/note","/ticket/price","/ticket/currency"]
  values: {"title":"Winter social","open":true,"venue":"riverside","room":"riverside_east","capacity":40,"pricing":"free","ticket":{"note":"Donations welcome"},"contact":{"email":"events@example.com","phone":""}}
  violations: []
  notes: []
  stale: []
```

`changes` is every stored value the call changed, or a dry run would:
writing back what the read tool returned answers none, and saves
nothing.

The same tool and the same three calls, from Drush, after a read of
what is stored:

```bash
drush tool:info surface:registration.step3:configure

drush tool:run surface:registration.step3:configure:read --uid=1

drush tool:run surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Autumn meetup","capacity":50,"venue":"harbour","room":"harbour_deck","pricing":"paid","ticket":{"price":12.5,"currency":"EUR"}}}'

drush tool:run surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Garden party","venue":"library","room":"library_garden","capacity":45,"pricing":"free"}}'

drush tool:run surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Winter social","capacity":40,"venue":"riverside","room":"riverside_east","pricing":"free","ticket":{"note":"Donations welcome"}}}' --input=dry_run=true
```

`--uid=1` because `tool:run` runs as anonymous unless told otherwise,
and the operation asks for "administer site configuration", for a read
as for a write. `tool:info` lists no defaults and requires no key:
configuring changes what exists, a key left out keeps its current value
(each key the resource requires says "Required; a value already stored
stands when left out."), and the description, after saying what the
operation sets, says the read tool, the second command, returns what
that value is. The dry run
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
needs no license and one steward, the default, so example 4's module
does not change the answers. Enable it and send the first call with a
capacity of 150, which the upper deck seats, and no license to see it
refuse.

### When a call is refused

The listing names what each key depends on, once, in its description,
by the path a caller sends it under, and says nothing about what is
stored. With example 4's module enabled, `tool:info` shows, among the
`values` input's properties:

```
|   └ room                               | string  | No       | No       |        | Room                      | riverside_main|riverside_east|library_reading|library_garden|harbour_auditorium|harbour_deck | Room: Depends on venue. Required; a…     |
|   └ capacity                           | integer | No       | No       |        | Capacity                  | minimum 1                                                                                    | Capacity: Depends on room, third_party…  |
|       └ stewards                       | integer | No       | No       |        | Stewards                  |                                                                                              | Stewards: Depends on capacity. Required… |
```

`tool:info` cuts a long description short; the capacity's reads in full
"Depends on room, third_party_settings.surface_examples_compliance.license.",
and the room's "Depends on venue. Required; a value already stored stands
when left out."

A refusal says what is allowed for the values sent, in one sentence per
key: what the key depends on, by its path, and what that holds, and for
a value not on its list, the list as it stands for them, each value to
send with its label beside it. The result carries the same refusal as
data, each violation's path, a stable code and its message, which the
agent reads instead of the text. An agent fixes each key in one retry. `ToolFeedbackTest` plays
that loop as a dry run through the Tool API's runner, the way MCP and
ECA call a tool: the agent sends the venue by name; then moves the
event to a room of another venue; then doubles the capacity to 240,
leaving the stewards at three. The three refusals:

```
Failed: The values were refused:
/venue: The value you selected is not a valid choice. Choose riverside (Riverside Hall), library (Old Library) or harbour (Harbour Centre).
Failed: The values were refused:
/room: The value you selected is not a valid choice. This applies when Venue is riverside (Riverside Hall). Choose riverside_main (Main hall) or riverside_east (East room).
Failed: The values were refused:
/third_party_settings/surface_examples_compliance/stewards: This value should be 5 or more. This applies when Capacity is 240.
```

Each time the agent reads the fix from the refusal alone and the next
call is accepted. The Tool API holds `values` to its shape before the
tool runs (types, the keys it requires, its nesting) and the pipeline
holds it to every rule, against the definitions the values sent narrow,
so a value outside the listing's list is answered with the list that
applies rather than refused with none
(why: [decision](../../docs/decisions.md#a-refusal-names-what-it-depends-on)).

### Files

Example 5 adds no file of its own: it is made from the files of
example 3.

## In React

Examples 1 to 3 again, with no Form API: the same operation served as a
JSON contract by the base module's HTTP door and rendered by a React
app, from the experimental example `surface_examples_react`.

```bash
drush pm:install surface_examples_react
```

The landing page then links each example "In React":
`/surface-react/registration.step1/configure`,
`/surface-react/registration.step2/configure` and
`/surface-react/registration.step3/configure`. Each is the same resource
as the form beside it, read from `/surface-api/registration.stepN`:
JSON Schema with the labels as `oneOf` titles, the bounds as `minimum`
and `maximum`, the ticket variants as a conditional on the pricing, and an
`x-surface` note on every key saying what the form knows about it.

Change the venue on example 2: the app narrows the room and the
capacity itself from the full schema, as the form's AJAX rebuild does,
and asks the server (`POST /surface-api/registration.step2/schema`)
only for a rule the schema cannot state; the room comes back on its
empty option, standing for the stored room, which stays on the server.
Press Submit to save: the answers go to
`PATCH /surface-api/registration.step2`, which writes what the form's
Save writes, refuses what it refuses (a 422, each refusal beside its
field and in a summary, with nothing written), and answers with the
contract as now stored, which the app re-renders from. If someone saved
the example since the page loaded, the write is refused (a 412: the
`If-Match` the app sends no longer matches) rather than overwriting
them. The collapsed Contract panel under the form is the
contract as it stands.
[The served contract](../../docs/served-contract.md) has the format.

## Example 6: every door

The same contract through every door. The decoupled page is the one
above, [In React](#in-react): the contract served as JSON and drawn by a
React app. ECA and an AI agent, through the tool of example 5, are yours
to show on your own site. The landing page links `/surface-api/openapi.json`, every example's paths
described for any OpenAPI client, and `/surface-api/docs`, Swagger UI
over it, to try each call with your session.

### Files

Example 6 adds no file of its own: it is made from the files of
example 3.

## One small resource per concept

Examples 7 to 12 leave the registration behind. Each is a small
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
#[Operation('configure',
  label: new TranslatableMarkup('Configure the bulletin'),
  description: new TranslatableMarkup('Sets how often the event bulletin goes out, the day it is sent and how many items an issue holds.'),
  permission: 'administer site configuration',
)]
final class BulletinResource implements ResourceInterface {

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
      ->because(t('Up to 5 in a weekly issue.'));
  }

}
```

Nothing is run to learn either rule. The full schema states each as
written, an `if`/`then` the frame lists in `x-surface.rules`, and the
send day as `false` under the monthly conditional and `required` under
the other: the panel shows the rules beside those three conditionals.
Choose
Monthly and the send day is gone from the form, over AJAX; the stored
Friday is kept for the day the bulletin goes weekly again: the third
call's values leave it out, as every answer leaves out a key that does
not apply, and storage still holds it. Choose Weekly and the items stop at five,
said under the field: "Up to 5 in a weekly issue."

```bash
drush tool:run surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"monthly","send_day":"monday"}}' --input=dry_run=true

drush tool:run surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"weekly","items":8}}' --input=dry_run=true

drush tool:run surface:event.bulletin:configure --uid=1 --input='{"values":{"frequency":"monthly","items":8}}' --input=dry_run=true
```

```
1. A send day for a monthly bulletin
Failed: The values were refused:
/send_day: Send day does not apply when Frequency is monthly (Monthly).

2. Eight items in a weekly issue
Failed: The values were refused:
/items: This value should be between 1 and 5. This applies when Frequency is weekly (Weekly).

3. Eight items in a monthly issue, and no send day
Success: Dry run of Configure the bulletin: the values were accepted and prepared, and nothing was written.
  values: {"frequency":"monthly","items":8}
```

### Files

What example 7 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 7 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/BulletinResource.php`](src/Resource/BulletinResource.php) | the resource: three keys and two declared rules | 29 |
| [`modules/surface_examples/src/Storage/BulletinStorage.php`](src/Storage/BulletinStorage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step7` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.bulletin` | the config schema, generated from the class | 20 |
| [`modules/surface_examples/config/install/surface_examples.bulletin.yml`](config/install/surface_examples.bulletin.yml) | the settings the module ships with | 3 |

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
      ->because(t('Required while this person is listed publicly.'));
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
        'message' => t('Write the number in digits, such as +1 555 0100.'),
      ]);
    DefinitionMetadata::setExamples($phone, ['+1 555 0100']);
    return $phone;
  }

}
```

The press page wires the port to `public`, and mixes the review in, so
the review's two keys are the page's own: stored flat beside `public`,
drawn among its keys, and named in a refusal as `reviewed_by`, not under
a parent ([Mixins](../../docs/resources.md#mixins)). The review date asks
for a date input, `setSetting('widget', 'date')`, which both documents
state as `format: date`.

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
      ->setSetting('widget', 'date')
      ->addConstraint('Regex', [
        'pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D',
        'message' => t('Write the date as YYYY-MM-DD, such as 2026-10-19.'),
      ]);

    $inputs->add('reviewed_by', 'string', t('Reviewed by'));

    $inputs->narrow('reviewed_by')
      ->when(Condition::present('reviewed_on'))
      ->require()
      ->because(t('Required once a review date is set.'));
  }

}
```

<!-- code: src/Resource/PressResource.php -->

```php
#[Resource('event.press', storage: PressStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the press page'),
  description: new TranslatableMarkup('Sets the press page: whether it is shown, the press contact, and who last reviewed it and when.'),
  permission: 'administer site configuration',
)]
final class PressResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('public', 'boolean', t('Show on the press page'), default: TRUE);

    $inputs->attach('contact', PersonStructure::class, reads: ['listed' => 'public'])
      ->setLabel(t('Press contact'));

    $inputs->mixIn(ReviewStructure::class);
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
#[Operation('configure',
  label: new TranslatableMarkup('Configure the information desk'),
  description: new TranslatableMarkup('Sets the information desk: its phone, whether it is listed in the printed program, its lead and up to four volunteers.'),
  permission: 'administer site configuration',
)]
final class InfoDeskResource implements ResourceInterface {

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
drush tool:run surface:event.press:configure --uid=1 --input='{"values":{"public":true,"contact":{"name":"Robin Lee","email":null}}}' --input=dry_run=true

drush tool:run surface:event.press:configure --uid=1 --input='{"values":{"public":false,"contact":{"name":"Robin Lee","email":null}}}' --input=dry_run=true

drush tool:run surface:event.press:configure --uid=1 --input='{"values":{"reviewed_on":"2026-10-19","reviewed_by":null}}' --input=dry_run=true
```

```
1. A public contact with the email cleared
Failed: The values were refused:
/contact/email: Email is required when Show on the press page is on.

2. The same contact, not shown on the press page
Success: Dry run of Configure the press page: the values were accepted and prepared, and nothing was written.
  values: {"public":false,"contact":{"name":"Robin Lee","email":null,"phone":"+1 555 0100"},"reviewed_on":"2026-10-01","reviewed_by":"Sam Ortiz"}

3. A review date with no reviewer
Failed: The values were refused:
/reviewed_by: Reviewed by is required when Reviewed on is set.
```

```bash
drush tool:run surface:event.desk:configure --uid=1 --input='{"values":{"phone":"555-0199"}}' --input=dry_run=true
```

```
1. A desk phone the shape refuses
Failed: The values were refused:
/phone: Write the number in digits, such as +1 555 0100.
```

### Files

What example 8 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 8 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Structure/PersonStructure.php`](src/Structure/PersonStructure.php) | the person: three keys, and a rule that reads a port, listed | 14 |
| [`modules/surface_examples/src/Shape/PhoneNumber.php`](src/Shape/PhoneNumber.php) | the phone: a named shape | 14 |
| [`modules/surface_examples/src/Structure/ReviewStructure.php`](src/Structure/ReviewStructure.php) | the review: two keys and a rule, mixed into the press page | 17 |
| [`modules/surface_examples/src/Resource/PressResource.php`](src/Resource/PressResource.php) | the press page: one key, the person attached, the review mixed in | 14 |
| [`modules/surface_examples/src/Storage/PressStorage.php`](src/Storage/PressStorage.php) | its storage: names the config object | 3 |
| [`modules/surface_examples/src/Resource/InfoDeskResource.php`](src/Resource/InfoDeskResource.php) | the information desk: the phone, the person attached, and a collection of up to four | 17 |
| [`modules/surface_examples/src/Storage/InfoDeskStorage.php`](src/Storage/InfoDeskStorage.php) | its storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step8` | the press page's route | 8 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step8_desk` | the information desk's route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.press` | the press page's config schema, generated from the class | 39 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.desk` | the information desk's config schema, generated from the class | 59 |
| [`modules/surface_examples/config/install/surface_examples.press.yml`](config/install/surface_examples.press.yml) | the press page's shipped settings | 7 |
| [`modules/surface_examples/config/install/surface_examples.desk.yml`](config/install/surface_examples.desk.yml) | the information desk's shipped settings | 11 |

## Example 9: keys that come from the site

`/surface-examples/9`

One checkbox and one set of limits per ticket type the site sells. The
class cannot list the ticket types: the site's configuration does, in
`surface_examples.ticket_types`, and a provider reads it
([Keys that come from the site](../../docs/resources.md#keys-that-come-from-the-site)).

<!-- code: src/Resource/TicketSalesResource.php -->

```php
#[Resource('event.sales', storage: TicketSalesStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure ticket sales'),
  description: new TranslatableMarkup('Sets which ticket types are on sale, and for each how many tickets one order may hold and whether a waiting list opens when it sells out.'),
  permission: 'administer site configuration',
)]
final class TicketSalesResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addEach('on_sale', 'boolean', from: TicketTypes::class, default: FALSE)
      ->setLabel(t('On sale'))
      ->setDescription(t('Which ticket types can be bought.'));

    $inputs->attachEach('limits', TicketLimitStructure::class, from: TicketTypes::class, reads: ['selling' => 'on_sale'])
      ->setLabel(t('Limits'));
  }

}
```

Each type's limits read whether that type is on sale. The structure
declares a port, `selling`, and rules its keys out while it is off;
`reads: ['selling' => 'on_sale']` wires the port of the entry `<type>`
to `on_sale.<type>`, the entry of the same id in the sibling map
([Ports on an entry](../../docs/resources.md#ports-on-an-entry)).

<!-- code: src/Structure/TicketLimitStructure.php -->

```php
#[Structure('event.ticket_limit')]
final class TicketLimitStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('per_order', 'integer', t('Per order'), default: 4)
      ->addConstraint('Range', ['min' => 1, 'max' => 10]);

    $inputs->add('waiting_list', 'boolean', t('Waiting list when sold out'), default: FALSE);

    $inputs->reads('selling', 'boolean');

    $inputs->narrow('per_order')->when(Condition::equals('selling', FALSE))->doesNotApply();

    $inputs->narrow('waiting_list')->when(Condition::equals('selling', FALSE))->doesNotApply();
  }

}
```

Untick a type and its limits go: the form drops their elements over
AJAX, and the page drops their fields with no request. Tick it again and
they return as stored, since storage keeps what a key holds while it does
not apply. The full schema states the rule once per type, on the
resource's frame, an `if` on the type's checkbox and a `then` reaching
into its limits:

```json
{
    "if": {
        "properties": {
            "on_sale": {
                "type": "object",
                "properties": {
                    "student": {
                        "const": false
                    }
                },
                "required": [
                    "student"
                ]
            }
        },
        "required": [
            "on_sale"
        ]
    },
    "then": {
        "properties": {
            "limits": {
                "type": "object",
                "properties": {
                    "student": {
                        "type": "object",
                        "properties": {
                            "per_order": false
                        }
                    }
                }
            }
        }
    }
}
```

<!-- code: src/Keys/TicketTypes.php -->

```php
final class TicketTypes implements SiteListInterface, ContainerInjectionInterface {

  /**
   * The configuration object the ticket types are listed in.
   */
  public const CONFIG = 'surface_examples.ticket_types';

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
  public function list(): SiteList {
    $config = $this->configFactory->get(self::CONFIG);
    $keys = (new SiteList())->addCacheableDependency($config);
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
drush config:set surface_examples.ticket_types types.vip '{label: VIP, locked: false}' --input-format=yaml
drush config:delete surface_examples.ticket_types types.vip
```

The form has a VIP checkbox and VIP limits at once; the second command
takes them away again. The speaker type is locked in the configuration:
its checkbox is drawn fixed, `readOnly` in both documents, and a value a
tool sends for it is not taken, which the answer says in a note. It is
not on sale as shipped, so its limits do not apply and are not drawn;
put on sale, they are drawn fixed too. The panel shows the keys the provider answered, its cache tag,
one type's rules as the full schema states them, and the locked type in
both documents. A limit sent for a type off sale is refused, naming the
checkbox it depends on.

```bash
drush tool:run surface:event.sales:configure --uid=1 --input='{"values":{"on_sale":{"standard":true,"speaker":true}}}' --input=dry_run=true

drush tool:run surface:event.sales:configure --uid=1 --input='{"values":{"on_sale":{"student":false},"limits":{"student":{"per_order":2}}}}' --input=dry_run=true
```

```
1. Speaker tickets put on sale
Success: Dry run of Configure ticket sales: the values were accepted and prepared, and nothing was written.
/on_sale/speaker: Speaker is locked and kept as stored; the value sent was not taken.
  values: {"on_sale":{"standard":true,"student":true,"speaker":false},"limits":{"standard":{"per_order":4,"waiting_list":false},"student":{"per_order":1,"waiting_list":true},"speaker":[]}}

2. Student tickets taken off sale, with a new limit for them
Failed: The values were refused:
/limits/student/per_order: Per order does not apply when On sale: Student is off.
```

### Files

What example 9 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 9 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/TicketSalesResource.php`](src/Resource/TicketSalesResource.php) | the resource: a checkbox and a set of limits per ticket type | 15 |
| [`modules/surface_examples/src/Structure/TicketLimitStructure.php`](src/Structure/TicketLimitStructure.php) | one type's limits: two keys that apply while it is on sale, read through a port | 11 |
| [`modules/surface_examples/src/Keys/TicketTypes.php`](src/Keys/TicketTypes.php) | the provider: lists the ticket types from the site's configuration | 17 |
| [`modules/surface_examples/src/Storage/TicketSalesStorage.php`](src/Storage/TicketSalesStorage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step9` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.sales` | the config schema, generated from the class | 30 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.ticket_types` | the ticket types' config schema, written by hand | 17 |
| [`modules/surface_examples/config/install/surface_examples.sales.yml`](config/install/surface_examples.sales.yml) | the settings the module ships with | 14 |
| [`modules/surface_examples/config/install/surface_examples.ticket_types.yml`](config/install/surface_examples.ticket_types.yml) | the ticket types the site has, one of them locked | 10 |

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
  "live"`, and the schema for these answers, the form and the
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
#[Operation('configure',
  label: new TranslatableMarkup('Configure the program'),
  description: new TranslatableMarkup('Sets the program: its headline, how many sessions it has, whether it is published, and its fees.'),
  permission: 'administer site configuration',
)]
#[Operation('check',
  label: new TranslatableMarkup('Check a program'),
  description: new TranslatableMarkup('Checks a program against every rule the program configuration applies, and saves nothing.'),
  permission: 'administer site configuration',
  readOnly: TRUE,
)]
final class ProgramResource implements ResourceInterface {

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
(why: [decision](../../docs/decisions.md#an-operation-states-how-it-behaves)).
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
drush tool:run surface:event.program:check --uid=1 --input='{"values":{"headline":"Three days of talks","sessions":12}}'

drush tool:run surface:event.program:check --uid=1 --input='{"values":{"headline":"Three days of talks","sessions":40}}'
```

```
1. A program checked
Success: Check a program changes nothing: the values were accepted and prepared, and nothing was written.
  values: {"headline":"Three days of talks","sessions":12,"published":false,"fees":{"standard":20.0,"concession":10.0}}

2. A program with too many sessions, checked
Failed: The values were refused:
/sessions: This value should be between 1 and 30.
```

### Another module answers too

```bash
drush pm:install surface_examples_workflow
```

then reload example 10. A second module, which example 10 does not
know, answers access for the program and its fees with two alters,
each implementing `OperationAccessInterface`, the access class's
interface, which `#[Alters]` on the class makes an alter's answer. Its
answers are asked after
the program's own (the operation's permission, then the fees' access
class), and the stricter answer stands, so it can lock or leave out
and can never open what the fees' own access locked
([An alter's answer](../../docs/resources.md#an-alters-answer)). Each
answer's kind is read from its cacheability, as any answer's is:

- **Static**, for an account without "see event fees", the module's
  permission: the fees are left out, read from the account's
  permissions alone, so the full schema and the tool listing for that
  account have no fees at all, where before they were locked.
- **Runtime**, once the module's workflow state, which it keeps in
  State, is published: the program is locked whole in the operation
  that changes it, its headline and its fees with every other key, and
  the fees are fixed wherever else they appear. Only what is stored
  says so, so the full schema marks every key of the program `access:
  "live"`, and a call is refused by name.

<!-- code: ../surface_examples_workflow/src/Alter/FeesWorkflowAlter.php -->

```php
#[Alters(FeesStructure::class)]
final class FeesWorkflowAlter implements OperationAccessInterface {

  /**
   * The permission that may see the fees.
   */
  public const PERMISSION = 'see event fees';

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where the workflow state is kept.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if (!$account->hasPermission(self::PERMISSION)) {
      // Static: the account alone.
      return AccessAnswer::omitted('Only an account that may see event fees sees them.')->addCacheableDependency($read);
    }
    // Runtime: a stored value, read now.
    $read->setCacheMaxAge(0);
    if ($this->state->get(ProgramWorkflowAlter::STATE) === ProgramWorkflowAlter::PUBLISHED) {
      return AccessAnswer::locked('The fees are fixed once the program is published in its workflow.')->addCacheableDependency($read);
    }
    return AccessAnswer::open()->addCacheableDependency($read);
  }

}
```

<!-- code: ../surface_examples_workflow/src/Alter/ProgramWorkflowAlter.php -->

```php
#[Alters(ProgramResource::class, operations: ['configure'])]
final class ProgramWorkflowAlter implements OperationAccessInterface {

  /**
   * The State key holding the program's workflow state.
   */
  public const STATE = 'surface_examples_workflow.state';

  /**
   * The workflow state that fixes the program.
   */
  public const PUBLISHED = 'published';

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where the workflow state is kept.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $answer = $this->state->get(self::STATE) === self::PUBLISHED
      ? AccessAnswer::locked('The program is published in its workflow, so it is fixed.')
      : AccessAnswer::open();
    // Runtime: a stored value, read now.
    return $answer->mergeCacheMaxAge(0);
  }

}
```

The panel above the form then says what the module answers, and its
last call is made with the workflow state published, set for the call
and put back after it:

```bash
drush state:set surface_examples_workflow.state published
drush tool:run surface:event.program:configure --uid=1 --input='{"values":{"headline":"Four days of talks"}}' --input=dry_run=true
drush state:delete surface_examples_workflow.state
```

```
3. A new headline, once the workflow module has published the program
Failed: The values were refused:
This may be seen and not changed, so nothing was written: The program is published in its workflow, so it is fixed.
```

Neither the program nor the fees changed, and Reset to defaults clears
the workflow state.

### Files

What example 10 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 10 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/ProgramResource.php`](src/Resource/ProgramResource.php) | the resource: four keys, and two operations, one of which changes nothing | 23 |
| [`modules/surface_examples/src/Structure/FeesStructure.php`](src/Structure/FeesStructure.php) | the fees: two keys, naming their access class | 9 |
| [`modules/surface_examples/src/Access/FeesAccess.php`](src/Access/FeesAccess.php) | the access class: locks the fees by permission, then once the program is published | 18 |
| [`modules/surface_examples/src/Storage/ProgramStorage.php`](src/Storage/ProgramStorage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step10` | the route that configures | 8 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step10_check` | the route that checks | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.program` | the config schema, generated from the class | 39 |
| [`modules/surface_examples/config/install/surface_examples.program.yml`](config/install/surface_examples.program.yml) | the settings the module ships with | 6 |
| [`modules/surface_examples_workflow/surface_examples_workflow.info.yml`](../surface_examples_workflow/surface_examples_workflow.info.yml) | the second module, which answers for the program and its fees | 9 |
| [`modules/surface_examples_workflow/surface_examples_workflow.permissions.yml`](../surface_examples_workflow/surface_examples_workflow.permissions.yml) | its permission, to see the fees | 3 |
| [`modules/surface_examples_workflow/src/Alter/FeesWorkflowAlter.php`](../surface_examples_workflow/src/Alter/FeesWorkflowAlter.php) | an alter answering for the fees: left out without the permission, locked once published | 18 |
| [`modules/surface_examples_workflow/src/Alter/ProgramWorkflowAlter.php`](../surface_examples_workflow/src/Alter/ProgramWorkflowAlter.php) | an alter answering for the program: locked whole once published | 14 |

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
#[Operation('configure',
  label: new TranslatableMarkup('Configure the doors'),
  description: new TranslatableMarkup('Sets when the doors open, the badges checked at the door and the notes for the door team.'),
  permission: 'administer site configuration',
)]
final class DoorsResource implements ResourceInterface {

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
        'message' => t('Write the time as HH:MM, such as 18:30.'),
      ]);

    $day = DataDefinition::create('string')
      ->addConstraint('LabeledChoice', [
        'choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')],
      ]);
    $inputs->addList('days', $day, t('Days'), default: ['fri', 'sat'])
      ->setRequired(TRUE);

    $inputs->add('badges', 'string', t('Badges'), default: 'printed')
      ->setSetting('widget', 'radios')
      ->addConstraint('LabeledChoice', [
        'choices' => ['printed' => t('Printed at the door'), 'digital' => t('On the phone'), 'none' => t('No badges')],
      ]);

    $inputs->add('notes', 'string', t('Notes for the door team'))
      ->setSetting('multiline', TRUE);

    $inputs->addList('greeters', 'string', t('Greeters'), default: []);
  }

}
```

The config schema is written from the resource, so it is fully
validatable ([A schema generated from the
resource](../../docs/storage.md#a-schema-generated-from-the-resource)):
`FullyValidatable` at its root, every key stored, and `nullable: true`
on the notes alone, the one single value with no default. So the storage
knows the notes may hold nothing and the badges may not: the badges are always held, their radios offer no
"- None -", and both schemas leave `null` out of their type.

What a call sends for a key means one of three things
([Absent, null, or a value](../../docs/semantics.md#absent-null-or-a-value)):
left out, the key keeps what is stored; `null`, it is cleared; a value
replaces it. The four dry runs show each, and the fourth what null does
to a key the storage always holds: it is refused, naming the default.
The panel shows them, and a table of each key's widget and type.

```bash
drush tool:run surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"]}}' --input=dry_run=true

drush tool:run surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"notes":null}}' --input=dry_run=true

drush tool:run surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"notes":"Doors close at ten."}}' --input=dry_run=true

drush tool:run surface:event.doors:configure --uid=1 --input='{"values":{"opens":"19:00","days":["fri"],"badges":null}}' --input=dry_run=true
```

```
1. The notes left out: kept
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  values: {"opens":"19:00","days":["fri"],"badges":"printed","notes":"Hand out the maps.","greeters":["Ana","Ben"]}

2. The notes sent null: cleared
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  values: {"opens":"19:00","days":["fri"],"badges":"printed","notes":null,"greeters":["Ana","Ben"]}

3. The notes sent a value: replaced
Success: Dry run of Configure the doors: the values were accepted and prepared, and nothing was written.
  values: {"opens":"19:00","days":["fri"],"badges":"printed","notes":"Doors close at ten.","greeters":["Ana","Ben"]}

4. The badges sent null: refused, since they are always held
Failed: The values were refused:
/badges: Badges cannot be cleared: it always holds a value, printed (Printed at the door) by default. Leave it out to keep what it holds.
```

### Files

What example 11 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 11 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/DoorsResource.php`](src/Resource/DoorsResource.php) | the resource: five keys, each drawn by a stock widget | 32 |
| [`modules/surface_examples/src/Storage/DoorsStorage.php`](src/Storage/DoorsStorage.php) | the storage: names the config object and the keys it always holds | 4 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step11` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.doors` | the config schema, fully validatable, generated from the class | 31 |
| [`modules/surface_examples/config/install/surface_examples.doors.yml`](config/install/surface_examples.doors.yml) | the settings the module ships with | 9 |

## Example 12: a schema read into a structure

`/surface-examples/12`

A component chosen from the single-directory components the site has,
and below it the props that component takes and its slots. The class
lists none of them: each component says what props it takes, in the JSON
Schema of its `*.component.yml`, and a provider hands each schema to the
reader, which writes it into a structure
([Reading a schema](../../docs/reading-a-schema.md)); its slots are in
the same file, and a second provider writes them as a schema too. The
components are variants at `props` and again at `slots`, which the
component chooses between, one per component the site has
([Variants from the site](../../docs/resources.md#variants-from-the-site)).

<!-- code: src/Resource/SpotlightResource.php -->

```php
#[Resource('event.spotlight', storage: SpotlightStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the spotlight'),
  description: new TranslatableMarkup('Sets the component the event home page spotlights, chosen from the components the site has, and the props it is drawn with.'),
  permission: 'administer site configuration',
)]
final class SpotlightResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('component', 'string', t('Component'), default: 'surface_examples:card')
      ->setRequired(TRUE);

    $inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
      ->setLabel(t('Props'));

    $inputs->attachVariants('slots', by: 'component', from: ComponentSlots::class)
      ->setLabel(t('Slots'));
  }

}
```

The provider lists every component the site offers, the four this
module ships among them, each by its id and its name, and answers a
component's props, as the component declares them, only when a door
reads that component's variant (`entrySchema()`). A prop typed by a PHP class takes a value only Twig code
passes, which nothing stores: that type is left out, as the reader would
refuse it. It is the main module's, not this module's, since example 13's
block reads the components through it too.

<!-- code: ../../src/Variants/ComponentProps.php -->

```php
final class ComponentProps implements EntrySchemasInterface, ContainerInjectionInterface {

  /**
   * The JSON Schema types; a component may also name a PHP class.
   */
  protected const JSON_TYPES = ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'];

  /**
   * Constructs the provider.
   *
   * @param \Drupal\surface\Variants\ComponentsOffered $components
   *   The components the site offers.
   */
  public function __construct(
    protected readonly ComponentsOffered $components,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(ComponentsOffered::create($container));
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $variants = (new SiteList())->addCacheTags(ComponentsOffered::CACHE_TAGS);
    foreach ($this->components->offered() as $id => $component) {
      $variants->add((string) $id, (string) ($component['name'] ?? $id));
    }
    return $variants;
  }

  /**
   * {@inheritdoc}
   */
  public function entrySchema(string $id): array {
    return self::props($this->components->all()[$id]['props'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function entryDefs(): array {
    return [];
  }

  /**
   * Writes a component's props as the JSON Schema it is checked with.
   *
   * Closed, as core reads them. A prop typed by a PHP class takes a value
   * only Twig code passes, which nothing stores: that type is left out,
   * and a prop with no other type with it.
   *
   * @param array $props
   *   The props as the component declares them.
   *
   * @return array
   *   The schema.
   */
  public static function props(array $props): array {
    $props += ['type' => 'object', 'properties' => []];
    foreach ($props['properties'] as $name => $prop) {
      $types = (array) ($prop['type'] ?? []);
      $json = array_values(array_intersect($types, self::JSON_TYPES));
      if (count($json) === count($types)) {
        continue;
      }
      if ($json === []) {
        unset($props['properties'][$name]);
        $props['required'] = array_values(array_diff($props['required'] ?? [], [$name]));
        continue;
      }
      $props['properties'][$name]['type'] = $json;
    }
    $props['additionalProperties'] = FALSE;
    return $props;
  }

}
```

Its sibling, [`ComponentSlots`](../../src/Variants/ComponentSlots.php),
lists the same components, each with the object of its slots: one key
per slot its metadata declares, and none for a component with no slot.
Each slot holds one of three things, chosen by a `kind` key: `text`, a
plain string; `component`, one nested component, its `component`, its
`props` and its own `slots`, the same keys again; or `components`, a list
of those. What a slot's metadata expects narrows it, as a refusal: the
components it names under `expected`, by id or by a tag a component lists
under `tags`, and no component for an empty list; `minItems` and
`maxItems` bound the list, and a `maxItems` of 1 leaves no list. Slots
nest three deep, and a slot at that depth holds text alone
(why: [decision](../../docs/decisions.md#a-slot-holds-text-a-component-or-components)).

The module ships four components, so the page holds something whatever
the site's themes provide: a card, a hero, a call to action and a
section, with props of every type, lists of values, bounds, required
props, a nested object and an array between them, and a slot each but
the call to action. The card, as it declares itself:

<!-- component: components/card/card.component.yml -->

```yaml
$schema: https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json
name: Card
status: stable
description: 'A heading, a line of text and a link. Example 12 reads its props into a structure, and its slot beside them.'
tags:
  - block
props:
  type: object
  required:
    - heading
  properties:
    heading:
      type: string
      title: Heading
      maxLength: 80
    tone:
      type: string
      title: Tone
      enum:
        - plain
        - highlight
      meta:enum:
        plain: Plain
        highlight: Highlighted
      default: plain
    link:
      type: object
      title: Link
      properties:
        url:
          type: string
          format: uri
          title: Address
        label:
          type: ['string', 'null']
          title: Link text
slots:
  body:
    title: Text
    description: 'A line under the heading.'
    # No component: the slot holds text alone.
    expected: []
```

Read, each prop is a key. The heading is a required string of up to 80
characters, the tone a choice of Plain and Highlighted, labeled by `meta:enum` and starting from plain,
and the link a map whose address is a URI. The page shows the card's
schema beside the def it became in the full schema, what the reader
noted (the link is an object that leaves `additionalProperties` out,
read closed), and the variants as the full schema states them, one
conditional per component:

```json
"props": {
  "title": "Props",
  "anyOf": [..., {"$ref": "#/$defs/event.spotlight.props.surface_examples:card"}, ...],
  "x-surface": {"dependsOn": ["component"], "by": "component",
                "variants": {..., "surface_examples:card": "#/$defs/event.spotlight.props.surface_examples:card", ...}}
},
...
"allOf": [
  ...,
  {"if": {"properties": {"component": {"const": "surface_examples:card"}}, "required": ["component"]},
   "then": {"properties": {"props": {"title": "Props", "$ref": "#/$defs/event.spotlight.props.surface_examples:card"}}}},
  ...
]
```

The card's text is its `body` slot, which expects no component, so it
holds text alone. The hero's `actions` slot expects the call to action,
two at most, and the section's `content` slot any component tagged
`block`, which the card and the hero are:

```yaml
# hero.component.yml
slots:
  actions:
    title: Actions
    description: 'Up to two buttons under the title.'
    expected:
      - 'surface_examples:call_to_action'
    maxItems: 2

# section.component.yml
slots:
  content:
    title: Content
    description: 'The components the section holds.'
    expected:
      - block
```

The full schema states the slots as it states the props, one conditional
per component, each slot a def of its own: the hero's actions offer
text, one component or a list of two at most, and the component they
hold offers the call to action alone.

```json
"component.slot.surface_examples:hero.actions.1": {
  "properties": {
    "kind": {"title": "Holds", "type": "string", "oneOf": [{"const": "text", ...}, {"const": "component", ...}, {"const": "components", ...}], "default": "text"},
    "text": {"title": "Text", "type": ["string", "null"]},
    "component": {"title": "Component", "$ref": "#/$defs/component.nested.surface_examples:call_to_action.1"},
    "components": {"title": "Components", "type": "array", "maxItems": 2,
                   "items": {"$ref": "#/$defs/component.nested.surface_examples:call_to_action.1"}}
  },
  "allOf": [{"if": {"not": {"properties": {"kind": {"const": "text"}}, "required": ["kind"]}},
             "then": {"properties": {"text": false}}}, ...]
},
"component.nested.surface_examples:call_to_action.1": {
  "properties": {
    "component": {"title": "Component", "type": "string", "oneOf": [{"const": "surface_examples:call_to_action", "title": "Call to action"}]},
    "props": {"anyOf": [{"$ref": "#/$defs/component.props.surface_examples:call_to_action"}, ...], "x-surface": {"by": "component", ...}},
    "slots": {"anyOf": [{"$ref": "#/$defs/component.slots.surface_examples:call_to_action.2"}, ...], "x-surface": {"by": "component", ...}}
  },
  "required": ["component"]
}
```

Choose another component and the props below it are that component's:
the form rebuilds them over AJAX, and the React page draws them with no
request, since the full schema states every component's def. The card's
def read back is the card again, byte for byte, as every document of
these examples a runtime refiner does not narrow reads back to itself
([the round trip](../../docs/reading-a-schema.md#the-round-trip)).

That holds while the site has at most 20 components, the site list
limit, `surface.settings:site_list_limit`. This module's four are
under it, and so is every site its tests run on, so this page and its
tests show every component spelled out. A site whose themes provide
more is past it, as the development site this page was written on is,
with 312: the full schema then lists each component by id and label,
and states the props and slots open, marked `deferred`, with no def and
no conditional per component and a `fetch` template in each `deferred`;
the full schema fetched with a component chosen,
`GET /surface-api/event.spotlight/schema?choose=/props={component}`,
states that component's defs exactly, and so does the schema for these
answers. The React page fetches it once per component chosen
(why: [decision](../../docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
The full schema went from 3 MB and 2,465 defs to 18 KB there
(why: [decision](../../docs/decisions.md#a-budget-for-what-the-site-decides)).

The tool listing does not wait for the limit. On every site it offers
every component under `component`, states the props and slots with the
keys of the component stored and no other, and names that fetch and the
narrowing tool for any other component. A tool's definition is sent with
every request that offers the tool, and the Tool API cannot say which
keys the component chooses, so spelled out, the listing would hold every
component's keys in one map with none required: 1.6 MB with the 312
components, 9 KB as it is
(why: [decision](../../docs/decisions.md#a-budget-for-what-the-site-decides)).
The props in the full schema, past the limit:

```json
"props": {"title": "Props", "type": ["object", "null"],
          "x-surface": {"dependsOn": ["component"], "by": "component",
                        "deferred": {"fetch": "https://example.com/surface-api/event.spotlight/schema?choose=/props={value}"}}}
```

The form still swaps the props over AJAX, and the React page fetches
`GET /surface-api/event.spotlight/schema?choose=/props={component}`
when the component changes (then the slots, joined), once per
component, and narrows from it. An agent changing a prop of the
component stored writes it in one call; for another component it reads
in the write tool's listing both ways to learn the keys: that fetch, or
the tool it asks with its values, in two calls, that one and then the
write:

```bash
drush tool:run surface:event.spotlight:configure:schema --uid=1 --input='{"values":{"component":"surface_examples:call_to_action"}}'
drush tool:run surface:event.spotlight:configure --uid=1 --input='{"values":{"component":"surface_examples:call_to_action","props":{"text":"Buy a ticket","url":"https://example.com/tickets"}}}' --input=dry_run=true
```

The narrowing tool is there on every site, since the tool listing never
states every component's keys; the page's panel says which state the
documents are in, and why.

A site can offer fewer components than it has:
`surface.settings:components_offered` lists component ids
(`provider:name`) and providers (an entry with no colon, every component
it provides), and both providers offer only those; empty, the shipped
value, offers every one. It shortens the list, where the site list limit
only moves a long one out of the documents
(why: [decision](../../docs/decisions.md#a-budget-for-what-the-site-decides)).
To offer this module's four alone:

```bash
ddev drush config:set surface.settings components_offered.0 surface_examples
```

A stored component the setting leaves out is not lost: the chooser stands
for it on its empty option ("- Keep surface_examples:card (no longer
offered) -"), a save that leaves the chooser alone keeps the component,
its props and its slots as stored, and every door reads them back as
stored. Its props are not drawn until it is offered again, since the
variants are the components offered.

The config object holds the component's id, its props and its slots.
Its config schema is written from the resource, and the props and the
slots are `type: ignore` there: which components a site has is the
site's, so no schema written once can name their props or slots, and the
variant the component chooses holds them to its keys before anything is
written. The resource, its two providers and its storage are the code;
the components' schemas and metadata are none.

A tool that sends one component's props for another is refused, naming
the component they belong to; one that sends a hero darker than its
schema allows is refused by the bound the schema states.

```bash
drush tool:run surface:event.spotlight:configure --uid=1 --input='{"values":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six","align":"start"}}}' --input=dry_run=true

drush tool:run surface:event.spotlight:configure --uid=1 --input='{"values":{"component":"surface_examples:card","props":{"align":"start","overlay":60}}}' --input=dry_run=true

drush tool:run surface:event.spotlight:configure --uid=1 --input='{"values":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six","overlay":140}}}' --input=dry_run=true
```

```
1. The hero, with props it takes
Success: Dry run of Configure the spotlight: the values were accepted and prepared, and nothing was written.
  values: {"component":"surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false},"slots":{"actions":{"kind":"text","text":null}}}

2. A card sent the props of a hero
Failed: The values were refused:
/props/align: props.align belongs to the surface_examples:hero variant, but component chose surface_examples:card.
/props/overlay: props.overlay belongs to the surface_examples:hero variant, but component chose surface_examples:card.

3. A hero darker than its schema allows
Failed: The values were refused:
/props/overlay: This value should be between 0 and 100.
```

### Files

What example 12 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 12 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/SpotlightResource.php`](src/Resource/SpotlightResource.php) | the resource: a component, and its props and slots as variants the component chooses | 16 |
| [`src/Variants/ComponentProps.php`](../../src/Variants/ComponentProps.php) | the provider of the props: one variant per component, read from its props schema; the main module's | 40 |
| [`src/Variants/ComponentSlots.php`](../../src/Variants/ComponentSlots.php) | the provider of the slots: one variant per component, read from its metadata; the main module's | 229 |
| [`src/Variants/ComponentsOffered.php`](../../src/Variants/ComponentsOffered.php) | the components both providers offer: every one, or those surface.settings:components_offered allows; the main module's | 31 |
| [`modules/surface_examples/src/Storage/SpotlightStorage.php`](src/Storage/SpotlightStorage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step12` | the route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.spotlight` | the config schema, generated from the class | 15 |
| [`modules/surface_examples/config/install/surface_examples.spotlight.yml`](config/install/surface_examples.spotlight.yml) | the settings the module ships with | 11 |
| [`modules/surface_examples/components/card/card.component.yml`](components/card/card.component.yml) | the card: its props schema and its slot | 41 |
| [`modules/surface_examples/components/hero/hero.component.yml`](components/hero/hero.component.yml) | the hero: its props schema and its slots | 45 |
| [`modules/surface_examples/components/section/section.component.yml`](components/section/section.component.yml) | the section: its props schema and a slot for other components | 16 |
| [`modules/surface_examples/components/call_to_action/call_to_action.component.yml`](components/call_to_action/call_to_action.component.yml) | the call to action: its props schema | 39 |

## Example 13: a block that embeds a component

`/surface-examples/13`

Example 12's three lines are the settings of a block. The block plugin
names a structure with `#[UsesStructure]` and renders; its settings
form, their validation and their storage (the block's settings) come
from the structure, through the block host
([Plugins](../../docs/resources.md#plugins)). The block lives in a
submodule of its own, `surface_examples_component_block`, and a placed
block, of this plugin or any other, is placed, configured and removed
through the placement resource of `surface_examples_block`, the general
case. The examples depend on neither, so the page says how to enable
both while they are off:

```bash
ddev drush pm:install surface_examples_component_block surface_examples_block
```

The structure is example 12's three lines under the block's id, from the
same providers:

<!-- code: ../surface_examples_component_block/src/Structure/ComponentBlockStructure.php -->

```php
#[Structure('block.surface_component')]
final class ComponentBlockStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('component', 'string', t('Component'));

    $inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
      ->setLabel(t('Props'));

    $inputs->attachVariants('slots', by: 'component', from: ComponentSlots::class)
      ->setLabel(t('Slots'));
  }

}
```

One difference: the component is not required. The block host builds
the block from the declared defaults before anything is chosen, the
block form and the block listing among the places that do, and no
component is one every site has to start from; a block with no
component renders nothing. The plugin renders the component it holds
with its props, less a prop that holds nothing, which core's check of
the props refuses for a prop that may not be null; a component the site
no longer has renders nothing too. Each slot renders what it holds: text
as a plain string, escaped where the slot prints it; one component as its
own render array, its props held and its slots filled the same way; a
list as each of those. A slot that holds nothing is not passed, so the
component prints nothing there. The block names the extension that
provides each component it embeds, at any depth, as a dependency, so the
block goes with any of them:

<!-- code: ../surface_examples_component_block/src/Plugin/Block/ComponentBlock.php -->

```php
#[Block(
  id: self::ID,
  admin_label: new TranslatableMarkup('Component'),
)]
#[UsesStructure(ComponentBlockStructure::class)]
final class ComponentBlock extends TypedBlockBase implements ContainerFactoryPluginInterface {

  /**
   * The plugin id.
   */
  public const ID = 'surface_component';

  /**
   * The single-directory components the site has.
   */
  protected ComponentPluginManager $components;

  /**
   * {@inheritdoc}
   *
   * Set after the constructor, which runs the configuration through the
   * pipeline before any property of this class could be.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $block = new static($configuration, $plugin_id, $plugin_definition);
    $block->components = $container->get('plugin.manager.sdc');
    return $block;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = $this->element($this->getConfiguration()) ?? [];
    // The components the structure was built from, its props' and its
    // slots' at every depth: a module or theme installed or taken away
    // may add or remove one stored.
    CacheableMetadata::createFromObject($this->memoizedSurface())->applyTo($build);
    return $build;
  }

  /**
   * Renders one component as stored: its props held, its slots filled.
   *
   * @param mixed $stored
   *   What is stored for it: its `component`, `props` and `slots`.
   *
   * @return array|null
   *   The component's render array, or NULL for no component, or one the
   *   site no longer has.
   */
  protected function element(mixed $stored): ?array {
    $component = is_array($stored) ? ($stored['component'] ?? NULL) : NULL;
    if (!is_string($component) || $component === '' || $this->components->getDefinition($component, FALSE) === NULL) {
      return NULL;
    }
    $element = [
      '#type' => 'component',
      '#component' => $component,
      '#props' => self::held(is_array($stored['props'] ?? NULL) ? $stored['props'] : []),
    ];
    $slots = $this->filled(is_array($stored['slots'] ?? NULL) ? $stored['slots'] : []);
    if ($slots !== []) {
      $element['#slots'] = $slots;
    }
    return $element;
  }

  /**
   * Turns the stored slots into what each slot renders.
   *
   * Text is a plain string, escaped where it is printed; one component is
   * its render array, slots and all; a list is a render array of each.
   * A slot that holds nothing, or only components the site no longer has,
   * is left out, so the component prints nothing there.
   * Decision: see
   * docs/decisions.md#a-slot-holds-text-a-component-or-components.
   *
   * @param array $slots
   *   The slots as stored, by name: each its `kind` and what that holds.
   *
   * @return array<string, array>
   *   A render array per slot that holds something, by name.
   */
  protected function filled(array $slots): array {
    $filled = [];
    foreach ($slots as $name => $slot) {
      if (!is_array($slot)) {
        continue;
      }
      $items = is_array($slot['components'] ?? NULL) ? $slot['components'] : [];
      $content = match ($slot['kind'] ?? NULL) {
        'text' => is_string($slot['text'] ?? NULL) && $slot['text'] !== '' ? ['#plain_text' => $slot['text']] : NULL,
        'component' => $this->element($slot['component'] ?? NULL),
        'components' => array_values(array_filter(array_map($this->element(...), $items))) ?: NULL,
        default => NULL,
      };
      if ($content !== NULL) {
        $filled[(string) $name] = $content;
      }
    }
    return $filled;
  }

  /**
   * Leaves out each prop that holds nothing, at any depth.
   *
   * What is stored holds null for a prop no value was given, which core's
   * check of the props refuses for a prop of a type that is not null, an
   * object or a string with no null beside it. Left out, the component
   * reads its own default, as a prop never passed.
   *
   * @param array $props
   *   The props as stored.
   *
   * @return array
   *   The props that hold a value.
   */
  public static function held(array $props): array {
    if (array_is_list($props)) {
      return $props;
    }
    $held = [];
    foreach ($props as $name => $value) {
      if ($value !== NULL) {
        $held[$name] = is_array($value) ? self::held($value) : $value;
      }
    }
    return $held;
  }

  /**
   * {@inheritdoc}
   *
   * The extensions that provide its components, the one it names and each
   * one its slots hold, so the block goes with any of them.
   */
  public function calculateDependencies(): array {
    $dependencies = [];
    foreach ($this->embedded($this->getConfiguration()) as $component) {
      $definition = $this->components->getDefinition($component, FALSE);
      if (!is_array($definition) || !isset($definition['provider'], $definition['extension_type'])) {
        continue;
      }
      $type = $definition['extension_type'] instanceof \BackedEnum ? $definition['extension_type']->value : (string) $definition['extension_type'];
      $dependencies[$type][] = $definition['provider'];
    }
    return array_map(static fn (array $providers): array => array_values(array_unique($providers)), $dependencies);
  }

  /**
   * Lists the components a stored component embeds, itself included.
   *
   * @param mixed $stored
   *   What is stored for it.
   *
   * @return string[]
   *   The ids, the component first, then each its slots hold, in order.
   */
  protected function embedded(mixed $stored): array {
    $component = is_array($stored) ? ($stored['component'] ?? NULL) : NULL;
    if (!is_string($component) || $component === '') {
      return [];
    }
    $ids = [$component];
    foreach (is_array($stored['slots'] ?? NULL) ? $stored['slots'] : [] as $slot) {
      $nested = match (is_array($slot) ? ($slot['kind'] ?? NULL) : NULL) {
        'component' => [$slot['component'] ?? NULL],
        'components' => is_array($slot['components'] ?? NULL) ? $slot['components'] : [],
        default => [],
      };
      foreach ($nested as $item) {
        array_push($ids, ...$this->embedded($item));
      }
    }
    return $ids;
  }

}
```

Place it through the block layout, `/admin/structure/block`: "Place
block", then "Component". Choose a component and its props are drawn
below it over AJAX, read from its schema; choose another and they are
that component's. Without JavaScript the props are drawn for what is
stored, so a component whose props require something is refused on the
first save, and its props are there on the block's form once it is
saved (why: [decision](../../docs/decisions.md#a-plugin-host-cannot-overlay-a-full-submission)).

With all three modules on, whichever came last places two blocks
through the placement resource's create operation, with the values of the
first two calls below, each in the default theme's content region and
shown on `/surface-examples/13` alone, below what the page says:
Spotlight, `surface_examples_spotlight`, the card as example 12
ships it, a line of text in its slot; and Tonight,
`surface_examples_section`, a section whose slot holds a card,
whose own slot holds a line of text.

The page shows the block's three lines beside example 12's, the block
structure's full schema cut to the four components this module ships
(the same variants as example 12's, under the block's id), the hero's
actions slot as the full schema narrows it, the four tools a placed
block has, a link to place one, and what it cost. Past the site list
limit the block's props and slots are open and `deferred`, as example
12's are, the hero's actions slot is read from the full schema fetched
with the hero chosen. On every site the placement's create and update
have a narrowing tool each, `surface:block.placement:create:schema`
and `surface:block.placement:update:schema`, which the table lists
too: the plugin's settings are variants the site lists, so a tool
listing states the keys of the stored plugin at most, as example 12's
states the stored component's. The test sites
have 11 to 16 block plugins and four components, under the limit.

A plugin's structure is held by its host and is no tool. A placed block
is a config entity, `block.block.<id>`, with a machine name and storage
of its own, and `surface_examples_block` declares it as a resource,
`block.placement`, whose plugin chooses its settings: for this plugin,
the block's structure, as declared; for any other, what its config schema
says ([its README](../surface_examples_block/README.md)). The five calls
below place the Spotlight and Tonight blocks, change the Spotlight block
to the hero, send a hero where the hero's actions expect a call to
action, and remove the Spotlight block, each as a dry run, on a site whose default theme is Stark, where the
values hold `"theme":"stark"`; the page sends the site's own default
theme. The first two are the calls that placed them, made again: a
create whose caller names the thing is refused for the name it took. The
fourth is refused naming the one component the slot expects.

```bash
drush tool:run surface:block.placement:create --uid=1 --input='{"values":{"label":"Spotlight","id":"surface_examples_spotlight","label_display":true,"plugin":"surface_component","settings":{"component":"surface_examples:card","props":{"heading":"Doors open at half past six","tone":"highlight","link":{"url":"https://example.com/tickets","label":"Find your ticket"}},"slots":{"body":{"kind":"text","text":"Bring your ticket, on your phone or printed."}}},"theme":"stark","region":"content","weight":100,"visibility":[{"id":"request_path","settings":{"pages":"/surface-examples/13"}}]}}' --input=dry_run=true
```

```
1. The Spotlight block placed through create, made again: refused for the machine name it took
Failed: The values were refused:
/id: A block named surface_examples_spotlight already exists.
```

```bash
drush tool:run surface:block.placement:create --uid=1 --input='{"values":{"label":"Tonight","id":"surface_examples_section","label_display":false,"plugin":"surface_component","settings":{"component":"surface_examples:section","props":{"heading":"Tonight"},"slots":{"content":{"kind":"component","component":{"component":"surface_examples:card","props":{"heading":"Talks start at seven"},"slots":{"body":{"kind":"text","text":"In the main hall, first floor."}}}}}},"theme":"stark","region":"content","weight":101,"visibility":[{"id":"request_path","settings":{"pages":"/surface-examples/13"}}]}}' --input=dry_run=true
```

```
2. The Tonight block, a section holding a card, placed through create, made again: refused for the machine name it took
Failed: The values were refused:
/id: A block named surface_examples_section already exists.
```

```bash
drush tool:run surface:block.placement:update --uid=1 --input='{"id":"surface_examples_spotlight","values":{"settings":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six","align":"start"}}}}' --input=dry_run=true
```

```
3. The Spotlight block set to the hero
Success: Dry run of Edit a placed block: the values were accepted and prepared, and nothing was written.
  values: {"label":"Spotlight","id":"surface_examples_spotlight","label_display":true,"plugin":"surface_component","settings":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false},"slots":{"actions":{"kind":"text","text":null}}},"theme":"stark","region":"content","weight":100,"status":true,"visibility":[{"id":"request_path","negate":false,"settings":{"pages":"/surface-examples/13"}}]}
```

```bash
drush tool:run surface:block.placement:update --uid=1 --input='{"id":"surface_examples_spotlight","values":{"settings":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six"},"slots":{"actions":{"kind":"component","component":{"component":"surface_examples:hero","props":{"title":"Find your ticket"}}}}}}}' --input=dry_run=true
```

```
4. A hero in the hero's actions, which expect a call to action: refused, naming the one it expects
Failed: The values were refused:
/settings/slots/actions/component/component: The value you selected is not a valid choice. Choose surface_examples:call_to_action (Call to action).
```

```bash
drush tool:run surface:block.placement:delete --uid=1 --input='{"id":"surface_examples_spotlight"}' --input=dry_run=true
```

```
5. The Spotlight block removed, rehearsed
Success: Dry run of Remove a placed block: the removal was rehearsed, and nothing was removed. The values it holds are answered.
  values: {"label":"Spotlight","id":"surface_examples_spotlight","label_display":true,"plugin":"surface_component","settings":{"component":"surface_examples:card","props":{"heading":"Doors open at half past six","tone":"highlight","link":{"url":"https://example.com/tickets","label":"Find your ticket"}},"slots":{"body":{"kind":"text","text":"Bring your ticket, on your phone or printed."}}},"theme":"stark","region":"content","weight":100,"status":true,"visibility":[{"id":"request_path","negate":false,"settings":{"pages":"/surface-examples/13"}}]}
```

### Files

What example 13 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 13 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples_component_block/surface_examples_component_block.info.yml`](../surface_examples_component_block/surface_examples_component_block.info.yml) | the module the block lives in | 10 |
| [`modules/surface_examples_component_block/src/Structure/ComponentBlockStructure.php`](../surface_examples_component_block/src/Structure/ComponentBlockStructure.php) | the block's structure: example 12's three lines under the block's id | 10 |
| [`modules/surface_examples_component_block/src/Plugin/Block/ComponentBlock.php`](../surface_examples_component_block/src/Plugin/Block/ComponentBlock.php) | the block plugin: names the structure and draws the chosen component | 96 |
| [`modules/surface_examples_component_block/config/schema/surface_examples_component_block.schema.yml`](../surface_examples_component_block/config/schema/surface_examples_component_block.schema.yml), entry `block.settings.surface_component` | the config schema of the block's settings | 14 |
| [`modules/surface_examples/src/Hook/SpotlightBlockHooks.php`](src/Hook/SpotlightBlockHooks.php) | places the Spotlight and Tonight blocks once both modules are enabled | 36 |
| [`modules/surface_examples/components/card/card.twig`](components/card/card.twig) | the card's template | 9 |
| [`modules/surface_examples/components/hero/hero.twig`](components/hero/hero.twig) | the hero's template | 9 |
| [`modules/surface_examples/components/section/section.twig`](components/section/section.twig) | the section's template | 6 |
| [`modules/surface_examples/components/call_to_action/call_to_action.twig`](components/call_to_action/call_to_action.twig) | the call to action's template | 1 |

## Example 14: an optional extension

`/surface-examples/14`, and the webhook at `/surface-examples/14/webhook`

A resource that calls a service takes a credential: a key pasted here, a
secret, or, once a module that manages keys is on, a key the site
manages in its place, with no pasted box beside it. That is the Key
module's pattern. `surface_examples_keys` stands in for the Key
module, so nothing contributed is installed, and the examples do not
depend on it, so the page says how to enable it while it is off:

```bash
ddev drush pm:install surface_examples_keys
```

Each page shows both resources as they are now, says whether the module
is on, and makes the calls below that the module allows. Two ways reach
the same form, each for the case it fits: the owner declares both
sources, or another module's alter offers the managed key
(why: [decision](../../docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).

### The owner's way: a variant the site adds

The mailer knows the module that manages keys. It declares a source and
a credential whose variants the source chooses, listed by a provider:

<!-- code: src/Resource/MailerResource.php -->

```php
#[Resource('event.mailer', storage: MailerStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the mailer'),
  description: new TranslatableMarkup("Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages."),
  permission: 'administer site configuration',
)]
final class MailerResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sender', 'email', t('Sender'), default: 'tickets@example.com')
      ->setRequired(TRUE);

    $inputs->add('endpoint', 'uri', t('Mail service'), default: 'https://mail.example.com/v1/send')
      ->setRequired(TRUE);

    $inputs->add('source', 'string', t('Credential source'), default: 'pasted')
      ->setRequired(TRUE);

    $inputs->attachVariants('credential', by: 'source', from: CredentialSources::class)
      ->setLabel(t('Credential'));
  }

}
```

The provider lists the pasted source always and the managed one while
the module is on, cacheable by the list of modules and the keys'
configuration, so the second source is there at the next request after
the module is installed, with no cache rebuild. Both are structures of
this module, named once in `structures()`: the provider is closed
(`ClosedSiteListInterface`), so the config schema written from the
resource gives each its own type,
`surface_examples.mailer.credential.[%parent.source]`, whichever
the site offers when it is written, where example 12's components are an
open map.

<!-- code: src/Variants/CredentialSources.php -->

```php
final class CredentialSources implements ClosedSiteListInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The module that manages keys.
   */
  public const KEYS = 'surface_examples_keys';

  /**
   * Constructs the provider.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('module_handler'), $container->get('string_translation'));
  }

  /**
   * {@inheritdoc}
   */
  public static function structures(): array {
    return [
      'pasted' => PastedCredentialStructure::class,
      'managed' => ManagedCredentialStructure::class,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $sources = (new SiteList())
      ->addCacheTags(['config:core.extension', 'config:' . self::KEYS . '.keys'])
      ->add('pasted', $this->t('Pasted here'));
    if ($this->moduleHandler->moduleExists(self::KEYS)) {
      $sources->add('managed', $this->t('A managed key'));
    }
    return $sources;
  }

}
```

<!-- code: src/Structure/PastedCredentialStructure.php -->

```php
#[Structure('event.mailer.credential.pasted',
  label: new TranslatableMarkup('Pasted credential'),
  description: new TranslatableMarkup('The mailer credential pasted in, kept as a secret and never read back.'),
)]
final class PastedCredentialStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $api_key = $inputs->add('api_key', 'string', t('API key'))
      ->setDescription(t('The key the mail service issued, pasted here.'))
      ->setRequired(TRUE);
    DefinitionMetadata::setSecret($api_key);
  }

}
```

<!-- code: src/Structure/ManagedCredentialStructure.php -->

```php
#[Structure('event.mailer.credential.managed',
  label: new TranslatableMarkup('Managed credential'),
  description: new TranslatableMarkup('The mailer credential as the name of a key the site manages, whose value is read where it is used.'),
)]
final class ManagedCredentialStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('key_id', 'string', t('Key'))
      ->setDescription(t('A key the site manages; its value is read where it is used, never stored here.'))
      ->setRequired(TRUE)
      ->addConstraint('ManagedKey');
  }

}
```

The managed key declares `ManagedKey`, the module's constraint, which
its resolver reads as the keys the site manages, so the form offers them
and both documents state them.

With the module off there is one source, and nothing to choose: the
source is drawn as that source, its label where a select would be, and
posted as it is. Both documents state it as `const`, with the label
under `x-surface.fixed` (the widget hint `fixed`, which the React page
draws as the label), and the credential is the pasted source's def:

```json
{
    "source": {
        "title": "Credential source",
        "type": "string",
        "const": "pasted",
        "default": "pasted",
        "x-surface": {
            "fixed": "Pasted here"
        }
    },
    "credential": {
        "title": "Credential",
        "anyOf": [
            {
                "$ref": "#/$defs/event.mailer.credential.pasted"
            },
            {
                "type": "null"
            }
        ],
        "x-surface": {
            "dependsOn": [
                "source"
            ],
            "by": "source",
            "variants": {
                "pasted": "#/$defs/event.mailer.credential.pasted"
            }
        }
    }
}
```

With the module on, the source offers both, and the credential is
`anyOf` of the two sources' defs, one conditional each:

```json
{
    "source": {
        "title": "Credential source",
        "type": "string",
        "oneOf": [
            {
                "const": "pasted",
                "title": "Pasted here"
            },
            {
                "const": "managed",
                "title": "A managed key"
            }
        ],
        "default": "pasted"
    },
    "credential": {
        "title": "Credential",
        "anyOf": [
            {
                "$ref": "#/$defs/event.mailer.credential.pasted"
            },
            {
                "$ref": "#/$defs/event.mailer.credential.managed"
            },
            {
                "type": "null"
            }
        ],
        "x-surface": {
            "dependsOn": [
                "source"
            ],
            "by": "source",
            "variants": {
                "pasted": "#/$defs/event.mailer.credential.pasted",
                "managed": "#/$defs/event.mailer.credential.managed"
            }
        }
    }
}
```

The pasted key is a secret: the form draws an empty box, and no answer
returns it. A pasted key stored is kept when the managed source is
chosen, beside the managed key's name, and taken up again when the
pasted source is chosen back, since no caller could send it again; the
managed source's config schema says it may be there. A plain value of
the other source goes, as between any two variants.

### The alter's way: the pasted key ruled out

The webhook does not know the module that manages keys. It declares an
endpoint and a pasted key:

<!-- code: src/Resource/WebhookResource.php -->

```php
#[Resource('event.webhook', storage: WebhookStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the sales webhook'),
  description: new TranslatableMarkup("Sets the address the event's sales are posted to, and the key each post is signed with."),
  permission: 'administer site configuration',
)]
final class WebhookResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('endpoint', 'uri', t('Endpoint'), default: 'https://hooks.example.com/sales')
      ->setRequired(TRUE);

    $api_key = $inputs->add('api_key', 'string', t('API key'))
      ->setDescription(t('The key each post is signed with, pasted here.'));
    DefinitionMetadata::setSecret($api_key);
  }

}
```

The module's alter mounts a managed key on it, draws it right after the
pasted key, rules the pasted key out once a key is chosen, a rule
declared as data, and says what the code that uses the webhook reads in
its place, a resolve:

<!-- code: ../surface_examples_keys/src/Alter/WebhookKeyAlter.php -->

```php
#[Alters(WebhookResource::class)]
final class WebhookKeyAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('key_id', 'string', $this->t('Key'))
      ->setDescription($this->t('A key the site manages, used in place of a pasted one.'))
      ->addConstraint('ManagedKey');
    $inputs->describe('third_party_settings.surface_examples_keys', label: $this->t('Managed key'), after: 'api_key');
    // A managed key chosen: a pasted one means nothing beside it.
    $inputs->narrow('api_key')
      ->when(Condition::present('key_id'))
      ->doesNotApply();
    // And where the webhook is used, the managed key's value in its place.
    $inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class);
  }

}
```

With a key chosen, the form draws no pasted box, a pasted key sent is
refused naming the managed key, and what is stored for the pasted key is
kept; with the module off, the pasted box is back and the stored key with
it. The full schema states the rule as written, the conditional the
webhook's frame lists under `x-surface.rules`:

```json
[
    {
        "if": {
            "properties": {
                "third_party_settings": {
                    "type": [
                        "object",
                        "null"
                    ],
                    "properties": {
                        "surface_examples_keys": {
                            "type": [
                                "object",
                                "null"
                            ],
                            "properties": {
                                "key_id": {
                                    "not": {
                                        "type": "null"
                                    }
                                }
                            },
                            "required": [
                                "key_id"
                            ]
                        }
                    },
                    "required": [
                        "surface_examples_keys"
                    ]
                }
            },
            "required": [
                "third_party_settings"
            ]
        },
        "then": {
            "properties": {
                "api_key": false
            }
        }
    }
]
```

The alter ships ruling out. The alternative, not shipped, locks: the
pasted key stays drawn, fixed, and a pasted key sent is noted as not
taken instead of refused. That leaves an empty password box that cannot
be used, and a caller who sends both learns of it only from a note; a
conditional lock is also no effect a declared rule has (ROADMAP item 3
left it out), so this is the spelling it would have:

```php
// Not shipped: the pasted key drawn fixed once a managed key is
// chosen, and a pasted key sent noted as not taken.
$inputs->narrow('api_key')
  ->when(Condition::present('key_id'))
  ->lock();
```

### The use: a managed key in the pasted key's place

Where the webhook is used: the code that signs a post reads `api_key`
through `Resources::resolved()`, and knows nothing of managed keys. The
alter says, beside its rule, that once a managed key is chosen its value
stands in for the pasted key there: `resolve('api_key', from: ['key_id'],
using: ManagedKeyValue::class)`. The value is read when a post is signed
and never stored on the webhook; the pasted key stays stored as it was,
and no document, form or tool states the resolve. The mailer declares
the managed source itself, so its code reads `credential.key_id` and
asks the key service for the value.

The signer is the webhook's code. It is a service of this module, which
does not depend on the module that manages keys:

<!-- code: src/WebhookSigner.php -->

```php
final class WebhookSigner {

  /**
   * The webhook.
   */
  public const RESOURCE = 'event.webhook';

  /**
   * Constructs the signer.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which reads the webhook's values.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
  ) {}

  /**
   * Signs a post.
   *
   * @param array $payload
   *   What is posted.
   *
   * @return string
   *   The HMAC-SHA256 of the payload's JSON, keyed by the webhook's key.
   *
   * @throws \RuntimeException
   *   When the webhook has no key to sign with.
   */
  public function sign(array $payload): string {
    $key = $this->resources->resolved(self::RESOURCE)['api_key'] ?? NULL;
    if (!is_string($key) || $key === '') {
      throw new \RuntimeException('The webhook has no key to sign a post with.');
    }
    return hash_hmac('sha256', (string) json_encode($payload), $key);
  }

  /**
   * Gets the address each post is sent to.
   *
   * @return string
   *   The endpoint.
   */
  public function endpoint(): string {
    return (string) ($this->resources->resolved(self::RESOURCE)['endpoint'] ?? '');
  }

}
```

`ManagedKeyValue` is the module's class that answers a key's value for
its name, as the Key module's `getKeyValue()` does; a name the site no
longer manages answers nothing, so the signer has no key and says so,
and the pasted key does not stand in. The webhook's page says which key
a post is signed with now, by its name, never its value. Uninstall the
module and the signer reads the pasted key again, as it was stored
(why: [decision](../../docs/decisions.md#a-key-may-stand-for-another-at-use)).

### The calls

Made on the settings the module ships, with the module that manages
keys on, each a dry run but the read. The first is a write rehearsed,
since a page that makes its calls whenever it is drawn writes nothing:
the secret is in no answer. The second chooses the managed source; the
third sends a pasted key beside it and is refused, naming the source
that chose the other variant; the fourth reads what is stored, with the
pasted key left out. A managed key stored would be read by its name. The
webhook's three: a pasted key, a managed key, and both, refused naming
the key the pasted one depends on. With the module off, the page makes
only the calls that need nothing of it.

```bash
drush tool:run surface:event.mailer:configure --uid=1 --input='{"values":{"source":"pasted","credential":{"api_key":"mk-example-7f3a91"}}}' --input=dry_run=true
```

```
1. A key pasted, rehearsed: the secret is in no answer
Success: Dry run of Configure the mailer: the values were accepted and prepared, and nothing was written.
  values: {"sender":"tickets@example.com","endpoint":"https://mail.example.com/v1/send","source":"pasted","credential":[]}
```

```bash
drush tool:run surface:event.mailer:configure --uid=1 --input='{"values":{"source":"managed","credential":{"key_id":"mail_service"}}}' --input=dry_run=true
```

```
2. A managed key chosen, rehearsed
Success: Dry run of Configure the mailer: the values were accepted and prepared, and nothing was written.
  values: {"sender":"tickets@example.com","endpoint":"https://mail.example.com/v1/send","source":"managed","credential":{"key_id":"mail_service"}}
```

```bash
drush tool:run surface:event.mailer:configure --uid=1 --input='{"values":{"source":"managed","credential":{"key_id":"mail_service","api_key":"mk-example-7f3a91"}}}' --input=dry_run=true
```

```
3. A pasted key sent while the managed source is chosen: refused, naming the source
Failed: The values were refused:
/credential/api_key: credential.api_key belongs to the pasted variant, but source chose managed.
```

```bash
drush tool:run surface:event.mailer:configure:read --uid=1
```

```
4. The mailer read: the pasted key is left out
Success: Configure the mailer: the stored values were read, and nothing was written.
  values: {"sender":"tickets@example.com","endpoint":"https://mail.example.com/v1/send","source":"pasted","credential":[]}
```

```bash
drush tool:run surface:event.webhook:configure --uid=1 --input='{"values":{"api_key":"wh-example-02c4d8"}}' --input=dry_run=true
```

```
1. A key pasted, rehearsed
Success: Dry run of Configure the sales webhook: the values were accepted and prepared, and nothing was written.
  values: {"endpoint":"https://hooks.example.com/sales","third_party_settings":[]}
```

```bash
drush tool:run surface:event.webhook:configure --uid=1 --input='{"values":{"third_party_settings":{"surface_examples_keys":{"key_id":"webhook_signing"}}}}' --input=dry_run=true
```

```
2. A managed key chosen, rehearsed
Success: Dry run of Configure the sales webhook: the values were accepted and prepared, and nothing was written.
  values: {"endpoint":"https://hooks.example.com/sales","third_party_settings":{"surface_examples_keys":{"key_id":"webhook_signing"}}}
```

```bash
drush tool:run surface:event.webhook:configure --uid=1 --input='{"values":{"api_key":"wh-example-02c4d8","third_party_settings":{"surface_examples_keys":{"key_id":"webhook_signing"}}}}' --input=dry_run=true
```

```
3. A pasted key sent beside a managed key: refused, naming the key it depends on
Failed: The values were refused:
/api_key: API key does not apply when Managed key: Key is set.
```

### Files

What example 14 is made of, as its pages list them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 14 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Resource/MailerResource.php`](src/Resource/MailerResource.php) | the owner's way: a sender, a mail service, and a credential whose source a provider lists | 18 |
| [`modules/surface_examples/src/Variants/CredentialSources.php`](src/Variants/CredentialSources.php) | the provider: the pasted source always, the managed source while the keys module is on, both named for storage | 28 |
| [`modules/surface_examples/src/Structure/PastedCredentialStructure.php`](src/Structure/PastedCredentialStructure.php) | the pasted source: a key, a secret | 12 |
| [`modules/surface_examples/src/Structure/ManagedCredentialStructure.php`](src/Structure/ManagedCredentialStructure.php) | the managed source: the name of a key the site manages | 12 |
| [`modules/surface_examples/src/Storage/MailerStorage.php`](src/Storage/MailerStorage.php) | its storage: names the config object | 3 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step14` | the mailer's route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.mailer` | the mailer's config schema, generated from the class | 18 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.mailer.credential.pasted` | the pasted source's config schema | 10 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.mailer.credential.managed` | the managed source's config schema, with the pasted key it keeps | 13 |
| [`modules/surface_examples/config/install/surface_examples.mailer.yml`](config/install/surface_examples.mailer.yml) | the mailer's shipped settings | 5 |
| [`modules/surface_examples/src/Resource/WebhookResource.php`](src/Resource/WebhookResource.php) | the alter's way: an endpoint and a pasted key, and nothing of managed keys | 15 |
| [`modules/surface_examples/src/Storage/WebhookStorage.php`](src/Storage/WebhookStorage.php) | its storage: names the config object | 3 |
| [`modules/surface_examples/src/WebhookSigner.php`](src/WebhookSigner.php) | the webhook's code: signs a post with the key the webhook's values hold where it is used | 16 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step14_webhook` | the webhook's route | 8 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.webhook` | the webhook's config schema, generated from the class | 19 |
| [`modules/surface_examples/config/install/surface_examples.webhook.yml`](config/install/surface_examples.webhook.yml) | the webhook's shipped settings | 2 |
| [`modules/surface_examples_keys/surface_examples_keys.info.yml`](../surface_examples_keys/surface_examples_keys.info.yml) | the module that manages keys, which the page tells you to enable | 9 |
| [`modules/surface_examples_keys/surface_examples_keys.services.yml`](../surface_examples_keys/surface_examples_keys.services.yml) | its one service, the keys and their values | 5 |
| [`modules/surface_examples_keys/surface_examples_keys.install`](../surface_examples_keys/surface_examples_keys.install) | takes the managed key it stored on the webhook away when it is uninstalled | 11 |
| [`modules/surface_examples_keys/src/Keys/ManagedKeys.php`](../surface_examples_keys/src/Keys/ManagedKeys.php) | the keys the site manages and their values, read from configuration with its cacheability | 24 |
| [`modules/surface_examples_keys/src/Plugin/Validation/Constraint/ManagedKeyConstraint.php`](../surface_examples_keys/src/Plugin/Validation/Constraint/ManagedKeyConstraint.php) | the constraint a key naming a managed key declares | 15 |
| [`modules/surface_examples_keys/src/Plugin/Validation/Constraint/ManagedKeyConstraintValidator.php`](../surface_examples_keys/src/Plugin/Validation/Constraint/ManagedKeyConstraintValidator.php) | its validator: refuses a name the site does not manage | 19 |
| [`modules/surface_examples_keys/src/Plugin/SurfaceOptionsResolver/ManagedKeyOptions.php`](../surface_examples_keys/src/Plugin/SurfaceOptionsResolver/ManagedKeyOptions.php) | the resolver that reads the constraint as the list of keys | 28 |
| [`modules/surface_examples_keys/src/Alter/WebhookKeyAlter.php`](../surface_examples_keys/src/Alter/WebhookKeyAlter.php) | the alter: mounts a managed key on the webhook, rules the pasted key out once one is chosen, and resolves it to the managed key's value at use | 17 |
| [`modules/surface_examples_keys/src/Compute/ManagedKeyValue.php`](../surface_examples_keys/src/Compute/ManagedKeyValue.php) | a managed key's value, for the name the webhook stores | 6 |
| [`modules/surface_examples_keys/config/schema/surface_examples_keys.schema.yml`](../surface_examples_keys/config/schema/surface_examples_keys.schema.yml), entry `surface_examples_keys.keys` | the keys' config schema, written by hand | 20 |
| [`modules/surface_examples_keys/config/schema/surface_examples_keys.schema.yml`](../surface_examples_keys/config/schema/surface_examples_keys.schema.yml), entry `surface_examples.webhook.third_party.surface_examples_keys` | the config schema of what it stores on the webhook | 11 |
| [`modules/surface_examples_keys/config/install/surface_examples_keys.keys.yml`](../surface_examples_keys/config/install/surface_examples_keys.keys.yml) | the two keys it ships, with their values | 9 |

## Example 15: a config entity of your own

`/surface-examples/15`, with the forms at `/surface-examples/15/add`,
`/surface-examples/15/venue/{id}` and `/surface-examples/15/venue/{id}/delete`

A module that declares its own config entity type puts a resource over
it from the start. The venue is a config entity,
`surface_examples.event_venue.<id>`, declared with core's
`#[ConfigEntityType]` attribute and `config_export`, with no form
classes, no list builder and no links: the resource's operations create,
update and delete a venue at every door, and its routes are the forms.

<!-- code: src/Entity/EventVenue.php -->

```php
#[ConfigEntityType(
  id: 'event_venue',
  label: new TranslatableMarkup('Venue'),
  label_collection: new TranslatableMarkup('Venues'),
  label_singular: new TranslatableMarkup('venue'),
  label_plural: new TranslatableMarkup('venues'),
  config_prefix: 'event_venue',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'weight' => 'weight',
    'status' => 'status',
  ],
  admin_permission: 'administer event venues',
  label_count: [
    'singular' => '@count venue',
    'plural' => '@count venues',
  ],
  config_export: [
    'id',
    'label',
    'slug',
    'capacity',
    'address',
    'rooms',
    'weight',
  ],
)]
final class EventVenue extends ConfigEntityBase {

  /**
   * The names every venue's config object starts with, before its id.
   */
  public const PREFIX = 'surface_examples.event_venue';

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The name people read.
   */
  protected ?string $label = NULL;

  /**
   * The name in a link, computed from the name when the venue is added.
   */
  protected ?string $slug = NULL;

  /**
   * How many people the venue holds.
   */
  protected ?int $capacity = NULL;

  /**
   * Where it is.
   */
  protected ?string $address = NULL;

  /**
   * Its rooms, each an id, a name and its seats.
   *
   * @var array<int, array{id: string, label: string, seats: int}>|null
   */
  protected ?array $rooms = [];

  /**
   * Its place among the venues: lighter first.
   */
  protected ?int $weight = 0;

  /**
   * Gets the rooms.
   *
   * @return array<int, array{id: string, label: string, seats: int}>
   *   Each room, in the order the venue lists them.
   */
  public function rooms(): array {
    return $this->rooms ?? [];
  }

  /**
   * Gets the slug.
   *
   * @return string|null
   *   The name in a link, or NULL for a venue saved before it had one.
   */
  public function slug(): ?string {
    return $this->slug;
  }

  /**
   * Gets the weight.
   *
   * @return int
   *   Its place among the venues.
   */
  public function weight(): int {
    return (int) $this->weight;
  }

}
```

The resource is the venue by its machine name, on a `VenueStorage`, a
`ConfigEntityStorage`. Create, update and delete are about one venue. Listing
and reordering are about the venues together, so they name none: each
takes the keys of a structure of its own, which `structure:` on its
`#[Operation]` names. The listing is read only; the reorder is an update
over the collection. Neither is a new kind of operation.

<!-- code: src/Resource/VenueResource.php -->

```php
#[Resource('event.venue',
  identity: ['id'],
  storage: VenueStorage::class,
  access: VenueAccess::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a venue'),
  description: new TranslatableMarkup('Adds a venue under a machine name the caller chooses, or one computed from its name, with its capacity, address and rooms; a machine name already in use is refused.'),
  permission: self::ADMINISTER,
  creates: TRUE,
  idempotent: TRUE,
)]
#[Operation('list',
  label: new TranslatableMarkup('List the venues'),
  description: new TranslatableMarkup('Answers a page of the venues, lighter first, each one the account may read, and how many the filter matched.'),
  permission: self::VIEW,
  readOnly: TRUE,
  structure: VenueListingStructure::class,
)]
#[Operation('reorder',
  label: new TranslatableMarkup('Reorder the venues'),
  description: new TranslatableMarkup('Gives each venue named its new weight, all in one write; a venue left out keeps its own, and an unknown or repeated venue is refused.'),
  permission: self::ADMINISTER,
  structure: VenueReorderStructure::class,
)]
final class VenueResource implements ResourceInterface {

  /**
   * The permission that adds, edits, reorders and removes the venues.
   */
  public const ADMINISTER = 'administer event venues';

  /**
   * The permission that lists them.
   */
  public const VIEW = 'view event venues';

  /**
   * Edits a venue, named by its machine name, which stays as it is.
   */
  #[Operation('update',
    label: new TranslatableMarkup('Edit a venue'),
    description: new TranslatableMarkup('Changes a venue, named by its machine name, which stays as it is: its name, capacity, address, rooms, weight and status.'),
    permission: self::ADMINISTER,
  )]
  public static function update(EventVenue $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * Removes a venue, named by its machine name.
   */
  #[Operation('delete',
    label: new TranslatableMarkup('Remove a venue'),
    description: new TranslatableMarkup('Removes a venue, named by its machine name, with its rooms.'),
    permission: self::ADMINISTER,
    destructive: TRUE,
    deletes: TRUE,
  )]
  public static function delete(EventVenue $id): OperationContext {
    return new OperationContext(known: ['id' => (string) $id->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('label', 'string', t('Name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);

    $inputs->compute('id', 'machine_name', t('Machine name'),
      from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)
      ->setDescription(t('A unique name for the venue: lowercase letters, numbers and underscores, computed from its name unless one is given.'))
      ->addConstraint('Length', ['max' => 32]);

    $inputs->compute('slug', 'string', t('Slug'), from: ['label'], using: Slugify::class)
      ->setDescription(t('The name in a link: lowercase letters, numbers and dashes, set when the venue is added.'))
      ->addConstraint('Regex', [
        'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D',
        'message' => t('The slug may hold only lowercase letters, numbers and single dashes between them.'),
      ])
      ->addConstraint('Length', ['max' => 128]);

    $inputs->add('capacity', 'integer', t('Capacity'))
      ->setDescription(t('How many people the venue holds.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 100000]);

    $inputs->add('address', 'string', t('Address'))
      ->addConstraint('Length', ['max' => 255]);

    $inputs->attachList('rooms', VenueRoomStructure::class, max: 20)
      ->setLabel(t('Rooms'))
      ->setDescription(t('The rooms an event at the venue may be held in.'));

    $inputs->add('weight', 'integer', t('Weight'), default: 0)
      ->setDescription(t('Its place among the venues: lighter first.'))
      ->addConstraint('Range', ['min' => -1000, 'max' => 1000]);

    $inputs->add('status', 'boolean', t('Enabled'), default: TRUE)
      ->setDescription(t('Whether events may be held there; a disabled venue is listed only for an account that administers the venues.'));
  }

}
```

The machine name is the venue's identity, and a computed key a caller
may type: `machine_name` is core's config schema type, a string with
the pattern `^[a-z0-9_]+$` and a Length the venue narrows to 32, and
`when: Compute::UNLESS_SENT` computes it from the name only when a call
leaves it out. The stock `MachineName` does what core's machine name
element does in the browser: "Town Hall" is `town_hall`. A call that
sends one keeps it, so both documents state the key with
`x-surface.computed: {"from": ["label"], "when": "unless_sent"}` and no
`readOnly`, and the add tool offers it. The build holds the machine name
a create names to one no venue holds, with the stock `SurfaceUnique`
it adds to the identity key, "A venue named town_hall already exists.",
and the add form draws core's machine name element beside the name. On
edit the machine name is the venue the page is about, fixed.

The slug is a computed key, `compute()`: a key of the venue, stored and
read like any other, whose value nobody types. `Slugify` computes it
from the name, once, when the venue is added (`when:` is
`Compute::ON_CREATE` unless it says `Compute::ON_CHANGE`), so renaming a
venue leaves its links working. The pipeline computes it after it
accepts the rest and judges it by its own pattern; the storage refuses
a slug another venue holds, and the refusal names the name, the key a
caller can change. Both documents state it `readOnly` with
`x-surface.computed: {"from": ["label"], "when": "create"}`, the write
tool's `values` leaves it out, the read answers it, and the form draws
it fixed. A slug sent equal to the one the venue holds is noted and not
taken; any other is refused.

<!-- code: src/Slugify.php -->

```php
final class Slugify implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($from['label'] ?? ''))), '-');
    return $slug === '' ? NULL : $slug;
  }

}
```

The listing takes a page and a filter, and declares what it answers,
`defineOutputs()`: the page, a list of venues, and how many matched.
Its tool is `surface:event.venue:list`, a read with no dry run.

<!-- code: src/Structure/VenueListingStructure.php -->

```php
#[Structure('event.venue.listing')]
final class VenueListingStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('offset', 'integer', t('Offset'), default: 0)
      ->setDescription(t('How many venues to skip, in order of weight.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 10000]);

    $inputs->add('limit', 'integer', t('Limit'), default: 20)
      ->setDescription(t('How many venues to answer at most.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);

    $inputs->add('status', 'boolean', t('Enabled'))
      ->setDescription(t('Only the venues enabled, or only those disabled; left out, every venue.'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $item = MapDataDefinition::create()
      ->setLabel(t('Venue'))
      ->setPropertyDefinition('id', DataDefinition::create('string')->setLabel(t('Machine name'))->setRequired(TRUE))
      ->setPropertyDefinition('label', DataDefinition::create('string')->setLabel(t('Name'))->setRequired(TRUE))
      ->setPropertyDefinition('capacity', DataDefinition::create('integer')->setLabel(t('Capacity'))->setRequired(TRUE))
      ->setPropertyDefinition('weight', DataDefinition::create('integer')->setLabel(t('Weight'))->setRequired(TRUE));
    $outputs->addList('items', $item, t('Venues'))
      ->setDescription(t('The page of venues, lighter first, each one the account may read.'))
      ->setRequired(TRUE);

    $outputs->add('total', 'integer', t('Total'))
      ->setDescription(t('How many venues the filter matched that the account may read, on every page.'))
      ->setRequired(TRUE);
  }

}
```

The reorder takes a collection of venues and their weights:

<!-- code: src/Structure/VenueReorderStructure.php -->

```php
#[Structure('event.venue.reorder')]
final class VenueReorderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('venues', VenueOrderStructure::class, min: 1)
      ->setLabel(t('Venues'))
      ->setDescription(t('Each venue to move, once, with its new weight; a venue left out keeps its own.'))
      ->setRequired(TRUE);
  }

}
```

<!-- code: src/Structure/VenueOrderStructure.php -->

```php
#[Structure('event.venue.order')]
final class VenueOrderStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Venue'))
      ->setDescription(t('The machine name of a venue the site has.'))
      ->setRequired(TRUE);

    $inputs->add('weight', 'integer', t('Weight'))
      ->setDescription(t('Its place among the venues: lighter first.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => -1000, 'max' => 1000]);
  }

}
```

The storage is where the venues are read and written together. A
listing loads nothing, since a query has nothing stored, and its
`prepare()` is its answer: prepare is the last stage a read runs, and
one with no side effects, so the page and the total are what the tool
answers beside the one result shape. Each venue is asked the resource's
access answer in the listing's own context, knowing that venue, and one
whose answer is not allowed is left out of the page and of the total. A
reorder loads every venue's id and weight; its `prepare()` refuses an id
that names no venue or is named twice, by its place in the list, and
rehearses the rest; `commit()` saves them, in that one write.

<!-- code: src/Storage/VenueStorage.php -->

```php
final class VenueStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  public const ENTITY_TYPE_ID = 'event_venue';

  /**
   * {@inheritdoc}
   */
  public const PROPERTIES = ['id', 'label', 'slug', 'capacity', 'address', 'rooms', 'weight', 'status'];

  /**
   * {@inheritdoc}
   */
  public const ALWAYS_HELD = ['rooms', 'weight', 'status'];

  /**
   * The operation that lists the venues.
   */
  public const LIST = 'list';

  /**
   * The operation that reorders them.
   */
  public const REORDER = 'reorder';

  /**
   * Constructs a VenueStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, autowired.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, autowired, which answers access for each venue.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
    protected readonly ResourcesInterface $resources,
  ) {
    parent::__construct($entityTypeManager, $typedConfig, $string_translation);
  }

  /**
   * {@inheritdoc}
   *
   * Nothing for a listing; every venue's id and weight for a reorder.
   */
  public function load(OperationContext $context): array {
    return match ($context->id) {
      self::LIST => [],
      self::REORDER => [
        'venues' => array_values(array_map(static fn (EventVenue $venue): array => [
          'id' => (string) $venue->id(),
          'weight' => $venue->weight(),
        ], $this->venues())),
      ],
      default => parent::load($context),
    };
  }

  /**
   * {@inheritdoc}
   *
   * A listing's answer, a reorder's rehearsal, or a venue's, its slug
   * and its rooms held to what no other venue has.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    return match ($context->id) {
      self::LIST => $this->listing($context, $values, $account),
      self::REORDER => $this->reordering($values),
      default => $this->venue($context, $values, $account),
    };
  }

  /**
   * {@inheritdoc}
   *
   * A reorder saves each venue it rehearsed with its new weight.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    if ($context->id !== self::REORDER) {
      parent::commit($context, $prepared, $account);
      return;
    }
    $storage = $this->storage();
    foreach ($prepared['weights'] ?? [] as $id => $weight) {
      $venue = $storage->load((string) $id);
      if ($venue instanceof EventVenue) {
        $venue->set('weight', (int) $weight)->save();
      }
    }
  }

  /**
   * Reads a page of the venues the account may read.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The listing's context.
   * @param array $values
   *   The accepted offset, limit and status.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the listing is read as.
   *
   * @return array{items: list<array{id: string, label: string, capacity: int, weight: int}>, total: int}
   *   The page and how many venues matched.
   */
  protected function listing(OperationContext $context, array $values, AccountInterface $account): array {
    $status = $values['status'] ?? NULL;
    $matched = [];
    foreach ($this->venues() as $venue) {
      if (is_bool($status) && $venue->status() !== $status) {
        continue;
      }
      // Decision: see
      // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
      if (!$this->resources->access(VenueResource::class, $context->withKnown(['id' => (string) $venue->id()]), $account)->isAllowed()) {
        continue;
      }
      $matched[] = [
        'id' => (string) $venue->id(),
        'label' => (string) $venue->label(),
        'capacity' => (int) $venue->get('capacity'),
        'weight' => $venue->weight(),
      ];
    }
    return [
      'items' => array_slice($matched, (int) ($values['offset'] ?? 0), (int) ($values['limit'] ?? 20)),
      'total' => count($matched),
    ];
  }

  /**
   * Rehearses a reorder: each venue named, once, with its new weight.
   *
   * @param array $values
   *   The accepted list of venues and weights.
   *
   * @return array{weights: array<string, int>}
   *   The weight each venue named takes, by its machine name.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   When an id names no venue or is named twice, or a venue's config
   *   schema refuses its new weight.
   */
  protected function reordering(array $values): array {
    $venues = $this->venues();
    $refused = [];
    $weights = [];
    $first = [];
    foreach (array_values(is_array($values['venues'] ?? NULL) ? $values['venues'] : []) as $delta => $item) {
      $id = is_array($item) && is_string($item['id'] ?? NULL) ? $item['id'] : '';
      if (!isset($venues[$id])) {
        $refused[] = new Violation('venues', $delta . '.id', $this->t('No venue is named @id.', ['@id' => $id]), Violation::NOT_FOUND);
        continue;
      }
      if (isset($first[$id])) {
        $refused[] = new Violation('venues', $delta . '.id', $this->t('The @id venue is named already, at position @first: a reorder names each venue once.', [
          '@id' => $id,
          '@first' => $first[$id],
        ]), Violation::TAKEN);
        continue;
      }
      $first[$id] = $delta;
      $copy = $this->copy($venues[$id], []);
      $copy->set('weight', (int) $item['weight']);
      foreach ($this->validate($copy, $copy->toArray()) as $violation) {
        $refused[] = new Violation('venues', $delta . '.weight', $violation->message, $violation->code);
      }
      $weights[$id] = (int) $item['weight'];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return ['weights' => $weights];
  }

  /**
   * Rehearses one venue, its slug and rooms held to what no other venue has.
   *
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The create or the update.
   * @param array $values
   *   The accepted values.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the venue is written as.
   *
   * @return array
   *   The venue's exported array.
   *
   * @throws \Drupal\surface\Pipeline\StorageViolationsException
   *   When another venue holds the slug, a room id is held twice, here or
   *   by another venue, or as ConfigEntityStorage refuses.
   */
  protected function venue(OperationContext $context, array $values, AccountInterface $account): array {
    $own = $context->known['id'] ?? $values['id'] ?? NULL;
    $elsewhere = [];
    foreach ($this->venues() as $id => $venue) {
      if ($id !== $own) {
        foreach ($venue->rooms() as $room) {
          $elsewhere[(string) $room['id']] = (string) $id;
        }
      }
    }
    $refused = [];
    $slug = $values['slug'] ?? NULL;
    foreach ($this->venues() as $id => $venue) {
      if ($id !== $own && is_string($slug) && $venue->slug() === $slug) {
        // A rule no schema can state, said by the storage that knows the
        // other venues; the pipeline names the key the slug is computed
        // from after it.
        $refused[] = new Violation('slug', '', $this->t('The slug @slug is held already, by @venue: a slug is held once across the venues.', [
          '@slug' => $slug,
          '@venue' => $id,
        ]), Violation::TAKEN);
      }
    }
    $seen = [];
    foreach (array_values(is_array($values['rooms'] ?? NULL) ? $values['rooms'] : []) as $delta => $room) {
      $id = is_array($room) && is_string($room['id'] ?? NULL) ? $room['id'] : NULL;
      if ($id === NULL) {
        continue;
      }
      if (isset($elsewhere[$id]) || isset($seen[$id])) {
        $refused[] = new Violation('rooms', $delta . '.id', $this->t('The room id @id is held already, by @venue: a room id is held once across the venues.', [
          '@id' => $id,
          '@venue' => $elsewhere[$id] ?? $own,
        ]), Violation::TAKEN);
      }
      $seen[$id] = TRUE;
    }
    try {
      $record = parent::prepare($context, $values, $account);
    }
    catch (StorageViolationsException $e) {
      $refused = [...iterator_to_array($e->getViolations(), FALSE), ...$refused];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    assert(isset($record));
    return $record;
  }

  /**
   * Loads every venue, lighter first, then by name.
   *
   * @return array<string, \Drupal\surface_examples\Entity\EventVenue>
   *   The venues, by machine name.
   */
  protected function venues(): array {
    $venues = array_filter($this->storage()->loadMultiple(), static fn ($venue): bool => $venue instanceof EventVenue);
    uasort($venues, [EventVenue::class, 'sort']);
    return $venues;
  }

}
```

The access class is what the listing filters by: a disabled venue is
only for an account that administers the venues, which only the venue
can say, so an account that only views them lists the enabled ones.

<!-- code: src/Access/VenueAccess.php -->

```php
final class VenueAccess implements OperationAccessInterface {

  /**
   * Constructs the access class, which the container builds as a service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the venue the context names.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    $id = $context->known['id'] ?? NULL;
    if ($account->hasPermission(VenueResource::ADMINISTER) || !is_string($id) || $id === '') {
      // Static: the account alone, or no venue to ask about.
      return AccessResult::allowed()->cachePerPermissions();
    }
    $venue = $this->entityTypeManager->getStorage(VenueStorage::ENTITY_TYPE_ID)->load($id);
    if (!$venue instanceof EventVenue) {
      return AccessResult::allowed()->cachePerPermissions()->addCacheTags(['config:' . VenueStorage::ENTITY_TYPE_ID . '_list']);
    }
    // Runtime: what is stored, under the venue's own tag.
    $answer = $venue->status() ? AccessResult::allowed() : AccessResult::forbidden('The venue is disabled, and only an account that administers the venues reads it.');
    return $answer->cachePerPermissions()->addCacheableDependency($venue);
  }

}
```

### The config schema, written from the resource

`drush surface:config-schema --write` writes the entity type's
config schema from the resource, as it writes a config object's: a
fully validatable `config_entity`, each key the storage maps to a
property, by the rules a config object's keys follow. The three venues
the module ships are config of their own, which no resource describes,
so no install file is written.

<!-- schema: surface_examples.event_venue.* -->

```yaml
surface_examples.event_venue.*:
  type: config_entity
  label: 'Venue'
  constraints:
    FullyValidatable: ~
  mapping:
    id:
      type: machine_name
      label: 'Machine name'
      constraints:
        Length:
          max: 32
    label:
      type: string
      label: 'Name'
      constraints:
        Length:
          max: 128
    slug:
      type: string
      label: 'Slug'
      nullable: true
      constraints:
        Regex:
          pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/D'
        Length:
          max: 128
    capacity:
      type: integer
      label: 'Capacity'
      constraints:
        Range:
          min: 1
          max: 100000
    address:
      type: string
      label: 'Address'
      nullable: true
      constraints:
        Length:
          max: 255
    rooms:
      type: sequence
      label: 'Rooms'
      constraints:
        Count:
          max: 20
      sequence:
        type: mapping
        label: 'Rooms'
        mapping:
          id:
            type: string
            label: 'Room id'
            constraints:
              Regex:
                pattern: '/^[a-z0-9_]+$/D'
              Length:
                max: 64
          label:
            type: string
            label: 'Room name'
            constraints:
              Length:
                max: 128
          seats:
            type: integer
            label: 'Seats'
            constraints:
              Range:
                min: 1
                max: 10000
    weight:
      type: integer
      label: 'Weight'
      constraints:
        Range:
          min: -1000
          max: 1000
    status:
      type: boolean
      label: 'Enabled'
```

### On HTTP

Over HTTP, the collection is the resource, one venue
its path, and the method the operation. `GET` on the collection answers
the listing, since the resource declares one: its query string the
listing's values, its answer the outputs. A resource with no listing
answers its create's envelope there, which is the create's custom
method here, `GET /surface-api/event.venue:create`. The reorder is a custom
method run with `PATCH`, the method an update takes.

| Method | Path | Operation | Answers |
| --- | --- | --- | --- |
| `GET` | `/surface-api/event.venue?limit=2` | `list` | the listing: its outputs, a page of venues and the total |
| `POST` | `/surface-api/event.venue` | `create` | 201 with the new venue's Location |
| `GET` | `/surface-api/event.venue:create` | `create` | the envelope a create starts from |
| `GET` | `/surface-api/event.venue/riverside` | `update` | the envelope: what is stored, its schema and its ETag |
| `PATCH` | `/surface-api/event.venue/riverside` | `update` | the update, held by If-Match |
| `DELETE` | `/surface-api/event.venue/riverside` | `delete` | the removal, no body |
| `PATCH` | `/surface-api/event.venue:reorder` | `reorder` | the reorder, a custom method on the collection |
| `GET` | `/surface-api/event.venue/schema` | `list` | the listing's full schema, its inputs and its outputs |

```
GET /surface-api/event.venue?limit=2

{"items":[{"id":"riverside","label":"Riverside Hall","capacity":520,"weight":0},{"id":"library","label":"Old Library","capacity":90,"weight":1}],"total":3}
```

The React pages are `/surface-react/event.venue/create` and
`/surface-react/event.venue/update?id=riverside`.

### The calls

Made in order on the venues the module ships, as the page draws them:
the listing, two to a page; Town Hall added as a dry run, then for
real, so the next calls find it; Town Hall read back, its slug computed
from its name; its capacity changed; a rename rehearsed, the slug kept
as it was set; a venue sent with a slug of its own, refused naming the
key the slug is computed from; a reorder rehearsed, Town Hall first and
Riverside Hall last; a reorder that names a venue the site does not
have and one twice, refused by the storage at each place; Town Hall's
removal rehearsed, then made, so the three venues shipped are what is
left. The calls never touch a venue
the site has: where one is named `town_hall` already, the page makes
each call as a dry run, says so, and writes nothing.

```bash
drush tool:run surface:event.venue:list --uid=1 --input='{"values":{"limit":2}}'
```

```
1. The venues listed, two to a page
Success: List the venues changes nothing: the values were accepted and prepared, and nothing was written.
  values: {"offset":0,"limit":2,"status":null}
  items: [{"id":"riverside","label":"Riverside Hall","capacity":520,"weight":0},{"id":"library","label":"Old Library","capacity":90,"weight":1}]
  total: 3
```

```bash
drush tool:run surface:event.venue:create --uid=1 --input='{"values":{"label":"Town Hall","id":"town_hall","capacity":300,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3}}' --input=dry_run=true
```

```
2. A fourth venue, Town Hall, added: rehearsed
Success: Dry run of Add a venue: the values were accepted and prepared, and nothing was written.
  values: {"label":"Town Hall","id":"town_hall","slug":"town-hall","capacity":300,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3,"status":true}
```

```bash
drush tool:run surface:event.venue:create --uid=1 --input='{"values":{"label":"Town Hall","id":"town_hall","capacity":300,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3}}'
```

```
3. Town Hall added: written, so the next calls find it
Success: Add a venue: the values were accepted and written.
```

```bash
drush tool:run surface:event.venue:update:read --uid=1 --input='{"id":"town_hall"}'
```

```
4. Town Hall read back: its slug, computed from its name
Success: Edit a venue: the stored values were read, and nothing was written.
  values: {"label":"Town Hall","slug":"town-hall","capacity":300,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3,"status":true}
```

```bash
drush tool:run surface:event.venue:update --uid=1 --input='{"id":"town_hall","values":{"capacity":350}}'
```

```
5. Town Hall's capacity changed: written
Success: Edit a venue: the values were accepted and written.
```

```bash
drush tool:run surface:event.venue:update --uid=1 --input='{"id":"town_hall","values":{"label":"Town Hall and Annex"}}' --input=dry_run=true
```

```
6. Town Hall renamed, rehearsed: the slug stays as it was set
Success: Dry run of Edit a venue: the values were accepted and prepared, and nothing was written.
  values: {"label":"Town Hall and Annex","id":"town_hall","slug":"town-hall","capacity":350,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3,"status":true}
```

```bash
drush tool:run surface:event.venue:create --uid=1 --input='{"values":{"label":"Concert Hall","id":"concert_hall","capacity":500,"slug":"concerts"}}' --input=dry_run=true
```

```
7. A venue sent a slug of its own: refused, naming the key it is computed from
Failed: The values were refused:
/slug: Slug is computed from label and takes no value of its own; leave it out.
```

```bash
drush tool:run surface:event.venue:reorder --uid=1 --input='{"values":{"venues":[{"id":"town_hall","weight":-1},{"id":"riverside","weight":4}]}}' --input=dry_run=true
```

```
8. Town Hall moved first and Riverside Hall last, in one write: rehearsed
Success: Dry run of Reorder the venues: the values were accepted and prepared, and nothing was written.
  values: {"venues":[{"id":"town_hall","weight":-1},{"id":"riverside","weight":4}]}
```

```bash
drush tool:run surface:event.venue:reorder --uid=1 --input='{"values":{"venues":[{"id":"concert_hall","weight":0},{"id":"library","weight":1},{"id":"library","weight":2}]}}' --input=dry_run=true
```

```
9. A reorder naming a venue the site does not have, and one twice: refused
Failed: The values were refused:
/venues/0/id: No venue is named concert_hall.
/venues/2/id: The library venue is named already, at position 1: a reorder names each venue once.
```

```bash
drush tool:run surface:event.venue:delete --uid=1 --input='{"id":"town_hall"}' --input=dry_run=true
```

```
10. Town Hall removed: rehearsed
Success: Dry run of Remove a venue: the removal was rehearsed, and nothing was removed. The values it holds are answered.
  values: {"id":"town_hall","label":"Town Hall","slug":"town-hall","capacity":350,"address":"1 Market Square","rooms":[{"id":"town_hall_council","label":"Council chamber","seats":120},{"id":"town_hall_foyer","label":"Foyer","seats":80}],"weight":3,"status":true}
```

```bash
drush tool:run surface:event.venue:delete --uid=1 --input='{"id":"town_hall"}'
```

```
11. Town Hall removed: written, so the three venues shipped are what is left
Success: Remove a venue: removed. The values it held are answered.
```

### Files

What example 15 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 15 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples/src/Entity/EventVenue.php`](src/Entity/EventVenue.php) | the entity type: its declaration, its keys and the properties it exports, and no form, list builder or link | 47 |
| [`modules/surface_examples/src/Resource/VenueResource.php`](src/Resource/VenueResource.php) | the resource: a venue by its machine name, computed from its name unless one is given, added, edited and removed, and the venues listed and reordered; its slug a computed key | 77 |
| [`modules/surface_examples/src/Slugify.php`](src/Slugify.php) | the slug's deriver: a pure function of the name, run when a venue is added | 6 |
| [`modules/surface_examples/src/Structure/VenueRoomStructure.php`](src/Structure/VenueRoomStructure.php) | a room: an item of the venue's rooms | 18 |
| [`modules/surface_examples/src/Structure/VenueListingStructure.php`](src/Structure/VenueListingStructure.php) | what the listing takes, a page and a filter, and what it answers, the page and the total | 27 |
| [`modules/surface_examples/src/Structure/VenueReorderStructure.php`](src/Structure/VenueReorderStructure.php) | what the reorder takes: a list of venues and their weights | 9 |
| [`modules/surface_examples/src/Structure/VenueOrderStructure.php`](src/Structure/VenueOrderStructure.php) | an item of that list: a venue and its new weight | 12 |
| [`modules/surface_examples/src/Storage/VenueStorage.php`](src/Storage/VenueStorage.php) | the storage: a ConfigEntityStorage, which reads the listing, writes the reorder in one commit, and holds a slug to one venue | 151 |
| [`modules/surface_examples/src/Access/VenueAccess.php`](src/Access/VenueAccess.php) | the access class: a disabled venue is only for an account that administers the venues | 17 |
| [`modules/surface_examples/config/schema/surface_examples.schema.yml`](config/schema/surface_examples.schema.yml), entry `surface_examples.event_venue.*` | the entity type's config schema, generated from the resource | 82 |
| [`modules/surface_examples/config/install/surface_examples.event_venue.riverside.yml`](config/install/surface_examples.event_venue.riverside.yml) | Riverside Hall, shipped | 18 |
| [`modules/surface_examples/config/install/surface_examples.event_venue.library.yml`](config/install/surface_examples.event_venue.library.yml) | the Old Library, shipped | 18 |
| [`modules/surface_examples/config/install/surface_examples.event_venue.harbour.yml`](config/install/surface_examples.event_venue.harbour.yml) | the Harbour Centre, shipped | 18 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step15_create` | the add form: the generic operation form | 8 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step15_update` | the edit form, its {id} the venue | 12 |
| [`modules/surface_examples/surface_examples.routing.yml`](surface_examples.routing.yml), entry `surface_examples.step15_delete` | the remove form: a confirmation, since a delete takes no values | 12 |
| [`modules/surface_examples/src/Form/VenueFormCosmetics.php`](src/Form/VenueFormCosmetics.php) | what the three forms say once they wrote, and the page they go back to | 20 |

## Example 16: references, three ways

`/surface-examples/16`, once its module is on:

```bash
drush pm:install surface_examples_references
```

The module needs node: the sponsorship's announcement is an article. It
ships the article and page bundles as optional config, for a site that
has neither, a role, Sponsorship editor, and when it is installed it
writes three nodes the calls name: an article, a draft article and a
page. Uninstalled, it deletes them.

A key that refers to an entity is declared by the data type string of
what it refers to, and every door sends and answers the entity's id, as
the entity type keeps it: an integer for a node or a user, a string for
a venue. The config object stores a node or a user by its uuid, which
names the same entity on a site the config is deployed to, and a venue
or a role by its id, its machine name.

<!-- code: ../surface_examples_references/src/Resource/SponsorshipResource.php -->

```php
#[Resource('event.sponsorship', storage: SponsorshipStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the sponsorship'),
  description: new TranslatableMarkup("Sets the sponsor's venue, contact and announcement, and what the sponsor is featured beside, linked to and credited on."),
  permission: 'set event sponsorship',
)]
final class SponsorshipResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('venue', 'entity:event_venue', t('Venue'), default: 'riverside')
      ->setDescription(t("Where the sponsor's stand is."))
      ->setRequired(TRUE);

    $inputs->add('contact', 'entity:user', t('Contact'))
      ->setDescription(t('Who answers for the sponsor.'));

    $inputs->add('page', 'entity:node:article', t('Announcement'))
      ->setDescription(t('The article that announces the sponsor.'));

    // Of any type: an entity type, then an id of it.
    $inputs->add('thing', 'entity', t('Featured beside'))
      ->setDescription(t('Any content the sponsor is featured beside.'))
      ->addConstraint('SurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);

    $inputs->add('setting', 'entity', t('Linked to'))
      ->setDescription(t('A venue or a role the sponsorship is linked to.'))
      ->addConstraint('SurfaceReferenceableType', [
        'interface' => ConfigEntityInterface::class,
        'targets' => ['event_venue' => [], 'user_role' => [], 'node' => []],
      ]);

    $inputs->add('credit', 'entity', t('Credited on'))
      ->setDescription(t('An article or a user the sponsor is credited on.'))
      ->addConstraint('SurfaceReferenceableType', [
        'targets' => ['node' => ['article'], 'user' => []],
      ]);
  }

}
```

| Key | Declared as | What it builds |
| --- | --- | --- |
| `venue` | `entity:event_venue` | a string, the venue's machine name, with `SurfaceValidReference` naming the entity type |
| `contact` | `entity:user` | an integer, a user's id, with the same constraint |
| `page` | `entity:node:article` | an integer, a node's id, the constraint naming the bundle too |
| `thing` | `entity`, content entities | two keys attached at the key: `entity_type`, a choice of the content entity types the account may reference one of, and `id`, a string, which a refiner narrows to the entity type chosen with the same constraint |
| `setting` | `entity`, config entities among three | the same two keys; of the three entity types it names, node is no config entity, so the venue and the role are offered |
| `credit` | `entity`, an article or a user | the same two keys; the bundle list narrows a node's id to articles |

Which entities a key may name is core's entity reference selection
handler's answer for the account asking: the `default` handler for the
entity type, the bundle as its target bundles, sorted by label. Its query
carries the entity type's access tag, so a node's leaves out unpublished
nodes for an account without "bypass node access", and a user's leaves
out blocked users without "administer users". The list varies by what
the handler read, `user.permissions` and, for a node, `user.node_grants:view`.

While the site has at most 50 candidates, the setting
`surface.settings:reference_list_limit`, the key lists them: a
select on the form, a `oneOf` with their labels in both documents, an
enum in the tool listing. Past it, the key is an id: core's
`entity_autocomplete` element on the form, `x-surface.widget:
autocomplete` and the constraint under `checkedOnServer` in both
documents, and a sentence in the tool listing naming where to look one
up: the resource's lookup, `GET
/surface-api/event.sponsorship:lookup?key=/contact&q=adm`, which answers
ids and labels through the same handler, for the same account. Both
documents mark every reference key `x-surface.reference`, `{"entityType":
"node", "bundles": ["article"]}`, or for a bare key's id `{"by":
"entity_type"}`. The page shows the keys as the full schema states them
now, and again with the limit held at 1 for its own request, as
`ddev drush config:set surface.settings reference_list_limit 1`
would set it, without saving it.

### The calls

Made on the settings the module ships and the three nodes it wrote, each
a dry run. The draft is sent as an account whose only role is
Sponsorship editor, which may not see unpublished content; Drush names
that account by its id, which the site chose when it made it.

```bash
drush tool:run surface:event.sponsorship:configure --uid=1 --input='{"values":{"venue":"library","contact":1,"page":1,"thing":{"entity_type":"node","id":1}}}' --input=dry_run=true
```

```
1. A venue, a contact, an article and a node of any content type: accepted
Success: Dry run of Configure the sponsorship: the values were accepted and prepared, and nothing was written.
  values: {"venue":"library","contact":1,"page":1,"thing":{"entity_type":"node","id":"1"},"setting":{"entity_type":null},"credit":{"entity_type":null}}
```

```bash
drush tool:run surface:event.sponsorship:configure --uid=1 --input='{"values":{"page":3}}' --input=dry_run=true
```

```
2. A page where an article is asked for: refused
Failed: The values were refused:
/page: This entity (node: 3) cannot be referenced.
```

```bash
# UID: an account whose only role, beside authenticated, is event_sponsorship_editor.
drush tool:run surface:event.sponsorship:configure --uid="$UID" --input='{"values":{"page":2}}' --input=dry_run=true
```

```
3. The draft article, as an account that may not see unpublished content: refused
Failed: The values were refused:
/page: This entity (node: 2) cannot be referenced.
```

```bash
drush tool:run surface:event.sponsorship:configure --uid=1 --input='{"values":{"setting":{"entity_type":"node","id":1}}}' --input=dry_run=true
```

```
4. A node where a config entity is asked for: refused
Failed: The values were refused:
/setting/id: ID does not apply.
/setting/entity_type: The entity type node cannot be referenced here.
```

### Files

What example 16 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 16 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples_references/surface_examples_references.info.yml`](../surface_examples_references/surface_examples_references.info.yml) | the module: a second one, which needs node for the article the announcement is | 11 |
| [`modules/surface_examples_references/src/Resource/SponsorshipResource.php`](../surface_examples_references/src/Resource/SponsorshipResource.php) | the resource: three keys declared by the data type string of what they refer to, and three of any entity type, each filtered | 31 |
| [`modules/surface_examples_references/src/Storage/SponsorshipStorage.php`](../surface_examples_references/src/Storage/SponsorshipStorage.php) | the storage: names the config object | 3 |
| [`modules/surface_examples_references/surface_examples_references.routing.yml`](../surface_examples_references/surface_examples_references.routing.yml), entry `surface_examples.step16` | the route: the generic operation form, with the examples' panels | 8 |
| [`modules/surface_examples_references/surface_examples_references.permissions.yml`](../surface_examples_references/surface_examples_references.permissions.yml) | its permission, to set the sponsorship | 3 |
| [`modules/surface_examples_references/config/schema/surface_examples_references.schema.yml`](../surface_examples_references/config/schema/surface_examples_references.schema.yml), entry `surface_examples_references.sponsorship` | the config schema, generated from the class: a node or a user typed uuid, which is how the object stores it, a venue its id; a bare key two strings | 53 |
| [`modules/surface_examples_references/config/install/surface_examples_references.sponsorship.yml`](../surface_examples_references/config/install/surface_examples_references.sponsorship.yml) | the settings the module ships with | 12 |
| [`modules/surface_examples_references/config/install/user.role.event_sponsorship_editor.yml`](../surface_examples_references/config/install/user.role.event_sponsorship_editor.yml) | the role of the account that may not see a draft, which one call is made as | 13 |
| [`modules/surface_examples_references/config/optional/node.type.article.yml`](../surface_examples_references/config/optional/node.type.article.yml) | the article bundle, for a site that has none | 10 |
| [`modules/surface_examples_references/config/optional/node.type.page.yml`](../surface_examples_references/config/optional/node.type.page.yml) | the page bundle, which the announcement refuses | 10 |
| [`modules/surface_examples_references/src/Hook/SponsorshipHooks.php`](../surface_examples_references/src/Hook/SponsorshipHooks.php) | writes the three nodes the calls name once the module is installed | 11 |
| [`modules/surface_examples_references/surface_examples_references.install`](../surface_examples_references/surface_examples_references.install) | deletes them when it is uninstalled | 3 |
| [`modules/surface_examples_references/src/Sponsorship.php`](../surface_examples_references/src/Sponsorship.php) | the three nodes: an article, a draft article and a page, their ids kept in State | 40 |
| [`modules/surface_examples_references/surface_examples_references.post_update.php`](../surface_examples_references/surface_examples_references.post_update.php) | stores as uuids the ids a site stored before the object stored uuids | 6 |
| [`src/Reference/References.php`](../../src/Reference/References.php) | what a reference key is: its definition, core's selection handler for its candidates, the limit and the lookup; the main module's | 234 |
| [`src/Reference/EntityReferenceStructure.php`](../../src/Reference/EntityReferenceStructure.php) | the two parts of a bare entity key, and the refiner that narrows its id to the entity type chosen; the main module's | 49 |
| [`src/Reference/ReferenceStorageLayout.php`](../../src/Reference/ReferenceStorageLayout.php) | how a config storage keeps a reference to content: by uuid, read back as the id; the main module's | 373 |
| [`src/Plugin/Validation/Constraint/ValidReferenceConstraint.php`](../../src/Plugin/Validation/Constraint/ValidReferenceConstraint.php) | the id's constraint, core's ValidReference for a key that holds one id; the main module's | 20 |
| [`src/Plugin/Validation/Constraint/ReferenceableTypeConstraint.php`](../../src/Plugin/Validation/Constraint/ReferenceableTypeConstraint.php) | the entity type's constraint, which the filters are options of; the main module's | 17 |
| [`src/Plugin/SurfaceOptionsResolver/ReferenceOptions.php`](../../src/Plugin/SurfaceOptionsResolver/ReferenceOptions.php) | the resolver that lists the candidates up to the limit; the main module's | 39 |
| [`src/Plugin/SurfaceWidget/AutocompleteWidget.php`](../../src/Plugin/SurfaceWidget/AutocompleteWidget.php) | core's entity autocomplete, for a key past the limit; the main module's | 59 |

## Example 17: retitle in bulk, access borrowed

`/surface-examples/17`, once its module is on:

```bash
drush pm:install surface_examples_retitle
```

The module needs node: it retitles nodes. It ships the page bundle as
optional config, for a site that has none, two roles, Node retitler
("retitle nodes" and core's "edit any page content") and Retitle
requester ("retitle nodes" alone), and when it is installed it writes
three basic pages its calls name. Uninstalled, it deletes them.

A module ships a resource of its own, with its own keys, storage and
permission, that acts on things another module owns. The retitle takes a
list of nodes and a style, and writes their titles. It may retitle only
a node the account may update and whose title it may edit, and those
two answers are not its own: they are node's access handler's, with
every module that alters node access or field access. The retitle names
them as gates, `gate:` on its `#[Operation]`, and asks them of each node
in the list. Nothing here copies a check, so a workflow, a group or a
field permissions module that changes who may edit a node changes what
the retitle may do, without being named.

<!-- code: ../surface_examples_retitle/src/Resource/RetitleResource.php -->

```php
#[Resource('ai.retitle', storage: RetitleStorage::class)]
#[Operation('retitle',
  label: new TranslatableMarkup('Retitle nodes'),
  description: new TranslatableMarkup('Rewrites the titles of one to fifty nodes in the style chosen, each node one the account may update and whose title it may edit; a new title sent is written as sent.'),
  permission: 'retitle nodes',
  gate: ['entity:node:update', 'field:node.title:edit'],
)]
final class RetitleResource implements ResourceInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attachList('items', RetitleItemStructure::class, min: 1, max: 50)
      ->setLabel(t('Nodes'))
      ->setDescription(t('Each node to retitle, one to fifty.'))
      ->setRequired(TRUE);

    $inputs->add('style', 'string', t('Style'), default: TitleRewriter::TITLE)
      ->setDescription(t('How the title of a node sent no new title is rewritten.'))
      ->addConstraint('LabeledChoice', ['choices' => TitleRewriter::styles()]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $title = MapDataDefinition::create()
      ->setLabel(t('Title'))
      ->setPropertyDefinition('node', DataDefinition::create('integer')->setLabel(t('Node'))->setRequired(TRUE))
      ->setPropertyDefinition('old', DataDefinition::create('string')->setLabel(t('Old title'))->setRequired(TRUE))
      ->setPropertyDefinition('new', DataDefinition::create('string')->setLabel(t('New title'))->setRequired(TRUE));
    $outputs->addList('titles', $title, t('Titles'))
      ->setDescription(t("Each node's title before and after, in the order sent: what a dry run would write, or what was written."))
      ->setRequired(TRUE);
  }

}
```

Each item is a node, which the gates ask about, and a new title, written
as sent when one is sent:

<!-- code: ../surface_examples_retitle/src/Structure/RetitleItemStructure.php -->

```php
#[Structure('ai.retitle.item')]
final class RetitleItemStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('node', 'entity:node', t('Node'))
      ->setDescription(t('The node to retitle.'))
      ->setRequired(TRUE);

    $inputs->add('new_title', 'string', t('New title'))
      ->setDescription(t("Written as sent; left out, the node's title is rewritten in the style chosen."))
      ->addConstraint('Length', ['max' => 255]);
  }

}
```

| Gate | What answers it |
| --- | --- |
| `entity:node:update` | core's update access on the node: node's access handler, `hook_node_access()`, node grants and every module answering `hook_entity_access()` |
| `field:node.title:edit` | core's edit access on the node's title: the handler's `fieldAccess()` and every module answering `hook_entity_field_access()`, this one's included |

Field access does not include entity access, which is why node's own
form asks both, and so does the retitle; each answers by itself, and a
node either refuses is refused. The operation's own permission, "retitle
nodes", is asked first; the tool listing declares it. Whether each node
may be retitled is settled per node, once the list is sent: the full
schema marks `items` `x-surface.live`, and the tool listing says on it
that it is decided once the thing it belongs to is known. A refusal
lands on the node's own item, `/items/0/node`, with what may not be done
to which node and the owner's reason, and the other items carry none.
The gates are asked before the storage's `prepare()`, so the storage
only ever sees items every gate allowed.

The new titles come from `TitleRewriter`, which is no model: title case,
sentence case, or title case after the prefix "Festival:", the same
every time. A dry run answers the titles before and after, which is
what the storage's `prepare()` rehearsed, and a write answers what it
wrote, one save per node whose title changes.

<!-- code: ../surface_examples_retitle/src/Storage/RetitleStorage.php -->

```php
final class RetitleStorage implements ResourceStorageInterface, ContainerInjectionInterface {

  /**
   * Constructs a RetitleStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads and saves the nodes.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, which keeps the titles the last retitle wrote.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('state'));
  }

  /**
   * {@inheritdoc}
   *
   * No values: a retitle names its nodes each time. The titles the last
   * one wrote, which are no key, are what a write reads back as its
   * output.
   */
  public function load(OperationContext $context): array {
    return ['titles' => array_values((array) $this->state->get(Retitle::LAST, []))];
  }

  /**
   * {@inheritdoc}
   *
   * Each item's node, its title, and the title it takes.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $style = is_string($values['style'] ?? NULL) ? $values['style'] : TitleRewriter::TITLE;
    $storage = $this->entityTypeManager->getStorage('node');
    $titles = [];
    $refused = [];
    foreach (array_values((array) ($values['items'] ?? [])) as $delta => $item) {
      $node = $storage->load($item['node'] ?? 0);
      if (!$node instanceof NodeInterface) {
        $refused[] = new Violation('items', $delta . '.node', sprintf('There is no node %s.', (string) ($item['node'] ?? '')), Violation::NOT_FOUND);
        continue;
      }
      $sent = $item['new_title'] ?? NULL;
      $new = is_string($sent) && $sent !== '' ? $sent : TitleRewriter::rewrite((string) $node->getTitle(), $style);
      if (mb_strlen($new) > 255) {
        $refused[] = new Violation('items', $delta . '.new_title', sprintf('The title %s rewrites to is longer than 255 characters.', $node->label()), 'length');
        continue;
      }
      $titles[] = ['node' => (int) $node->id(), 'old' => (string) $node->getTitle(), 'new' => $new];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return ['titles' => $titles];
  }

  /**
   * {@inheritdoc}
   *
   * One save per node whose title changes, then the list kept.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ($prepared['titles'] ?? [] as ['node' => $id, 'old' => $old, 'new' => $new]) {
      $node = $storage->load($id);
      if ($node instanceof NodeInterface && $old !== $new) {
        $node->setTitle($new)->save();
      }
    }
    $this->state->set(Retitle::LAST, $prepared['titles'] ?? []);
  }

}
```

On a site whose pages are moderated, the three pages are written as
drafts, which an account that may not see unpublished content cannot
name, and core's update access asks the workflow too, refusing an
account without a transition: the reference key asks the first and the
gate the second, so the calls there are refused before they write.

### The calls

Made on the three pages the module wrote, as an account whose only
role, beside authenticated, is Node retitler, but the second, made as
one whose only role is Retitle requester. The third is made while the
module's field access hook locks the third page's title, which a State
flag turns on. The fourth writes, the fifth rehearses the same call
again, and the last puts each title back as it was installed. While a
page is titled otherwise, by the form or a call of yours, the page makes
every call as a dry run, so it never undoes what was saved. Drush names
each account by its id, which the site chose when it made it.

```bash
# UID: an account whose only role, beside authenticated, is node_retitler.
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4},{"node":5},{"node":6}],"style":"title"}}' --input=dry_run=true
```

```
1. Three pages in title case, rehearsed as the retitler: accepted, each new title shown
Success: Dry run of Retitle nodes: the values were accepted and prepared, and nothing was written.
  values: {"items":[{"node":4,"new_title":null},{"node":5,"new_title":null},{"node":6,"new_title":null}],"style":"title"}
  titles: [{"node":4,"old":"volunteer briefing for the night shift","new":"Volunteer Briefing for the Night Shift"},{"node":5,"old":"WHERE TO PARK ON FESTIVAL DAYS","new":"Where to Park on Festival Days"},{"node":6,"old":"menu of the food stalls","new":"Menu of the Food Stalls"}]
```

```bash
# UID: an account whose only role, beside authenticated, is retitle_requester.
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4},{"node":5},{"node":6}],"style":"title"}}' --input=dry_run=true
```

```
2. The same pages, as an account that may retitle and edit no page: refused, on each page
Failed: The values were refused:
/items/0/node: You may not update volunteer briefing for the night shift: The 'edit any page content' permission is required.
/items/1/node: You may not update WHERE TO PARK ON FESTIVAL DAYS: The 'edit any page content' permission is required.
/items/2/node: You may not update menu of the food stalls: The 'edit any page content' permission is required.
```

```bash
# UID: an account whose only role, beside authenticated, is node_retitler.
drush state:set surface_examples_retitle.locked 1
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4},{"node":6}],"style":"title"}}' --input=dry_run=true
drush state:delete surface_examples_retitle.locked
```

```
3. The first and third pages, while the third one's title is locked: refused by the field gate alone, on the third
Failed: The values were refused:
/items/1/node: You may not edit the title of menu of the food stalls: The menu is at the printer, so its title is locked.
```

```bash
# UID: an account whose only role, beside authenticated, is node_retitler.
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4},{"node":5},{"node":6}],"style":"title"}}'
```

```
4. The three pages retitled as the retitler: written, one save per page
Success: Retitle nodes: the values were accepted and written.
  titles: [{"node":4,"old":"volunteer briefing for the night shift","new":"Volunteer Briefing for the Night Shift"},{"node":5,"old":"WHERE TO PARK ON FESTIVAL DAYS","new":"Where to Park on Festival Days"},{"node":6,"old":"menu of the food stalls","new":"Menu of the Food Stalls"}]
```

```bash
# UID: an account whose only role, beside authenticated, is node_retitler.
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4},{"node":5},{"node":6}],"style":"title"}}' --input=dry_run=true
```

```
5. Read back: the same call rehearsed, each title now the one written
Success: Dry run of Retitle nodes: the values were accepted and prepared, and nothing was written.
  values: {"items":[{"node":4,"new_title":null},{"node":5,"new_title":null},{"node":6,"new_title":null}],"style":"title"}
  titles: [{"node":4,"old":"Volunteer Briefing for the Night Shift","new":"Volunteer Briefing for the Night Shift"},{"node":5,"old":"Where to Park on Festival Days","new":"Where to Park on Festival Days"},{"node":6,"old":"Menu of the Food Stalls","new":"Menu of the Food Stalls"}]
```

```bash
# UID: an account whose only role, beside authenticated, is node_retitler.
drush tool:run surface:ai.retitle:retitle --uid="$UID" --input='{"values":{"items":[{"node":4,"new_title":"volunteer briefing for the night shift"},{"node":5,"new_title":"WHERE TO PARK ON FESTIVAL DAYS"},{"node":6,"new_title":"menu of the food stalls"}]}}'
```

```
6. Each title sent as it was installed: written, so the pages are as the module wrote them
Success: Retitle nodes: the values were accepted and written.
  titles: [{"node":4,"old":"Volunteer Briefing for the Night Shift","new":"volunteer briefing for the night shift"},{"node":5,"old":"Where to Park on Festival Days","new":"WHERE TO PARK ON FESTIVAL DAYS"},{"node":6,"old":"Menu of the Food Stalls","new":"menu of the food stalls"}]
```

### Files

What example 17 is made of, as its page lists them, each with its lines
of code, counted without blank lines or comments:

<!-- files: 17 -->

| File | What it is | Lines |
| --- | --- | --- |
| [`modules/surface_examples_retitle/surface_examples_retitle.info.yml`](../surface_examples_retitle/surface_examples_retitle.info.yml) | the module: a third one, which needs node for the pages it retitles | 11 |
| [`modules/surface_examples_retitle/src/Resource/RetitleResource.php`](../surface_examples_retitle/src/Resource/RetitleResource.php) | the resource: a list of nodes and a style, its own permission, and two gates borrowed from core, a node's update and its title's edit | 28 |
| [`modules/surface_examples_retitle/src/Structure/RetitleItemStructure.php`](../surface_examples_retitle/src/Structure/RetitleItemStructure.php) | an item: the node, which the gates ask about, and the new title, if one is sent | 11 |
| [`modules/surface_examples_retitle/src/TitleRewriter.php`](../surface_examples_retitle/src/TitleRewriter.php) | the rewriter: title case, sentence case or a prefix, the same every time, where a model would suggest a title | 38 |
| [`modules/surface_examples_retitle/src/Storage/RetitleStorage.php`](../surface_examples_retitle/src/Storage/RetitleStorage.php) | the storage: works out each title, then saves each node, and answers the titles before and after | 46 |
| [`modules/surface_examples_retitle/surface_examples_retitle.routing.yml`](../surface_examples_retitle/surface_examples_retitle.routing.yml), entry `surface_examples.step17` | the route: the generic operation form, with the examples' panels | 8 |
| [`modules/surface_examples_retitle/surface_examples_retitle.permissions.yml`](../surface_examples_retitle/surface_examples_retitle.permissions.yml) | its permission, to ask for a retitle at all | 3 |
| [`modules/surface_examples_retitle/config/optional/user.role.node_retitler.yml`](../surface_examples_retitle/config/optional/user.role.node_retitler.yml) | the role the calls are made as: the permission, and core's "edit any page content", optional config so it is installed once the page bundle is there | 16 |
| [`modules/surface_examples_retitle/config/install/user.role.retitle_requester.yml`](../surface_examples_retitle/config/install/user.role.retitle_requester.yml) | the role of the account the gate refuses: the permission alone | 13 |
| [`modules/surface_examples_retitle/config/optional/node.type.page.yml`](../surface_examples_retitle/config/optional/node.type.page.yml) | the page bundle, for a site that has none | 10 |
| [`modules/surface_examples_retitle/src/Hook/RetitleHooks.php`](../surface_examples_retitle/src/Hook/RetitleHooks.php) | writes the three pages once the module is installed, and locks the third one's title while a State flag is set, as another module would through core's field access | 26 |
| [`modules/surface_examples_retitle/surface_examples_retitle.install`](../surface_examples_retitle/surface_examples_retitle.install) | deletes them when it is uninstalled | 3 |
| [`modules/surface_examples_retitle/src/Retitle.php`](../surface_examples_retitle/src/Retitle.php) | the three pages, their ids kept in State | 38 |
| [`src/ResourceBuild/Gates.php`](../../src/ResourceBuild/Gates.php) | what answers a gate: core's access handler, or another resource's operation; the main module's | 87 |
| [`src/ResourceBuild/ItemGates.php`](../../src/ResourceBuild/ItemGates.php) | the gates asked once per item, before the storage sees it; the main module's | 35 |

## How this page is kept true

Every code block above is the file it names, from its first attribute
or its class line to its closing brace, and `ExamplesReadmeTest` fails
when one drifts, and so does the component example 12 quotes. Each
example's Files table is its list in `Series`, the one its page and its
line on the landing page are drawn from, and `ExampleFilesTest`
fails when a table and the list differ, or the list and the module. The Drush
commands and answers of examples 7 to 17 are asserted by
`ExamplesConceptsToolTest`, and example 15's config schema entry is the
schema file's, which `ExamplesReadmeTest` checks. The JSON of example 1's config schema is asserted by
`ConfigSchemaContractTest`, which also checks this page quotes it. The tool's three answers are asserted by
`ExamplesToolTest`, which also checks this page quotes them, and the
Drush commands are checked against `ExampleCalls`.

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesStepsTest` | Examples 1 to 3: each form builds and saves, example 1 as its classic twin does; example 2's twin refuses a capacity beyond the room's seats or below 1 in the resource's words and stores a valid set as the resource does; the venue narrows the room and the room the capacity; the pricing chooses the ticket variant; the contact validates its email; the panel's rows as the answers move; each example's class stays screen sized. |
| `Kernel\ExamplesComplianceTest` | Example 4: the alter's keys and label; its keys in one fieldset titled "Compliance", drawn between the capacity and the pricing; the capacity capped at a hundred without a license and the room's limit with one; the license's pattern; the stewards' minimum following the capacity; what the license and the capacity replace on the form; a room closed in State left out, said in the room's help, and refused on submit; example 5's calls unchanged. |
| `Kernel\StaticRefinerEnforcementTest` | Examples 2 and 3, without example 4 and with it: every conditional the served contract enumerates, in the full schema and in the schema for no values and for the defaults, is what refining afresh for its values answers, and example 4's declared license rule is what refining with the rules alone answers for sampled values. |
| `Kernel\ExamplesToolTest` | Example 5: the three calls and their answers; what the script prints. |
| `Kernel\ConfigSchemaContractTest` | What config schema can say: the plain mapping of examples 1 and 3, whole, with no list, no bound, nothing required and no keys for the ticket, the same whatever is stored or mounted; core's answer, a comment and no property; a constraint a schema does declare is said; the JSON this page quotes. |
| `Kernel\ExamplesTodayPanelTest` | The second panel under examples 1 to 3: its two documents and its table, row by row; its rows read from the resource's full schema, so the pricing, the venue and what is stored leave every row and both documents as they were; example 4's keys on the resource's side only; the classic twins' pages, example 2's with its two line counts. |
| `Unit\ExamplesReadmeTest` | This page against the files it quotes, its Drush commands, and the two line counts it states for example 2's twin. |
| `Unit\ExampleFilesTest` | Each example's files in `Series`: every one there, every resource, storage, structure, shape, provider, access class, alter, component, route, config schema entry and shipped settings file of the examples listed by one example, what a listed class names listed with it or before it, each docs page and decision there, the line counts, and each Files table on this page the list's. |
| `Functional\ExamplesRoutesTest` | Every route answers an administrator and refuses anonymous; the landing page; both panels under example 3, and a save through it; each classic twin's page with its two documents, and a save through it; example 2's twin linked from the landing page with its line counts, and its page saying them; the Files panel of examples 1 to 5, the twins included, each file linked with its lines of code and a short one shown whole; under each example on the landing page, what it introduces, where it is explained, the decision and the file count, one line per example from `Series`. |
| `Functional\ExamplesStep2ClassicTest` | Example 2 and its twin posted the same answers without JavaScript: a room of another venue refused on the room by both, a capacity beyond the room's seats and one below 1 refused by both in the same words, and a valid set stored identically by each. |
| `FunctionalJavascript\ExamplesStep2ClassicAjaxTest` | Example 2's twin in a browser: a new venue swaps the room's options and leaves it on "- Select -" with nothing stored changed, a room describes the capacity, the venue moved back shows the stored room again, and a save after a move writes the new venue and room. |
| `FunctionalJavascript\ExamplesAjaxRefreshTest` | In a browser: example 2's venue replaces the room and the capacity and leaves the venue the same node, the orphaned room on its empty option with the stale marker and the capacity with no upper bound; a room then narrows the capacity and loses `- Select -`. Example 3's paid pricing swaps the note for a price and a currency, and leaves the second panel's table and its two documents as they were. |
| `FunctionalJavascript\ExamplesComplianceRefreshTest` | Example 4, in a browser: one fieldset titled "Compliance", the ceiling of 100 lifted to the main hall's 400 by `2048`, and the stewards at 1 for 50 and 3 for 150; a malformed license on blur is said once, under the license, with `aria-invalid` and nothing at the top, and caps the capacity at a hundred; corrected, the error goes and the room's limit comes back. A refused capacity left by a click on text keeps no focus, and left by Tab lands on the Compliance fieldset after it. The fieldset is drawn below the capacity and above the pricing, and stays there when `2048` and Tab lift the capacity, which, with the stewards, is all that is replaced. With the East room closed in State, choosing Riverside Hall offers the main hall alone and says why. |
| `Functional\ServedContractEndpointsTest` | In React: the landing page's links, and example 2's contract, refine and validate over HTTP. |
| `Functional\ServedSubmitEndpointTest` | In React: example 2 saved over HTTP, refused, and refused after someone else saved. |
| `Kernel\ExamplesResetTest` | Reset to defaults puts every example, and what another module stored on example 3, back to the shipped files, puts example 15's venues back as shipped, a venue removed made again and one added removed, and opens every room example 4 closed. |
| `Kernel\ExamplesConceptsTest` | Examples 7 to 12 and 14: each form builds and saves; example 7's rules in the full schema as declared, the send day gone for a monthly bulletin and kept in storage; example 8's one def at two sites with each wiring, the mixed-in keys flat, the shape at two keys, the volunteers' collection; example 9's keys from the configuration, a type added without a cache rebuild, the locked type in both documents and on the form; example 10's fees for two accounts, locked once published, and both operations' facts and tools; example 11's widgets, the always held badges and the notes that clear; example 12's four components read as four variants at the props and again at the slots, the card's props as keys with their constraints, its text a slot, both documents stating them, the form swapped to the hero and saved with its slot, a hero too dark refused; the panel above each, and none above examples 1 to 3; each class stays screen sized; example 13's page with its modules off, saying how to enable both, then with the block alone on, its parts and no call, then with the placement resource on too, the Spotlight and Tonight blocks placed through the create operation, the three lines side by side, the block's contract cut to the four components, the hero's actions narrowed, the four placement tools and the five calls' answers, nothing written.; example 14's panel with its module off, then on: how to enable it, the one source and then two in the contract excerpt, the webhook's rule, the use paragraph and the line naming the key a post is signed with, the lock not shipped, and the calls each state allows. |
| `Kernel\ExamplesWorkflowTest` | Example 10 with the workflow module: the fees left out of the full schema, the tool listing and the read tool for an account without "see event fees" and kept for one with it; the program locked whole once the workflow state is published, settled at call time and left unmarked in the full schema; checking still answers; the panel's paragraph, with the module and without it. |
| `Kernel\ExamplesConceptsToolTest` | Examples 7 to 17: every call `ConceptCalls` writes down, its answer, nothing left written, the two blocks and the venues included, and this page quoting each command and answer. |
| `Kernel\VenueResourceTest` | Example 15: the entity type installs with its three venues and holds to its generated schema; the resource builds for each operation; both documents of create, update, list and reorder; the listing's outputs schema; the tools, the listing paged and filtered and an account that only views left without the disabled venue; the reorder written in one commit, and refused for an unknown venue and one named twice; the slug computed from the name on create and kept on a rename, left out of the write and in the read, a slug sent equal noted and another refused, one another venue holds refused naming the name, and the post-update writing a missing one; example 2 offering a venue added, with no cache rebuild. |
| `Functional\VenueApiTest` | Example 15 over HTTP and through its forms: `GET` on the collection answering the listing, a refused query, `POST` 201 with `Location`, the slug written and read, `POST .../schema` answering the slug it would compute, a `PATCH` sending another slug refused, the venue's `PATCH` and `DELETE`, the reorder's `PATCH` and its 405 for `POST`; the create and update forms saving, the slug drawn fixed; the delete form's confirmation removing; the page and its calls. |
| `Kernel\ReferenceKeysTest` | Example 16: the three spellings and what each builds; both documents of the sponsorship pinned whole under the limit and past it, under `tests/fixtures/references/`; the candidates the account's, cached by what they read; the refusals, in one message; the lookup and what it leaves out; the tool listing's enum and the sentence naming the lookup; the OpenAPI document's lookup; the page's panel. |
| `Kernel\RetitleExampleTest` | Example 17: the module writes its three pages and its two roles, and deletes the pages when uninstalled; both documents of the retitle pinned whole under `tests/fixtures/retitle/`, the list marked `live` in the full schema alone; the tool listing's permission, its description and the sentence on the list; the rewriter the same every time, and again on what it wrote; the page's form and panel, the gates, the calls, and each title put back. |
| `Functional\RetitleFormTest` | Example 17 through its form and over HTTP: anonymous refused; an account that may edit no page refused on its page, nothing written; the retitler's save written, and the page then making its calls as dry runs; `PATCH` refused with a 422 per item, a dry run answering the titles it would write, a write answering what it wrote, and the titles read back. |
| `Functional\ReferenceKeysFormTest` | Example 16 without JavaScript: a select up to the limit, core's entity autocomplete past it, the article saved by its uuid either way and drawn back as its id, and refused in the constraint's words; the lookup answering, refusing an account without the permission and leaving the draft out for one that may not see it; the React page told where the lookup is, and the full schema stating a bare key's id per entity type. |
| `Functional\ExamplesConceptsRoutesTest` | Every route of examples 7 to 12 and 14 answers an administrator and refuses anonymous; the landing page lists them; each page shows its panel and its Files panel and saves through its form; example 10's check writes nothing. Example 13 refuses anonymous, says to enable its two modules, then, with them on, shows its parts and the calls' answers, and draws the Spotlight card and the Tonight section below on its page alone; its Files panel, the modules on or off. |
| `Kernel\ExamplesExtensionTest` | Example 14 with the module that manages keys off, then on: both resources' full schemas, whole; the one source stated as `const` and drawn fixed, then two; the second source and the managed key there with no cache rebuild, in the documents and the tool; a pasted key kept across a switch to the managed key and back, and the config schema each source's stored value holds to; the read tool leaving the pasted key out and naming the managed key; the refusals, a pasted key sent while the managed source is chosen, a pasted key beside a managed key on the webhook, a key the site does not manage; the webhook's pasted key back with its value once the module is off; the signer, the webhook's code, signing with the pasted key while the module is off or no managed key is chosen and with the managed key's value once one is, the pasted key kept in config and the managed value stored nowhere, and with no key for a name the site no longer manages; and this page quoting the documents, the use and the lock it does not ship. |
| `Functional\ExamplesExtensionFormTest` | Example 14 through its forms without JavaScript: the one source drawn as its label and no select, then a select of two; the mailer's pasted key saved, the managed key chosen and the pasted source chosen back with nothing typed, the key kept; the webhook's pasted box gone once a managed key is saved, and back when the module is off. |
| `Kernel\ComponentBlockTest` | Example 13's block: its structure and both documents, the hero's narrowed slot pinned, and what it renders, slots included ([its README](../surface_examples_component_block/README.md)). |
| `Kernel\ComponentSlotsTest` | What a slot expects read back from the metadata, the depth slots nest to, and each expectation a refusal. |
| `Kernel\BlockPlacementToolsTest` | Example 13's block placed, configured, read and removed through the placement resource's tools ([its README](../surface_examples_block/README.md)). |
| `Functional\ComponentBlockPlacementTest` | Example 13's block through the block form without JavaScript. |
| `FunctionalJavascript\ComponentBlockAjaxTest` | Example 13's block in a browser: a component chosen draws its props and slots over AJAX, a slot's kind switched draws what it holds, a nested component chosen draws its own props, and the block saved renders it. |
| `FunctionalJavascript\ExamplesSpotlightAjaxTest` | Example 12 in a browser: choosing the hero swaps the card's props for the hero's over AJAX, the form saved stores the hero and its props, and the page read back draws them. |
| `Kernel\SchemaRoundTripTest` | Every example's full schema, read back by the reader, is itself again, byte for byte, or differs only as its listed kind says. |
