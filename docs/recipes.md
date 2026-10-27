# Common settings recipes

Nine things a resource often asks for that no other page shows end to
end. The snippets of the first six are keys of the test module's
opening hours (`resource_test.opening_hours`, in
`tests/modules/data_surface_resource_test`), written into its
`defineInputs()` as shown here; the seventh quotes the same module's
workshop, the eighth the schema test module's alias settings
(`schema_test.aliases`, in `tests/modules/data_surface_schema_test`),
and the last example 15's venue.
A test fails if this page and the class differ, and another holds each
key to what this page says it does. Read
[Your first resource](first-resource.md) first: a recipe is a few more
lines in the same method. To try them on a page, example 11 of the
examples module, `/surface-examples/11`, holds a time, checkboxes,
radios, a text area and a list of free strings, and shows what a key
left out, sent null or sent a value does.

This is a page of its own, linked from the first resource and from
[Concepts](concepts.md), because neither can carry it: the first page is
held to four ideas by a test, and Concepts is one line per idea, saying
when you need it and not how.

## A time of day

Core has no data type for a time of day, so a time is a string with a
pattern, which asks the form for a time picker:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('opens', 'string', t('Opens at'), default: '09:00')
  ->setRequired(TRUE)
  ->setDescription(t('HH:MM, on a 24-hour clock.'))
  ->setSetting('widget', 'time')
  ->addConstraint('Regex', [
    'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
    'message' => t('Write the time as HH:MM, such as 09:00.'),
  ]);
```

The form draws the browser's time input, the served contract says
`x-surface.widget: "time"` and React draws the same; the browser posts
`09:00`, and the pattern is still the rule, at every door
([Widgets](widgets.md#which-element-a-list-of-choices-is)).

Prefer the string. What a person picks, what an agent sends and what the
config object stores are the same `09:00`, a refusal is the pattern's
own message, and the pattern reaches both schemas (it ends in `$` with
the `D` modifier, which both engines read alike; why:
[decision](decisions.md#a-pattern-both-engines-read-alike)). Take an
integer of minutes after midnight instead when something compares the
time: a bound, as the closing time below, or a
[declared rule](refinement.md#declaring-a-rule), whose conditions read
numbers and never the order of two strings. The form then shows a
number, `1020` for 17:00, which is the price.

## An unconditional rule is a constraint

"The library closes later than noon" holds whatever any other key holds,
so it is no rule between keys: it is what the key allows, a constraint
in `defineInputs()`:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('closes', 'integer', t('Closes at, in minutes after midnight'), default: 1020)
  ->setRequired(TRUE)
  ->setDescription(t('Later than noon: 1020 is 17:00.'))
  ->addConstraint('Range', ['min' => 721, 'max' => 1439]);
```

A declared rule is a condition and an effect. Written with no condition,
`$inputs->narrow('closes')->constrain('Range', ['min' => 721])` is
refused when the resource is built ("... says no ->when(). A rule is a
condition and at least one effect"), and the resource has no tool until
it is fixed. Reach for `narrow()` only when another key's value decides.

## A multi-select

A list whose item is a choice: the `LabeledChoice` goes on the item, and
`addList()` declares the list around it, labeled, defaulted and required
as `add()` declares any key. Up to twelve choices it is drawn as
checkboxes.

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$day = DataDefinition::create('string')
  ->addConstraint('LabeledChoice', [
    'choices' => [
      'mon' => t('Monday'),
      'tue' => t('Tuesday'),
      'wed' => t('Wednesday'),
      'thu' => t('Thursday'),
      'fri' => t('Friday'),
      'sat' => t('Saturday'),
      'sun' => t('Sunday'),
    ],
  ]);
$inputs->addList('days', $day, t('Days open'), default: ['mon', 'tue', 'wed', 'thu', 'fri'])
  ->setRequired(TRUE)
  ->addConstraint('UniqueItems', []);
```

`addList()` takes the item as a type name (`'string'`), a shape's class,
or a definition, as here, when the item has constraints of its own, and
returns the list for the rest of the core setters.

The form draws checkboxes, one per day, the served contract says
`x-surface.widget: "checkboxes"`, React draws its checkboxes, and the
tool lists the days as the items' values. Every box unchecked is the
empty list; boxes left as drawn keep the days. A list of more than
twelve choices is a multiple select, and
`setSetting('widget', 'checkboxes')` or `setSetting('widget', 'select')`
on the list asks for one or the other at any length
([Widgets](widgets.md#which-element-a-list-of-choices-is)). A day not on
the list is refused by its position ("days.1: The value you selected is
not a valid choice."), and a day listed twice by the list ("days: The
item "mon" is listed more than once."), which both schemas state as
`uniqueItems: true`. An item is never null in either schema: an empty
item is dropped before anything reads the list
([Value semantics](semantics.md#lists)). Required means configured, so
an empty list satisfies it
([Declaring a resource](declaring-a-resource.md#required)), and no box
is required on the form.

## A text area

A string with core's `multiline` setting:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('notes', 'string', t('Notes'))
  ->setSetting('multiline', TRUE);
```

The string widget draws a `textarea`, with no `maxlength` and no
placeholder, and the served contract says `x-surface.widget:
"textarea"` ([Widgets](widgets.md#the-stock-widgets)). Its config schema
type is `string`, which the generator writes
([Storage](storage.md#a-schema-generated-from-the-resource)): config
schema's `text` is a string marked translatable, and a resource cannot
say yet that a key is translatable.

## Applies only when another key holds a value, and is then required

The door code means something only while after-hours access is on, and
then it must be given. Declare it required in `defineInputs()`, as it is
wherever it applies, and say where it does not apply with a declared
rule: it does not apply `unless()` after-hours access is on.

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('after_hours', 'boolean', t('After-hours access'), default: FALSE);
$inputs->add('door_code', 'string', t('Door code'))
  ->setRequired(TRUE)
  ->addConstraint('Length', ['min' => 4, 'max' => 12]);
$inputs->narrow('door_code')
  ->unless(Condition::equals('after_hours', TRUE))
  ->doesNotApply();
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`unless($c)` is `when(Condition::not($c))`, read without the double
negative: the door code does not apply unless after-hours access is on.
Both schemas state it as `when()` would, and the conditional requiring the
code wherever it applies tests `after_hours` itself, with no `not` around
a `not`.

Not `->require()` in a rule: required where it applies is what
`defineInputs()` already says, a key that does not apply is never
required, and the build refuses a rule requiring a key a refiner may
rule out ([Declaring a rule](refinement.md#declaring-a-rule)). With after-hours access off, the form draws no
element for the code, a code sent for it is refused ("Door code does
not apply when After-hours access is off."), and the stored code is
kept for the day it applies again. With it on, the code is required
("Door code is required."). How each door treats such a key:
[When a key does not apply](refinement.md#when-a-key-does-not-apply);
what a form posted without JavaScript keeps:
[Value semantics](semantics.md#a-key-that-does-not-apply).

**A list** is the case where required does not say what a reader
means. Required means configured, and the empty list is a value, so a
required list that applies is satisfied by no item at all: neither
`setRequired()` nor a rule's `->require()` asks for one. "At least one
day while after-hours access is on" is a `Count` with a minimum of 1,
with its own message, in `defineInputs()` beside the same rule: the
count is what the list allows wherever it applies, and a key that does
not apply is never validated, so the condition stays the rule's alone.

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->addList('late_days', $day, t('After-hours days'), default: [])
  ->setRequired(TRUE)
  ->addConstraint('Count', [
    'min' => 1,
    'minMessage' => t('Choose at least one day for after-hours access.'),
  ]);
$inputs->narrow('late_days')
  ->unless(Condition::equals('after_hours', TRUE))
  ->doesNotApply();
```

With after-hours access on and no day checked, every door refuses it
("Choose at least one day for after-hours access."), and the full
schema says `minItems: 1`. A list that always applies, and needs an item only
while another key holds a value, takes the count in the rule instead,
`->constrain('Count', ['min' => 1, 'message' => t('...')])` after its
`->when()` ([Declaring a rule](refinement.md#declaring-a-rule)); its
refusal is the rule's message, with the condition after it, or core's
sentence when the rule gives none.

**A key left out keeps what is stored**
(why: [decision](decisions.md#absent-keeps-null-clears)), and that meets
required on an edit: a key required by its declaration, or by a rule's
`->require()`, is refused only when it is sent empty (`""` or `null`)
or nothing is stored for it. Turning after-hours access on with a code
and a day already stored needs neither sent again, and a tool call that
leaves the code out is accepted; one that sends `"door_code": ""` is
refused ("Door code is required.").

## A list of free strings

A list whose item is a plain string, such as keywords, is a list to the
tool, both schemas and React, and on the form too: the stock list widget
draws it as rows, one text field per keyword, with "Remove" beside each
and "Add another" under them, each rebuilding the list over AJAX. Nothing
to declare but the list, its item a type name:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->addList('keywords', 'string', t('Keywords'), default: []);
```

A row left empty is dropped, as the pipeline drops an empty item; what a
keyword may be (a `Length`, a `Regex` on the item definition) reaches
each row's field, and a refused keyword is marked on its own row. The
served contract says `x-surface.widget: "list"`. A text area of one
keyword per line, or a comma separated list, is still a widget of your
own if you want one ([Writing a widget](widgets.md#writing-a-widget));
the demo extras module ships the second, `CommaSeparatedListWidget`.

## One key per thing the site has

A checkbox per form the site has, a select per punctuation character: the
keys are the site's, so a provider lists them and the resource declares
the map they sit in. The workshop holds a light switch, a stock code and
a bench per bench its configuration lists:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Workshop/WorkshopResource.php -->

```php
$inputs->attachEach('benches', BenchStructure::class, from: WorkshopBenches::class)
  ->setLabel(t('Benches'));
$inputs->addEach('lights', 'boolean', from: WorkshopBenches::class, default: FALSE)
  ->setLabel(t('Lights'))
  ->setDescription(t('Which benches have their lamp on.'));
$inputs->addEach('codes', Sku::class, from: WorkshopBenches::class)
  ->setLabel(t('Bench codes'));
```

`addEach()` is one value per thing, of a type or a shape; `attachEach()`
is one structure per thing. The provider is a class of its own, never
the resource, and says what it read, so the form, both schemas and the
tool listing change when the configuration does:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Keys/WorkshopBenches.php -->

```php
public function list(): SiteList {
  $config = $this->configFactory->get(self::CONFIG);
  $keys = (new SiteList())->addCacheableDependency($config);
  foreach ($config->get('benches') ?? [] as $id => $bench) {
    $keys->add((string) $id, (string) $bench['label'], locked: (bool) ($bench['locked'] ?? FALSE));
  }
  return $keys;
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A bench added to the configuration is a key at the next request, at
every door. A bench marked locked there is drawn fixed and keeps what is
stored. The bench structure's rule, no hours for a bench out of use,
applies at every bench. Store the map as a `sequence` in config schema.
A list that cannot be cached is refused: declare the tags of what the
provider reads ([Keys that come from the
site](resources.md#keys-that-come-from-the-site)).

## Asked as checkboxes, stored as a list of ids

Pathauto asks for a checkbox per entity type and stores the list of the
types checked, `enabled_entity_types: [node, user]`. Ask for what a
person and an agent should send, a map of booleans from a provider
([above](#one-key-per-thing-the-site-has)), and let the storage
translate. The alias settings ask for their types so:

<!-- excerpt: tests/modules/data_surface_schema_test/src/Resource/AliasSettingsResource.php -->

```php
$inputs->addEach('types', 'boolean', from: AliasTypes::class, default: FALSE)
  ->setLabel(t('Types with aliases'));
```

The storage, a `ConfigObjectStorage` subclass, turns the stored list
into the map in `load()`:

<!-- excerpt: tests/modules/data_surface_schema_test/src/Storage/AliasSettingsStorage.php -->

```php
public function load(OperationContext $context): array {
  $values = parent::load($context);
  if (is_array($values[self::LISTED] ?? NULL)) {
    $values[self::LISTED] = array_fill_keys(array_map('strval', $values[self::LISTED]), TRUE);
  }
  return $values;
}
```

and the map back into the list in `prepare()`, before the config schema
judges what will be stored:

<!-- excerpt: tests/modules/data_surface_schema_test/src/Storage/AliasSettingsStorage.php -->

```php
public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
  if (is_array($values[self::LISTED] ?? NULL)) {
    $values[self::LISTED] = array_map('strval', array_keys(array_filter($values[self::LISTED])));
  }
  return parent::prepare($context, $values, $account);
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The build holds the resource to the config schema the storage writes to
([A schema the module did not generate](storage.md#a-schema-the-module-did-not-generate)),
and that schema, written by hand as pathauto's is, types the key as the
storage stores it: `types` a `sequence` of `string`.

The resource asks for a boolean at each entry, so the build refuses it,
once per entry: "it is a boolean, which the schema types string". Tell
the check on the storage. Where the stored shape differs, as here,
override `widerThanSchema()` and drop the key's paths from what the
parent answers:

<!-- excerpt: tests/modules/data_surface_schema_test/src/Storage/AliasSettingsStorage.php -->

```php
public function widerThanSchema(DataSurfaceInterface $built): array {
  return array_filter(
    parent::widerThanSchema($built),
    static fn (string $path): bool => $path !== self::LISTED && !str_starts_with($path, self::LISTED . '.'),
    ARRAY_FILTER_USE_KEY,
  );
}
```

Where a key is stored under another name, override the protected
`configSchemaPaths()` instead and answer the schema's property for each
key, by key; a key it leaves out is read as stored elsewhere and is not
compared.

Three more things hold for a translated key:

- **An id the list leaves out reads as its entry's default**, as any
  entry a stored map leaves out does
  ([Keys that come from the site](resources.md#keys-that-come-from-the-site)).
  Give the entries `default: FALSE`, so a type left out reads unchecked.
  An entry that starts checked reads checked whatever the list says,
  which suits only an entry that is also locked, as pathauto's types
  whose path field another module provides are.
- **The schema is yours to write.** `drush data_surface:config-schema`
  reads no storage: it writes the shape the resource asks for, here a
  sequence of booleans, which the stored list does not match. There is
  no way to tell it a key is translated, so a module with one keeps its
  schema written by hand, as pathauto's is, and does not run the command
  with `--write`; without `--write`, the command shows that difference
  every time.
- **Every door asks for the map.** The tool, HTTP, the form and React
  send and receive the map of booleans; only storage sees the list.

## A title and a machine name

A thing with a name and a machine name, as Drupal's own forms ask for a
content type or a block: the machine name is suggested from the name, a
person may type over it, it is held once, and it stays as it is once
the thing exists. It is two keys. Example 15's venue declares them:

<!-- excerpt: modules/data_surface_examples/src/Resource/VenueResource.php -->

```php
$inputs->add('label', 'string', t('Name'))
  ->setRequired(TRUE)
  ->addConstraint('Length', ['max' => 128]);
$inputs->compute('id', 'machine_name', t('Machine name'),
  from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)
  ->setDescription(t('A unique name for the venue: lowercase letters, numbers and underscores, computed from its name unless one is given.'))
  ->addConstraint('Length', ['max' => 32]);
```

`id` is the resource's identity, `#[Resource(identity: ['id'])]`, and its
storage a `ConfigEntityStorage`. That is all it takes:

- `machine_name` is core's config schema type: the pattern
  `^[a-z0-9_]+$` and a Length of 166, both stated in the schema, which
  the key narrows to 32.
- A call that leaves `id` out gets one computed from the name by the
  stock `MachineName`, as the browser suggests it: "Café Déjà Vu" is
  `cafe_deja_vu`. A call that sends one keeps it.
- A create refuses a machine name a venue holds: "A venue named
  riverside already exists." The build adds that rule, the stock
  `DataSurfaceUnique`, to the identity key of every create whose storage
  can say whether a thing exists; write none of your own.
- The form draws core's machine name element beside the name. On edit
  the machine name is the venue the page is about: drawn disabled, and
  kept whatever is sent.

Each class's `use` line: [Namespaces](concepts.md#namespaces).
The rules in full: [A title and its machine
name](declaring-a-resource.md#a-title-and-its-machine-name).
