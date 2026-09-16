# Outputs

A surface has two halves. The definitions a host **accepts** are the
input half, and everything else in these docs is about them. The
definitions a host **emits** are the output half, declared in the same
vocabulary, sealed into the same collection type, and held to the same
narrowing rule.

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

In the same declaration as the inputs, on the same builder:

```php
public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
  $builder->setDefinition('variant', DataDefinition::create('string')
    ->setLabel(new TranslatableMarkup('Variant')));

  $builder->setOutputDefinition('text', DataDefinition::create('string')
    ->setLabel(new TranslatableMarkup('Text'))
    ->setDescription(new TranslatableMarkup('What is shown.'))
    ->setRequired(TRUE));

  $classes = new ListDataDefinition(['type' => 'list'], DataDefinition::create('string'));
  $classes->setLabel(new TranslatableMarkup('Classes'));
  $builder->setOutputDefinition('classes', $classes);
  $builder->addOutputRefinement('classes', ['variant']);
}
```

A surface built at runtime says the same thing on its own builder, and
adds an output refiner that is not the host itself the same way:

```php
$builder->addOutputRefiner('classes', new MyOutputRefiner());
```

Three differences from an input, all of them enforced rather than
documented:

| | Input | Output |
| --- | --- | --- |
| **Default value** | Declared, merged under stored values by `accept()`. | Refused. A default is what a value starts from when nobody sent one, and nobody sends an output. A definition carrying `default_value`, at any depth, is refused at declaration and again at seal. |
| **Locking** | Fixes the value to what storage holds. | Refused. Locking narrows what a caller may send. |
| **Refinement edges** | Name sibling **input** keys. | Name **input** keys too: what is emitted is decided by what was given. An output never refines against another output, because outputs are produced in one act by code that already knows all of them. |

The edges are checked against the input map at seal, so an output that
refines against something the surface never accepts is refused there
rather than silently never refining.

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

## Refinement against the inputs

```php
$surface->refineOutputs($input_values);   // A surface with narrowed outputs.
```

An output refines when every input key it depends on holds a configured
value — the same rule `refine()` applies to inputs, from the same place.
A host that narrows its outputs implements
`DataSurfaceOutputRefinerInterface`:

```php
public function refineOutputDefinition(string $name, DataDefinitionInterface $definition, array $input_values): DataDefinitionInterface {
  if ($name === 'classes' && ($input_values['variant'] ?? NULL) !== NULL) {
    $definition->getItemDefinition()->addConstraint('Choice', [
      'choices' => ['v-' . $input_values['variant']],
    ]);
  }
  return $definition;
}
```

It is a **separate interface** from `DataSurfaceRefinerInterface`, not a
second method on it, so that every host base class and every refiner
already written stays exactly as thin as it was. A host that refines
both halves implements both, and the builder recognizes one object
serving as both without being told.

Every link is held to the same narrowing check as an input refiner, by
the same `Refinement\Narrowing`: a refined output must accept only what
the definition it was handed already accepted. On the output side that
rule reads as "a consumer that read the advertisement is never handed a
value the advertisement ruled out", which is the whole reason for
declaring outputs.

`refineOutputs()` is deliberately not folded into `refine()`. The two
narrow different halves against the same values, but they are asked at
different moments: `validate()` refines the inputs on every submission,
while the outputs only matter once the host has run. Folding them would
run every output refiner on every validation for an answer nothing in
that path reads.

Output refiner chains are flat, unlike the input side's per-contributor
chains. An output's value space has exactly one owner, because nothing
can extend an output's choices — a contributor mounts an output of its
own instead — so there is no space to divide and no union to take.

## Conformance

```php
$violations = $pipeline->conformOutput($surface, $emitted, $input_values);
```

What it does, in order:

1. Refines the outputs against the input values, so the definitions the
   emitted values are held to are the ones those inputs selected.
2. Strips every `Omitted` key, at every depth.
3. Refuses keys no output definition declares, at any depth, in the same
   spirit `accept()` refuses unknown input keys.
4. Checks every present value against its definition's type and
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

The demo formatter is the worked example: it declares two outputs,
implements `formatValue()`, writes no render array, and emits
`Omitted::value()` for its classes when no variant is chosen.

### The tool bridge

`SurfaceInputDefinitions::outputsFromSurface()` converts an output map
into the Tool API's output definitions, which are plain
`ContextDefinition` objects rather than input definitions — an output is
never rendered as a form element, never refined by a caller's other
answers, and never locked.

What travels: the data type, the label, the description, the required
flag, the constraints, and the enum, through the same treatment the
inputs get. What is lost, on top of the two the inputs already lose
(example values and type settings, both Tool API gaps):

- **The refinement edges.** The Tool API has `input_definition_refiners`
  and no output counterpart, so there is nowhere to say "this output
  narrows when that input is sent". Convert a surface already put
  through `refineOutputs()` to advertise the narrowed answer instead.
- **The `Omitted` marker itself**, which is a PHP marker and not a
  value. The statement it makes survives as `required`.
- **Who contributed what.** A mounted third-party output arrives as an
  ordinary property of the `third_party_outputs` map.

The two field tools in `data_surface_tool` still declare their outputs
by hand, and both halves of that are deliberate: the `#[Tool]` attribute
takes outputs statically, and what those tools emit is the *stored*
settings, which is storage shape and belongs to the target rather than
the surface shape this bridge converts.

## Contributing an output

A module that owns neither the host nor its execution can still add to
what the host emits, under its own namespace, at build time:

```php
$event->builder->setThirdPartyOutputDefinition(
  'my_module',
  'badge',
  DataDefinition::create('string')
    ->setLabel(new TranslatableMarkup('Badge')),
);
```

The value lands at `third_party_outputs.my_module.badge` — advertised,
conformance checked, and machine-visible — and cannot collide with the
owner's outputs or with another contributor's. The owner may not declare
an output of that name; the key belongs to whoever mounts under it.
Policy filters do not apply to outputs: a filter speaks about what a
site allows a caller to configure, and nothing configures an output.
