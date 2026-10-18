# The served contract

A resource built in one of its operations, served as a JSON document a
client with no PHP can render a form from, re-narrow as answers change,
validate against before anything is written, and then write through. The
emitter is the main module's; the five endpoints and a React app that
renders from them are the experimental submodule `data_surface_react`.

This is the roadmap's "served contract" in its first shape: one
operation at a time, read, rehearsed and written.

## Where the emitter lives

`Drupal\data_surface\Contract\ContractEmitter`, the service
`data_surface.contract_emitter`, is the module's canonical contract: it
reads any sealed data surface and nothing else, and `emit()` answers a
`ServedContract` (the document, and its cacheability beside it). The
contract names no widget. `x-surface.widget` (and `multiple`, its
qualifier on a list of allowed values) is presentation, the Form API
mapping said out loud for a renderer, and the roadmap rejects widget
vocabulary in the contract, so it is written only when a caller asks:
`emit(..., widgets: TRUE)`. `data_surface_react` is that caller; its
endpoints serve the contract with the hints, for its app to draw from.
Everything else under `x-surface` is the data surface's own reading of
a key and is always there.

It is this module's own emitter, not the Tool API's: the Tool API's
context definitions have no place for labelled values, a conditional on
a sibling, or anything the form knows about a key, so the tool bridge's
JSON Schema cannot say them. This one can.

## Where to see it

- **Under an operation form.** `data_surface_tool`'s contract panel,
  which the examples show under each form, shows it collapsed as "The
  schema for these answers", emitted for the operation and the form's
  values as they stand, so it rebuilds with the form. Beside it, "The
  full schema, for any answers" is [the second document](#two-documents),
  which does not. Below them, "What the Tool API can advertise" shows
  the derived tool's schema for comparison. The examples add a second
  panel under that one, "Without a resource: what config schema can
  say": the same settings described from config schema alone, set
  beside the full schema key by key
  (`modules/data_surface_examples/README.md`).
- **Over HTTP.** `GET /surface-api/{surface}/{situation}`, the
  resource's id and the operation's id, with `data_surface_react`
  enabled: the whole document, widget hints included. The full schema is
  `GET /surface-api/{surface}/{situation}/schema` beside it.
- **Not `drush tool:info`.** That prints the Tool API's rendering of the
  derived tool, which cannot say a shape keyed by a sibling: a slot is
  flattened to one map, the variant the stored values choose, or every
  variant's keys with none required before anything chooses
  ([resources](resources.md), "Emission"). The `allOf` of `if`/`then`
  below is only in this contract, for a slot and for
  [an enumerated key](#exact-where-it-can-be) alike: a Tool API context
  definition has no conditional, so the tool's schema stays as loose as
  the unrefined data surface.

## Two documents

The emitter writes two documents from one data surface, by one code path.

- **The schema as it stands**, `emit()`, is everything below: built for
  one set of values, with `values` and `stale` beside it. A key whose
  watched siblings hold values is refined for them, so with Riverside
  Hall stored the room offers Riverside's two rooms. A runtime refiner
  is run for it like any other, because this document is the present
  moment: with the East room closed, Riverside offers the main hall
  alone. It is what a form is drawn from, and what `/surface-api` and
  `/refine` serve. It describes those values and no others, at the
  moment it was emitted: do not validate an arbitrary submission against
  it.
- **The full schema**, `emitFull()`, reads no value, so it is the same
  whatever is stored or typed. Every key is the one declared
  (every room; a capacity from 1 to 1000), every dependency that can be
  listed is written as conditionals on the parent, always, under
  [the same rules and cap](#exact-where-it-can-be), and a key whose
  dependency cannot be listed keeps its declared schema with
  `x-surface.dynamic: true`. A runtime refiner is never run for it: the
  key keeps the width its static refiners give it and carries
  `x-surface.runtime: true`. Its `x-surface` carries only what holds for
  any values (`locked`, `dependsOn`, `enumerated`, `dynamic`, `runtime`,
  a slot's `by` and `variants`, `group`, `after`, `patternMessage`,
  `checkedOnServer`); there is no `refined`, `stale`, `emptyOption` or
  `chosen`, and no `values` or `stale` beside the schema. A child's
  frame is described from nothing, not from the child's defaults. Only
  the locked keys an operation knows are read, since no value changes
  them. It is what a validator, or an agent planning its values, or
  anything that takes the schema once, wants, and what a drawing client
  [narrows from](#a-drawing-client-that-narrows-locally).
  `GET .../schema` serves it, with the widget hints; the contract panel
  shows it.

### Never stricter than the runtime

Neither is ever stricter than the pipeline
([decision](decisions.md#two-documents-never-stricter-than-the-runtime)),
and the rule is about the **accepted document**: what `accept()` makes
of the input after coercion, not the raw input. That is the Tool API's
shape: it coerces a tool's inputs by a documented table first and
validates after, and its matrix tests compare the advertised schema
with what the tool accepts post-coercion. So a form's `"50"` for an
integer, `""` for nothing, a partial update and a value posted for a
locked key are notations the pipeline reads ([semantics](semantics.md#the-coercion-table))
and the schema need not admit; the document they become, it does.

- **A `null` on an optional key is a value.** Left out, an optional key
  keeps what it holds; `null` clears it
  ([semantics](semantics.md#absent-null-or-a-value)), so its type
  allows `null`. A secret is the one optional key whose type does not:
  `null` does not clear it, its marker does. The contract's `values`
  carry every key, a secret as `null` because it is masked; read
  against the schema, that one `null` is the key left out.
- **`format` is advisory.** `format` is written as JSON Schema 2020-12
  defines it, an annotation a client may opt into asserting
  ([decision](decisions.md#format-is-an-annotation)). Drupal's checks
  are not the formats': an address with a non-ASCII local part, or a
  URI with a space, is accepted by the pipeline and refused by a client
  that asserts `format`.
- **`default` is an annotation too.** A validator that fills a missing
  key from its `default` before reading `required` (opis's
  `allowDefaults`) is not reading the schema as a standard one does;
  the suite does not.
- **Where the schema cannot say a rule it stays wide.** Where a rule
  has no JSON Schema keyword, a dependency is free text or too large to
  list, or a refiner is declared runtime, the schema stays at the
  declared width and says so (`checkedOnServer`, `dynamic`, `runtime`);
  the pipeline's refinement is the guard with the last word. A Regex
  whose body uses anything the two engines do not read alike carries no
  `pattern` at all ([decision](decisions.md#a-pattern-both-engines-read-alike)).
- **A stale value is storage's.** The full schema reads no storage, so a
  stored value a static list no longer offers, which the pipeline keeps
  stale, is outside it.

`AdvertisedSchemaAcceptanceTest` holds this for every example and the
test fixtures: each accepted document is accepted by both documents and
each refused one refused, both ways where the schema is exact and one
way where it is `dynamic` or `runtime`, by a standard validator
(`format` not asserted, no defaults filled).

### Three markers

A key a refiner narrows carries at most one of `enumerated` and
`dynamic`, for its static refiners, and `runtime` besides when a runtime
refiner narrows it too ([refinement](refinement.md#static-and-runtime-refiners)).

| Marker | Means | In the full schema | In the schema for these answers |
| --- | --- | --- | --- |
| `enumerated` | Captured exactly. The key's static refiners watch only keys that offer a list, within the cap, so what they answer for every combination is written as conditionals on the parent. | On every such key, always: no value is read. | Only while a sibling its static refiners watch holds nothing; once all do, the key is refined for them and carries neither this nor `dynamic`. |
| `dynamic` | Static by declaration, but not captured here: a watched key cannot be listed (free text, a number, a key an alter mounted) or the combinations exceed `ENUMERATION_CAP`. The key keeps its declared schema; a client can ask, with values, at `/refine`. | On every such key. | As for `enumerated`. |
| `runtime` | Declared impossible to capture: a runtime refiner narrows the key with a rule no schema can state (a lookup, uniqueness, the account, the time). Never run ahead of time and never in a conditional. | On the key, which keeps the width its static refiners give it: wider than the pipeline, by design. | On the key, always; the key is refined by the runtime refiner for these values, so this document is valid for those values, at that moment, and no others. |

A key with a static and a runtime refiner, example 4's room, is
`enumerated` (per venue) and `runtime` (closed rooms). A key with only a
runtime refiner carries `runtime` alone.

Example 2's full schema, abbreviated:

```json
"room": {"title": "Room", "type": "string", "oneOf": [...all six rooms...],
         "x-surface": {"locked": false, "dependsOn": ["venue"], "enumerated": true}},
"capacity": {"title": "Capacity", "type": ["integer", "null"], "minimum": 1, "maximum": 1000, "default": 50,
             "x-surface": {"locked": false, "dependsOn": ["room"], "enumerated": true}},
...
"allOf": [
  {"if": {"properties": {"venue": {"const": "riverside"}}, "required": ["venue"]},
   "then": {"properties": {"room": {"oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}]}}}},
  ...two more venues...
  {"if": {"properties": {"room": {"const": "riverside_main"}}, "required": ["room"]},
   "then": {"properties": {"capacity": {"description": "Up to 400 for the Main hall.", "maximum": 400}}}},
  ...five more rooms...
]
```

## The document

| Key | Holds |
| --- | --- |
| `surface` | The resource's id, the one on its `#[Resource]` (or a structure's, on its `#[Structure]`). |
| `situation` | The operation's id, or `null` for a resource or structure asked for in no operation. |
| `label` | The operation's label. |
| `schema` | JSON Schema 2020-12 for the inputs, `x-surface` on every property. |
| `values` | The values the schema describes now: what storage holds over the declared defaults (which carry an operation's starting values and known identity), with any in-progress answers over that. Every key is present. A secret is `null` (masked: read as the key left out); a stale value is `null`, its path in `stale`. |
| `stale` | The dotted paths shown on the empty option in place of a stored value. |
| `outputs` | The output schema, when the resource or structure declares outputs. |
| `fingerprint` | On the GET and in a submit's answer: a keyed hash of the stored values the document was built from, for a submit to send back ([the fingerprint rule](#the-fingerprint-rule)); `null` for a resource or structure with no storage. Not on a refine. |
| `discarded` | On a refine only: the keys whose answer the new choices orphaned. |

### The schema

Each frame (the top level, an attached part, a slot's variant) is an
object with `properties` in declaration order, `required`, and
`additionalProperties: false`; an attached part and a slot's variant are
each written once, as a def under `$defs`, and referred to
([reusable subschemas](#reusable-subschemas)).

| The declaration says | The schema says |
| --- | --- |
| label, description | `title`, `description` |
| `string`, `email`, `uri`, `integer`, `float`, `boolean` | `type` (`format: email` / `uri` for those types, advisory); an optional key also allows `null`, which clears it (a secret does not) |
| `setRequired(TRUE)` | the key in `required`: present and not null, nothing about size. The contract's `values` always carry every key, so presence is never the question. |
| a declared default, examples | `default`, `examples` |
| `Range` | `minimum`, `maximum` |
| `Length` | `minLength`, `maxLength` |
| `Count` | `minItems`, `maxItems` |
| `Regex` | `pattern` (`not: {pattern}` for `match: false`), carried only when its body stays inside the subset both engines read alike and its modifiers are `u` and `D`; otherwise left to the server ([decision](decisions.md#a-pattern-both-engines-read-alike)) |
| `Email` | `format: email`, advisory |
| `NotBlank` | `minLength: 1` |
| any list of allowed values | `oneOf: [{const, title, description?}]`, read from the options service, so the labels travel; never `enum`. An optional key adds an untitled `{const: null}`. |
| a map | a nested object |
| an attached part | a reference to its def under `$defs`, beside its label ([reusable subschemas](#reusable-subschemas)) |
| a list | `type: array`, `items` |
| a collection | `type: array`, `items` a reference to the child's def, narrowed by composition only where every item differs from it (a key the child's operation locked, a locked list), `minItems` and `maxItems` from its Count as the parent's refiners left it, and `x-surface.list: true` ([below](#collections)) |
| a locked key | `readOnly: true` and `const` of the value the operation knows |
| a child its access locked | `readOnly: true` on the part, and on every key inside it with `const` of what is stored (none in the full schema); an omitted child is not there at all ([Three answers](resources.md#three-answers)) |
| a secret | `writeOnly: true`, its declared type, never in `required` (left out or `null`, the stored secret stands), no default, `null` in `values` ([decision](decisions.md#a-secret-is-optional-never-nullable)) |
| any other constraint | named in `x-surface.checkedOnServer`: the schema is not the whole story, ask `/validate` |

### `x-surface`

| Keyword | Holds |
| --- | --- |
| `widget` | Only when the caller asks for widget hints (`data_surface_react` does). The Form API-equivalent hint: `select`, `radios`, `checkbox`, `number`, `text`, `textarea`, `email`, `fieldset`, `slot`, `list` (a collection too); `null` when no widget would claim the key. |
| `locked` | Identity the operation knows, or a key of a child whose access answered locked: shown, never changed. |
| `dependsOn` | The sibling keys this key's refiners watch, in its own frame; a key an alter mounted, which only that alter may watch, by its dotted path in that frame (`third_party_settings.<module>.<key>`). A client refines when one of them changes. |
| `refined` | Whether the key is narrowed right now, compared with what the resource or structure advertises in this operation. |
| `stale` | The stored value is no longer offered; the key is shown on its empty option, standing for it. |
| `emptyOption` | On a single select: `{show, label}`, by [the empty option rule](decisions.md#the-empty-option-rule): always for an optional select (`- None -`), for a required one only while no valid choice is selected (`- Select -`). |
| `multiple` | With the widget hints, on a list of allowed values: a multiple select. |
| `by`, `variants`, `chosen` | On a slot: the deciding key, the values that choose a variant, and the one chosen now. |
| `variant` | On a slot's conditional, beside the reference to the variant's def: which value it is for. |
| `group` | Only when `true`, on `third_party_settings`: the object only groups one object per contributing module, each that module's fieldset, so it has no `title` or `description` and a renderer draws nothing around them, as the form does. |
| `after` | On a module's object inside a `group`, when its alter placed it (`describe(..., after:)`): draw this fieldset after the named property of the object that contains its group. Only where it is drawn: its properties stay in the group, where its values are sent, and the schema's order and nesting do not move. |
| `checkedOnServer` | Constraints no keyword states. |
| `patternMessage` | On a key with a `pattern`: the Regex's own message, which is how a person is told what the pattern allows. The pattern is for machines; a renderer explains the key by this, never by the pattern. |
| `enumerated` | Only when `true`: the key's static refiners have nothing to refine against yet, and what they answer for every value they could be handed is stated as conditionals on the parent ([below](#exact-where-it-can-be)). Captured exactly: the schema is exact for this key with no round trip, less what `runtime` adds. |
| `dynamic` | Only when `true`: the key's static refiners have nothing to refine against yet, and what they would answer cannot be listed (a watched key with no list of values, or too many combinations). Static by declaration, not captured here: the schema is the unrefined, looser one, wider than the pipeline and never narrower ([decision](decisions.md#two-documents-never-stricter-than-the-runtime)); refine when a `dependsOn` key changes. In [the full schema](#two-documents) every key that cannot be listed carries it. |
| `list` | Only when `true`, on a collection: an array whose every item is a child, its `items` a reference to the child's def. A renderer draws each item as that def's fieldset, adds and removes items within `minItems` and `maxItems`, and reads `dependsOn` inside each item under its delta (`shelves.1.unit`). |
| `runtime` | Only when `true`: a runtime refiner narrows the key too, by a rule no schema can state ([three markers](#three-markers)). Never in a conditional and never in the full schema; the schema for these answers is refined by it for its values. Written beside `enumerated` or `dynamic` when the key has static refiners as well. |

### Reusable subschemas

The module draws a wall between a parent and its child: a parent cannot
refine into a child, a child cannot watch a parent's key, and a child
keeps its own refiners and alters
([attached structures](resources.md#children)).
That makes a child a self-contained subschema, and both documents say
so with JSON Schema 2020-12's own `$defs` and `$ref`, through the one
code path they share (`ContractEmitter::define()` and `reference()`):

1. **One def per child.** Every attached child and every slot's
   variant is written once, under the root's `$defs`, keyed by the id on
   its class's `#[Structure]` or `#[Resource]` (`registration.contact`).
   A child with no class, a [derived variant](resources.md#derived-variants), is keyed
   `<parent id>.<slot key>.<value>` (`field.storage.settings.string`), an
   attached one `<parent id>.<key>`. The same class at two keys, or in
   two frames, is one def. A def may refer to other defs (a child inside
   a variant). Two children claiming one id with declarations that are
   not the same, which only a hand-sealed data surface or two different
   contexts can produce, keep one def each, the second as `<id>@2`,
   because a site may only narrow its def and neither would narrow the
   other. A child reached inside its own def would be referred to, so a
   resource or structure that ever contained itself ends; the build step
   already refuses one.
2. **The def is the declaration**: the child's frame as it emits itself
   for no values, with nothing locked. Its properties, `required` and
   `additionalProperties: false`; its own conditionals under its
   `allOf` (what its refiners answer for each value they could be
   handed); and its own `x-surface` facts (a `dependsOn` inside the
   child, `enumerated`, `dynamic`, `group`, `after`, a slot's `by` and
   `variants`). An alter of the child applies in the def, since alters
   apply by class. No label: that is the parent's to give. Each document
   writes its own defs, the schema for these answers with its
   `refined`, `stale` and `emptyOption` as they read for no values, the
   full schema without them; otherwise they are the same.
3. **The site carries the instance.** Where the child is used, the
   property is `{"$ref": "#/$defs/<id>", ...}` with what is particular
   to that use beside it: the title and description the parent gives the
   key, and the `x-surface` the parent reads for it (`locked`,
   `dependsOn` as the parent sees it, `refined`, `variant` on a slot's
   conditional, the widget hint). JSON Schema 2020-12 applies keywords
   beside `$ref` as well as its target.
4. **Narrowing is composition.** Where the child at a site says more than
   its def (a key its context locked, a key refined for the values in
   this document), the site is `{"allOf": [{"$ref": ...}, {narrowing}],
   ...}`, the narrowing holding only what differs from the def, by the
   same "only what changed" reading the [enumeration](#exact-where-it-can-be)
   uses: properties compared one by one, conditionals the def does not
   already state, and a property's `x-surface` written whole where it
   differs. Where nothing differs, the reference is bare. Refinement only
   narrows, so a validator applying the def and the narrowing accepts
   exactly what the child at that site accepts; `ServedContractTest`
   holds the two readings to the same verdicts.
5. **A slot's conditionals refer to the variants' defs.** Each `then`
   names the slot with a reference to its variant's def, beside the
   slot's label and `x-surface.variant`; the slot's own property keeps
   what it said before (`by`, `variants`, `chosen`).

Only local references, `#/$defs/<id>`, are written: the def's key is a
JSON Pointer segment (`~` and `/` escaped) inside the URI fragment. A
reader that wants a key's effective schema inline resolves them once:
`Contract\SchemaResolver::inline()` in PHP, `inlineSchema()` in the
React app. Both replace a reference by its def where it stood, lay a
composition's narrowing over it taking the narrowing's value for every
keyword it states (`properties` one by one, `required` as a union, the
conditionals concatenated), and leave a reference back into a def being
read as it is.

Example 3's full schema, abbreviated. Nothing in example 3 differs from
its def, so every reference is bare:

```json
"properties": {
  ...
  "ticket": {"title": "Ticket", "type": ["object", "null"],
             "x-surface": {"dependsOn": ["pricing"], "by": "pricing", "variants": ["free", "paid"], ...}},
  "contact": {"title": "Contact", "$ref": "#/$defs/registration.contact",
              "x-surface": {"locked": false, "dependsOn": []}}
},
"allOf": [
  ...the room per venue...
  {"if": {"properties": {"pricing": {"const": "paid"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.paid",
     "x-surface": {"locked": false, "dependsOn": [], "variant": "paid"}}}}},
  ...free...
],
"$defs": {
  "registration.step3.ticket.free": {...},
  "registration.step3.ticket.paid": {"type": "object", "properties": {"price": {...}, "currency": {...}},
                                     "additionalProperties": false, "required": ["price", "currency"]},
  "registration.contact": {"type": "object",
    "properties": {
      "email": {"title": "Email", "type": "string", "format": "email", "x-surface": {"locked": false, "dependsOn": []}},
      "phone": {"title": "Phone", "type": ["string", "null"], "x-surface": {"locked": false, "dependsOn": []}}},
    "additionalProperties": false, "required": ["email"]}
}
```

A narrowing, from the full schema of `field.instance:edit` for a long
text field: the field's storage is attached in the storage's own `edit`
context, which knows its field type and locks it, while the
`field.storage` def is the storage as declared, with the field type
open:

```json
"storage": {
  "title": "Field storage", "description": "What every bundle using this field shares.",
  "allOf": [
    {"$ref": "#/$defs/field.storage"},
    {"properties": {"field_type": {"default": "string_long", "readOnly": true, "const": "string_long",
                                   "x-surface": {"locked": true, "dependsOn": []}}}}
  ],
  "x-surface": {"locked": false, "dependsOn": []}
}
```

In the schema for these answers a site also narrows where the child's
own values refined it: a shelf whose unit is `cm` refers to the shelf's
def, which states the ceiling for each unit, and narrows its height to
`maximum: 200`, `x-surface.refined: true`.

### Collections

A collection is the parent's key, so its property says what the parent
says about it: its title and description, `type: array`, `minItems` and
`maxItems` from its Count, `readOnly` when locked, and under
`x-surface` the usual instance facts (`locked`, `dependsOn`, `refined`,
`enumerated` or `dynamic` when the parent's refiner of it watches a
sibling) and `list: true`. Its `items` refer to the child's def, the
same def an attached child of that class refers to; both documents
describe the items from the child's locked keys alone, so the schema
says nothing one item's answers decide, and the reference is bare
unless a lock narrows it. The schema stays value-independent: the
items themselves are in `values`, one map per item, and an item's stale
key is listed in `stale` under its delta. A parent's refiner of the
list is enumerated like any key's: the cellar's full schema says, per
size, how many racks fit.

```json
"racks": {
  "title": "Spice racks", "description": "One rack to four.",
  "type": ["array", "null"],
  "items": {"$ref": "#/$defs/surface_test.larder.rack"},
  "minItems": 1, "maxItems": 4,
  "x-surface": {"widget": "list", "locked": false, "dependsOn": ["size"], "list": true, "enumerated": true}
},
...
"allOf": [
  {"if": {"properties": {"size": {"const": "small"}}, "required": ["size"]},
   "then": {"properties": {"racks": {"maxItems": 2}}}}
]
```

A list locked by its access is `readOnly`, and its items narrow the def
by composition, every key inside them `readOnly` and none holding a
`const`: each item holds its own value, which is in `values`.

### A slot is a conditional

A slot's own property fixes no shape. Each variant is a `then` on the
parent, under an `if` naming the deciding key's value as a `const`, so a
validator applies exactly the chosen variant's rules and a renderer reads
the shape the current value selects. The `then` refers to the variant's
def ([above](#reusable-subschemas)), beside the slot's label. There is no
OpenAPI `discriminator` beside it: OpenAPI's names a property inside the
object whose alternatives it picks, and a slot's deciding key is a
sibling of it ([decision](decisions.md#no-openapi-discriminator-on-a-slot)). Example
3's ticket, abbreviated, as `/surface-api` serves it (with the widget
hints):

```json
"ticket": {
  "title": "Ticket", "type": "object",
  "x-surface": {"widget": "slot", "by": "pricing", "variants": ["free", "paid"], "chosen": "free", "dependsOn": ["pricing"], ...}
},
...
"allOf": [
  {"if": {"properties": {"pricing": {"const": "free"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.free",
     "x-surface": {"widget": "fieldset", "variant": "free", ...}}}}},
  {"if": {"properties": {"pricing": {"const": "paid"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.paid",
     "x-surface": {"widget": "fieldset", "variant": "paid", ...}}}}}
],
"$defs": {
  "registration.step3.ticket.free": {"type": "object",
    "properties": {"note": {"title": "Note", "type": ["string", "null"], ...}},
    "additionalProperties": false},
  "registration.step3.ticket.paid": {"type": "object",
    "properties": {
      "price": {"title": "Price", "type": "number", "minimum": 0.01, ...},
      "currency": {"title": "Currency", "type": "string", "default": "EUR",
                   "oneOf": [{"const": "EUR", "title": "EUR"}, {"const": "GBP", "title": "GBP"}, {"const": "USD", "title": "USD"}], ...}},
    "required": ["price", "currency"], "additionalProperties": false},
  ...
}
```

### Exact where it can be

A static refiner is a pure function of the values it watches, and most
watched keys offer a short list of values. So where a key's static
refiners have nothing to refine against yet (a sibling they watch holds
nothing), the emitter runs them ahead of time for every value they could be
handed and writes each answer as a conditional on the parent, beside
the slots': an `if` naming each watched sibling's value as a `const`,
and a `then` saying what the key's schema says differently from the
unrefined one. The property carries `x-surface.enumerated: true`. A
generic JSON Schema validator then refuses what the server refuses, with
no `/refine` round trip.

- **Every watched sibling must offer a list.** Its own definition, as
  refined for the combination so far, resolves to an option set (a
  `Choice`, a `LabeledChoice`, a list an options resolver reads). A
  sibling that holds a value is held at it. A sibling that is free text,
  a number, a boolean, a list of values, a child, or a key an alter
  mounted (named by its dotted path) cannot be listed.
- **The combinations are capped**, at 64 per key
  (`ContractEmitter::ENUMERATION_CAP`): the product of the watched
  siblings' lists, read in declaration order, each under the values
  before it, so the demo block's `field` is enumerated per entity type
  and, under each, per bundle. Above the cap, or with a sibling that
  cannot be listed, the key is not enumerated and is marked
  `x-surface.dynamic: true` instead: its schema is the unrefined one and
  `dependsOn` says what to refine on, as before
  ([decision](decisions.md#enumeration-is-capped)).
- **Each conditional is what `/refine` answers, less the runtime.** It
  is computed by refining the whole data surface against the values with the
  combination over them, the same call `/refine` makes with the runtime
  refiners left out, so chains compose (the room per venue, the capacity
  per room) and an alter's static refiners in the chain are included. A
  runtime refiner is never called for a conditional; only the siblings
  the static refiners watch are enumerated over, and the key carries
  `runtime` besides.
- **Only what changed.** A `then` names the key and only the keywords
  that differ from the unrefined schema (the narrowed `oneOf`, a lower
  `maximum`, the description naming the reason); a map's properties are
  compared one by one. A combination that changes nothing writes no
  conditional.
- **Inside a child too.** Each frame is described the same way, so
  a slot's variant enumerates its own keys inside the variant's schema,
  under the slot's conditional. A child's frame starts from the
  child's defaults, as refinement does, so a child key watching a
  sibling with a default is shown refined against that default, not
  enumerated.
- **With values, nothing changes.** A key whose watched siblings hold
  values is refined, as the GET and `/refine` serve it, and carries
  neither keyword. That is the schema as it stands;
  [the full schema](#two-documents) is handed no values, so there every
  listable key is enumerated, always.
- **The lists are dependencies.** Every option list and every refined
  data surface a conditional was read from is merged into the contract's
  cacheability (the demo block's static contract depends on
  `entity_bundles`).

In the shipped resources and structures, described for no values:
example 2's and example 3's room (per venue, 3 conditionals) and
capacity (per room, 6); the demo block's `bundle` (per entity type) and
`field` (per entity type and bundle); the demo formatter's `variant`
(per casing); the demo extras' `limit` (per presentation; only the grid
writes one). Dynamic: example 4's capacity, which also watches the
free-text licence, and its stewards, inside the mount, which watch the
capacity, a number. Runtime: example 4's room, which its alter narrows
by the rooms a site has closed, read from State; it stays enumerated per
venue for example 3's own refiner. The field instance's `bundle` would
be enumerated per entity type, but every operation of it knows the
entity type, so it is always served refined.

Example 2, nothing chosen, abbreviated:

```json
"room": {"title": "Room", "type": "string", "oneOf": [...every room...],
         "x-surface": {"dependsOn": ["venue"], "refined": false, "enumerated": true, ...}},
"capacity": {"title": "Capacity", "type": ["integer", "null"], "minimum": 1, "maximum": 1000,
             "x-surface": {"dependsOn": ["room"], "refined": false, "enumerated": true, ...}},
...
"allOf": [
  {"if": {"properties": {"venue": {"const": "riverside"}}, "required": ["venue"]},
   "then": {"properties": {"room": {"oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}]}}}},
  ...
  {"if": {"properties": {"room": {"const": "riverside_main"}}, "required": ["room"]},
   "then": {"properties": {"capacity": {"description": "Up to 400 for the Main hall.", "maximum": 400}}}},
  ...
]
```

### Example 2, abbreviated

The venue narrows the room and the room the capacity, for the stored
Riverside Hall and its main hall, as `/surface-api` serves it:

```json
{
  "surface": "registration.step2",
  "situation": "configure",
  "label": "Configure registration",
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "title": "Configure registration",
    "type": "object",
    "properties": {
      "title": {"title": "Event title", "type": "string", "x-surface": {"widget": "text", ...}},
      "open": {"title": "Registration open", "type": ["boolean", "null"], "default": true, ...},
      "venue": {"title": "Venue", "type": "string",
                "oneOf": [{"const": "riverside", "title": "Riverside Hall"}, {"const": "library", "title": "Old Library"}, {"const": "harbour", "title": "Harbour Centre"}],
                "x-surface": {"widget": "select", "dependsOn": [], "emptyOption": {"show": false, "label": "- Select -"}, ...}},
      "room": {"title": "Room", "type": "string",
               "oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}],
               "x-surface": {"widget": "select", "dependsOn": ["venue"], "refined": true, "stale": false, ...}},
      "capacity": {"title": "Capacity", "description": "Up to 400 for the Main hall.", "type": ["integer", "null"],
                   "minimum": 1, "maximum": 400, "default": 50,
                   "x-surface": {"widget": "number", "dependsOn": ["room"], "refined": true, ...}}
    },
    "additionalProperties": false,
    "required": ["title", "venue", "room"]
  },
  "values": {"title": "Spring meetup", "open": true, "venue": "riverside", "room": "riverside_main", "capacity": 50},
  "stale": []
}
```

## The widget mapping

What the emitter's `widget`, when asked for, mirrors is
`DataSurfaceWidgetManager`'s choice, in its weight order
([Widgets](widgets.md)).

| Definition | Form API element | `x-surface.widget` | React component |
| --- | --- | --- | --- |
| any key whose constraints resolve to a non-empty list | `select`, empty option by the rule | `select` | `SelectField` |
| a list of such values | `select` `#multiple` | `select`, `multiple: true` | `MultipleSelectField` |
| — (never emitted) | — | `radios` | `RadiosField` |
| a map with properties, an attached part | `details` | `fieldset` | `FieldsetField` |
| `third_party_settings`, which only groups each module's map | `container`, no title | `fieldset`, `group: true` | `FieldsetField`, as a plain `div` |
| a module's map its alter placed | its `details`, drawn after the named key | `fieldset`, `after: "<key>"` | `FieldsetField`, drawn after that sibling of the group |
| a slot | the chosen variant's `details`; nothing while unresolved | `slot` | `SlotField` |
| `string` with the `multiline` setting | `textarea`, no maxlength, no placeholder | `textarea` | `TextareaField` |
| `email` | `email` | `email` | `TextField` (type email) |
| `string`, `uri` | `textfield`, `url` | `text` | `TextField` (type text, url; password for a secret) |
| `integer`, `float` | `number`, `#min`/`#max` from Range, `#step` 1 or any | `number` | `NumberField` |
| `boolean` | `checkbox`, never `#required` | `checkbox` | `CheckboxField` |
| a list with no allowed values | no stock widget | `list` | `ListField` (add and remove rows) |
| a collection | a `details` of item `details`, "Add another" and "Remove" over AJAX | `list`, `list: true` | `ListField`, each item the child's `FieldsetField`, at least `minItems`, at most `maxItems` |
| a locked key | `#disabled`, "Fixed for this operation." | `locked: true` | disabled, the same note |
| a locked child | its fieldset `#disabled`, every element inside with it | `locked: true` on the fieldset and on every key inside | a fieldset of disabled keys |

## The endpoints

All six routes take the operation's parameters from the query string
(and a POST body's `parameters`), and are gated by
`_data_surface_react_access`: the operation's permission, then the
access class the resource or structure names, through
`Resources::access()`, with no opinion read as a refusal. A resource,
operation or parameter that names nothing is refused. The POSTs require
`X-CSRF-Token` from `/session/token`
(`_csrf_request_header_token`). A POST body that is not a JSON object is
a 400: the access check reads the operation from the query string alone
when it cannot read the body, and the controller refuses the body.

**`GET /surface-api/{surface}/{situation}`**, `{surface}` the
resource's id and `{situation}` the operation's id, answers the document
for the values the operation form would open with, and their
`fingerprint`.

**`GET /surface-api/{surface}/{situation}/schema`** answers
[the full schema](#two-documents) for the operation, with the widget
hints: `surface`, `situation`, `label`, `schema` and, when declared,
`outputs`; no `values`, `stale` or `fingerprint`. It reads no value, so
it is the same whatever is stored or typed, and it is the one document
that may be cached ([below](#cacheability)). The same access as the GET
contract.

**`POST .../refine`**, body `{values, stale}`, answers the document
re-narrowed against the values, which is what the form's AJAX rebuild
computes, the same way:

1. The values are narrowed to the data surface's keys and overlaid on what
   the operation opens with.
2. A path in `stale` whose value comes back empty stands for the stored
   value again: the served form of the marker the operation form's
   container posts. The stored value never travels; the path does.
3. The form builder's discard rule (`discardedRefinementInput()`) drops
   an answer the new choice of its dependency orphans: it falls back to
   what is stored, and if that is not offered either, it is shown on the
   empty option, `stale`, with the stored value kept on the server.
   Changing the venue answers the library's room as `null`, `stale:
   ["room"]`, `discarded: ["room", "capacity"]`; putting the venue back,
   with the stale path sent, shows the reading room chosen again.

The document is emitted from the form's own overlay,
`refinementOverlay()`, settled to [the same fixed point](forms.md#the-in-form-half-discarding-orphaned-input)
the AJAX rebuild reaches. A key that falls back to a stored value the
edit itself orphaned — a dependency moved away from what is stored, and
the new answer no longer offers the stored value — is an **orphan**: it
is held unanswered, so nothing below it is refined against the value it
stands for, and it is listed in `stale` with `x-surface.stale: true` and
a `null` value, whatever its widget. `discarded` names the inputs
dropped on the way there; `stale` names what is shown standing for a
stored value, orphans and keys the site narrowed away alike. So moving
the venue off the library leaves the capacity at its declared
`maximum: 1000`, with no room's description under it, rather than
capped by a reading room no longer on the screen. A client sends the
`stale` paths back with the next refine, validate or submit; empty there
stands for the stored value again, so a refine answers the same orphan,
and a save judges the stored room under the venue the same submission
moved, and refuses it, as the form's Save does.

**`POST .../validate`**, body `{values, stale}`, is the pipeline's dry
run: `submit(dry_run: TRUE)` with the access answer. Every value was
sent on purpose, so nothing is discarded, the full-submit rule: a stale
path sent empty is read as the stored value, and the run judges it, so a
room left stale after its venue moved is refused. It answers:

```json
{
  "valid": false,
  "violations": [{"path": "capacity", "message": "This value should be between 1 and 150."}],
  "stale": [],
  "values": {...},
  "prepared": null
}
```

`violations` are refusals by full dotted path (`ticket.price`,
`contact.email`, `@access`), messages rendered to plain text at this
boundary; `stale` the non-blocking stale references; `values` what
`accept()` made of the input; `prepared` what storage rehearsed
writing, when the run reached prepare. Nothing is written.

**`POST .../submit`**, body `{values, stale, parameters, fingerprint?}`,
is the same run, not dry: validate's body, validate's stale rule, the
same access answer handed to `submit()`, and a write when nothing is
refused. A refusal is data, not an error: the answer is 200 with
`committed: false`, and nothing is written
([decision](decisions.md#a-refused-submit-is-an-answer)). 403 is for a
request access refuses, or that carries no token; 400 for a malformed
body (not an object; `values` or `parameters` not an object; `stale`
not a list of strings; `fingerprint` neither a string nor `null`) or a
resource or structure with no storage. Every key is always present:

| Key | Holds |
| --- | --- |
| `committed` | Whether the values were written. |
| `valid` | Whether nothing was refused; on this endpoint the same answer as `committed`. |
| `violations` | Each refusal, `{path, message}`, as validate's. |
| `stale` | Each stale reference the write kept, `{path, message}`: the operation form's warning, said out loud. Present on a write as on a refusal. |
| `outputs` | The declared outputs as storage reads them back after the write (`StorageAdapter::outputs()`), the tool's same answer; `{}` when none are declared, as the examples, the content type and the field do. |
| `contract` | On a write, the whole document rebuilt from what is stored now, `fingerprint` included, so a client re-renders without a second request; `null` on a refusal. |
| `created` | On a write by an operation that creates, where the created thing now lives: `{surface, situation, parameters}`: the resource's id, the id of an operation of it that does not create ([below](#where-a-created-thing-lives)), and that operation's parameters; otherwise `null`. |

A refused example 2, the library's garden room sent with the harbour:

```json
{
  "committed": false, "valid": false,
  "violations": [{"path": "room", "message": "The value you selected is not a valid choice."}],
  "stale": [], "outputs": {}, "contract": null, "created": null
}
```

The harbour's deck, written:

```json
{
  "committed": true, "valid": true, "violations": [], "stale": [], "outputs": {},
  "contract": {
    "surface": "registration.step2", "situation": "configure", "label": "Configure registration",
    "schema": {...},
    "values": {"title": "Autumn meetup", "open": true, "venue": "harbour", "room": "harbour_deck", "capacity": 90},
    "stale": [],
    "fingerprint": "Bczjfcce6B4UTbwYT4FM8ZU3kl8Ue4_ZUuFfgBv72DY"
  },
  "created": null
}
```

A content type added at `node.type/add` as `served_demo` answers the
same, with the add operation's empty contract, and:

```json
"created": {"surface": "node.type", "situation": "edit", "parameters": {"type": "served_demo"}}
```

The operation form's cosmetic message and redirect are its route's, and
the API has no route default to read them from; their JSON equivalents
are `committed` and `created`, and the client says the rest.

### Where a created thing lives

`ServedOperations::created()` answers only for an operation whose
context `creates`. It takes the resource's operations in declaration
order and picks the first that does not create and whose every required
parameter is supplied, by name, from the outputs storage read back,
then from the identity keys the write accepted, then from the identity
the creating operation already knew. The answer is checked, not
assumed: the operation is built from those parameters (an entity
parameter loads what its id names), its context must not create, and
every identity key it knows must equal what was written. The content
type's `edit(NodeTypeInterface $type)` is answered by the `type` the
write accepted. A field added at `field.instance:add` gets `null`: its
`edit(FieldConfigInterface $field)` takes the field's own id
(`node.article.field_x`), which neither its outputs (it declares none)
nor its identity keys carry by that name; declaring a `field` output
would answer it. Example 2's `configure` creates nothing, so `null`.

### The fingerprint rule

Optional, and off unless a client sends one. The GET contract carries
`fingerprint`, an HMAC (the site's hash salt) of the resource's id and
the stored values `load()` returned, narrowed to the data surface's keys
(`ServedOperations::fingerprint()`). A submit body may send it back.
When it is sent and no longer matches what storage holds when the
submit arrives, the submit is refused before the pipeline runs:
`committed: false` and one violation at path `''`, "The stored values
changed since this form was loaded. Reload it to see them, then make
your changes again.", with nothing written. When it is not sent (absent
or `null`), the last write wins, as on the operation form
([decision](decisions.md#a-fingerprint-is-opt-in)). A refine never
carries one: it re-reads storage, so a client that took its fingerprint
would always match. A write's answer carries the new one, for the next
submit. It is a check, not a lock: a write between the comparison and
the commit, a few milliseconds, still wins.

### Cacheability

The contract carries what the refined data surface and every option list in
it depend on, those an [enumerated](#exact-where-it-can-be) conditional
was read from included, the access answer's own, and `url.query_args`, as HTTP
cache metadata on a `CacheableJsonResponse`. It is never stored: the
values in it come from storage, and storage says nothing about how
long what it loaded holds ([decision](decisions.md#a-served-contract-is-never-stored)).
The full schema carries the same metadata, and
`languages:language_interface` for its labels, but is not max-age 0: no
stored value is in it, so what it says it depends on is all it depends
on.

## A drawing client that narrows locally

The schema for these answers is still what a drawing client loads first:
it carries the values, the stale paths and the fingerprint, and it is
refined by the runtime refiners for those values. Beside it, once, the
client may fetch the full schema, and from then on answer most changes
itself. The rule, as `data_surface_react` applies it:

- **Narrow from the full schema.** On a change of a key, collect every
  key that depends on it, transitively, through `dependsOn` in the frame
  they share (the top, or a child's, by path). When none of them is
  marked `dynamic` or `runtime`, nor holds one inside it, read each
  key's schema for the new values from the full schema: as declared,
  with the `then` of every conditional on its frame whose `if` the values
  meet once every key it watches holds a value, and with its
  conditionals, held to what those keys still offer, while one holds
  nothing. A slot is its chosen variant's def; a part is its def,
  described for its values.
- **Settle as `/refine` does.** The answer must be the server's, so the
  client mirrors the [discard rule](#the-endpoints): an answer the new
  choices orphan is dropped and falls back to what is stored; a stored
  value its moved dependency no longer offers is held unanswered, shown
  on the empty option, and listed in `stale`; and the reading of a select
  (the [empty option rule](decisions.md#the-empty-option-rule), a value
  no longer offered shown as nothing) and of a key holding nothing (its
  default) is the emitter's. A number above a lowered maximum is kept,
  as the server keeps it, and the pipeline refuses it on submit.
- **Ask when a dependent is `dynamic` or `runtime`.** Its rule is not in
  the full schema, so the client posts to `/refine` as before. It also
  asks for anything it cannot settle: a stale value it was handed as
  `null`, whose stored value only the server knows; a reference left
  unresolved; a conditional naming a key outside its frame.
- **Validate and submit always ask.** The pipeline has the last word,
  and the full schema is never stricter than it, but not always as
  strict.

Example 2 narrows every change itself. Example 3 does too; with example
4's module on, a change of the venue asks (the room is `runtime`, for
the closed rooms), and so does the licence and the room (the capacity is
`dynamic`, watching free text), while the pricing is still narrowed
here: the ticket is a slot, stated whole.

## What a write needs

What this list asked for before Submit was wired, and where each is now:

- `POST .../submit` running `submit()` without `dry_run`, the same body
  and the same stale rule as validate. **Done**; the cosmetic message
  and redirect have JSON equivalents in `committed` and `created`, and
  the app says "Saved" itself.
- Its stale references said out loud, as the form's warning is.
  **Done**: `stale` on every answer.
- An operation that creates (`add`) answering where the created thing now
  lives, so the app can move to its `edit` operation's page. **Done**
  for any resource whose non-creating operation's parameters the outputs
  or identity answer (the content type); not for the field instance,
  [above](#where-a-created-thing-lives).
- A decision on concurrent edits. **Done**: the opt-in
  [fingerprint](#the-fingerprint-rule); without it the last write wins,
  as on the form.
