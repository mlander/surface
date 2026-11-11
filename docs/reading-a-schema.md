# Reading a schema

**Experimental.** Reading a schema (the JSON Schema, config schema and
typed data readers) is one of this module's bolder ideas, not a settled
part of the pattern. Its PHP API, its stored shape and what its doors
answer may change in any commit, and nothing is migrated.
[What is promised](evolution.md#what-is-promised) says what the module
does promise.

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
| `type` `string`, `integer`, `number`, `boolean` | the data type `string`, `integer`, `float`, `boolean`: the type table the emitter writes from (`Contract\LeafTypes`), read backwards, so every value a declaration holds is written with a `type` and read back to it ([decision](decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader)) |
| `format` `email` or `idn-email`, `uri`, `date-time` | the data type `email`, `uri`, `datetime_iso8601`; `idn-email`, which documents before contract 5 wrote, is written back as `email`, the dialect's ([Contract 5](served-contract.md#contract-5)) |
| `format` `date`, `time` | a `Regex` of RFC 3339's date or full time (none beside a `pattern`, which is the rule); a `date` is drawn as a date input |
| `format` `uuid` | a `Uuid` constraint, written back as `format: uuid`; core's check is strict and refuses the nil UUID, which the format allows ([decision](decisions.md#one-table-maps-a-constraint-to-its-keywords-both-ways)) |
| `format` on a type other than string | dropped, with no note: an annotation, which checks nothing |
| any other `format` | dropped, noted: an annotation, which no constraint checks |
| `null` beside the type (`["string", "null"]`) | a key null clears |
| `required` | the key required |
| an optional property whose type refuses null and that has a `default` | a key its storage always holds, which null cannot clear (why: [decision](decisions.md#a-key-its-storage-always-holds)); with no default, a key null clears, since a key always held with nothing to hold would refuse every submission |
| `default`, `examples` | its default, its examples |
| `enum` | a `Choice`; with `meta:enum` labels (single-directory components), a `LabeledChoice` |
| `oneOf` of `const` with `title` and `description` | a `LabeledChoice`, its labels and descriptions; an untitled `{"const": null}` the key null clears |
| `const` | a `Choice` of one value; with `readOnly`, the key locked at it |
| a list of values with no `type` | the type of its values |
| `minimum`, `maximum` on an integer or a number | `Range` |
| `exclusiveMinimum`, `exclusiveMaximum` | on an integer, `Range` to the next whole number in; on a number, kept ([below](#what-is-kept)) |
| `minLength`, `maxLength` on a string | `Length`, `min` and `max`; two equal bounds as `min` and `max`, not `exactly`; `minLength: 1` as `Length`, not `NotBlank` |
| `minItems`, `maxItems` on an array | `Count`, `min` and `max`; on a collection, its bounds, a bound that is no whole number noted and not read |
| `uniqueItems: true` on an array | `UniqueItems`; `false` is what an array says without it, and reads as nothing |
| `pattern` on a string | a `Regex`, when it is inside the subset PHP and an ECMA engine read alike: the body, delimited, with `D` and, for a character outside ASCII, `u` (why: [decision](decisions.md#a-pattern-both-engines-read-alike)); `x-portable-messages.pattern` its message; outside the subset, kept |
| `not: {pattern}` on a string | a `Regex` that must not match; beside a `pattern`, both are kept, since a definition holds one `Regex`; beside a `format` read as a `Regex` (`date`, `time`), the format's pattern is kept as a `pattern` |
| `allOf`, `anyOf` of parts each one constraint of the table | `Sequentially`, `AtLeastOneOf` of them (why: [decision](decisions.md#a-composite-is-its-parts)); otherwise kept |
| any of these on a type it does not say anything of (`minLength` on an integer) | kept ([below](#what-is-kept)) |
| `readOnly` | the key locked, at its `const` |
| `writeOnly` | a secret |
| an object with properties | a map, each property a definition of its own; where it holds more than a map can (a reference, a rule or variants its `allOf` states), an anonymous map with a frame of its own, `addMap()`, written inline again; closed otherwise, or saying what a map does not write, a structure attached at the key, `<owner>.<key>` |
| an object with no properties | a value of type `any`, held whole to its schema |
| `$ref` to an object under `$defs` | a structure attached at the key, its id the def's key: one structure wherever it is used |
| `$ref` to a scalar under `$defs` | the shape of that id, where the site has one, and what the property says beside it; otherwise the def read in place, noted |
| an array of scalars | a list, its items read as a value is |
| an array of objects, or of references to one | a collection, its Count from `minItems` and `maxItems`, `UniqueItems` from `uniqueItems: true` |
| a property that is a `oneOf` or `anyOf` of objects, each chosen by a conditional `if` naming a sibling's `const` | variants that sibling chooses, each the object its conditional names; the conditionals are the variants' |
| an `allOf` holding a reference to an object def, beside the frame's own properties | that structure mixed in, at its first member's place |
| a conditional whose `if` names one sibling's `const` and whose `then` is a reference to an object def, alone or the one entry of an `allOf` | kept, a `JsonSchema` constraint on the frame, and noted: a `then` that refers to a def is no effect a declared rule has, and variants are read where a property holds them (the `oneOf`/`anyOf` row) |
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
| `group`, `after` | a map that only groups, a map placed after a key |
| `rules`, `within` | where the emitter moved its markers, put back first (`SchemaResolver::restored()`) |

Three keywords of the dialect are read too ([The dialect's own keywords](served-contract.md#the-dialects-own-keywords)):

| Keyword | Read as |
| --- | --- |
| `x-portable-ui` `widget` `radios`, `time`, `date`, `checkboxes`; `textarea` | the widget it asks for; a multiline string |
| `x-portable-ui` `group`, with the frame's `groups`; `weight`; `options.placeholder`, `prefix`, `suffix`, `help` | the key's arrangement, described as an author describes it (`describe(group:, weight:, placeholder:, prefix:, suffix:, help:)`): a group's `widget` `details` is `ArrangementGroup::details()`, closed where its `options.open` is `false`, any other `fieldset()`, noted where it is neither; a key naming a group its frame does not declare, or one with no label, is noted and read ungrouped; a weight that is no whole number and a text that is no string are noted and not read; the keys of a map or a module's mount are read with the map's own `groups`. `options.emptyOption` and `options.multiple` are noted and not read: the options resolver decides them |
| `x-portable-messages` | under `pattern`, or `not` for `not: {pattern}`: the `Regex`'s message |
| `portable-check` `drupal:JsonSchema` | that constraint, from its `schema`; with keywords the value keeps besides, one constraint, the `allOf` of both. Any other check is noted and not read: what the emitter names there, and why, is in [What the keywords leave to the server](served-contract.md#what-the-keywords-leave-to-the-server) |

A document contract 4 wrote said both under `x-surface`: its
`checkedOnServer`, a list of `{constraint, message, schema}`, is read as
`portable-check`, and its `patternMessage` as `x-portable-messages`
under the code of the node's `pattern` or `not`
(`SchemaReader::respelled()`), so the document is checked as it was.
A document served before 2026-11-10 said the widget hints under
`x-surface` (`widget`, `multiple`, `arrangement` with the whole group on
each key, `emptyOption`): they are read as `x-portable-ui` says them
now, each group declared once on its frame (`SchemaReader::hinted()`).

Below the root, `$id`, `$schema`, `$anchor` and `$dynamicAnchor` are
dropped, each noted, wherever they stand, in a schema kept for a
JsonSchema constraint too: what a structure keeps of a schema is served
again under `portable-check`, below the root, where a Portable Schema
document holds no `$id` and no `$schema`.

The rest says what is true of one moment (`refined`, `stale`,
`chosen`, `doesNotApply`), of an account or an access
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

Both documents name it under `portable-check`, the key's or the
frame's, with the schema itself, and say the keywords it checks under
`x-portable-messages`, so a reader of a document reads it back as the
same constraint:

```json
"tags": {
  "title": "Tags", "type": ["array", "null"], "items": {"type": "string"},
  "portable-check": {"drupal:JsonSchema": {"schema": {"contains": {"const": "news"}}}},
  "x-portable-messages": {"check.drupal:JsonSchema": "Checked on the server against the schema it was read from: contains."}
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
keywords, a `default` or `examples` of a whole object, a `portable-check`
check other than `drupal:JsonSchema` (known by its plugin alone), and what a site narrows of a child (a lock its context made).

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
each in every operation that needs no subject: 73 documents read back
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

## Config schema

`Reader\ConfigSchemaReader` reads a config schema type into a blueprint
the same way, with no JSON Schema between: a mapping is a map or an
attached structure, a sequence a list or a collection, and a type named
through another key is variants, which `Variants\ConfigSchemaTypes`
lists, in one of two forms.

**A type named through a sibling,** `[%parent.<key>]`, is variants at
the key, chosen by the sibling and stored under the key, as core stores
a block's settings beside its plugin id. A binding written by hand
names the schema type and the path of the value:

<!-- code: tests/modules/surface_reader_test/src/Structure/BlockDirectStructure.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three block plugins' settings, straight from config schema.
 */
#[Structure('reader_test.block_direct')]
final class BlockDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('plugin', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['page_title_block', 'system_branding_block', 'system_menu_block:main']]);

    $inputs->attachVariants('settings', by: 'plugin', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'type' => 'block.block.*',
      'path' => 'settings',
      'by' => 'plugin',
    ]);
  }

}
```

**A type chosen by a key inside its own value,** `condition.plugin.[id]`
or `views.sort.[plugin_id]`, is one structure wherever it is used,
`config_schema:<expression>`: the fallback type's keys, then the
variants the own key chooses at `configuration`
(`ConfigSchemaReader::CHOSEN`), stored flat beside the own key, as core
stores them. The reader declares them with
`attachVariants(ConfigSchemaReader::CHOSEN, by: $by, from: ConfigSchemaTypes::class, arguments: ['type' => $named])`
and `Variants::STORED_AS` `Variants::FLAT` on the map it returns
([Stored flat](resources.md#stored-flat)). `configuration` is core's
word for a plugin's own settings
(`ConfigurableInterface::getConfiguration()`, and the key
`layout_builder.component` and `system.action.*` nest them under); the
map at it carries no label, since the schema names none. A fallback
that declares `configuration` itself is refused when the structure is
built: stored flat, its key and the chosen type's would be one key.

So the contract nests the chosen keys one level under `configuration`
where the YAML is flat. The test module's condition host keeps one
condition as a block's visibility keeps each, the request path's pages
beside its id:

<!-- yaml: tests/modules/surface_reader_test/config/install/surface_reader_test.condition_host.yml#condition -->

```yaml
condition:
  id: request_path
  negate: false
  pages: '/node/*'
```

Every door reads it as `id`, `negate` and `configuration`, the pages
under `configuration`, and writes it back beside the id.

A binding written by hand may hold the same variants under a key of its
own, stored under it: the test module's conditions bind
`condition.plugin.[id]` through the block's `visibility.*`, at
`settings`, each variant the condition's keys less those every
condition has:

<!-- code: tests/modules/surface_reader_test/src/Structure/ConditionDirectStructure.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Structure;

use Drupal\surface\Variants\ConfigSchemaTypes;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Three conditions' settings, straight from config schema.
 *
 * Each less the keys every condition has, which its frame holds.
 */
#[Structure('reader_test.condition_direct')]
final class ConditionDirectStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('id', 'string', t('Plugin'))
      ->addConstraint('Choice', ['choices' => ['request_path', 'response_status', 'user_role']]);

    $inputs->attachVariants('settings', by: 'id', label: t('Settings'), from: ConfigSchemaTypes::class, arguments: [
      'type' => 'block.block.*',
      'path' => 'visibility.*',
      'by' => 'id',
    ]);
  }

}
```

**A key the schema declares is always declared.** A derived structure
is closed, so a key left out would refuse the stored value that holds
it. Where the reader derives no shape (a type of `ignore` or
`undefined`, a type chosen by a key outside the frame, an entry typed by
its own key, a sequence of sequences of mappings, a class of no leaf
type), the key is an `any` held whole to nothing
(`LeafRules::open()`): it accepts what is stored there as it is, and
both documents write it with its title and no type. The reason is still
noted at the key's path (`Reading::left()`)
(why: [decision](decisions.md#a-schema-can-be-read-back-into-a-structure)).

**Each variant in its own frame.** Under `configuration` each variant is
its type whole, less the fallback's keys, so two types that declare one
key unlike each keep their own definition: `views.field.field` holds
`separator` to a `label`, with its `Regex`, and `views.field.user_roles`
(from `views.field.prerender_list`) to a plain `string`. A variant may
hold children, maps and variants at a key of its own, as
`views.field.field` holds its formatter's `settings`
(why: [decision](decisions.md#variants-stored-flat-are-a-layout)).

**Which structure each value is.** `Variants\ConfigSchemaTypes` lists the
variants of both forms, bound by name to the type and the path of the
value (`['type' => 'block.block.*', 'path' => 'settings']`) or to an
own-key expression (`['type' => 'condition.plugin.[id]']`), with
`'by' => <key>` where a binding written by hand checks the deciding
key the schema names. Its values come from a `PluginExists` constraint
on the deciding key (the plugin manager's definitions), else a
`Choice`, else the schema's type names. Where they come from a plugin
manager, a plugin whose class names its settings with
`#[UsesStructure]` is that structure, built as declared, its refiners,
children and defaults with it, and its schema is not read;
every other value is the structure its schema type is. For an own-key
expression that structure is the type's `:own` form,
`config_schema:<type>:own`: the type's keys less those of its frame
type, the type itself or any type it extends, matched by keys, not by
name; a type that matches none is refused
(`ConfigSchemaReader::structure()`). So `views.argument.date_day`
(`type: views.argument.date`, which is `type: views_argument`, as
`views.argument.*` is) holds `views.argument.date`'s keys and its own,
less those of `views.argument.*`. A `#[Variant]`
declared for the owner at the key wins over both, as for any provider
(why: [decision](decisions.md#a-placed-block-is-a-resource)).

## The decision

What is kept rather than refused, opis at runtime, open objects, the
drafts converted first and variants from a provider are as this page
says them
(why: [decision](decisions.md#a-schema-can-be-read-back-into-a-structure)).
