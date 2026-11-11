# Widgets

Use this page when a key needs a form element the stock widgets do not
draw, or to find which element a key gets.
[Keys](introduction/05-keys.md) and
[Rules on a key](introduction/06-rules-on-a-key.md) introduce what a
widget reads.

A widget maps one node of the served document to a form element, and
reads the submitted value back. It is a plugin type: classes live in
`Plugin/SurfaceWidget` and are declared with the `#[SurfaceWidget]`
attribute.

The form is drawn from the document the data surface serves for its
values, emitted with the widget hints, as the HTTP door serves it to the
React app ([Forms](forms.md#the-form-is-drawn-from-the-document)). A
widget reads one node of it and **nothing else**: the key's schema with
its `$ref`s followed and its compositions merged, its hints under
`x-portable-ui`, and Surface's markers under `x-surface`. It never sees
the data surface, a definition, the host or the storage. What it needs
that the document does not say, the label of the entity a reference
holds, it asks its context, which the host answers. That is what makes
one key render the same way wherever it appears, and the PHP form and
the React app read the same document by the same rules.

## The API

```php
public function isApplicable(Schema $node, WidgetContext $context): bool;
public function buildElement(Schema $node, mixed $value, WidgetContext $context): array;
public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`Schema` is Portable Schema's (`PortableSchema\Builder\Schema`), the node
inline. `WidgetContext` says what the node does not say of itself: its
JSON Pointer in the values, whether the object holding it lists it in
`required`, whether the envelope lists its value as stale, whether an
element around it is locked, whether it is drawn inside a collection's
item (`item`); and it carries the host's answers (`labelOf()`,
`selection()`) and the drawer, which draws the nodes inside a node
(`properties()`, `node()`).

Plain values in, a render element out, plain values back. A node's
element **is** its input, or a container of named children for an
object. There is no wrapper nesting, so a submitted value tree is
already in the node's shape.

## The word names the widget

The emitter chooses each key's widget from its definition, and writes
its word into the node, `x-portable-ui.widget`. The drawer draws the node
with the widget that word names (`SurfaceWidgetManager::widgetFor()`),
so the choice is made once and a client never makes it again. There is
one table, word to plugin ID (`SurfaceWidgetManager::words()`): each
widget's words (`answersTo()`, its plugin ID first), and
`SurfaceWidgetManager::DOCUMENT_WORDS`, the consumer's mapping of the
words the document uses for a stock widget by another name, `text`,
`textarea` and `email` to the string widget and `fixed` to the select.
The emitter writes only words that table holds: a widget's own by
`WidgetChoice::wordOf()`, the first word it answers to that the table
maps to it, and the words for a value's shape by
`WidgetChoice::valueWord()`, each checked by `WidgetChoice::word()`.
`variants` names no widget: the drawer draws a key with variants itself.

A word no widget answers to (a module's widget the site does not have),
a word whose widget's `isApplicable()` refuses the node, and a node with
no word (a timestamp, a date and time, a key with variants) fall to the
stock word for the node's shape, in the order of the React app's
`stockWidget()` (`SurfaceWidgetManager::stockWord()`): a list whose
items offer choices a multiple select, any other list `list`, a node
offering choices a select, an object a fieldset, a boolean a checkbox,
an integer or a number a number input, a string whose `format` is
`email` an email input, any other string a text input. A node that
states no one type (a value of any type) is drawn by the module's own
widgets in weight order, the first whose `isApplicable()` says yes, the
JSON widget last. The weights decide only among widgets answering to
the same word. `isApplicable()` reads the node alone (`type`, `format`,
`oneOf`, `items`, a check, a hint), never a live collaborator. A
module's widget is drawn only where the document names it.

Two of the emitter's words are rules of their own. A module's widget the
emitter chooses by a setting of its own (the extras' comma separated
list) is named by its word, where `list` stood before. A list the list
widget does not draw (its items lists, or of any type) is named by no
word, and the drawer draws it as JSON.

The emitter's side is `Contract\WidgetChoice`: a widget that claims the
key first, then the widget the key asks for by its word, then every
widget in weight order whose `drawsDefinition()` says it draws the
definition (`Contract\DefinitionWidgetInterface`). Every stock widget
implements both halves; `WidgetAgreementTest` holds them to each other
for every key of every example, collections, keys with variants and keys
with no word among them.

### How a widget declines

By returning `FALSE` from `isApplicable()`, and on the emitter's side
from `drawsDefinition()`. Every data type core defines is drawn by a
stock widget, a type whose value nobody types (bytes, a language) as an
item saying it is not drawn here, and the `json` widget is the last
resort for an `any` key and a list nothing else draws, so the form never
refuses a key it is declared with. A data type a module adds that no
widget names, such as `filter_format`, still throws naming it.

To put a widget in front of or behind another module's, alter its
weight rather than its class:

```php
function my_module_surface_widget_info_alter(array &$definitions): void {
  $definitions['select']['weight'] = 10;
}
```

## The stock widgets

The second column is what the emitter chooses the widget for, from the
definition; the drawer draws the node its word names.

| Widget | Weight | Chosen for | Element |
| --- | --- | --- | --- |
| `checkboxes` | -15 | A list whose item resolves to a list of allowed values, while it offers at most twelve (`CheckboxesWidget::MAXIMUM_OPTIONS`), or that asks for checkboxes. | `checkboxes`, never `#required` |
| `radios` | -15 | One value that resolves to a list of allowed values and asks for radios. | `radios`; `- None -` a radio only where the key may be cleared |
| `select` | -10 | Any definition whose constraints resolve to a list of allowed values, an empty one included: one value, and a list the checkboxes leave (more than twelve, or asking for a select). See [Options and resolvers](options.md). | `select`; a list's `#multiple`, never `#required`; an `item` showing the one value a deciding key's variants offer |
| `autocomplete` | -5 | A key that refers to one entity type, its id with `SurfaceValidReference`, or the id of a key of any entity type once its entity type is chosen, whose candidates are not listed: more than `surface.settings:reference_list_limit` ([Options](options.md#a-key-that-refers-to-an-entity)). | core's `entity_autocomplete`, its handler and selection settings the check's options where they carry them, else the host's answer (`WidgetContext::selection()`: a reference field's handler and settings, which the document does not carry); read back as the id in brackets, `Riverside Hall (riverside)` as `riverside`; core's own check of what was typed taken off |
| `fieldset` | 5 | A `ComplexDataDefinitionInterface`. One that declares no property, such as a variant for a choice with nothing more to say, renders as an empty container and answers an empty map. A list of maps inside it is the form builder's, drawn as a collection in its place ([below](#a-list-of-maps-is-a-collection)). | `details` |
| `list` | 8 | A list of single values with no list of allowed values, whose item a widget draws: keywords, a list of free strings or numbers. | a `fieldset` of rows, "Remove" beside each, "Add another" under them |
| `time` | 9 | A `string` that asks for a time. | a time input |
| `date` | 9 | A `string` that asks for a date. | a date input |
| `string` | 10 | `string`, `email`, `uri`, `duration_iso8601`; the words `text`, `textarea` and `email`. | `textfield`, `email`, `url`; `textarea` when multiline; `password` for a secret (`writeOnly`); `#size` and `#rows` from the hints `options.size` and `options.rows` |
| `number` | 10 | `integer`, `float`, `decimal`, `timestamp`, `timespan`. | `number`, `#step` 1, or any for a float or a decimal |
| `checkbox` | 10 | `boolean`. | `checkbox`, never `#required` |
| `datetime` | 10 | `datetime_iso8601`. | core's `datetime`, a date and a time input in the site's time zone; read back as the ISO 8601 string, its offset kept |
| `not_drawn` | 10 | `language`, `language_reference`, `entity_reference`, and a list of any of them: a value nobody types, which no blueprint declares, so only a definition handed to the engine by hand reaches it. | `item`: "This is not drawn on this form; what it holds is kept.", posting nothing |
| `scalars` | 999 | A key of type `any` read from a schema whose `type` lists two or more of string, integer, number and boolean, and nothing else but null (`ScalarsWidget::stated()`, from the schema `SchemaReader::statedTypes()` reads off its JsonSchema constraint); on the drawer's side, a node whose `type` lists them (`ScalarsWidget::scalars()`). | `textfield`, its description naming the types; read as the first stated type the text spells, in this order: integer, number, `true` or `false`, string; empty sends nothing |
| `json` | 1000 | What every other widget refused: a key of type `any` that holds no variants and is no union of scalars (its types include an object or a list, or it states none), and a list the list widget refuses, its items lists or `any`. | `textarea` of pretty-printed JSON, its description naming the type; empty sends nothing, text that is not JSON is an error on the element |

The weights say the selection rule out loud: the checkboxes and radios
are asked first, and each claims only what its rule gives it; the
select widget next, so a string key carrying a `Choice` renders as a
select rather than a text field; a reference with too many candidates
to list next, so it is no number or text field; the fieldset widget comes next, so a complex
definition that also resolves to options is still a select; the list
widget takes a list with no choices; and the per-type widgets are the
fallback.

The `json` widget is the last resort, so the form draws every key it is
declared with instead of throwing
(why: [decision](decisions.md#the-form-never-refuses-to-draw-a-declared-key)).
The served contract names no widget for it, nor for the `scalars`,
`datetime` and `not_drawn` widgets, nor for a timestamp: none is a fact
about the key. What the `json` widget decodes, and what the `scalars`
widget reads, goes through `accept()` and the key's own constraints, the
JsonSchema constraint of a value read from a schema among them. The
`scalars` widget is the one stock widget that reads a type out of what
was typed: the key's type is several, and a text field posts text, so
nothing else could say which was meant.

### A list of maps is a collection

A list whose item is a map is drawn as a
[collection](resources.md#collections) is, wherever it stands: one
details per item, each the map's own fields, "Remove" in each and "Add
another" under them, over AJAX. At the top of a frame, inside a
collection's item, inside a variant (a component's props), inside an
attached part, or inside a plain map. A widget cannot draw it, since it
knows neither the list's place in the form nor the container its buttons
rebuild: the drawer draws the collection in its place, a list of
objects in the document (`x-surface.collection`, or `items` an object),
and reads it back item by item. Each item is the items' object, the
conditionals it states narrowed for that item's values
(`DocumentDrawer::narrowedFor()`): the document states one `items` for
every item. Where `attachList()` declared the list, its child
structure's def is the items; a list declared with a map as its item is
the map, and the list is accepted and validated through its own
definition, as any list is. A list of lists is the
`json` widget's.

### Which element a list of choices is

The definition alone does not say whether a list of choices is
checkboxes or a multiple select, nor one choice a select or radios, so
the stock widgets have a rule (why: [decision](decisions.md#a-widget-is-chosen-from-the-definition)):

- **A list of choices is checkboxes up to twelve, a multiple select
  above.** A multiple select needs a modifier key for a second value and
  loses every choice on a stray click, so only a list too long to scan
  as a column of checkboxes is one.
- **One choice is a select**, whatever its length, unless it asks for
  radios. A select has none of the multiple select's faults, and radios
  spend a line per choice on every key, which is the page's to decide.
- **One value to choose is no choice.** A deciding key whose variants
  offer one value, which may not be cleared, is drawn as an `item`
  showing that value's label and posting the value, while it holds it or
  nothing; a list the key declares, or a stored value no longer offered,
  is a select as above (why: [decision](decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).
- **A list of free values is rows**, each the element the item's own
  widget draws, added and removed over AJAX. Each item keeps its own
  element's hints, and a refusal of one is shown on its row.

A definition asks for an element with core's own setting, in the words
the served contract's `x-portable-ui.widget` uses:

```php
$inputs->add('handling', 'string', t('Handling'))
  ->setSetting('widget', 'radios')
  ->addConstraint('LabeledChoice', ['choices' => [...]]);

$inputs->addList('days', $day, t('Days'))
  ->setSetting('widget', 'select');   // a multiple select, at any length

$inputs->add('opens', 'string', t('Opens at'))
  ->setSetting('widget', 'time');     // the Regex is still the rule

$inputs->add('reviewed_on', 'string', t('Reviewed on'))
  ->setSetting('widget', 'date');     // both schemas say `format: date`
```

`checkboxes`, `radios`, `select`, `time` and `date` are the words the stock
widgets answer to (`SurfaceWidgetBase::answersTo()`): each is the
widget's plugin ID, as `fieldset` and `checkbox` are, and the word
`x-portable-ui.widget` says. *Renamed 2026-10-24:* the select, fieldset and
checkbox widgets were the plugins `options`, `map` and `boolean`; a
`hook_surface_widget_info_alter()` keyed by an old ID finds
nothing. A word the
widget that claims the definition does not answer to is refused when the
element is built ("... asks for the radios widget, which does not draw a
key of type list of string; the select widget does."), rather than drawn
as something it did not ask for. A widget of a module's own answers to
its plugin ID, so `setSetting('widget', 'my_module_color')` asks for one
whose `isApplicable()` reads that setting.

The select widget claims a key whose list is **empty** too. A refiner
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
says (why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)). A select offers an empty option
always when the key may be cleared, and when it holds a value only while
no valid choice is selected; standing for a stored value no longer
offered, it reads "- Keep @value (no longer offered) -". A key holds a
value when it is required, or when its storage always holds it: a key a
fully validatable config schema does not mark `nullable: true`, which
null cannot clear
(why: [decision](decisions.md#the-empty-option-rule),
[decision](decisions.md#a-key-its-storage-always-holds)). Radios show nothing checked where a select
shows that empty option, and a `- None -` radio only where the key may
be cleared. The string
widget picks its element type from the definition (`textarea` when the
definition carries the `multiline` type setting, otherwise `email`,
`url` or `textfield` from the data type). A textarea gets no
`maxlength`. No widget draws a declared example as a placeholder: an
empty field showing a value reads as filled. A placeholder, a prefix, a
suffix, a help text, a weight and a group are the key's
[arrangement](forms.md#arranging-the-keys), which the form builder draws
on whatever element the widget built
(`SurfaceWidgetBase::arranged()`), a module's own widget's
included.

A definition marked secret is the one case where the string widget
renders something a data type would not predict: a `password` element
with no `#default_value`, no `#placeholder` (not even a declared one)
and no `#required`, plus the
note "Leave blank to keep the current value." The form builder does not
hand a secret's value to the widget in the first place, so nothing about
a stored secret reaches the render array from either side. Why each of
those is missing is in
[Declaring a resource](declaring-a-resource.md#secrets) and
[Value semantics](semantics.md#secrets-keep-what-they-hold).

## Extraction returns raw shapes

A widget **does not coerce**. `extractValue()` hands back the raw
submitted value in the node's shape (a scalar for one value, an array
keyed by property name for an object), and the pipeline's `accept()`
casts it.

This is not a division of labor for its own sake. Casting in the widgets
is what an earlier design did, and it meant a caller that handed the
pipeline a JSON payload rather than a form submission got no coercion at
all and validated raw strings. With one coercion table in `accept()`, a
generated form and a payload carrying the same strings produce the same
values. The rules are in [Value semantics](semantics.md).

Each element drawn for a value carries its JSON Pointer
(`#surface_path`) and the plugin ID of the widget that drew it
(`#surface_widget`), and no node: extraction finds the node at that
pointer in the envelope drawn (`Form\Document\DocumentNodes::at()`), the
one the request drew or, for a form read from the form cache, the one
emitted again for the values the container keeps (`#surface_values`), so
the form reads each value back with the widget that built it, through
that node. The base class reads an element that holds one value at its
own place in the form, and checkboxes, which post every option with its
value or `0`, as the list of the values checked. A widget that renders
several elements, as the map and list widgets do, overrides
`extractValue()`. An element standing for a stale value, left on its
empty option, keeps what is stored: the form reads the stored value back
at its pointer, since the document never carries it.

### The one subtlety in extraction

Form API assigns `#parents` from the root of the complete form, so they
are absolute, while a subform state reads values relative to the fragment
it wraps. A host that hands its plugin a subform state (a block's
settings, built with `SubformState::createForSubform()`, is the common
case) would therefore have every value looked up one level too deep and
come back `NULL`, which the data surface reports as "not configured": a
spurious violation for a required key, and a stored value silently
replaced by nothing for an optional one.

`SurfaceWidgetBase::rawValue()` handles it ([the SubformState lesson](forms.md#the-subformstate-lesson) is the whole story), and a
widget that overrides `extractValue()` should go through it rather than
reading `$form_state` directly. An absolute path is read against the complete
form state it is absolute in; an element with no `#parents` has not been
processed at all (programmatic use, kernel tests) and is read at its
pointer, relative to the state handed over.

## Writing a widget

```php
#[SurfaceWidget(
  id: 'my_module_color',
  label: new TranslatableMarkup('Color'),
  weight: 5,
)]
final class ColorWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return $definition->getDataType() === 'string'
      && $definition->getSetting('my_module_color') === TRUE;
  }

  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::types($node) === ['string'];
  }

  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    return $this->baseElement($node, $context) + [
      '#type' => 'color',
      '#default_value' => $value ?? '#000000',
    ];
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`drawsDefinition()` is the emitter's question: it is asked in weight
order, and the widget that says yes first is the word the document
carries. `isApplicable()` is the drawer's, asked only of a node whose word
names no widget that draws it. `baseElement()` supplies what every
element wants from a node (`#title` from `title`, `#description` from
`description`, `#required` from the owner's `required`), so a widget
only adds the part that is its own. `#required` draws the marker and the
browser's `required` attribute and is never checked by core: the form
builder keeps that check off, so a required key left empty is refused in
the data surface's words (why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)). The attribute takes `id`, `label`, `weight`, and
optionally a `deriver`, as every core plugin attribute does, and
`claims` and `priority` ([below](#a-module-serves-a-widget-to-every-key-of-a-kind)).

Three things to hold to:

1. **Declare a weight that says where you belong.** Lower than 10 to
   undercut a per-type widget, lower than -10 to undercut the options
   widget, lower than -15 to undercut the checkboxes and radios.
2. **Do not coerce.** Give back what was submitted, in the node's shape.
3. **Read nothing but the node.** If you find yourself wanting the data
   surface or the host, the thing you want is probably a keyword the
   emitter writes, a setting your widget publishes
   ([below](#a-widget-may-publish-its-settings)), or the context's
   `labelOf()`.

### A widget may publish its settings

A module's widget may read settings of its own from a key's definition,
how it is drawn: a palette, a number of columns. Those it lists in
`publishedSettings()` reach the document: the emitter writes each the
definition holds under `x-portable-ui.options`, its name in lowerCamel
(`swatch_columns` as `swatchColumns`), as a hint, and the widget's
`buildElement()` reads it there (`static::options($node)`). The reader
reads each back into the setting it was written from, where the widget
the word names publishes one of that name, and notes it otherwise. A
setting that says what the key accepts is a constraint, never a
published setting. The stock string widget's `size` and `rows` reach
the document the same way, as `options.size` and `options.rows`.

## A module serves a widget to every key of a kind

The example above draws a key whose owner asked for it. A module that
ships a color picker wants every color key on the site, on resources it
did not write, and their owners cannot each ask. The widget claims them
instead:

```php
#[SurfaceWidget(
  id: 'my_module_color',
  label: new TranslatableMarkup('Color'),
  claims: [['type' => 'string', 'format' => 'color']],
  priority: 10,
)]
final class ColorWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return !DefinitionMetadata::isSecret($definition);
  }

  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return $node->get('writeOnly') !== TRUE;
  }

  // buildElement() as above.

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A claim names what the definition says its value is, in one of three
forms:

| Claim | Serves |
| --- | --- |
| `['type' => 'string']` | every key of that data type |
| `['type' => 'string', 'format' => 'color']` | every key of that type whose `format` setting is that word, `->setSetting('format', 'color')` |
| `['shape' => 'event.license']` | every key of that [shape](declaring-a-resource.md#named-shapes) |

A claim of a list or a map is refused when the plugins are discovered:
a list is drawn item by item, each item by the widget that serves it,
and a map is a fieldset of its keys.

How the emitter chooses (`WidgetChoice::claimed()`):

1. **A key whose owner asks for a widget**, `setSetting('widget', ...)`,
   is drawn by that widget, and no claim is asked. The owner wins.
2. **The claims**, the highest `priority` first, then by plugin ID. The
   first widget whose claim matches the key and whose
   `drawsDefinition()` says yes draws it. A claimed widget may still decline a key, as the
   one above declines a secret.
3. **The stock rule**, by weight, as above. A widget that claims is not
   asked here for a key it does not claim, unless the key names it, so
   its `drawsDefinition()` answers for the keys it claims alone, and its
   weight matters only when a key asks for it by name.

`x-portable-ui.widget` says the chosen widget's word, the first word it
answers to (its plugin ID), in both documents, and the catalog's view
of one resource (`drush surface:catalog --resource=<id>`) names each
key's widget among its notes. The React page draws the stock widgets
and nothing else, so a word it has no field for is drawn as the stock
field for the key's JSON type: a list of choices a multiple select, a
list rows, choices a select, an object a fieldset, a boolean a
checkbox, a number a number input, an address an email input, any other
string a text input (`stockWidget()` in the app). A key no widget draws,
`widget: null`, stays "Not editable here". The OpenAPI document carries
no widget hints. The `format` setting is read by claims alone: no
document writes it as JSON Schema's `format`, and no rule checks it
(why: [decision](decisions.md#a-widget-is-chosen-from-the-definition)).
