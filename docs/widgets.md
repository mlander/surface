# Widgets

A widget maps one data definition to a form element, and reads the
submitted value back. It is a plugin type: classes live in
`Plugin/DataSurfaceWidget` and are declared with the
`#[DataSurfaceWidget]` attribute.

A widget reads the definition and **nothing else**. It never sees the
surface, the host or the target: everything it needs — type, label,
description, whether the value is required, the values it allows — is on
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
collaborator before it can answer — the options widget asks the options
service whether the definition's constraints name a list that is not
empty — and a static answer would have to reach the container to do it.

The manager instantiates every widget once, in ascending weight order,
and asks each in turn; the first that says yes wins. So a specialized
widget undercuts a generic per-type one by declaring a **lower** weight.
Widgets hold no per-definition state, so one instance serves every
definition for the rest of the request.

### How a widget declines

By returning `FALSE` from `isApplicable()`. There is no other way to opt
out, and there is no fallback widget: a definition no widget claims
throws at build time rather than rendering as something approximate. An
unrefined `any` key is the case you will meet first — no widget claims
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

| Widget | Weight | Applies to |
| --- | --- | --- |
| `options` | -10 | Any definition whose constraints resolve to a non-empty list of allowed values. See [Options and resolvers](options.md). |
| `map` | 5 | A `ComplexDataDefinitionInterface` that declares at least one property. |
| `string` | 10 | `string`, `email`, `uri`. |
| `number` | 10 | `integer`, `float`. |
| `boolean` | 10 | `boolean`. |

The weights say the selection rule out loud: the options widget is asked
first, so a string key carrying a `Choice` renders as a select rather
than a text field; the map widget comes next, so a complex definition
that also resolves to options is still a select; and the per-type widgets
are the fallback.

What the stock widgets do beyond the obvious: defaults populate at every
depth, and constraint options reach the element, so the browser enforces
what the definition declares. `Length`'s `max` becomes `#maxlength` on
the string widget and `Range`'s `min` and `max` become `#min` and `#max`
on the number widget. Optional selects offer an empty choice. The string
widget picks its element type from the definition — `textarea` when the
definition carries the `multiline` type setting, otherwise `email`,
`url` or `textfield` from the data type — and both the string and number
widgets render a definition's first declared example as `#placeholder`,
which is how a valid value is shown before it is typed rather than after
validation fails. A textarea gets neither `#maxlength` nor
`#placeholder`.

A definition marked secret is the one case where the string widget
renders something a data type would not predict: a `password` element
with no `#default_value`, no `#placeholder` and no `#required`, plus the
note "Leave blank to keep the current value." The form builder does not
hand a secret's value to the widget in the first place, so nothing about
a stored secret reaches the render array from either side. Why each of
those is missing is in [Declaring a surface](declaring-a-surface.md#secrets)
and [Value semantics](semantics.md#secrets-keep-what-they-hold).

## Extraction returns raw shapes

A widget **does not coerce**. `extractValue()` hands back the raw
submitted value in the definition's shape — a scalar for a primitive
definition, an array keyed by property name for a complex one — and the
pipeline's `accept()` casts it.

This is not a division of labor for its own sake. Casting in the widgets
is what an earlier design did, and it meant a caller that handed the
surface a JSON payload rather than a form submission got no coercion at
all and validated raw strings. With one casting table in `accept()`, a
generated form and a payload carrying the same strings produce the same
values. The rules are in [Value semantics](semantics.md).

The base class reads the element back for a widget whose element holds
one value at its own place in the form. A widget that renders several
elements, as the map widget does, overrides `extractValue()`.

### The one subtlety in extraction

Form API assigns `#parents` from the root of the complete form, so they
are absolute, while a subform state reads values relative to the fragment
it wraps. A host that hands its plugin a subform state — a block's
settings, built with `SubformState::createForSubform()`, is the common
case — would therefore have every value looked up one level too deep and
come back `NULL`, which the surface reports as "not configured": a
spurious violation for a required key, and a stored value silently
replaced by nothing for an optional one.

`DataSurfaceWidgetBase::rawValue()` handles it, and a widget that
overrides `extractValue()` should go through it rather than reading
`$form_state` directly. An absolute path is read against the complete
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

`baseElement()` supplies what every element wants from a definition —
`#title`, `#description`, `#required` — so a widget only adds the part
that is its own. The attribute takes `id`, `label`, `weight`, and
optionally a `deriver`, as every core plugin attribute does.

Three things to hold to:

1. **Declare a weight that says where you belong.** Lower than 10 to
   undercut a per-type widget, lower than -10 to undercut the options
   widget.
2. **Do not coerce.** Give back what was submitted, in the definition's
   shape.
3. **Read nothing but the definition.** If you find yourself wanting the
   surface or the host, the thing you want is probably a constraint on
   the definition and a resolver that reads it.
