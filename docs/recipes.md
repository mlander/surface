# Common settings recipes

Six things a settings page asks for that no other page shows end to end.
Each snippet is a key of the test module's opening hours
(`resource_test.opening_hours`, in
`tests/modules/data_surface_resource_test`), written into its
`defineInputs()` as shown here; a test fails if this page and the class
differ, and another holds each key to what this page says it does. Read
[Your first resource](first-resource.md) first: a recipe is a few more
lines in the same method.

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
    'message' => 'Write the time as HH:MM, such as 09:00.',
  ]);
```

The form draws the browser's time input, the served contract says
`x-surface.widget: "time"` and React draws the same; the browser posts
`09:00`, and the pattern is still the rule, at every door
([Widgets](widgets.md#which-element-a-list-of-choices-is)).

Prefer the string. What a person picks, what an agent sends and what the
config object stores are the same `09:00`, a refusal is the pattern's
own message, and the pattern reaches both schemas (it ends in `$` with
the `D` modifier, which both engines read alike:
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
the list is labeled and required as any key is. Up to twelve choices it
is drawn as checkboxes.

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
$days = (new ListDataDefinition(['type' => 'list'], $day))
  ->setLabel(t('Days open'))
  ->setRequired(TRUE);
$inputs->addDefinition('days', $days, default: ['mon', 'tue', 'wed', 'thu', 'fri']);
```

The form draws checkboxes, one per day, the served contract says
`x-surface.widget: "checkboxes"`, React draws its checkboxes, and the
tool lists the days as the items' values. Every box unchecked is the
empty list; boxes left as drawn keep the days. A list of more than
twelve choices is a multiple select, and
`setSetting('widget', 'checkboxes')` or `setSetting('widget', 'select')`
on the list asks for one or the other at any length
([Widgets](widgets.md#which-element-a-list-of-choices-is)). A day not on
the list is refused by its position ("days.1: The value you selected is
not a valid choice."). An item is never null in either schema: an empty
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
"textarea"` ([Widgets](widgets.md#the-stock-widgets)). Store it as
`text` in config schema.

## Applies only when another key holds a value, and is then required

The door code means something only while after-hours access is on, and
then it must be given. Declare it required in `defineInputs()`, as it is
wherever it applies, and say where it does not apply with a declared
rule:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('after_hours', 'boolean', t('After-hours access'), default: FALSE);
$inputs->add('door_code', 'string', t('Door code'))
  ->setRequired(TRUE)
  ->addConstraint('Length', ['min' => 4, 'max' => 12]);
$inputs->narrow('door_code')
  ->when(Condition::not(Condition::equals('after_hours', TRUE)))
  ->doesNotApply();
```

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

## A list of free strings

A list whose item is a plain string, such as keywords, is a list to the
tool, both schemas and React, and on the form too: the stock list widget
draws it as rows, one text field per keyword, with "Remove" beside each
and "Add another" under them, each rebuilding the list over AJAX. Nothing
to declare but the list:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$keywords = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))
  ->setLabel(t('Keywords'));
$inputs->addDefinition('keywords', $keywords, default: []);
```

A row left empty is dropped, as the pipeline drops an empty item; what a
keyword may be (a `Length`, a `Regex` on the item definition) reaches
each row's field, and a refused keyword is marked on its own row. The
served contract says `x-surface.widget: "list"`. A text area of one
keyword per line, or a comma separated list, is still a widget of your
own if you want one ([Writing a widget](widgets.md#writing-a-widget));
the demo extras module ships the second, `CommaSeparatedListWidget`.
