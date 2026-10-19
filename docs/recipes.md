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
pattern:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$inputs->add('opens', 'string', t('Opens at'), default: '09:00')
  ->setRequired(TRUE)
  ->setDescription(t('HH:MM, on a 24-hour clock.'))
  ->addConstraint('Regex', [
    'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
    'message' => 'Write the time as HH:MM, such as 09:00.',
  ]);
```

Prefer the string. What a person types, what an agent sends and what the
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
the list is labeled and required as any key is.

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

The form draws a multiple select, the served contract says
`x-surface.widget: "select"` with `multiple: true`, React draws its
multiple select, and the tool lists the days as the items' values. A day
not on the list is refused by its position ("days.1: The value you
selected is not a valid choice."). An item is never null in either
schema: an empty item is dropped before anything reads the list
([Value semantics](semantics.md#lists)). Required means configured, so
an empty list satisfies it
([Declaring a resource](declaring-a-resource.md#required)). Checkboxes
are not a stock widget; see the last recipe.

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
tool, both schemas and React, which draws it as rows to add and remove.
On a Form API form no stock widget draws it: the options widget draws a
list only as a multiple select, and building a form for a key no widget
claims is refused. So the form needs a widget of your own, which the key
asks for with a setting:

<!-- excerpt: tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php -->

```php
$keywords = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))
  ->setLabel(t('Keywords'))
  ->setSetting(LinesWidget::SETTING, TRUE);
$inputs->addDefinition('keywords', $keywords, default: []);
```

The widget claims only the lists that ask for it, draws a text area, and
reads the text back in the list's shape, one item per line, deciding
nothing else: what a keyword may be is the resource's to say.

<!-- excerpt: tests/modules/data_surface_resource_test/src/Plugin/DataSurfaceWidget/LinesWidget.php -->

```php
#[DataSurfaceWidget(
  id: 'resource_test_lines',
  label: new TranslatableMarkup('One item per line'),
  weight: 0,
)]
final class LinesWidget extends DataSurfaceWidgetBase {

  /**
   * The definition setting that asks for this widget.
   */
  public const SETTING = 'resource_test_lines';

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ListDataDefinitionInterface
      && $definition->getSetting(self::SETTING) === TRUE
      && $definition->getItemDefinition()->getDataType() === 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'textarea',
      '#default_value' => is_array($value) ? implode("\n", $value) : '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    return is_string($raw) ? preg_split('/\R/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) : $raw;
  }

}
```

The demo extras module ships another, a comma separated list
(`CommaSeparatedListWidget`). Writing one in full:
[Widgets](widgets.md#writing-a-widget). A stock widget for it, with
checkboxes and radios, is the next unit on the roadmap.
