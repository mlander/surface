# Outputs

This page covers what an operation returns after it runs, as opposed
to what it accepts, and how that is declared and checked. Most
resources need none of it: a settings page returns nothing beyond the
values it saved. [Operations](introduction/09-operations.md) introduces
what runs.

A data surface has two halves. The definitions a host **accepts** are the
input half, and everything else in these docs is about them. The
definitions a host **emits** are the output half, declared in the same
vocabulary and sealed into the same collection type, but never
refined.

```php
$surface->getDefinitions();        // What may be sent.
$surface->getOutputDefinitions();  // What comes back.
```

Both are a `DefinitionMap` of `Entry` objects. A data surface whose
class declares no outputs answers with an empty map, so a caller never
has to ask whether a data surface knows about outputs before asking what
it emits.

The boundary rule is unchanged, and it is the reason outputs are worth
declaring at all: **contracts are objects, payloads are arrays**. What a
host says it emits is a typed map of definitions; what it actually
emits is a plain PHP array, exactly like the values flowing the other
way.

For how this fits with the other kinds of transformation, see
[Transformations](transformations.md).

## Declaring outputs

Beside the inputs, in the same class, on `DefinesOutputsInterface`:

```php
#[UsesStructure]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements StructureInterface, DefinesOutputsInterface {

  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('text', 'string', t('Text'))
      ->setDescription(t('The field value, prefixed and cased as the settings ask.'))
      ->setRequired(TRUE);

    $outputs->addList('classes', DataDefinition::create('string')->setLabel(t('Class')), t('Classes'))
      ->setDescription(t('The classes the chosen variant puts on the wrapper. Absent when no variant is chosen.'));
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The interface is optional, because most resources and structures only
ask, so `defineInputs()` reads as "what this asks for" and a class that
never mentions outputs answers with an empty map. Outputs take the same tool
as inputs. Inputs and outputs are separate namespaces: a key may appear
in both with different meanings.

Three differences from an input, all of them enforced rather than
documented:

| | Input | Output |
| --- | --- | --- |
| **Default value** | Declared, merged under stored values by `accept()`. | Refused. A default is what a value starts from when nobody sent one, and nobody sends an output. A definition carrying `default_value`, at any depth, is refused when it is added and again at seal. |
| **Locking** | Fixes the value to what storage holds. | Refused. Locking narrows what a caller may send. |
| **Refinement** | `#[RefinesInput]` methods narrow it against its siblings. | Refused: a `#[RefinesInput]` naming an output key is refused when the resource or structure is built. A refinement narrows what may be sent, and nobody sends an output. |

An output whose shape depends on an input value would be a variant,
declared with `attachVariants()` on that input key, exactly as for an
input's variants. Outputs hold no attached structure yet: `attach()` and
`attachVariants()` on an output are refused by name, so until they can, an
output that would vary is advertised at its widest: the demo formatter's
`classes` is an open list of class names, whatever variant is chosen.

## Absent, NULL, and the NotEmitted sentinel

Three states, and the middle one is the one worth being careful about.

| The emitted array | Means | Legal when |
| --- | --- | --- |
| The key is missing | The producer has nothing to say about it on this run. | The output is not required. |
| The key holds `NotEmitted::value()` | The same thing, said from inside an array literal. Conformance strips it before anything else looks. | The output is not required. |
| The key holds `NULL` | `NULL` **is** the value. It is emitted, and it is checked against the definition like `0` or the empty string. | The definition accepts it, so never for a required output. |

`Pipeline\NotEmitted` exists because PHP has no way to leave a key out of
an array expression, and the readable shape of a producer is one
literal:

```php
return [
  'text' => $text,
  'classes' => $variant === NULL ? NotEmitted::value() : ['v-' . $variant],
];
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Saying `'classes' => []` instead would be a different statement: "there
are no classes" rather than "this run has nothing to say about
classes". An emitted schema that cannot tell those apart is the gap
this sentinel closes, and it is the same distinction JSON resource
libraries reach for with their own missing-value markers.

The sentinel is a singleton and is recognized by identity, so nothing a
producer could legitimately emit is mistaken for it. Stripping reaches
every depth (a map output's properties are outputs too) and closes
the gap an omitted list item leaves, because a list with a hole in it
is not a shape any consumer expects.

## Conformance

```php
$violations = $pipeline->conformOutput($surface, $emitted);
```

What it does, in order:

1. Strips every `NotEmitted` key, at every depth.
2. Refuses keys no output definition declares, at any depth, in the same
   spirit `accept()` refuses unknown input keys.
3. Checks every present value against its definition's type and
   constraints, through typed data, with path-aware violations.

It answers with the same `ViolationSet` of `Violation` objects
that `validate()` answers with, so a caller reads one shape whichever
direction it is checking.

**Nothing is coerced.** Input arrives from people and payloads and is
coerced through [the coercion table](semantics.md#the-coercion-table); an output is produced
by code that had the declaration in front of it, so `'2'` where an
integer was declared is a bug in the producer and reporting it is the
point. That is stricter than core's own `PrimitiveType` constraint,
which asks whether a value *could be* the type, and deliberately so: the
question here is what a consumer will actually be handed. The strict
check covers the plain scalar types (integer, float,
boolean, string, email, uri) and leaves everything else alone, because
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

The pure data step, with no render array, no theme and no markup: it
returns the formatter's outputs, one array per delta.
`viewElements()` in the base then assembles one element per delta from a small published vocabulary:

| Key | Renders as |
| --- | --- |
| `text` | A `#plain_text` child of the wrapper, so field text containing markup is shown as written rather than filtered. |
| `classes` | The wrapper's `class` attribute. Absent means no class attribute at all, not an empty one. |
| `tag` | The wrapper element; `span` by default. |

A formatter whose markup is not one wrapper around one string overrides
`viewElements()` as it always did. The split is still worth having,
because the data step stays separately callable (by a test, by a JSON
representation, by an agent asking what this formatter would show) and
separately checkable against the contract.

The demo formatter is the worked example: it is its own structure, and
declares two outputs in its static `defineOutputs()`; it implements
`formatValue()`, writes no render array, and emits
`NotEmitted::value()` for its classes when no variant is chosen. Its
`classes` output is advertised open whatever variant is chosen, because
outputs are never refined.

### The tool bridge

`InputDefinitions::outputsFromSurface()` converts an output map
into the Tool API's output definitions (`OutputDefinition`, and
`ListOutputDefinition` or `MapOutputDefinition` for a list or a map)
rather than input definitions: an output is
never rendered as a form element, never refined by a caller's other
answers, and never locked.

What travels: the data type, the label, the description, the required
flag, the constraints, and the enum, through the same treatment the
inputs get. What is lost, on top of the two the inputs already lose
(example values and type settings, both Tool API gaps):

- **The `NotEmitted` marker itself**, which is a PHP marker and not a
  value. The statement it makes survives as `required`.

The tools `data_surface_tool` derives from operations answer with the
accepted `values`, as the read tool would answer them, `committed`, and
the resource's own outputs converted here. A resource's outputs are
what its storage's `load()` hands back once the values are committed; a
read only operation, which is never committed, and a dry run, which
stops before it writes, answer what its storage's `prepare()` answered
under an output's name: example 17's dry run answers each node's title
before and after (why: [decision](decisions.md#who-produces-outputs)). An operation that takes
the keys of a structure of its own (`#[Operation(structure:)]`) answers
that structure's outputs: example 15's listing declares `items`, a list
of maps, and `total`
([An operation that takes keys of its own](resources.md#an-operation-that-takes-keys-of-its-own)). What prepare rehearsed, in storage layout, is
never served (why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## No alter adds an output

What a call answers is its operation's: the owner declares it, and a
consumer reading the owner's output schema reads all of it. Another
module adds no output at build time; a reaction to a save (a message
sent, a record written elsewhere) belongs to ECA. Until 2026-10-24 an
alter could, through `AltersOutputsInterface` and a `third_party_outputs`
mount; nothing shipped used it, and an alter that still implements it
or keeps an `alterOutputs()` method is refused at discovery
(why: [decision](decisions.md#the-surface-was-counted-and-cut)).
