# Outputs

A surface has two halves. The definitions a host **accepts** are the
input half, and everything else in these docs is about them. The
definitions a host **emits** are the output half, declared in the same
vocabulary and sealed into the same collection type, but never
refined.

```php
$surface->getDefinitions();        // What may be sent.
$surface->getOutputDefinitions();  // What comes back.
```

Both are a `DefinitionMap` of `SurfaceEntry` objects. A surface that
declares no outputs answers with an empty map, so a caller never has to
ask whether a surface knows about outputs before asking what it emits.

The boundary rule is unchanged, and it is the reason outputs are worth
declaring at all: **contracts are objects, payloads are arrays**. What a
host says it emits is a typed map of definitions; what it actually
emits is a plain PHP array, exactly like the values flowing the other
way.

## Declaring outputs

Beside the inputs, in the same class, on `HasOutputsInterface`:

```php
#[UsesSurface]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements SurfaceInterface, HasOutputsInterface {

  public static function defineOutputs(ShapeInterface $outputs): void {
    $outputs->add('text', 'string', t('Text'))
      ->setDescription(t('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE);
    $outputs->addDefinition('classes', (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')
      ->setLabel(t('Class'))))
      ->setLabel(t('Classes'))
      ->setDescription(t('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.')));
  }

}
```

The interface is optional, because most surfaces only ask, so
`defineInputs()` reads as "what this asks for" and a surface that never
mentions outputs answers with an empty map. Outputs take the same tool
as inputs. Inputs and outputs are separate namespaces: a key may appear
in both with different meanings.

Three differences from an input, all of them enforced rather than
documented:

| | Input | Output |
| --- | --- | --- |
| **Default value** | Declared, merged under stored values by `accept()`. | Refused. A default is what a value starts from when nobody sent one, and nobody sends an output. A definition carrying `default_value`, at any depth, is refused when it is added and again at seal. |
| **Locking** | Fixes the value to what storage holds. | Refused. Locking narrows what a caller may send. |
| **Refinement** | `#[RefinesInput]` methods narrow it against its siblings. | Refused: a `#[RefinesInput]` naming an output key is refused when the surface is built. A refinement narrows what may be sent, and nobody sends an output. |

An output whose shape depends on an input value would be a variant,
declared with `attachBy()` on that input key, exactly as for an input
slot. Outputs hold no subsurface yet: `attach()` and `attachBy()` on an
output are refused by name, so until they can, an output that would
vary is advertised at its widest: the demo
formatter's `classes` is an open list of class names, whatever variant
is chosen.

## Absent, NULL, and the Omitted sentinel

Three states, and the middle one is the one worth being careful about.

| The emitted array | Means | Legal when |
| --- | --- | --- |
| The key is missing | The producer has nothing to say about it on this run. | The output is not required. |
| The key holds `Omitted::value()` | The same thing, said from inside an array literal. Conformance strips it before anything else looks. | The output is not required. |
| The key holds `NULL` | `NULL` **is** the value. It is emitted, and it is checked against the definition like `0` or the empty string. | The definition accepts it — so never for a required output. |

`Pipeline\Omitted` exists because PHP has no way to leave a key out of
an array expression, and the readable shape of a producer is one
literal:

```php
return [
  'text' => $text,
  'classes' => $variant === NULL ? Omitted::value() : ['v-' . $variant],
];
```

Saying `'classes' => []` instead would be a different statement: "there
are no classes" rather than "this run has nothing to say about
classes". An emitted schema that cannot tell those apart is the gap
this sentinel closes, and it is the same distinction JSON resource
libraries reach for with their own missing-value markers.

The sentinel is a singleton and is recognized by identity, so nothing a
producer could legitimately emit is mistaken for it. Stripping reaches
every depth — a map output's properties are outputs too — and closes
the gap an omitted list item leaves, because a list with a hole in it
is not a shape any consumer expects.

## Conformance

```php
$violations = $pipeline->conformOutput($surface, $emitted);
```

What it does, in order:

1. Strips every `Omitted` key, at every depth.
2. Refuses keys no output definition declares, at any depth, in the same
   spirit `accept()` refuses unknown input keys.
3. Checks every present value against its definition's type and
   constraints, through typed data, with path-aware violations.

It answers with the same `ViolationSet` of `SurfaceViolation` objects
that `validate()` answers with, so a caller reads one shape whichever
direction it is checking.

**Nothing is cast.** Input arrives from people and payloads and is
coerced through [the casting table](semantics.md); an output is produced
by code that had the declaration in front of it, so `'2'` where an
integer was declared is a bug in the producer and reporting it is the
point. That is stricter than core's own `PrimitiveType` constraint,
which asks whether a value *could be* the type, and deliberately so: the
question here is what a consumer will actually be handed. The strict
check covers the same types the casting table covers — integer, float,
boolean, string, email, uri — and leaves everything else alone, because
a rule this module never defined would be a guess. An integer where a
float was declared is the one widening allowed, since every integer is
that float exactly.

This is what makes generated conformance tests possible: a loop over
some fixtures, one `conformOutput()` per emitted array, and a host that
said what it emits is held to it by a test that knows nothing else about
it.

## The two consumers

### The formatter's data step

`DataSurfaceFormatterBase` splits viewing in two:

```php
public function formatValue(FieldItemInterface $item, array $settings): array;
```

The pure data step — no render array, no theme, no markup — returning
output-shaped data per delta. `viewElements()` in the base then
assembles one element per delta from a small published vocabulary:

| Key | Renders as |
| --- | --- |
| `text` | A `#plain_text` child of the wrapper, so field text containing markup is shown as written rather than filtered. |
| `classes` | The wrapper's `class` attribute. Absent means no class attribute at all, not an empty one. |
| `tag` | The wrapper element; `span` by default. |

A formatter whose markup is not one wrapper around one string overrides
`viewElements()` as it always did. The split is still worth having,
because the data step stays separately callable — by a test, by a JSON
representation, by an agent asking what this formatter would show — and
separately checkable against the contract.

The demo formatter is the worked example: it is its own surface, and
declares two outputs in its static `defineOutputs()`; it implements
`formatValue()`, writes no render array, and emits
`Omitted::value()` for its classes when no variant is chosen. Its
`classes` output is advertised open whatever variant is chosen, because
outputs are never refined.

### The tool bridge

`SurfaceInputDefinitions::outputsFromSurface()` converts an output map
into the Tool API's output definitions — `OutputDefinition`, and
`ListOutputDefinition` or `MapOutputDefinition` for structure — rather
than input definitions: an output is
never rendered as a form element, never refined by a caller's other
answers, and never locked.

What travels: the data type, the label, the description, the required
flag, the constraints, and the enum, through the same treatment the
inputs get. What is lost, on top of the two the inputs already lose
(example values and type settings, both Tool API gaps):

- **The `Omitted` marker itself**, which is a PHP marker and not a
  value. The statement it makes survives as `required`.
- **Who contributed what.** A mounted third-party output arrives as an
  ordinary property of the `third_party_outputs` map.

The tools `data_surface_tool` derives from situations answer with the
accepted `values`, `committed`, and the surface's own outputs converted
here; a dry run answers with what prepare rehearsed, in storage shape,
as `prepared`.

## Contributing an output

A module that owns neither the host nor its execution can still add to
what the host emits, at build time: its alter implements
`AltersOutputsInterface` beside `SurfaceAlterInterface`.

```php
public function alterOutputs(ShapeAdditionsInterface $outputs): void {
  $outputs->add('badge', 'string', $this->t('Badge'));
}
```

What the alter adds is mounted under its module's name, so the value
lands at `third_party_outputs.my_module.badge` — advertised,
conformance checked, and machine-visible — and cannot collide with the
owner's outputs or with another contributor's. The owner may not declare
an output of that name; the key belongs to whoever mounts under it. An
alter cannot offer more values on an output with `extendChoices()`:
nobody sends one.
