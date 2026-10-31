# Reading a schema

Read this page when you already have a JSON Schema document and want
the module to build keys and rules from it instead of from a class. It
lists what the module keeps from a document, what it checks on the
server, and what it refuses. [Keys](introduction/05-keys.md),
[Rules on a key](introduction/06-rules-on-a-key.md) and
[Structures](introduction/03-structures.md) introduce what a document
becomes.

The emitter writes a resource or structure as JSON Schema 2020-12
([The served contract](served-contract.md)). The reader,
`Contract\SchemaReader` (`surface.schema_reader`), runs that the
other way: it reads a JSON Schema document into the keys, children and
rules a blueprint declares, so what it describes is built, validated,
drawn, served and called as a structure written in code is. The
document may be one the emitter wrote, or one written anywhere else: a
single-directory component's props, a tool's input schema, an OpenAPI
component.

There are two ways in. (A third, `$inputs->fromSchema($document)` in a
class, had no caller and no test, and was removed on 2026-10-24: a class
that has its document in hand registers it on its own, below, and
attaches the structure.)

- **From the site.** Variants whose values and schemas are the site's,
  `attachVariants($key, by: $sibling, from: Provider::class)`: one
  variant per value a provider lists, each read from the schema it
  carries ([Variants from the site](resources.md#variants-from-the-site)).
  Example 12 reads every single-directory component's props this way
  (`/surface-examples/12`).
- **On its own.** `surface.schema_reader`'s `structure($document,
  $id)` registers the document as a structure and answers the name
  `ResourcesInterface::build()` takes; `notes($document)` lists what
  reading it would note, and `SchemaReader::upconvert()` converts an
  older draft ([below](#older-drafts)).

Each class's `use` line: [Namespaces](concepts.md#namespaces).

## The mapping

Each keyword is read as the declaration the emitter writes it from
([what the schema says](served-contract.md#the-schema)), so a document
the emitter wrote reads back as what it was written from
([the round trip](#the-round-trip)).

| The schema says | Read as |
| --- | --- |
| a property | a key; `title` its label, `description` its description (a property with no title has no label) |
| `type` `string`, `integer`, `number`, `boolean` | the data type `string`, `integer`, `float`, `boolean` |
| `format` `idn-email` or `email`, `uri`, `date-time` | the data type `email`, `uri`, `datetime_iso8601`; `email` is written back as `idn-email`, which Drupal's validator holds to |
| `format` `date`, `time`, `uuid` | a `Regex` of RFC 3339's date or full time (none beside a `pattern`, which is the rule), a `Uuid` constraint; a `date` is drawn as a date input |
| any other `format` | dropped, noted: an annotation, which no constraint checks |
| `null` beside the type (`["string", "null"]`) | a key null clears |
| `required` | the key required |
| an optional property whose type refuses null and that has a `default` | a key its storage always holds, which null cannot clear (why: [decision](decisions.md#a-key-its-storage-always-holds)); with no default, a key null clears, since a key always held with nothing to hold would refuse every submission |
| `default`, `examples` | its default, its examples |
| `enum` | a `Choice`; with `meta:enum` labels (single-directory components), a `LabeledChoice` |
| `oneOf` of `const` with `title` and `description` | a `LabeledChoice`, its labels and descriptions; an untitled `{"const": null}` the key null clears |
| `const` | a `Choice` of one value; with `readOnly`, the key locked at it |
| a list of values with no `type` | the type of its values |
| `minimum`, `maximum` | `Range` |
| `exclusiveMinimum`, `exclusiveMaximum` | on an integer, `Range` to the next whole number in; on a number, kept ([below](#what-is-kept)) |
| `minLength`, `maxLength` | `Length` |
| `minItems`, `maxItems` | `Count` |
| `uniqueItems: true` on an array | `UniqueItems`; `false` is what an array says without it, and reads as nothing |
| `pattern` | a `Regex`, when it is inside the subset PHP and an ECMA engine read alike: the body, delimited, with `D` and, for a character outside ASCII, `u` (why: [decision](decisions.md#a-pattern-both-engines-read-alike)); `x-surface.patternMessage` its message; outside the subset, kept |
| `not: {pattern}` | a `Regex` that must not match |
| `allOf`, `anyOf` of parts each one constraint | `Sequentially`, `AtLeastOneOf` of them (why: [decision](decisions.md#a-composite-is-its-parts)) |
| `readOnly` | the key locked, at its `const` |
| `writeOnly` | a secret |
| an object with properties | a map, each property a definition of its own; where it holds more than a map can (a reference, a rule, variants), a structure attached at the key, `<owner>.<key>` |
| an object with no properties | a value of type `any`, held whole to its schema |
| `$ref` to an object under `$defs` | a structure attached at the key, its id the def's key: one structure wherever it is used |
| `$ref` to a scalar under `$defs` | the shape of that id, where the site has one, and what the property says beside it; otherwise the def read in place, noted |
| an array of scalars | a list, its items read as a value is |
| an array of objects, or of references to one | a collection, its Count from `minItems` and `maxItems`, `UniqueItems` from `uniqueItems: true` |
| a property that is a `oneOf` or `anyOf` of objects, each chosen by a conditional `if` naming a sibling's `const` | variants that sibling chooses, each the object its conditional names; the conditionals are the variants' |
| an `allOf` holding a reference to an object def, beside the frame's own properties | that structure mixed in, at its first member's place |
| a conditional whose `if` is a condition of the vocabulary and whose `then` only narrows | a declared rule ([Declaring a rule](refinement.md#declaring-a-rule)): `{"properties": {k: false}}` `doesNotApply()`, `required` `require()`, a bound, a list or a pattern `constrain()`, a `description` `because()` |
| a conditional on a key a child's port is wired to, reaching into the child | the child's rule, on the port |
| `additionalProperties: false`, `unevaluatedProperties: false` | closed, as every structure is |
| `additionalProperties` absent or `true` | read closed, noted: a key nobody declared has no type, label or storage |

The vocabulary of conditions and effects is the one a declared rule
has: `present`, `absent`, `equals`, `in`, the four bounds, `not`,
`allOf` and `anyOf`; an `if` testing several keys at once is their
`allOf`.

### Our own keywords

`x-surface` is read back wherever it says something a structure holds:

| `x-surface` | Read as |
| --- | --- |
| `ports` | `reads()`, each port's type, or its shape where the site has one |
| `reads` | the wiring of a child's ports, where the wired key is a key of the object holding the child |
| `by`, `variants` | variants, and the def each value chooses; `by` beside `deferred` with no union and no `variants`, a list the site decides past its budget (why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)), is an object like any other, the marker noted: no variant is in the document to read |
| `collection` | a collection (`list` in contract 3) |
| `patternMessage` | the `Regex`'s message |
| `widget` `radios`, `time`, `date`, `checkboxes`; `textarea` | the widget it asks for; a multiline string |
| `group`, `after` | a map that only groups, a map placed after a key |
| `checkedOnServer` of a `JsonSchema` constraint | that constraint, from the schema beside its message |
| `rules`, `within` | where the emitter moved its markers, put back first (`SchemaResolver::restored()`) |

The rest says what is true of one moment (`refined`, `stale`,
`emptyOption`, `chosen`, `doesNotApply`), of an account or an access
answer (`readOnly` on a whole), or follows from what is read
(`dependsOn`, `enumerated`): read for nothing. `refine` and `live` say a
rule or an access answer only the server knows: not read, noted.

## What is kept

What no keyword of the vocabulary says is kept, not refused, and not
dropped: `not` (but a pattern), `multipleOf`,
`patternProperties`, `propertyNames`, `minProperties`,
`dependentRequired`, `dependentSchemas`, `contains`, `prefixItems`, a
type that is several types, a pattern outside the subset, an exclusive
bound on a number, a `$ref` to another document, a conditional outside
the vocabulary or one that widens, and anything else. The part of the
schema left over is a `JsonSchema` constraint:

- **on the key** it is about, holding the key's value to that part;
- **on the frame**, for what is said of the object as a whole (a
  conditional, `dependentSchemas`, `patternProperties`), holding the
  frame's accepted values to it; a frame keeps it on its definition
  map (`DefinitionMap::frameConstraints()`), and the pipeline checks it
  after the keys.

The pipeline checks each with opis/json-schema, as a standard validator
reads a schema: its extensions off, `format` not asserted, the defs the
part refers to carried with it. A refusal says what the validator said,
code `not_valid`: "This value does not hold to the schema it was read
from: At least one array item must match schema." A reference to another
document is never fetched: a value it would decide is refused, naming
the reference, code `unresolved`.

Both documents name it under `checkedOnServer`, the key's or the
frame's, with the keywords it checks and the schema itself, so a
reader of a document reads it back as the same constraint:

```json
"tags": {
  "title": "Tags", "type": ["array", "null"], "items": {"type": "string"},
  "x-surface": {"checkedOnServer": [{
    "constraint": "JsonSchema",
    "message": "Checked on the server against the schema it was read from: contains.",
    "schema": {"contains": {"const": "news"}}
  }]}
}
```

The documents are wider there than the pipeline, as they are wherever
a rule is checked on the server alone, and never stricter
(why: [decision](decisions.md#two-documents-never-stricter-than-the-runtime)).

A def's `title` and `description` are kept as well, as what the
structure read from it is called and is for: its label and its
description, which the emitter writes on its def again, and which the
catalog prints beside its id. A `title` that is the def's own key is
what the emitter writes for a structure with no label, so it reads back
as no label. The `title` of the root and of an object at a key is the
operation's or the key's, and the structure takes neither
(`SchemaReader::names()`;
why: [decision](decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for)).

## What is noted

Reading answers notes, by the JSON pointer of what each is about: a
keyword dropped, a keyword kept as a JsonSchema constraint, an object
read closed, a `format` no constraint checks. `notes($document)` reads
every frame of the document and answers all of them, building nothing. Example 12's
card notes one: `/properties/link/additionalProperties`, "Read closed:
a map holds the keys it declares, and a key the schema leaves open is
refused."

Dropped, each noted: `$comment`, `deprecated`, `contentMediaType`,
`contentEncoding`, `contentSchema`, `$anchor`, another vendor's `x-`
keywords, a `default` or `examples` of a whole object, a `checkedOnServer`
entry of a constraint other than `JsonSchema` (known by its plugin
alone), and what a site narrows of a child (a lock its context made).

## What is refused

What cannot be read at all, naming its pointer:

- an object at the root, a def or a variant that is no object
  (`/: what is read into a structure is an object; this is string.`);
- a property whose name holds a dot, which a key cannot;
- a local reference to a def the document does not hold;
- a type JSON Schema does not have (a single-directory component may
  name a PHP class, which a provider leaves out first: the main module's
  `ComponentProps::props()`, which example 12 and the component block
  use).

## Older drafts

`SchemaReader::upconvert()` converts draft-04, -06 and -07 first, at
every depth and whatever `$schema` says, since each conversion reads a
spelling 2020-12 does not have:

| Before | 2020-12 |
| --- | --- |
| `definitions`, `#/definitions/x` | `$defs`, `#/$defs/x` |
| `id` | `$id` |
| `exclusiveMinimum: true` beside `minimum: n` | `exclusiveMinimum: n` |
| `nullable: true` (OpenAPI 3.0) | `null` added to `type` and `enum` |
| `items` as a list, `additionalItems` | `prefixItems`, `items` |
| `dependencies` | `dependentRequired` for a list, `dependentSchemas` for a schema |

Single-directory components write draft-04: example 12 reads them as
they are, and the test module's poster reads a grid whose columns are
`minimum: 0, exclusiveMinimum: true`, an integer from 1.

## The round trip

The full schema the emitter writes for a resource or structure, read
back, builds a structure whose own full schema is that document again,
byte for byte. `SchemaRoundTripTest` holds it for every resource and
structure of the examples, the first resource and the test fixtures,
each in every operation that needs no subject: 43 documents read back
to themselves. A declaration a runtime refiner narrows is left out (3):
its rule is a lookup no document states. What does not round-trip is
listed there, each with its kind, and the test fails when one starts
to, or differs otherwise:

| Kind | Documents | Why |
| --- | --- | --- |
| A coded refiner | 20 | What it answers for each value is read back as a declared rule: the same conditional, its index under the frame's `rules`, and no `enumerated` on the key; what it could not list (`refine`) is not read at all. They differ in those markers alone, which the test holds. |
| A coded refiner, spelled otherwise | 4 | A rule ruling a key out guards the rules requiring it; a rule stated at a site moves into the child, in the child's order; a refiner answering a whole child is no narrowing; a refiner on a port read at a site leaves nothing to read. |
| A rule on a port, a structure alone | 5 | Stated where a container wires the port; emitted alone, nothing states it, and only the key's markers say it. |
| A rule that narrows nothing new | 1 | No conditional states it, so only the key's `dependsOn` says it, which nothing reads back. |
| Access | 12 | A lock, a whole locked or a key a runtime access answer marks `live` is the access class's, not the structure's. |
| An alter's mount | 4 | Its keys, its rules and what it attaches are the alter's: read back, the mount is a map or a child of the owner's, its rules a JsonSchema constraint on the frame. |
| Keys from the site | 2 | An entry a provider locks, and the port each entry reads, are the provider's: read back, the rules on those ports are a JsonSchema constraint on the frame. |
| A computed key | 2 | What computes it is a class no document names: read back, it is a key nobody types that holds what is stored, `readOnly`, with no `x-surface.computed`. |

Each of these still reads, builds, and holds values to what the document
says; what it loses is a marker or the code that would compute it.

## The decision

What is kept rather than refused, opis at runtime, open objects, the
drafts converted first and variants from a provider are as this page
says them
(why: [decision](decisions.md#a-schema-can-be-read-back-into-a-structure)).
