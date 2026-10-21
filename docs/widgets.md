# Widgets

A widget maps one data definition to a form element, and reads the
submitted value back. It is a plugin type: classes live in
`Plugin/DataSurfaceWidget` and are declared with the
`#[DataSurfaceWidget]` attribute.

A widget reads the definition and **nothing else**. It never sees the
data surface, the host or the storage: everything it needs (type, label,
description, whether the value is required, the values it allows) is on
the definition. That is what makes one definition render the same way
wherever it appears.

## The API

```php
public function isApplicable(DataDefinitionInterface $definition): bool;
public function buildElement(DataDefinitionInterface $definition, mixed $value): array;
public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed;
```

Plain values in, a render element out, plain values back. No typed-data
objects are threaded through the form path and no subform states exist
anywhere, so the coordinate-frame mistakes of an adapter-based form layer
cannot occur.

A definition's element **is** its input, or a container of named children
for a complex definition. There is no wrapper nesting, so a submitted
value tree is already in the definition's clean shape.

## Applicability is an instance method

`isApplicable()` is deliberately not static. A widget may have to ask a
collaborator before it can answer (the options widget asks the options
service whether the definition's constraints name a list that is not
empty), and a static answer would have to reach the container to do it.

The manager instantiates every widget once, in ascending weight order,
and asks each in turn; the first that says yes wins. So a specialized
widget undercuts a generic per-type one by declaring a **lower** weight.
Widgets hold no per-definition state, so one instance serves every
definition for the rest of the request.

### How a widget declines

By returning `FALSE` from `isApplicable()`. There is no other way to opt
out, and there is no fallback widget: a definition no widget claims
throws at build time rather than rendering as something approximate. An
unrefined `any` key is the case you will meet first: no widget claims
it, because guessing an element for a type that says nothing would be a
guess about the contract.

To put a widget in front of or behind another module's, alter its
weight rather than its class:

```php
function my_module_data_surface_widget_info_alter(array &$definitions): void {
  $definitions['options']['weight'] = 10;
}
```

## The stock widgets

| Widget | Weight | Applies to | Element |
| --- | --- | --- | --- |
| `checkboxes` | -15 | A list whose item resolves to a list of allowed values, while it offers at most twelve (`CheckboxesWidget::MAXIMUM_OPTIONS`), or that asks for checkboxes. | `checkboxes`, never `#required` |
| `radios` | -15 | One value that resolves to a list of allowed values and asks for radios. | `radios`; `- None -` a radio only where the key may be cleared |
| `options` | -10 | Any definition whose constraints resolve to a list of allowed values, an empty one included: one value, and a list the checkboxes leave (more than twelve, or asking for a select). See [Options and resolvers](options.md). | `select`; a list's `#multiple`, never `#required` |
| `map` | 5 | A `ComplexDataDefinitionInterface`. One that declares no property, such as a variant for a choice with nothing more to say, renders as an empty container and answers an empty map. | `details` |
| `list` | 8 | A list of single values with no list of allowed values, whose item a widget draws: keywords, a list of free strings or numbers. | a `fieldset` of rows, "Remove" beside each, "Add another" under them |
| `time` | 9 | A `string` that asks for a time. | a time input |
| `string` | 10 | `string`, `email`, `uri`. | `textfield`, `email`, `url`; `textarea` when multiline; `password` for a secret |
| `number` | 10 | `integer`, `float`. | `number` |
| `boolean` | 10 | `boolean`. | `checkbox`, never `#required` |

The weights say the selection rule out loud: the checkboxes and radios
are asked first, and each claims only what its rule gives it; the
options widget next, so a string key carrying a `Choice` renders as a
select rather than a text field; the map widget comes next, so a complex
definition that also resolves to options is still a select; the list
widget takes a list with no choices; and the per-type widgets are the
fallback.

### Which element a list of choices is

The definition alone does not say whether a list of choices is
checkboxes or a multiple select, nor one choice a select or radios, so
the stock widgets have a rule ([decision](decisions.md#a-widget-is-chosen-from-the-definition)):

- **A list of choices is checkboxes up to twelve, a multiple select
  above.** A multiple select needs a modifier key for a second value and
  loses every choice on a stray click, so only a list too long to scan
  as a column of checkboxes is one.
- **One choice is a select**, whatever its length, unless it asks for
  radios. A select has none of the multiple select's faults, and radios
  spend a line per choice on every key, which is the page's to decide.
- **A list of free values is rows**, each the element the item's own
  widget draws, added and removed over AJAX. Each item keeps its own
  element's hints, and a refusal of one is shown on its row.

A definition asks for an element with core's own setting, in the words
the served contract's `x-surface.widget` uses:

```php
$inputs->add('handling', 'string', t('Handling'))
  ->setSetting('widget', 'radios')
  ->addConstraint('LabeledChoice', ['choices' => [...]]);

$inputs->addList('days', $day, t('Days'))
  ->setSetting('widget', 'select');   // a multiple select, at any length

$inputs->add('opens', 'string', t('Opens at'))
  ->setSetting('widget', 'time');     // the Regex is still the rule
```

`checkboxes`, `radios`, `select` and `time` are the words the stock
widgets answer to (`DataSurfaceWidgetBase::answersTo()`). A word the
widget that claims the definition does not answer to is refused when the
element is built ("... asks for the radios widget, which does not draw a
key of type list of string; the select widget does."), rather than drawn
as something it did not ask for. A widget of a module's own answers to
its plugin ID, so `setSetting('widget', 'my_module_color')` asks for one
whose `isApplicable()` reads that setting.

The options widget claims a key whose list is **empty** too. A refiner
that narrows a list to nothing (`'choices' => []`), or a resolver that
finds nothing on a site, leaves a select offering its empty option and
nothing else, and the constraint refuses any value: a list of nothing is
still a list, never a text field to type into. A key that does not
apply at all is not narrowed to nothing: its refiner answers
`DoesNotApply::here()`, and no element is drawn for it
([Refinement](refinement.md#when-a-key-does-not-apply)).

What the stock widgets do beyond the obvious: defaults populate at every
depth, and constraint options reach the element, so the browser enforces
what the definition declares. `Length`'s `max` becomes the `maxlength`
attribute on the string widget and `Range`'s `min` and `max` become
`#min` and `#max` on the number widget. Those are hints for the browser,
not checks: the number widget clears core's `#element_validate`, whose
own wording ("must be lower than or equal to") would otherwise replace
the rule's message, and the string widget clears `#maxlength`, which
core checks the same way ("cannot be longer than"), so a refusal says
what the `Range` or the `Length`, and any message a refiner gave it,
says ([decision](decisions.md#an-element-hints-and-the-rule-speaks)). A select offers an empty option by [the empty
option rule](decisions.md#the-empty-option-rule): always when the key
may be cleared, and when it holds a value (required, or [always held by
its storage](decisions.md#a-key-its-storage-always-holds)) only while no
valid choice is selected. Radios show nothing checked where a select
shows that empty option, and a `- None -` radio only where the key may
be cleared. The string
widget picks its element type from the definition (`textarea` when the
definition carries the `multiline` type setting, otherwise `email`,
`url` or `textfield` from the data type), and both the string and number
widgets render a definition's first declared example as `#placeholder`,
which is how a valid value is shown before it is typed rather than after
validation fails. A textarea gets neither a `maxlength` nor a
`#placeholder`.

A definition marked secret is the one case where the string widget
renders something a data type would not predict: a `password` element
with no `#default_value`, no `#placeholder` and no `#required`, plus the
note "Leave blank to keep the current value." The form builder does not
hand a secret's value to the widget in the first place, so nothing about
a stored secret reaches the render array from either side. Why each of
those is missing is in
[Declaring a resource](declaring-a-resource.md#secrets) and
[Value semantics](semantics.md#secrets-keep-what-they-hold).

## Extraction returns raw shapes

A widget **does not coerce**. `extractValue()` hands back the raw
submitted value in the definition's shape (a scalar for a primitive
definition, an array keyed by property name for a complex one), and the
pipeline's `accept()` casts it.

This is not a division of labor for its own sake. Casting in the widgets
is what an earlier design did, and it meant a caller that handed the
pipeline a JSON payload rather than a form submission got no coercion at
all and validated raw strings. With one coercion table in `accept()`, a
generated form and a payload carrying the same strings produce the same
values. The rules are in [Value semantics](semantics.md).

The base class reads the element back for a widget whose element holds
one value at its own place in the form. It reads checkboxes too, which
post every option with its value or `0`, as the list of the values
checked, whichever widget reads them: a list a refiner narrowed may be
drawn as checkboxes and read back through the definition as advertised.
A widget that renders several elements, as the map and list widgets do,
overrides `extractValue()`.

### The one subtlety in extraction

Form API assigns `#parents` from the root of the complete form, so they
are absolute, while a subform state reads values relative to the fragment
it wraps. A host that hands its plugin a subform state (a block's
settings, built with `SubformState::createForSubform()`, is the common
case) would therefore have every value looked up one level too deep and
come back `NULL`, which the data surface reports as "not configured": a
spurious violation for a required key, and a stored value silently
replaced by nothing for an optional one.

`DataSurfaceWidgetBase::rawValue()` handles it ([the SubformState
lesson](forms.md#the-subformstate-lesson) is the whole story), and a
widget that overrides `extractValue()` should go through it rather than
reading `$form_state` directly. An absolute path is read against the complete
form state it is absolute in; an element with no `#parents` has not been
processed at all (programmatic use, kernel tests) and is read at
`$fallback_parents`, relative to the state handed over.

## Writing a widget

```php
#[DataSurfaceWidget(
  id: 'my_module_color',
  label: new TranslatableMarkup('Color'),
  weight: 5,
)]
final class ColorWidget extends DataSurfaceWidgetBase {

  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition->getDataType() === 'string'
      && $definition->getSetting('my_module_color') === TRUE;
  }

  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
      '#type' => 'color',
      '#default_value' => $value ?? '#000000',
    ];
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`baseElement()` supplies what every element wants from a definition
(`#title`, `#description`, `#required`), so a widget only adds the part
that is its own. `#required` draws the marker and the browser's
`required` attribute and is never checked by core: the form builder
keeps that check off, so a required key left empty is refused in the
data surface's words ([decision](decisions.md#an-element-hints-and-the-rule-speaks)). The attribute takes `id`, `label`, `weight`, and
optionally a `deriver`, as every core plugin attribute does.

Three things to hold to:

1. **Declare a weight that says where you belong.** Lower than 10 to
   undercut a per-type widget, lower than -10 to undercut the options
   widget, lower than -15 to undercut the checkboxes and radios.
2. **Do not coerce.** Give back what was submitted, in the definition's
   shape.
3. **Read nothing but the definition.** If you find yourself wanting the
   data surface or the host, the thing you want is probably a constraint on
   the definition and a resolver that reads it.
