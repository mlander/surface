# The served contract

This page covers the HTTP door: the JSON document that describes an
operation, the endpoints that narrow and check values before a write,
and the React form drawn from that document. Use it when a client
without PHP, such as a JavaScript app, reads or writes a resource.
[Doors](introduction/12-doors.md) introduces HTTP and React as doors,
and [Sealed](introduction/16-sealed.md) why the document matches the
form.

A resource built in one of its operations, served as a JSON document a
client with no PHP can render a form from, re-narrow as answers change,
rehearse a write against before anything is written, and then write
through. The emitter and the API, where the path is the resource and
the method the operation, are the base module's; a React app that
renders from it is an example client, the experimental submodule
`surface_examples_react`.

This is the roadmap's "served contract": one operation at a time, read,
rehearsed and written.

## Where the emitter lives

`Drupal\surface\Contract\ContractEmitter`, the service
`surface.contract_emitter`, is the module's canonical contract: it
reads any sealed data surface and nothing else, and `emit()` answers a
`ServedContract` (the document, and its cacheability beside it). The
contract names no widget. `x-surface.widget` (and `multiple`, its
qualifier on a list of allowed values) is presentation, the Form API
mapping said out loud for a renderer, and so is `x-surface.arrangement`,
how a key is laid out where it is drawn; the roadmap rejects widget
vocabulary in the contract, so they are written only when a caller asks:
`emit(..., widgets: TRUE)`. The HTTP door is that caller; its
endpoints serve the contract with the hints, for a client such as the
React example's app to draw from.
Everything else under `x-surface` is the data surface's own reading of
a key and is always there.

It is this module's own emitter, not the Tool API's: the Tool API's
context definitions have no place for labeled values, a conditional on
a sibling, or anything the form knows about a key, so the tool bridge's
JSON Schema cannot say them. This one can.

## Where to see it

- **Under an operation form.** The contract panel,
  which the examples show under each form, shows it collapsed as "The
  schema for these answers", emitted for the operation and the form's
  values as they stand, so it rebuilds with the form. Beside it, "The
  full schema, for any answers" is [the second document](#two-documents),
  which does not. Below them, "What the Tool API can advertise" shows
  the derived tool's schema for comparison. The examples add a second
  panel under that one, "Without a resource: what config schema can
  say": the same settings described from config schema alone, set
  beside the full schema key by key
  (`modules/surface_examples/README.md`).
- **Over HTTP.** `GET /surface-api/{resource}`, or
  `/surface-api/{resource}/{id}` for one thing: the whole document,
  widget hints included. The full schema is
  `GET .../schema` beside it ([the API](#the-api)).
- **Not `drush tool:info`.** That prints the Tool API's rendering of the
  derived tool, which cannot say keys a sibling chooses: variants a class
  declares are flattened to one map, the variant chosen, or every
  variant's keys with none required before anything chooses; of variants
  the site lists it states only the keys of the one the stored values
  choose, whatever the site list limit says
  ([resources](resources.md), "Emission";
  [the tool door](#asking-again-at-the-tool-door)). The `allOf` of `if`/`then`
  below is only in this contract, for variants and for
  [an enumerated key](#exact-where-it-can-be) alike: a Tool API context
  definition has no conditional, so the tool's schema stays as loose as
  the unrefined data surface.

## Contract 4

A contract version is the version of the wire format both documents are
written in, distinct from a resource's `version`: it counts the changes
to how every document is spelled, and a client refuses one it does not
know ([Evolving a resource](evolution.md#the-contracts-own-version)).
Both documents state it first under the top `x-surface`, `"contract":
4`, and every answer of the API in its `Contract-Version` header.
Contract 1 is what the module served until 2026-10-20, contract 2 until
2026-10-22, contract 3 until 2026-10-24; this page describes contract
4, which cut the `x-surface` keywords that said nothing or said it twice
and kept contract 3's paths, so no path answers 410 for it
(why: [decision](decisions.md#contract-4-fewer-keywords-nothing-new-on-the-wire)):

| In contract 3 | In contract 4 | Why |
| --- | --- | --- |
| `declared: true` on a key a declared rule narrows | Nothing on the key; the frame's `rules` lists the rule's conditionals, and its `then` names the key | The two always traveled together |
| `deferred: true`, `fetch` and `entries` beside it | `deferred: {fetch, entries}`, `entries` only on a map of keys from the site; `by` stays beside it | One state, one keyword; `by` is the variants' |
| `locked` on every key, `false` on all but a few | No `locked`: `readOnly: true`, with `const` where the key holds a value; `computed` says why a computed key is read only | `readOnly` is the standard keyword, and `false` said nothing |
| `dependsOn: []`, `refined: false`, `stale: false`, `chosen: null`, `variants: {}` on every key that had them | Each written only where it says something; an `x-surface` with nothing to say is not written | Absent says the same as empty |
| `list: true` on a collection | `collection: true` | The concept's own word; `list` is also a widget hint |
| `access: "live"` on a key under a runtime access answer | `live: true`, as on a key a runtime refiner narrows | A client does the same for both: ask per request, never cache |

**What a client of contract 3 does.** A keyword it does not know is
ignored: keywords are added within a contract, and a client must skip
what it does not read. A keyword removed or moved is the reason for a
new contract, so a client of contract 3 refuses a contract 4 document
by its number rather than misreading a key with no `locked` as open.
The contract diff reports a contract 3 baseline once, "contract version
3 to 4", and re-pins it.

## Contract 3

Contract 3, from 2026-10-22 to 2026-10-24, changed the HTTP door and
kept both documents as contract 2 writes them, but for the number
(why: [decision](decisions.md#contract-3-the-operation-is-the-method)). Its
paths are contract 4's:

| In contract 2 | In contract 3 | Why |
| --- | --- | --- |
| Five endpoints per operation, `/surface-api/{resource}/{operation}` and its `/schema`, `/refine`, `/validate`, `/submit` | One path per resource, `/surface-api/{resource}[/{id}]`: `GET` reads, `PATCH` updates, `POST` to the collection creates, `:{operation}` names any other ([the API](#the-api)) | The operation is the method; one operation is one call |
| The operation's parameters in the query string and a POST body's `parameters` | The identity in the path, the rest in the query string | The thing is the URL |
| `/refine` | `POST .../schema` | The schema, given these values |
| `/validate` | The write with `?dryRun=true` | One call, a flag, as the tool's `dry_run` |
| A body `{values, stale, parameters, fingerprint}` | The values document itself; stale paths in `X-Surface-Stale` | A merge patch is the body |
| `fingerprint` in the GET document and the submit body | `ETag` on the read, `If-Match` on the write, 412 when it no longer matches | The HTTP way to say it |
| `GET .../schema` answered `{resource, operation, label, schema, outputs}` | The full schema itself, `application/schema+json` | A schema at its own URL |
| A refusal a 200, `ok: false` | 422 problem details, `errors`, `notes` and `stale` | The status says it before the body |
| 403 Drupal's HTML page | Problem details for every error | One error format |
| A create answered 200 | 201 with `Location` | Where the created thing is read |
| Dotted paths, `contact.email` | JSON Pointers, `/contact/email`, at every door | The spelling JSON Schema uses |
| `Contract-Version` only on answers that were not errors | On every answer | A client reads it first |

Every contract 2 path answers 410 with what replaces it
([gone](#gone)). The documents are compared by the contract diff as
before; a baseline of contract 2 is reported once, "contract version 2
to 3", and re-pinned.

## Contract 2

What contract 2 moved, from contract 1, all at once so a client moves
once (why: [decision](decisions.md#contract-2)):

| In contract 1 | In contract 2 | Why |
| --- | --- | --- |
| No version stated | `x-surface.contract: 2`, first under the top `x-surface`; `Contract-Version: 2` on every answer of `/surface-api` that is not an error | A client tells a document it does not know from one it does |
| `surface` and `situation`, in the document, a submit's `created`, the React page's settings and a baseline | `resource` and `operation` | The product's retired words off the wire (why: [decision](decisions.md#the-product-keeps-its-name)) |
| No `$id` | `$id`, the URL a route serves the document at, the operation's parameters as its query string | Local references resolve wherever the document is embedded |
| No `title` on a def but a shape's, its label | `title` on every def, its id | Stable, language-free names for a type generator |
| The widget hint `slot` | `variants` | The concept's own word (why: [decision](decisions.md#variants-not-slots)) |
| A key with variants `{"type": ["object", "null"]}`, its `x-surface.variants` a list of values | `anyOf` of a reference to each variant's def, and `{"type": "null"}` where it may be cleared, and no `type` beside it; `variants` a map from each value to its def | A type generator writes `Free \| Paid \| null` |
| `x-surface` below an applicator: `variant` and the widget on a variant's `then`, `declared: true` on a rule's conditional, a key's `x-surface` in a site's narrowing | None below an applicator: the owning object's `x-surface.rules` and `x-surface.within` | A tool merging `allOf` fell back to the unmerged schema (B21) |
| `maximum`, `properties` or `required` with no `type` in a narrowing, a `then` or an `if` | The declared type beside them; a narrowing's `required` only what it adds | ajv's strict mode refused them (B22) |
| `dynamic`, `runtime`, `access: "runtime"` | `refine`, `live`, `access: "live"` (one `live` since contract 4) | Say what a client does; two facts, two words |
| `checkedOnServer` a list of plugin ids | A list of `{constraint, message}` | An id tells a reader nothing |
| `readOnly` in `x-surface.operation` | No `readOnly`: `kind: "read"` says it | JSON Schema's `readOnly` at the top means locked whole |
| `format: "email"` | `format: "idn-email"` | Drupal admits a non-ASCII local part (B23) |
| A timestamp's `Range` bounds as strings | Numbers | Valid against the 2020-12 metaschema |

The contract diff compares only documents of one contract version: a
baseline of another is reported once and re-pinned, with no reason
asked ([Evolving a resource](evolution.md#the-contracts-own-version)).

## Two documents

The emitter writes two documents from one data surface, by one code path.

- **The schema as it stands**, `emit()`, is everything below: built for
  one set of values, with `values` and `stale` beside it. A key whose
  watched siblings hold values is refined for them, so with Riverside
  Hall stored the room offers Riverside's two rooms. A runtime refiner
  is run for it like any other, because this document is the present
  moment: with the East room closed, Riverside offers the main hall
  alone. It is what a form is drawn from, and what the read and
  `POST .../schema` serve. It describes those values and no others, at the
  moment it was emitted: do not validate an arbitrary submission against
  it.
- **The full schema**, `emitFull()`, reads no value, so it is the same
  whatever is stored or typed. Every key is the one declared
  (every room; a capacity of at least 1, with no upper bound but a room's), every dependency that can be
  listed is written as conditionals on the parent, always, under
  [the same rules and cap](#exact-where-it-can-be), and a key whose
  dependency cannot be listed keeps its declared schema with
  `x-surface.refine: true`; a list the site decides, past its limit, is
  open with `x-surface.deferred`, an object holding a `fetch` template
  ([below](#a-list-the-site-decides)). A runtime refiner is never run for it: the
  key keeps the width its static refiners give it and carries
  `x-surface.live: true`. Access in it is the account's: it is
  emitted from the resource built for the account alone
  (`ResourcesInterface::buildStatic()`), so a key a static access answer
  locked is `readOnly`, one it left out is not there, and one a runtime
  answer may still lock or leave out is there, as the static answers
  leave it, marked `x-surface.live`, as a runtime refiner's key is; the
  schema for these answers settles it
  (why: [decision](decisions.md#access-is-static-or-runtime-read-from-its-cacheability)).
  Its `x-surface` carries only what holds for
  any values (`contract`, `version`, `computed`, `dependsOn`,
  `enumerated`, `refine`, `deferred`, `live`, `rules`, `within`,
  the `by` and `variants` of a key with variants, `group`, `after`,
  `patternMessage`,
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
(why: [decision](decisions.md#two-documents-never-stricter-than-the-runtime)),
and the rule is about the **accepted document**: what `accept()` makes
of the input after coercion, not the raw input. That is the Tool API's
model: it coerces a tool's inputs by a documented table first and
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
  (why: [decision](decisions.md#format-is-an-annotation)). Drupal's checks
  are not the formats': a URI with a space is accepted by the pipeline
  and refused by a client that asserts `format`. An email address is
  `idn-email` (RFC 6531), which admits a non-ASCII local part as
  Drupal's validator does; contract 1 said `email`, which refused one.
- **`default` is an annotation too.** A validator that fills a missing
  key from its `default` before reading `required` (opis's
  `allowDefaults`) is not reading the schema as a standard one does;
  the suite does not.
- **Where the schema cannot say a rule it stays wide.** Where a rule
  has no JSON Schema keyword, a dependency is free text or too large to
  list, or a refiner is declared runtime, the schema stays at the
  declared width and says so (`checkedOnServer`, `refine`, `live`);
  the pipeline's refinement is the guard with the last word. A Regex is
  carried as `pattern` only inside the subset PHP and a JavaScript
  engine read alike: literals, ranges in a class that is not negated,
  `\d` `\w` `\s` `\b` only without the `u` modifier, the quantifiers,
  alternation and groups, `^`, and `$` only with the `D` modifier, which
  every pattern ending in `$` needs
  (why: [decision](decisions.md#a-pattern-both-engines-read-alike)). Anything
  else carries no `pattern` and is named under `checkedOnServer`: the
  fourth newcomer's email shape, `/^[^@\s]+@example\.org$/D`, is checked
  on the server only, for its negated class, where
  `/^[a-z0-9._-]+@example\.org$/D` is carried, and `/^\d{4}$/uD` is
  checked on the server only, for `\d` under `u`.
- **A stale value is storage's.** The full schema reads no storage, so a
  stored value a static list no longer offers, which the pipeline keeps
  stale, is outside it.
- **A key that does not apply is not in the document.** The pipeline
  reads it as left out, so the accepted document has no such key, and
  both documents refuse it present (`false`, or no property under
  `additionalProperties: false`). A `null` or `''` sent for it is a
  notation of nothing the pipeline reads, as `"50"` is of a number; a
  value is refused at both ends.

`AdvertisedSchemaAcceptanceTest` holds this for every example and the
test fixtures: each accepted document is accepted by both documents and
each refused one refused, both ways where the schema is exact and one
way where it is `refine` or `live`, by a standard validator
(`format` not asserted, no defaults filled).

### Markers

A key a refiner narrows carries at most one of `enumerated` and
`refine`, for its static refiners, and `live` besides when a runtime
refiner narrows it too ([refinement](refinement.md#static-and-runtime-refiners)).
In contract 1, `refine` was `dynamic` and `live` was `runtime`. A list
the site decides past its limit carries a fourth, `deferred`, which no
refiner writes ([below](#a-list-the-site-decides)). A key under a
runtime access answer carries `live` too: in contract 3 it was
`access: "live"`.

| Marker | Means | In the full schema | In the schema for these answers |
| --- | --- | --- | --- |
| `enumerated` | Captured exactly. The key's static refiners watch only keys that offer a list, within the cap, so what they answer for every combination is written as conditionals on the parent. | On every such key, always: no value is read. | Only while a sibling its static refiners watch holds nothing; once all do, the key is refined for them and carries neither this nor `refine`. |
| `refine` | Static by declaration, but not captured here: a watched key cannot be listed (free text, a number, a key an alter mounted) or the combinations exceed `ENUMERATION_CAP`. The key keeps its declared schema; a client can ask, with values, at `POST .../schema`, and an agent at the narrowing tool ([asking again at the tool door](#asking-again-at-the-tool-door)). *Changed 2026-10-24:* a list the site decides past its limit is `deferred`, not `refine`. | On every such key. | As for `enumerated`. |
| `live` | Declared impossible to capture: a runtime refiner narrows the key with a rule no schema can state (a lookup, uniqueness, the account, the time), or an access answer that waits for the subject was set aside, so the key may be locked or left out once the subject is known. Never run ahead of time and never in a conditional. | On the key, which keeps the width its static refiners and static access give it: wider than the pipeline, by design. | On a key a runtime refiner narrows, always; the key is refined by it for these values, so this document is valid for those values, at that moment, and no others. A runtime access answer is applied here and marks nothing. |

A key with a static and a runtime refiner, example 4's room, is
`enumerated` (per venue) and `live` (closed rooms). A key with only a
runtime refiner carries `live` alone.

#### What a key marked says, and what to do

An invoker reads the marker to know how far the schema it holds can be
trusted for a key, and what to do about the rest
(why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).

| Marker | What it says | What to do |
| --- | --- | --- |
| `enumerated` | Stated in full: every answer of the key's static refiners is a conditional on its frame. | Narrow locally: evaluate the conditionals for the values. |
| `deferred` | Static, and left out for its size: a list the site decides with more entries than `site_list_limit`, open here, its `fetch` naming the full schema with one entry stated. | Fetch per choice, `GET .../schema?choose=<pointer>=<value>`, and cache the answer until the site's list changes (its `ETag`, its cache tags); or ask the narrowing with values; or send the values and be refused by name. |
| `refine` | Static, but not writable: a coded refiner over a value with no list (a number, free text), or more combinations than `ENUMERATION_CAP`. | Ask the narrowing with values, `POST .../schema` or the `:schema` tool, when a key it depends on changes. |
| `live` | Runtime: a rule no schema states (a lookup, uniqueness, the account, the time), or an access answer only the subject settles. | Ask the narrowing per request; never cache its answer. |
| `checkedOnServer` | A constraint only the server checks, named with what it checks. | Send, or rehearse with `?dryRun=true`, and read the refusal. |

Example 2's full schema, abbreviated:

```json
"room": {"title": "Room", "type": "string", "oneOf": [...all six rooms...],
         "x-surface": {"dependsOn": ["venue"], "enumerated": true}},
"capacity": {"title": "Capacity", "type": ["integer", "null"], "minimum": 1, "default": 50,
             "x-surface": {"dependsOn": ["room"], "enumerated": true}},
...
"allOf": [
  {"if": {"properties": {"venue": {"const": "riverside"}}, "required": ["venue"]},
   "then": {"properties": {"room": {"oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}]}}}},
  ...two more venues...
  {"if": {"properties": {"room": {"const": "riverside_main"}}, "required": ["room"]},
   "then": {"properties": {"capacity": {"type": ["integer", "null"], "description": "Up to 400 for the Main hall.", "maximum": 400}}}},
  ...five more rooms...
]
```

## The document

| Key | Holds |
| --- | --- |
| `resource` | The resource's id, the one on its `#[Resource]` (or a structure's, on its `#[Structure]`). |
| `operation` | The operation's id, or `null` for a resource or structure asked for in no operation. |
| `label` | The operation's label. |
| `schema` | JSON Schema 2020-12 for the inputs, `x-surface` on every property, and at the top how the operation behaves, `x-surface.operation` ([below](#the-schema)). |
| `values` | The values the schema describes now: what storage holds over the declared defaults (which carry an operation's starting values and known identity), with any in-progress answers over that. Every key is present. A secret is `null` (masked: read as the key left out); a stale value is `null`, its path in `stale`. |
| `stale` | The JSON Pointers shown on the empty option in place of a stored value (dotted paths until contract 3). |
| `outputs` | The output schema, when the resource or structure declares outputs. |
| `notes` | Served by the API: one entry per stale path, `{path, code, message}`, code `stale`, as a write's result says a stale value it keeps. |
| `discarded` | On a narrowing only (`POST .../schema`): the JSON Pointer of each answer the new choices orphaned. |

The fingerprint of the stored values is the read's `ETag`
([`If-Match`](#if-match)); contract 2 carried it as `fingerprint`.

A write's `created` uses the same two keys. Contract 1 spelled them `surface` and
`situation`, the product's retired words; the `x-surface` keyword keeps
the product's name ([Contract 2](#contract-2), [Contract 3](#contract-3)).

Every keyword below is written only where it says something: a list
that names nothing, a flag that is `false`, a value that is `null` is
left out, and so is an `x-surface` with nothing left to say. A client
reads an absent keyword as its empty value, and skips a keyword it does
not know ([Contract 4](#contract-4)).

### The schema

At the top, after `$schema`, the `$id` of a document a route serves,
the `title` that is the operation's label and the `description` that is
its description (what a call does to what, when the operation says
one), the top's `x-surface` says first which version of the contract
the document is written in, `contract` ([above](#contract-3)), then how
the operation behaves, as `#[Operation]` states it
(why: [decision](decisions.md#an-operation-states-how-it-behaves)), in both
documents alike:

```json
"$id": "https://example.com/surface-api/registration.step3",
"title": "Configure registration (step 3)",
"description": "Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact.",
"x-surface": {
  "contract": 3,
  "operation": {
    "id": "configure",
    "label": "Configure registration (step 3)",
    "description": "Sets the event registration: its title, whether it is open, the venue and room, the capacity, the pricing with its ticket, and the contact.",
    "kind": "update",
    "creates": false,
    "destructive": false,
    "idempotent": true
  },
  "version": 1
}
```

`$id` is the absolute URL the document is served at: the read's path,
`/surface-api/{resource}[/{id}]` (or its custom method's), for the
envelope, a narrowing and a write's `contract`, and that path's
`/schema` for the full schema, the operation's parameters that are not
in the path as its query string. The
references inside it are all local, so with it they resolve against
the document wherever it is embedded (an OpenAPI bundler, one validator
holding two documents). A document no route serves (the contract
panel's, a baseline, `emit()` called directly) has none.

`kind` names the call, derived and never declared: `read` for a read
only operation (`#[Operation(readOnly: TRUE)]`), `create` for one that
creates (`#[Operation(creates: TRUE)]`), and `update` for every other,
which changes what exists. There is no `readOnly` among the facts:
`kind: "read"` says it, and JSON Schema's `readOnly` at the same top
means the resource is locked whole: every key is held to what is
stored, and a submit is refused
(why: [decision](decisions.md#locked-at-the-top));
contract 1 also stated `readOnly` among them. A
generated tool asks for what its kind says at its door: a create
requires the keys the resource requires, and an update requires none,
since a key left out keeps what is stored
(why: [decision](decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
`creates` is the attribute's, stamped on the context; `description` is
there only when the operation says one; `idempotent` is the operation's
own, derived from `creates` unless declared (a create whose caller names
the thing, such as `node.type:create`, declares it), which a tool listing cannot
always say (the Tool API reads idempotence off the operation kind, and
every kind that writes is not idempotent). Then `version`, which
version of its stored values the resource or structure describes
([Evolving a resource](evolution.md#the-version)), and whatever else the
top frame says under `x-surface` (`doesNotApply`, `ports`, `rules`,
`within`). A document asked for in no operation has no `operation`:
`version` follows `contract`.

Each frame (the top level, an attached part, a variant) is an
object with `properties` in declaration order, `required`, and
`additionalProperties: false` (`unevaluatedProperties: false`, for a
frame that [mixes structures in](#a-mixed-in-structure-is-composed)); an
attached part and a variant are each written once, as a def
under `$defs`, and referred to ([reusable subschemas](#reusable-subschemas)).

| The declaration says | The schema says |
| --- | --- |
| label, description | `title`, `description`, as plain text: a placeholder's escaping decoded, a line break a new line, every other tag stripped; the form keeps the markup (why: [decision](decisions.md#a-machine-document-carries-plain-text)) |
| `string`, `email`, `uri`, `integer`, `float`, `boolean` | `type` (`format: idn-email` / `uri` for those types, `date` for a string drawn as a date, advisory); an optional key also allows `null`, which clears it (a secret does not, nor a key its storage always holds, one a fully validatable config schema does not mark `nullable: true`; why: [decision](decisions.md#a-key-its-storage-always-holds)) |
| `setRequired(TRUE)` | the key in `required`: present and not null, nothing about size. The contract's `values` always carry every key, so presence is never the question. |
| a declared default, examples | `default`, `examples` |
| `Range` | `minimum`, `maximum`, always numbers (core's timestamp field holds its bounds as strings) |
| `Length` | `minLength`, `maxLength` |
| `Count` | `minItems`, `maxItems` |
| `UniqueItems` | `uniqueItems: true`, after the bounds |
| `Regex` | `pattern` (`not: {pattern}` for `match: false`), carried only when its body stays inside the subset both engines read alike and its modifiers are `u` and `D`; otherwise left to the server (why: [decision](decisions.md#a-pattern-both-engines-read-alike)) |
| `Email` | `format: idn-email`, advisory |
| `NotBlank` | `minLength: 1` |
| `Sequentially`, `AtLeastOneOf` | `allOf`, `anyOf` of its parts, each stated as the constraint alone would be; when any part is not, the composite is left to the server (why: [decision](decisions.md#a-composite-is-its-parts)) |
| any list of allowed values | `oneOf: [{const, title, description?}]`, read from the options service, so the labels travel; never `enum`. An optional key that may be cleared adds an untitled `{const: null}` (not one its storage always holds), and so does a list of no values, which a required key's type then refuses too: nothing is allowed. |
| a map | a nested object |
| an attached part | a reference to its def under `$defs`, beside its label ([reusable subschemas](#reusable-subschemas)) |
| a key with variants | `anyOf` of a reference to each variant's def, and `{"type": "null"}` where it may be cleared, no `type` beside it; each variant stated exactly by a conditional on the parent ([below](#variants-are-conditionals)) |
| a key that refers to an entity, `entity:<type>[:<bundle>]` | its id's type, `x-surface.reference`, the candidates as `oneOf` up to `surface.settings:reference_list_limit`, and `SurfaceValidReference` under `checkedOnServer`; past the limit no `oneOf`, `x-surface.widget: autocomplete` ([Options](options.md#a-key-that-refers-to-an-entity)) |
| a key of any entity type, `entity` | a reference to its def, `{entity_type, id}`: the entity types as `oneOf`, the id a string marked `reference: {by: "entity_type"}`, `enumerated`, its candidates per entity type one conditional on the def |
| a deciding key that holds a value and whose variants offer one value, or a key its operation's context fixes to one (fixing makes it hold a value) | `const` of that value, no `oneOf`, the value's label under `x-surface.fixed`; a list the key declares itself, or a stored value no longer offered, is stated as any list (why: [decision](decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter), [a route that fixes a key](decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for)) |
| a structure mixed in, `mixIn()` | a reference to its def in the frame's `allOf`, beside the frame's own `properties` (each member in its place: `true`, what differs, or `false`), the frame closed with `unevaluatedProperties: false` ([below](#a-mixed-in-structure-is-composed)) |
| a list | `type: array`, `items`, never null: an empty item is dropped before anything reads the list (why: [decision](decisions.md#an-item-is-never-null)) |
| a collection | `type: array`, `items` a reference to the child's def, narrowed by composition only where every item differs from it (a key the child's operation locked, a locked list), `minItems` and `maxItems` from its Count as the parent's refiners left it, and `x-surface.collection: true` ([below](#collections)) |
| a locked key | `readOnly: true` and `const` of the value the operation knows |
| a computed key, `compute()` | `readOnly: true` and `x-surface.computed`, no `const` (what it holds follows its sources), never in `required`; in `values` what it holds ([Declaring a resource](declaring-a-resource.md#computed-keys)). One computed unless sent, a machine name, has no `readOnly` ([A title and its machine name](declaring-a-resource.md#a-title-and-its-machine-name)) |
| a child its access locked | `readOnly: true` on the part, and on every key inside it with `const` of what is stored (none in the full schema); an omitted child is not there at all ([Three answers](resources.md#three-answers)) |
| a key under a runtime access answer, in the full schema | there and writable, as the static answers leave it, with `x-surface.live`: it may be locked or left out once the subject is known; the schema for these answers says which, and marks nothing ([Static and runtime access](resources.md#static-and-runtime-access)) |
| a secret | `writeOnly: true`, its declared type, never in `required` (left out or `null`, the stored secret stands), no default, `null` in `values` (why: [decision](decisions.md#a-secret-is-optional-never-nullable)) |
| a key a refiner answers [does not apply](refinement.md#when-a-key-does-not-apply) for these values | no property, not in `required`, not in `values`; the frame's own `x-surface.doesNotApply` names it with what it depends on. In the full schema, `false` under each conditional that rules it out, and `required` under each where it applies and is required (why: [decision](decisions.md#a-key-that-does-not-apply)) |
| any other constraint | named in `x-surface.checkedOnServer` with what it checks: the schema is not the whole story, ask with `?dryRun=true`; not one whose resolver says its list is the whole of it (`exact: TRUE`), which is the `oneOf` ([Options](options.md#writing-a-resolver)) |

### `x-surface`

| Keyword | Holds |
| --- | --- |
| `contract` | At the top always, first: the version of the wire format the document is written in, `4` ([Contract 4](#contract-4)), which the `Contract-Version` header of every answer of the API says too. A client refuses a version it does not know ([Evolving a resource](evolution.md#the-contracts-own-version)). |
| `widget` | Only when the caller asks for widget hints (the HTTP door does). The Form API-equivalent hint, the widget's choice ([the widget mapping](#the-widget-mapping)): a module's widget that claims the key, by its plugin ID ([Widgets](widgets.md#a-module-serves-a-widget-to-every-key-of-a-kind)), or a stock word, `select`, `radios`, `checkboxes`, `checkbox`, `number`, `text`, `textarea`, `email`, `time`, `date`, `fieldset`, `variants` (a key with variants; `slot` in contract 1), `list` (a list of free values, and a collection), `fixed` (a deciding key whose variants offer one value, or a key its operation's context fixes to one, drawn as its label), `autocomplete` (a key that refers to an entity and lists no candidates, past the limit, and a bare reference's id, which is a select once its entity type's conditional lists them); `null` when no widget would claim the key. |
| `dependsOn` | Only where it names one: the sibling keys this key's refiners watch, in its own frame; a key an alter mounted, which only that alter may watch, by its dotted path in that frame (`third_party_settings.<module>.<key>`); a port the frame reads, by the port's name. A client refines when one of them changes. |
| `refined` | Only when `true`, in the schema for these answers: the key is narrowed right now, compared with what the resource or structure advertises in this operation. |
| `stale` | Only when `true`, in the schema for these answers: the stored value is no longer offered; the key is shown on its empty option, standing for it. |
| `emptyOption` | On a single select or radios: `{show, label}`: always for a select that may be cleared (`- None -`), for one that holds a value (required, or always held by its storage) only while no valid choice is selected (`- Select -`) (why: [decision](decisions.md#the-empty-option-rule), [decision](decisions.md#a-key-its-storage-always-holds)); a select `stale` on a stored value says it keeps it, `- Keep riverside_main (no longer offered) -`, and an orphan of the edit says `- Select -` or `- None -`, since a save refuses it. On radios: `show` is whether a `- None -` radio is drawn, only where the key may be cleared; one that holds a value shows nothing checked while no valid choice is. |
| `multiple` | With the widget hints, on a list of allowed values drawn as a select: a multiple select. A list drawn as checkboxes says `checkboxes` and no `multiple`. |
| `arrangement` | With the widget hints, on a key that declares one ([Arranging the keys](forms.md#arranging-the-keys)): how it is drawn, never what it accepts. Only what was declared: `group` (`{id, label, as: "fieldset" \| "details", collapsed, weight, description}`, `collapsed` only when `true`, `weight` and `description` only when declared: the keys of one object naming one `id` are drawn in one group, where the first of them is), `weight` (among the object's keys, or the group's; lighter first, declaration order between equals), `placeholder`, `prefix`, `suffix` and `help` (said after the description, and no part of what the key means), all plain text: `{"group": {"id": "pricing", "label": "Pricing", "as": "details", "collapsed": true}, "placeholder": "For example, 40", "prefix": "$"}`. Additive in contract 2: a client that does not read it draws the keys in order. |
| `by`, `variants`, `chosen` | On a key with variants: the deciding key; a map from each value that chooses a variant to its def's pointer, `{"free": "#/$defs/registration.step3.ticket.free", "paid": "#/$defs/registration.step3.ticket.paid"}` (a list of the values in contract 1), absent where no variant is declared; and the value chosen now, absent while none is. |
| `fixed` | On a deciding key whose variants offer one value, or a key its operation's context fixes to one, beside its `const`: the label that value is offered as, which a renderer draws in place of a select of one. Additive in contract 3. |
| `reference` | On a key that refers to an entity, in both documents, listed or not: what it refers to, `{"entityType": "node", "bundles": ["article"]}` (`bundles` only where the key names any), or for the id of a key of any entity type `{"by": "entity_type"}`, its entity type the sibling's value. The value is the id. A client finds an id at the resource's lookup ([below](#a-lookup)). Additive in contract 3 (why: [decision](decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up)). |
| `computed` | On a computed key, in both documents, beside `readOnly: true`: the keys it is computed from and when, `{"from": ["label"], "when": "create"}`, `create` for once, while storage holds nothing for it, `change` for again on every write that changes one of them. Nobody types it: a client shows it, narrows with `POST .../schema` when a key in `from` changes, which answers the value it would compute, and leaves it out of a write; a value sent equal to what it holds is noted, code `computed`, and any other refused. Additive in contract 3 (why: [decision](decisions.md#a-computed-key-is-a-pure-function-of-its-sources)). `unless_sent`, with no `readOnly`, for a key computed as `create` computes it only when a write leaves it out or sends it empty: a client shows the narrowing's value as a suggestion, sends it once the person typed over it, and a value sent is kept (why: [decision](decisions.md#a-machine-name-is-a-computed-identity)). |
| `group` | Only when `true`, on `third_party_settings`: the object only groups one object per contributing module, each that module's fieldset, so it has no `title` or `description` and a renderer draws nothing around them, as the form does. |
| `after` | On a module's object inside a `group`, when its alter placed it (`describe(..., after:)`): draw this fieldset after the named property of the object that contains its group. Only where it is drawn: its properties stay in the group, where its values are sent, and the schema's order and nesting do not move. |
| `checkedOnServer` | Constraints no keyword states, each `{constraint, message}`: the constraint's plugin id, and what it checks, the constraint's own `reason` where it was given one (the stock `SurfaceUnique`'s), else its own `message` (a Regex's), else its plugin's label, else the id: `[{"constraint": "SurfaceUnique", "message": "Refused when a venue named this already exists."}]`. A list of plugin ids in contract 1. A `JsonSchema` entry, what a structure read from a schema kept of it, carries the schema beside its message, `{"constraint": "JsonSchema", "message": "Checked on the server against the schema it was read from: contains.", "schema": {"contains": {"const": "news"}}}`; on an object (the top, a def) it is what that schema says of the object as a whole ([Reading a schema](reading-a-schema.md#what-is-kept)). |
| `patternMessage` | On a key with a `pattern`: the Regex's own message, which is how a person is told what the pattern allows. The pattern is for machines; a renderer explains the key by this, never by the pattern. |
| `enumerated` | Only when `true`: the key's static refiners have nothing to refine against yet, and what they answer for every value they could be handed is stated as conditionals on the parent ([below](#exact-where-it-can-be)). Captured exactly: the schema is exact for this key with no round trip, less what `live` adds. |
| `refine` | Only when `true` (`dynamic` in contract 1): the key's static refiners have nothing to refine against yet, and what they would answer cannot be listed (a watched key with no list of values, or too many combinations). Static by declaration, not captured here: the schema is the unrefined, looser one, wider than the pipeline and never narrower (why: [decision](decisions.md#two-documents-never-stricter-than-the-runtime)); refine when a `dependsOn` key changes. In [the full schema](#two-documents) every key that cannot be listed carries it. |
| `deferred` | On a key whose list the site decides and has more entries than the site list limit, in [the full schema](#two-documents) and in the schema for these answers while nothing is chosen: the key is an open object, variants with `by` beside it and no def per variant, or a map of keys from the site ([a list the site decides](#a-list-the-site-decides)). Static and stated, left out for its size. An object: `fetch`, an RFC 6570 template, the full schema's URL with `choose=<pointer>={value}` for the key and a `choose` for each variant on the way to it, `{value}` the deciding key's value or an entry's id, its query alone in a document no route serves, read against the full schema's URL; and, on a map of keys from the site only, `entries`, each key the map holds, `{"id": "bench_7", "label": "Bench 7"}`, in order, whose keys the schema for these answers states, and `fetch` one at a time. `refine` until 2026-10-24; `deferred: true` with `fetch` and `entries` beside it in contract 3 (why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)). |
| `collection` | Only when `true`, on a collection: an array whose every item is a child, its `items` a reference to the child's def. A renderer draws each item as that def's fieldset, adds and removes items within `minItems` and `maxItems`, and reads `dependsOn` inside each item under its delta (`shelves.1.unit`). `list` in contract 3. |
| `rules` | On an object owning an `allOf` (the top frame, a def), in [the full schema](#two-documents) and in a def: the indexes in that `allOf` of the conditionals that state a declared rule ([refinement](refinement.md#declaring-a-rule)), `"rules": [9]`, each stated as written, after the key's enumerated ones; the key a rule narrows is the one its `then` names, and carries no marker of its own (`declared: true` in contract 3). Exact: a client evaluates each `if` and tightens the key by its `then` (the lower maximum, the values both lists offer), whatever the keys it reads hold, and an enumerated conditional only once every key it watches holds a value, so it tells them apart by this list. The schema for these answers evaluates the rules for its values and writes none. Contract 1 wrote `x-surface: {"declared": true}` on the conditional itself. |
| `within` | On an object owning an applicator (a site, a frame, a def): what a schema below the applicator says under `x-surface`, as a map from its JSON pointer, relative to the object, to that `x-surface`. On the compliance mount, in the schema for these answers, whose narrowing refines the stewards: `{"/allOf/1/properties/stewards": {"dependsOn": ["capacity"], "refined": true}}`. Nothing below an applicator carries `x-surface` ([Contract 2](#contract-2)); `SchemaResolver::restored()` and the app's `inlineSchema()` put each entry back where it points. |
| `ports` | On an object, a def's or the top's, when the structure reads ports: each port's typed data `type`, whether it is `required`, and the `shape` it reads, when it reads one. A port is no property: its value is a key of the container, which the site names ([below](#a-port-is-stated-at-its-site)). |
| `reads` | On a site, the property where a child that reads ports is used: each port, mapped to the key of the parent's frame it reads. On a map of keys from the site whose entries read ports, each port mapped to the sibling map whose entry of the same id it reads. |
| `live` | Only when `true` (`runtime` in contract 1): the key is settled per request, by the server alone ([markers](#markers)). Either a runtime refiner narrows it, by a rule no schema can state, or, in [the full schema](#two-documents) only, an access answer that waits for the subject (it read a stored value, State, the `user` context) was set aside for this account, so the key is as the account's static answers leave it and may be locked or left out once the subject is known (`access: "live"` in contract 3). Never in a conditional. In the full schema the key keeps the width its static refiners and static access give it; the schema for these answers is refined by the runtime refiner for its values and applies the access answer, marking nothing for it. The access answer may be the owner's access class's or an alter's, each read by itself, so a key the account's static answers lock is `readOnly` and marked too; on every key of the document when the answer set aside is the resource's own at the top, which may lock it whole; on a key with variants, a variant offered may be withheld. Written beside `enumerated` or `refine` when the key has static refiners as well. A client asks the narrowing whenever the key is reached and never caches its answer. |
| `operation` | At the top only, after `contract`: how the operation behaves, `{id, label, description?, kind, creates, destructive, idempotent}` ([above](#the-schema)). Contract 1 also carried `readOnly`, which `kind: "read"` says. |
| `version` | At the top always, after `operation`; on a def only above 1, absent reading as 1: which version of its stored values the resource or structure describes, counting the breaking changes made to them. A compatible change never moves it; a client compares it before trusting a cached document ([Evolving a resource](evolution.md#the-version)). |
| `doesNotApply` | On an object, the top or a child's frame, never on a property: the keys of it a refiner answered [do not apply](refinement.md#when-a-key-does-not-apply) to these values, each with the keys it depends on, `{"send_day": ["frequency"]}`. They are no properties of it; a client refines when one of those changes. |

Removed in contract 4 ([Contract 4](#contract-4)): `locked` (a locked
key is `readOnly: true`, with `const` where it holds a value, and a
resource locked whole is `readOnly: true` at the top, where a submit is
refused and a renderer offers none; why:
[decision](decisions.md#locked-at-the-top)), `declared` (the frame's
`rules`), `access` (`live`), `fetch` and `entries` (inside `deferred`)
and `list` (`collection`).

A document is read as well as written. `Contract\SchemaReader` reads a
JSON Schema into a structure by this page's table run backwards, and the
full schema of a declaration, read back, is the same document again, but
for what a coded refiner, an access answer, an alter's mount or a
provider of keys says and no document can
([Reading a schema](reading-a-schema.md#the-round-trip)). Variants a
provider lists from the site, each read from its own schema, are stated
here exactly as a class's variants are.

Contract 1 also wrote `variant` on a variant's conditional, with the
widget and the key's other facts, saying which value it was for.
Contract 2 writes nothing beside the `then`'s reference: the value is
its `if`'s `const`, and the key's own `variants` names the def.

### Reusable subschemas

The module draws a wall between a parent and its child: a parent cannot
refine into a child, a child cannot watch a parent's key, and a child
keeps its own refiners and alters
([attached structures](resources.md#children)).
That makes a child a self-contained subschema, and both documents say
so with JSON Schema 2020-12's own `$defs` and `$ref`, through the one
code path they share (`ContractEmitter::define()` and `PropertySchema::reference()`):

A [shape](#a-shape-is-one-def) is a def too, one per shape, which each
key declared with it refers to.

1. **One def per child.** Every attached child and every
   variant is written once, under the root's `$defs`, keyed by the id on
   its class's `#[Structure]` or `#[Resource]` (`registration.contact`).
   A child with no class, a [variant from the site](resources.md#variants-from-the-site), is keyed
   `<parent id>.<key>.<value>` (`field.storage.settings.string`), an
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
   child, `enumerated`, `refine`, `group`, `after`, the `by` and
   `variants` of a key with variants, the `rules` it states, the `ports`
   it reads). An alter of the child applies in the def, since alters
   apply by class. Its `title` is what the structure is called, its
   label (`Paid ticket`), and its `description` what the structure is
   for, where it says; a structure with no label, its own or the one
   plugin's that holds it, is titled by its id (`registration.contact`).
   The key is the id either way, the same in every language. The key's
   own label is the parent's to give, beside the reference
   ([decision](decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for)). Each document
   writes its own defs, the schema for these answers with its
   `refined`, `stale` and `emptyOption` as they read for no values, the
   full schema without them; otherwise they are the same.
3. **The site carries the instance.** Where the child is used, the
   property is `{"$ref": "#/$defs/<id>", ...}` with what is particular
   to that use beside it: the title and description the parent gives the
   key, `readOnly` where it is locked, and the `x-surface` the parent
   reads for it (`dependsOn` as the parent sees it, `refined`, the
   widget hint, and
   `reads`, which of the parent's keys
   each port reads, with whatever depends on one stated on the parent's
   `allOf`: [a port is stated at its site](#a-port-is-stated-at-its-site)). JSON Schema 2020-12 applies keywords
   beside `$ref` as well as its target.
4. **Narrowing is composition.** Where the child at a site says more than
   its def (a key its context locked, a key refined for the values in
   this document), the site is `{"allOf": [{"$ref": ...}, {narrowing}],
   ...}`, the narrowing holding only what differs from the def, by the
   same "only what changed" reading the [enumeration](#exact-where-it-can-be)
   uses: properties compared one by one, conditionals the def does not
   already state, and a property's `x-surface` written whole where it
   differs, said on the site in `x-surface.within` by its pointer, since
   nothing below an applicator carries `x-surface`. The narrowing states
   the declared `type` beside a keyword only one type reads
   (`{"type": "object", "properties": {...}}`), and its `required` lists
   only the keys it adds to its def's. Where nothing differs, the
   reference is bare. Refinement only
   narrows, so a validator applying the def and the narrowing accepts
   exactly what the child at that site accepts; `ServedContractTest`
   holds the two readings to the same verdicts.
5. **Each variant's conditional refers to the variant's def.** Each
   `then` names the variants' key with a reference to its variant's def,
   beside the key's label and nothing else: the value it is for is its
   `if`'s `const`. The key's own property is the union of the variants'
   defs, and says under `x-surface` which value chooses which def (`by`,
   `variants`, `chosen`).

Only local references, `#/$defs/<id>`, are written: the def's key is a
JSON Pointer segment (`~` and `/` escaped) inside the URI fragment. A
reader that wants a key's effective schema inline resolves them once:
`Contract\SchemaResolver::inline()` in PHP, `inlineSchema()` in the
React app. Both first put each marker back where the emitter wrote it
(`SchemaResolver::restored()`): each `within` entry as the `x-surface`
of the schema it points at, and `x-surface.declared` on each
conditional that `rules` lists, a mark of the inline reading alone. Then they replace a reference by its def where it stood, lay a
composition's narrowing over it taking the narrowing's value for every
keyword it states (`properties` one by one, `required` as a union, the
conditionals concatenated), and leave a reference back into a def being
read as it is. A def's `title`, its id, is not laid over a site, and a
key's union of its variants is left to the conditionals, which state
each one exactly.

Example 3's full schema, abbreviated. Nothing in example 3 differs from
its def, so every reference is bare:

```json
"properties": {
  ...
  "ticket": {"title": "Ticket",
             "anyOf": [{"$ref": "#/$defs/registration.step3.ticket.free"}, {"$ref": "#/$defs/registration.step3.ticket.paid"}, {"type": "null"}],
             "x-surface": {"dependsOn": ["pricing"], "by": "pricing",
                           "variants": {"free": "#/$defs/registration.step3.ticket.free", "paid": "#/$defs/registration.step3.ticket.paid"}, ...}},
  "contact": {"title": "Contact", "$ref": "#/$defs/registration.contact"}
},
"allOf": [
  ...the room per venue...
  {"if": {"properties": {"pricing": {"const": "paid"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.paid"}}}},
  ...free...
],
"$defs": {
  "registration.step3.ticket.free": {...},
  "registration.step3.ticket.paid": {"title": "Paid ticket",
                                     "description": "The ticket when registration costs money: its price and currency.",
                                     "type": "object",
                                     "properties": {"price": {...}, "currency": {...}},
                                     "additionalProperties": false, "required": ["price", "currency"]},
  "registration.contact": {"title": "registration.contact", "type": "object",
    "properties": {
      "email": {"title": "Email", "type": "string", "format": "idn-email"},
      "phone": {"title": "Phone", "type": ["string", "null"]}},
    "additionalProperties": false, "required": ["email"]}
}
```

A narrowing, from the full schema of `field.instance:update` for a long
text field: the field's storage is attached in the storage's own `update`
context, which knows its identity, the entity type, the field name and
the field type, and locks it, while the `field.storage` def is the
storage as declared, with its identity open:

```json
"storage": {
  "title": "Field storage", "description": "What every bundle using this field shares.",
  "allOf": [
    {"$ref": "#/$defs/field.storage"},
    {"type": "object", "properties": {
      "entity_type": {"default": "node", "readOnly": true, "const": "node"},
      "field_name": {"default": "field_description", "readOnly": true, "const": "field_description"},
      "field_type": {"default": "string_long", "readOnly": true, "const": "string_long"}}}
  ]
}
```

The narrowing states `"type": "object"` beside its `properties`, the
def's own type, which narrows nothing. The identity is locked by
`readOnly` and `const`; the keys' `x-surface` says nothing the def does
not, so the site carries none. Where a narrowed key's `x-surface` does
differ, it is said on the site, in `within`, by the pointer from the
site to the key's narrowing (contract 3 also wrote `{"locked": true}`
there).

In the schema for these answers a site also narrows where the child's
own values refined it: a shelf whose unit is `cm` refers to the shelf's
def, which states the ceiling for each unit, and narrows its height to
`maximum: 200`, `x-surface.refined: true`.

### Collections

A collection is the parent's key, so its property says what the parent
says about it: its title and description, `type: array`, `minItems` and
`maxItems` from its Count, `readOnly` when locked, and under
`x-surface` the usual instance facts (`dependsOn`, `refined`,
`enumerated` or `refine` when the parent's refiner of it watches a
sibling) and `collection: true`. Its `items` refer to the child's def, the
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
  "items": {"$ref": "#/$defs/resource_test.larder.rack"},
  "minItems": 1, "maxItems": 4,
  "x-surface": {"widget": "list", "dependsOn": ["size"], "collection": true, "enumerated": true}
},
...
"allOf": [
  {"if": {"properties": {"size": {"const": "small"}}, "required": ["size"]},
   "then": {"properties": {"racks": {"type": ["array", "null"], "maxItems": 2}}}}
]
```

A list locked by its access is `readOnly`, and its items narrow the def
by composition, every key inside them `readOnly` and none holding a
`const`: each item holds its own value, which is in `values`.

### Variants are conditionals

The property at the variants' key is the union of the variants: `anyOf`
of a reference to each variant's def, and `{"type": "null"}` where the
key may be cleared, with no `type` beside it, since the union says it
(each def is an object, null its own member). A type generator writes
`Free | Paid | null` from it. A key with variants of which none is
declared yet keeps its `type` and has no `anyOf`. The union fixes no
keys of its own and is never narrower than the conditional that
applies: each variant is a `then` on the
parent, under an `if` naming the deciding key's value as a `const`, so a
validator applies exactly the chosen variant's rules and a renderer reads
the variant the current value selects. The `then` refers to the variant's
def ([above](#reusable-subschemas)), beside the key's label, and says
nothing else: the key's `x-surface.variants` maps each value to its
def. There is no
OpenAPI `discriminator` beside it: OpenAPI's names a property inside the
object whose alternatives it picks, and the variants' deciding key is a
sibling of it (why: [decision](decisions.md#no-openapi-discriminator-for-variants)). Example
3's ticket, abbreviated, as `/surface-api` serves it (with the widget
hints):

```json
"ticket": {
  "title": "Ticket",
  "anyOf": [{"$ref": "#/$defs/registration.step3.ticket.free"}, {"$ref": "#/$defs/registration.step3.ticket.paid"}, {"type": "null"}],
  "x-surface": {"widget": "variants", "dependsOn": ["pricing"], "by": "pricing",
                "variants": {"free": "#/$defs/registration.step3.ticket.free", "paid": "#/$defs/registration.step3.ticket.paid"},
                "chosen": "free", ...}
},
...
"allOf": [
  {"if": {"properties": {"pricing": {"const": "free"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.free"}}}},
  {"if": {"properties": {"pricing": {"const": "paid"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "$ref": "#/$defs/registration.step3.ticket.paid"}}}}
],
"$defs": {
  "registration.step3.ticket.free": {"title": "Free ticket", "description": "The ticket when registration costs nothing: a note shown beside the register button.",
    "type": "object",
    "properties": {"note": {"title": "Note", "type": ["string", "null"], ...}},
    "additionalProperties": false},
  "registration.step3.ticket.paid": {"title": "Paid ticket", "description": "The ticket when registration costs money: its price and currency.",
    "type": "object",
    "properties": {
      "price": {"title": "Price", "type": "number", "minimum": 0.01, ...},
      "currency": {"title": "Currency", "type": "string", "default": "EUR",
                   "oneOf": [{"const": "EUR", "title": "EUR"}, {"const": "GBP", "title": "GBP"}, {"const": "USD", "title": "USD"}], ...}},
    "required": ["price", "currency"], "additionalProperties": false},
  ...
}
```

### A port is stated at its site

A structure that reads a value from its container through a
[port](resources.md#ports) is written as any child is, once, as a def,
and the def names no container: it lists its ports under
`x-surface.ports`, a key that reads one names the port in its
`dependsOn`, and nothing that depends on a port is stated in it. A key
reading a port that holds nothing carries neither `enumerated` nor
`refine` there, and is in no `required` where it may be ruled out:
the def is the widest reading, whatever the port holds.

The site says the rest, on the parent's own `allOf`, because only the
parent knows which of its keys a port reads. Its property carries
`x-surface.reads`, each port and its key, and a `dependsOn` naming those
keys, the child's dependencies at the parent's paths; under them, an
`if` on the parent's key and a `then` reaching into the child where it
sits. A coded refiner reading a port is enumerated over the wired key's
list, as any key's refiners are over their siblings', and the site is
`enumerated`, or `refine` where the key offers no list or the refiner
also reads a key of the child's; a declared rule reading a port is
stated as written, its condition read at the wired key, its index in
the parent's `rules`, and the site carries no marker for it. The schema for these
answers binds each port to the value
its key holds, so the site narrows the def by composition, as any site
does. The test module's venue attaches the seating structure at
`seating`, its layout port wired to the venue's layout and its guests
to the capacity; its full schema, abbreviated:

```json
"seating": {"title": "Seating", "$ref": "#/$defs/resource_test.seating",
  "x-surface": {"dependsOn": ["capacity", "layout"], "reads": {"layout": "layout", "guests": "capacity"}, "enumerated": true}},
...
"allOf": [
  {"if": {"properties": {"layout": {"const": "standing"}}, "required": ["layout"]},
   "then": {"properties": {"seating": {"type": "object", "properties": {"aisle": false}}}}},
  ...theatre and banquet, each with its aisles...
  {"if": {"properties": {"capacity": {"type": "number", "maximum": 100}}, "required": ["capacity"]},
   "then": {"properties": {"seating": {"type": "object",
     "properties": {"rows": {"type": "integer", "description": "Up to 10 rows for up to 100 guests.", "maximum": 10}}}}}}
],
"x-surface": {..., "rules": [...the capacity rule's index...]},
"$defs": {
  "resource_test.seating": {"title": "resource_test.seating", "type": "object",
    "properties": {"rows": {...}, "aisle": {..., "x-surface": {"dependsOn": ["layout"]}}, ...},
    "required": ["rows"], "additionalProperties": false,
    "x-surface": {"ports": {"layout": {"type": "string", "required": true}, "guests": {"type": "integer", "required": false}}}}
}
```

The hall reuses the structure at `chairs`, reading its arrangement, and
its alter attaches it again at its mount, reading other keys: one def,
the conditionals at each site. Example 4's compliance structure, at its
alter's mount, is a site inside `third_party_settings` (`{"title":
"Compliance", "$ref": "#/$defs/registration.compliance", "x-surface":
{"reads": {"capacity": "capacity"}, ...}}`); its stewards read a number,
so nothing about them is stated ahead, and the group stays `refine` on
the capacity.

A structure at every entry of a map of keys from the site reads its
ports per entry ([Ports on an entry](resources.md#ports-on-an-entry)),
so its site is each entry: on the owner's `allOf`, an `if` on the
sibling map's entry and a `then` reaching into the map at the entry,
once per entry, a locked one's too. The map's property says `reads` as
the owner wired it, each port by the sibling map, and its `dependsOn`
names every sibling entry. The map's own def lists no port. Example 9's
student tickets, one of the two rules:

```json
{"if": {"properties": {"on_sale": {"type": "object", "properties": {"student": {"const": false}}, "required": ["student"]}}, "required": ["on_sale"]},
 "then": {"properties": {"limits": {"type": "object", "properties": {"student": {"type": "object", "properties": {"per_order": false}}}}}}}
```

The schema for these answers narrows each entry where it sits: a key
that does not apply is `false` in the entry's narrowing, and the entry's
`doesNotApply` names the port it depends on.

### A mixed-in structure is composed

A structure [mixed in](resources.md#mixins) is written once, as a def,
like any child, from the structure built by itself, and the owner's
frame composes it with its own keys: `allOf` of a reference to each
structure's def and the frame's own `properties`, closed with
`unevaluatedProperties: false` in place of `additionalProperties`. Each
member keeps its place among the owner's properties, so the order is
the declaration's: `true` where the def says all there is, only what
differs where the owner narrows it here (a lock, a context, the values
of this document, what reads a port at the owner's key), and `false`
where it does not apply to these values. What the def requires and the
conditionals it states are said there, not again; what depends on a port
is stated on the owner's `allOf`, as at a site. The journal's full
schema, abbreviated:

```json
"properties": {
  "title": {...}, "visibility": {...},
  "owner": {"x-surface": {"dependsOn": ["visibility"]}},
  "shared": {"x-surface": {"dependsOn": ["visibility"], "enumerated": true}},
  "created": {"x-surface": {"dependsOn": ["published"]}},
  "changed": true,
  "revision": {...}, "published": {...}, "archive": {"title": "Archive", "$ref": "#/$defs/resource_test.archive", ...}
},
"unevaluatedProperties": false,
"required": ["title", "visibility"],
"allOf": [
  {"$ref": "#/$defs/resource_test.ownership"},
  {"$ref": "#/$defs/resource_test.timestamps"},
  {"if": {"properties": {"visibility": {"const": "staff"}}, "required": ["visibility"]},
   "then": {"properties": {"owner": {"type": "string", "description": "Up to 32 characters for a staff audience.", "maxLength": 32}}}},
  {"if": {"properties": {"visibility": {"const": "public"}}, "required": ["visibility"]},
   "then": {"properties": {"shared": false}}},
  ...the journal's own two rules...
],
"x-surface": {..., "rules": [...the owner rule's index, and each declared one's...]},
"$defs": {
  "resource_test.timestamps": {"title": "resource_test.timestamps", "type": "object",
    "properties": {"created": {...}, "changed": {..., "x-surface": {"dependsOn": ["created"], "refine": true}}},
    "allOf": [{"if": {"properties": {"created": {"not": {"type": "null"}}}, "required": ["created"]},
               "then": {"properties": {"changed": {"description": "Required once the created time is set.", "type": "integer"}}, "required": ["changed"]}}],
    "x-surface": {"rules": [0]}},
  "resource_test.ownership": {"title": "resource_test.ownership", "type": "object",
    "properties": {"owner": {...}, "shared": {...}}, "required": ["owner"],
    "x-surface": {"ports": {"audience": {"type": "string", "required": true}}}},
  ...
}
```

- **A def may compose in turn.** A structure that mixes another in is
  written the same way in its own def: `allOf` of a reference to the
  other's def and its own properties, its members `true` where that def
  says all there is.
- **A def mixed in is open.** A closed def (`additionalProperties:
  false`) would refuse the owner's keys beside it, so in a document that
  mixes a structure in anywhere its def carries neither keyword, and
  every other place that uses it closes it, `{"$ref": ...,
  "unevaluatedProperties": false}`: the archive attaches the timestamps
  the journal mixes in, and its `stamps` is closed so. A document that
  only attaches the structure, as the ledger's, writes the closed def it
  always did.
- **Inline, the frame is flat.** `SchemaResolver::inline()` and the React
  app's `inlineSchema()` lay the frame over each def, each member in its
  place (`true` is the def's, a narrowing laid over it, `false` leaves
  it out), the defs' `required` and conditionals joined to the frame's,
  and close it with `additionalProperties: false`: what the pipeline
  reads, and what the page draws and narrows from.
- **The tool lists it flat.** The tool bridge converts the built data
  surface, where the members are the owner's keys, so `values` is flat;
  the Tool API writes its schema from typed definitions and has no
  `allOf` to carry.
- **Access.** A member its structure's access locked is `readOnly`, with
  the stored value as `const` in the schema for these answers and none in
  the full schema; one it omitted is not there, and neither is the def
  when every member is gone. A member whose structure's answer waits for
  the subject is marked `x-surface.live` in the full schema, beside the
  def.

### A shape is one def

A key declared with a [shape](declaring-a-resource.md#named-shapes) is
written as a reference to it. Each shape is one def in both documents,
under its id, as its alters left it, described as an optional key is:
its type allows `null`, since whether a key may hold nothing is the
key's, its `title` is the shape's label (its id where it has none),
and its `x-surface`
carries only what the shape checks, the message of its pattern and
what only the server checks. Each key that
uses it is `$ref` first, then its title, then only what it says
differently from the def, and its whole `x-surface`. Example 4's
compliance structure, in its def:

```json
"license": {
  "$ref": "#/$defs/event.license",
  "title": "Event license",
  "x-surface": {"widget": "text",
                "patternMessage": "A license number is four digits, such as 2048."}
}
```

and the shape beside it under `$defs`:

```json
"event.license": {
  "title": "Event license",
  "description": "The four digits after EV- on the license, such as 2048. Required to host more than 100 people.",
  "type": ["string", "null"],
  "pattern": "^\\d{4}$",
  "examples": ["2048"],
  "x-surface": {"patternMessage": "A license number is four digits, such as 2048."}
}
```

- **What a key adds is beside the reference.** The test module's depot
  uses one stock code at two keys: `sku` is required and at least four
  long, so it says `"type": "string"` and `"minLength": 4` beside the
  reference; `spare` says its own description, `"maxLength": 6` and its
  default. A key only narrows its shape, so the two together are what
  the pipeline accepts, and the matrix holds each to it.
- **Sibling keywords, `$ref` first.** In 2020-12 a `$ref` beside other
  keywords is their conjunction, and with the reference first a reader
  that lays the def where the reference stood (`SchemaResolver::inline()`,
  the React app's `inlineSchema()`) lays the key over it, keyword by
  keyword, to the same schema. A key resolved from a shape's def is a
  value, never a child's frame.
- **A conditional narrows the key, not the shape.** A `then` on a key
  declared with a shape says only what it narrows, as on any key, and
  refers to nothing.
- **A port names the shape it reads.** In a structure's def,
  `x-surface.ports.<port>.shape` is the id of the shape a port reads,
  beside its `type`.

### Exact where it can be

A static refiner is a pure function of the values it watches, and most
watched keys offer a short list of values. So where a key's static
refiners have nothing to refine against yet (a sibling they watch holds
nothing), the emitter runs them ahead of time for every value they could be
handed and writes each answer as a conditional on the parent, beside
the variants': an `if` naming each watched sibling's value as a `const`,
and a `then` saying what the key's schema says differently from the
unrefined one. The property carries `x-surface.enumerated: true`. A
generic JSON Schema validator then refuses what the server refuses, with
no round trip to `POST .../schema`.

- **Every watched sibling must offer a list.** Its own definition, as
  refined for the combination so far, resolves to an option set (a
  `Choice`, a `LabeledChoice`, a list an options resolver reads). A
  sibling that holds a value is held at it. A sibling that is free text,
  a number, a boolean, a list of values, a child, or a key an alter
  mounted (named by its dotted path) cannot be listed.
- **The combinations are capped**, at 64 per key
  (`ContractEmitter::ENUMERATION_CAP`): the product of the watched
  siblings' lists, read in declaration order, each under the values
  before it, so the example block's `field` is enumerated per entity type
  and, under each, per bundle. Above the cap, or with a sibling that
  cannot be listed, the key is not enumerated and is marked
  `x-surface.refine: true` instead: its schema is the unrefined one and
  `dependsOn` says what to refine on, as before
  (why: [decision](decisions.md#enumeration-is-capped)).
- **Each conditional is what `POST .../schema` answers, less the
  runtime.** It is computed by refining the whole data surface against
  the values with the combination over them, the same call a narrowing
  makes with the runtime
  refiners left out, so chains compose (the room per venue, the capacity
  per room) and an alter's static refiners in the chain are included. A
  runtime refiner is never called for a conditional; only the siblings
  the static refiners watch are enumerated over, and the key carries
  `live` besides.
- **Only what changed.** A `then` names the key and only the keywords
  that differ from the unrefined schema (the narrowed `oneOf`, a lower
  `maximum`, the description naming the reason); a map's properties are
  compared one by one. Beside a keyword only one type reads (`maximum`,
  `maxLength`, `properties`, `maxItems`), it states the key's declared
  `type`, which narrows nothing, so a strict validator and a type
  generator read the keyword as the key's. A combination that changes
  nothing writes no conditional.
- **Where it does not apply, and where it is required.** A combination
  for which a refiner answers that the key
  [does not apply](refinement.md#when-a-key-does-not-apply) writes
  `false` for it (`{"properties": {"send_day": false}}`, inside a map
  for a key an alter mounted). A key ruled out for some combination is
  not in the frame's `required`; a combination where it applies and is
  required writes `required` beside its narrowing, `{"required":
  ["send_day"]}`, and so does one for which a refiner makes an optional
  key required. A `refine` or `live` key whose refiner may rule it
  out, as its return type says, is in no `required`: nothing states
  where it applies.
- **Inside a child too.** Each frame is described the same way, so
  a variant enumerates its own keys inside the variant's schema,
  under its conditional. A child's frame starts from the
  child's defaults, as refinement does, so a child key watching a
  sibling with a default is shown refined against that default, not
  enumerated.
- **With values, nothing changes.** A key whose watched siblings hold
  values is refined, as the read and `POST .../schema` serve it, and carries
  neither keyword. That is the schema as it stands;
  [the full schema](#two-documents) is handed no values, so there every
  listable key is enumerated, always.
- **The lists are dependencies.** Every option list and every refined
  data surface a conditional was read from is merged into the contract's
  cacheability (the example block's static contract depends on
  `entity_bundles`).

### A list the site decides

Variants from the site and keys from the site take their list from the
site, not the code: a component per single-directory component a theme
provides, a key per bench a configuration lists. Each is spelled out in
the full schema while it has at most `surface.settings:site_list_limit`
entries (20 as shipped), and the tool listing spells it out the same
way (why: [decision](decisions.md#a-budget-for-what-the-site-decides)). Past it
the key is `deferred`: static, and left out for its size
(why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).

- **A key with variants** is an open object, `deferred`, its deciding
  key under `by`, with no def and no conditional per variant; the
  deciding key keeps its `oneOf` of every value and label. The schema
  for these answers, with a value chosen, states that variant exactly,
  its def and its one conditional and no other; with nothing chosen, it
  is open as in the full schema.
- **A map of keys from the site** is an open object, `deferred`, its
  keys named under `deferred.entries` by id and label. The schema for these
  answers states every key.
- **Each states how to fetch one entry.** `x-surface.deferred.fetch` is an RFC
  6570 template, the full schema's URL with a `choose` for the key, its
  value the expression `{value}`, and a `choose` for each variant on the
  way to it. A client fills `{value}` with the deciding key's value, or
  an entry's id, and fetches the full schema with that entry stated
  ([the fetch per choice](#the-fetch-per-choice)).
- **Each key counts at its own level.** A component in a slot is
  variants of its own, read from the schema its provider wrote, and
  counts the components the slot offers.

Example 12's props on a site with 312 components, in the full schema:

```json
"props": {"title": "Props", "type": ["object", "null"],
          "x-surface": {"dependsOn": ["component"], "by": "component",
                        "deferred": {"fetch": "https://example.com/surface-api/event.spotlight/schema?choose=/props={value}"}}}
```

A placed block's settings are variants per block plugin, and the
component block's props are variants per component inside one of them,
so the props' template names the plugin chosen on the way:
`.../block.placement/schema?choose=/settings=surface_component&choose=/settings/props={value}`.
In a document no route serves (a baseline, the contract panel, the
narrowing tool's answer) the template is its query alone,
`?choose=/props={value}`, read against the full schema's URL.

In the shipped resources and structures, described for no values:
example 2's and example 3's room (per venue, 3 conditionals) and
capacity (per room, 6); the example block's `bundle` (per entity type) and
`field` (per entity type and bundle); the example formatter's `variant`
(per casing); the extras example's `limit` (per presentation; only the grid
writes one). Declared: example 4's capacity, whose ceiling without a
license is a rule declared as data, stated as written beside its six
conditionals per room. Marked `refine`: example 4's stewards, in the
compliance structure at the mount, which read the capacity through a
port, a number. Marked `live`: example 4's room, which its alter narrows
by the rooms a site has closed, read from State; it stays enumerated per
venue for example 3's own refiner. The field instance's `bundle` would
be enumerated per entity type, but every operation of it knows the
entity type, so it is always served refined.

Example 2, nothing chosen, abbreviated:

```json
"room": {"title": "Room", "type": "string", "oneOf": [...every room...],
         "x-surface": {"dependsOn": ["venue"], "enumerated": true, ...}},
"capacity": {"title": "Capacity", "type": ["integer", "null"], "minimum": 1,
             "x-surface": {"dependsOn": ["room"], "enumerated": true, ...}},
...
"allOf": [
  {"if": {"properties": {"venue": {"const": "riverside"}}, "required": ["venue"]},
   "then": {"properties": {"room": {"oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}]}}}},
  ...
  {"if": {"properties": {"room": {"const": "riverside_main"}}, "required": ["room"]},
   "then": {"properties": {"capacity": {"type": ["integer", "null"], "description": "Up to 400 for the Main hall.", "maximum": 400}}}},
  ...
]
```

### Example 2, abbreviated

The venue narrows the room and the room the capacity, for the stored
Riverside Hall and its main hall, as `/surface-api` serves it:

```json
{
  "resource": "registration.step2",
  "operation": "configure",
  "label": "Configure registration (step 2)",
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "$id": "https://example.com/surface-api/registration.step2/configure",
    "title": "Configure registration (step 2)",
    "description": "Sets the event title, whether registration is open, the venue, a room at that venue and a capacity the room can hold.",
    "x-surface": {"contract": 3, "operation": {"id": "configure", "kind": "update", ...}, "version": 1},
    "type": "object",
    "properties": {
      "title": {"title": "Event title", "type": "string", "x-surface": {"widget": "text", ...}},
      "open": {"title": "Registration open", "type": ["boolean", "null"], "default": true, ...},
      "venue": {"title": "Venue", "type": "string",
                "oneOf": [{"const": "harbour", "title": "Harbour Centre"}, {"const": "library", "title": "Old Library"}, {"const": "riverside", "title": "Riverside Hall"}],
                "x-surface": {"widget": "select", "reference": {"entityType": "event_venue"}, ...}},
      "room": {"title": "Room", "type": "string",
               "oneOf": [{"const": "riverside_main", "title": "Main hall"}, {"const": "riverside_east", "title": "East room"}],
               "x-surface": {"widget": "select", "dependsOn": ["venue"], "refined": true, ...}},
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
`SurfaceWidgetManager`'s choice, in its weight order
([Widgets](widgets.md)): the emitter asks the stock widgets' own rule
for which element a list of choices is, so the hint and the form never
disagree (why: [decision](decisions.md#a-widget-is-chosen-from-the-definition)).
A definition asks for an element with `setSetting('widget', ...)` in
the hint's words: `radios`, `checkboxes`, `select`, `time`.

| Definition | Form API element | `x-surface.widget` | React component |
| --- | --- | --- | --- |
| a key a module's widget claims, unless its owner asks for a widget | that widget's element | its plugin ID | the stock field for the key's JSON type (`stockWidget()`) |
| any key whose constraints resolve to a list, an empty one included | `select`, empty option by the rule | `select` | `SelectField` |
| such a key that asks for radios, `setSetting('widget', 'radios')` | `radios`, a `- None -` radio only where the key may be cleared | `radios` | `RadiosField` |
| a deciding key that holds a value and whose variants offer one value, or a key its operation's context fixes to one (fixing makes it hold a value), while what is stored is that value or nothing | `item`: the value's label, the value posted, no AJAX trigger | `fixed` | `FixedField` |
| a key that refers to an entity, past the limit, and a bare reference's id | core's `entity_autocomplete`, its selection settings the key's; a select while its candidates are listed | `autocomplete` | `AutocompleteField`: a select while the schema lists the candidates, else a text input whose suggestions the lookup answers |
| a key that does not apply | nothing but its wrapper | (no property) | nothing |
| a list of such values offering at most twelve, or asking for checkboxes | `checkboxes`, never `#required` | `checkboxes` | `CheckboxesField` |
| a list of such values offering more, or asking for a select | `select` `#multiple` | `select`, `multiple: true` | `MultipleSelectField` |
| a map with properties, an attached part | `details` | `fieldset` | `FieldsetField` |
| `third_party_settings`, which only groups each module's map | `container`, no title | `fieldset`, `group: true` | `FieldsetField`, as a plain `div` |
| a module's map its alter placed | its `details`, drawn after the named key | `fieldset`, `after: "<key>"` | `FieldsetField`, drawn after that sibling of the group |
| a key with variants | the chosen variant's `details`; nothing while none is chosen | `variants` | `VariantsField` |
| `string` with the `multiline` setting | `textarea`, no maxlength, no placeholder | `textarea` | `TextareaField` |
| `email` | `email` | `email` | `TextField` (type email) |
| `string` asking for a time, `setSetting('widget', 'time')` | a time input (`date`, type `time`), the Regex still the rule | `time` | `TextField` (type time) |
| `string` asking for a date, `setSetting('widget', 'date')` | a date input (`date`), the Regex still the rule; both documents state `format: date` | `date` | `TextField` (type date) |
| `string`, `uri` | `textfield`, `url` | `text` | `TextField` (type text, url; password for a secret) |
| `integer`, `float` | `number`, `#min`/`#max` from Range, `#step` 1 or any | `number` | `NumberField` |
| `boolean` | `checkbox`, never `#required` | `checkbox` | `CheckboxField` |
| a list of single values with no allowed values | a `fieldset` of rows, each the item's element, "Remove" beside each and "Add another" under them, over AJAX | `list` | `ListField` (add and remove rows) |
| a collection, at any depth (inside a variant, an attached part or a map), and a list of maps no collection stands behind | a `details` of item `details`, "Add another" and "Remove" over AJAX | `list`, `collection: true` (a list of maps alone: `list`) | `ListField`, each item the child's `FieldsetField`, at least `minItems`, at most `maxItems` |
| a locked key | `#disabled`, "Fixed for this operation." | `readOnly: true` | disabled, the same note |
| a locked child | its fieldset `#disabled`, every element inside with it | `readOnly: true` on the fieldset and on every key inside | a fieldset of disabled keys |
| `timestamp`, `timespan`, `decimal` | `number`, `#step` 1 (any for a decimal) | `null` | the stock field for the key's JSON type (`stockWidget()`) |
| `datetime_iso8601` | core's `datetime` element, a date and a time input in the site's time zone, read back as the ISO 8601 string | `null` | the stock field for the key's JSON type (`stockWidget()`) |
| `duration_iso8601` | `textfield` | `null` | the stock field for the key's JSON type (`stockWidget()`) |
| `binary`, `language`, `language_reference`, `entity_reference`, and a list of them | an `item`: "This is not drawn on this form; what it holds is kept.", posting nothing | `null` | the stock field for the key's JSON type (`stockWidget()`) |
| an `any` key read from a schema typed as several scalar types (string, integer, number, boolean), the `scalars` widget | `textfield`, read as the first stated type the text spells: integer, number, `true` or `false`, then string | `null` | the stock field for the key's JSON type (`stockWidget()`) |
| what no other widget draws: an `any` key with no variants whose types include an object or a list, or state none; a list of lists or of `any` | `textarea` of JSON, the `json` widget | `null` | the stock field for the key's JSON type (`stockWidget()`) |

The last six rows name no hint: the contract says nothing about how a
form draws them, and the documents did not move when they were drawn.
The last is the Form API's last resort, so the form never refuses to
draw a declared key
(why: [decision](decisions.md#the-form-never-refuses-to-draw-a-declared-key)).

## The API

The path is the resource and the method is the operation
(why: [decision](decisions.md#contract-3-the-operation-is-the-method)). One
route, `surface.api`, answers every path below;
`ServedPaths` reads which operation a path and a method name.

| Method and path | What it does |
| --- | --- |
| `GET /surface-api/{resource}` | A singleton's envelope: what is stored and the schema for it. On a collection, the listing where the resource declares one, a read only operation that names no thing: its query string the values, its outputs the answer ([a listing](#a-listing)). Else the default create's envelope: its declared defaults and the schema a `POST` takes. |
| `PATCH /surface-api/{resource}` | A singleton's default update. A collection has none. |
| `POST /surface-api/{resource}` | The default create, on the collection of a resource with identity. |
| `GET` and `POST /surface-api/{resource}/{parent}` | A create that names the parent it adds to, its envelope and a run of it: `POST /surface-api/field.instance/node/article` adds a field to the article bundle. |
| `GET /surface-api/{resource}/{id}` | One thing's envelope, its default update's (or default read's). |
| `PATCH /surface-api/{resource}/{id}` | One thing's default update. |
| `DELETE /surface-api/{resource}/{id}` | One thing's default delete: removes it, with no body. |
| `GET .../schema` | The full schema of the operation `GET` serves at that path, `application/schema+json`. |
| `GET .../schema?choose=<pointer>=<value>` | The same, with each choice of a key `deferred` for its size stated in full ([the fetch per choice](#the-fetch-per-choice)). |
| `POST .../schema` | The envelope narrowed for the values the body sends: the schema, given these values. A query. |
| `GET .../{x}:{operation}` | A custom method's envelope: any operation that is not a default. |
| `PATCH .../{x}:{operation}` | A run of that operation when it is an update: example 15's reorder, `PATCH /surface-api/event.venue:reorder`. |
| `POST .../{x}:{operation}` | A run of any other: a create, or a read that checks and writes nothing. |
| `GET` and `POST .../{x}:{operation}/schema` | Its full schema, and its schema for values. |
| `GET .../{x}:lookup` | The candidates of one key that refers to an entity, of the operation `GET` serves at the path, by label: the lookup ([below](#a-lookup)). A resource that declares an operation named `lookup` has that operation there instead. |

`{id}` is the identity: an operation's parameters named for the
resource's identity keys, as path segments in the order
`#[Resource(identity:)]` lists the keys, from the first key on, joined
by `/` (up to four). A parameter is identity the caller knows before the
call, so it is in the path at every operation that takes it, named as
the key is: `/surface-api/node.type/recipe`,
`/surface-api/entity.content:node.page/12`, and for the field instance,
whose identity is `entity_type`, `bundle`, `field_name`, `field_type`,
`/surface-api/field.instance/node/article/field_x`. A create names the
parent it adds to, and leaves the keys it chooses to the body: the field
instance's create is `POST /surface-api/field.instance/node/article`, its
machine name and type in the body. An edit names the whole thing; an
identity key it leaves out follows from the rest (a field's type from
the field). A create that takes no identity, and every operation of a
singleton, is addressed at `/surface-api/{resource}`. An identity key no
operation takes as a parameter has no segment: a content entity
resource's entity type and bundle are in its id. A parameter named for
no identity key arrives in the query string
(`ResourceDefinition::identityParameters()`, why:
[decision](decisions.md#a-parameter-is-identity-the-caller-knows)).

**Which operation is the default.** At each path, of each kind: the
operation whose id is the kind's standard one (`create`, `update`,
`read` or `list`, `delete`), or `configure` for an update on a
singleton; or else the only operation of that kind at the path; two of
a kind with neither named so leaves the kind with no default
(why: [decision](decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals)).
An id `add`, `edit`, `remove` or `get` is refused at discovery, naming
the standard id. A read is a
default only where no update is, and the collection of a resource with
identity has no default update: `PATCH` there would change every thing,
which no operation means, so an update over the collection, example
15's reorder, is a custom method, as is an update at a path shorter
than the deepest one the resource's operations are addressed at. A path
an update is served at names a thing, so it has no default create. `GET`
serves the default update,
else the default read, else the default create; on the collection of a
resource with identity, a default read is its listing, which `GET` runs.
`PATCH` is the default update; `POST` is the default create; `DELETE` is
the default delete. Every other operation is a custom method,
`:{operation}` after the last segment of the path it addresses, run with
`PATCH` when it is an update and `POST` otherwise: example 10's `check`,
read-only beside the program's `configure`, is `POST
/surface-api/event.program:check`; the field instance's `reuse`, a
create that names the whole field, at the path its edit is served at,
is `POST /surface-api/field.instance/node/page/body:reuse`; example
15's `reorder` is `PATCH
/surface-api/event.venue:reorder`. A resource id with a colon of its own,
`entity.content:node.page`, is read whole first. A default may be named
the same way.

Every path is gated by `_surface_served_access`: the operation's
permission, then the access class the resource or structure names,
through `Resources::access()`, with no opinion read as a refusal. The
permission is asked first, from the two ids alone, before a parameter
is read or the subject loaded; a refusal past it (an access class
refusing what the parameters name, or an id that names nothing for an
account without the operation's permission) gives one fixed reason,
"The operation cannot be served to this account with these
parameters.", so it never says whether an id exists, and only an
account holding the operation's permission hears the access class's
own, and that an id names nothing (404)
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).
A `POST`, a `PATCH` or a `DELETE` carrying the session cookie requires
`X-CSRF-Token` from `/session/token` (`_csrf_request_header_token`).

Every answer carries `Contract-Version: 3`, the version of the wire
format its documents are written in ([Contract 3](#contract-3)), as
`x-surface.contract` says inside them; an error too. A client reads it
before the body and refuses a version it does not know; the React app
does, by the header or by the document.

### A listing

A resource with identity whose collection has a read only operation
that names no thing, its default read there, is listed by `GET` on the
collection (why: [decision](decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).
The query string is the listing's values, read by
[the coercion table](semantics.md#the-coercion-table) as a form's text
is, the operation's parameters and `dryRun` aside; the pipeline accepts
and validates them and stops after prepare, as for any read, and the
answer is the listing's outputs as its storage's prepare() answered
them, not an envelope. A value refused is 422 problem details. Example
15's:

```
GET /surface-api/event.venue?limit=2

200 Contract-Version: 3
Link: <https://example.com/surface-api/event.venue/schema>; rel="describedby"

{"items":[{"id":"riverside","label":"Riverside Hall","capacity":520,"weight":0},{"id":"library","label":"Old Library","capacity":90,"weight":1}],"total":3}
```

Its envelope is its custom method's, `GET /surface-api/event.venue:list`,
and its full schema the collection's, `GET /surface-api/event.venue/schema`:
the paging and the filter, and under `outputs` the page and the total.
The create's envelope moves to its custom method too,
`GET /surface-api/event.venue:create`. A resource with no listing answers
`GET` on its collection with its create's envelope, as before.

### A lookup

A key that refers to an entity lists its candidates while there are at
most `surface.settings:reference_list_limit`; past that it lists
none, and a client finds an id with the lookup, a custom read every
resource path answers
(why: [decision](decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up)):

```
GET /surface-api/event.sponsorship:lookup?key=/venue&q=riv&limit=10

200 Contract-Version: 3

{"key":"/venue","entityType":"event_venue","bundles":[],"items":[{"id":"riverside","label":"Riverside Hall"}]}
```

`key` is the JSON Pointer of the key (or a top-level name) in the
operation `GET` serves at the path, built for the account that asks, so
a key its access leaves out is not found; `q` is text the label
contains, none for the first candidates; `limit` the most to answer, 10
unless said and at most 50. For a key of any entity type, `type` names
the entity type, one its `entity_type` allows for the account: `GET
/surface-api/event.sponsorship:lookup?key=/thing/id&type=node&q=Sponsor`;
the key itself names its id. The answer's ids are in the key's type: a
bare key's id is a string. The candidates are the selection handler's
that the key's options and its check ask, for the same account, so the
lookup finds nothing a write refuses for access. It is gated as the
operation is, its permission then its access class, and answered
`no-store`. A key that refers to no entity, or a type the key does not
allow, is 404. The React app's autocomplete calls it; the tool listing
names it in the description of a key with too many to list; the OpenAPI
document describes it for every operation with a reference key. The
lookup serves the keys of the operation `GET` serves at the path, so a
custom method's own keys have none.

### The read

**`GET /surface-api/{resource}[/{id}]`** answers the envelope for the
values the operation form would open with:
[the document](#the-document), `resource`, `operation`, `label`,
`schema`, `values`, `stale`, `outputs` when declared, and `notes`. Its
`$id` is the path it was read at, absolute, the operation's other
parameters as its query string ([above](#the-schema)). Its headers:

| Header | Holds |
| --- | --- |
| `ETag` | The fingerprint of the stored values the envelope was built from ([`If-Match`](#if-match)), strong; none for a create or a resource with no storage. A proxy may pass it on weakened, `W/"..."`. |
| `Link` | `<.../schema>; rel="describedby"`: the full schema. |
| `Cache-Control` | `no-store, private`: never stored ([cacheability](#cacheability)). |

`HEAD` answers the same headers and no body.

**`GET .../schema`** answers [the full schema](#two-documents) itself,
`application/schema+json`, with the widget hints: the JSON Schema
document, its `$id` this path, with no envelope around it (contract 2
answered `{resource, operation, label, schema, outputs}`; the outputs
are the envelope's). It reads no value, so it is the same whatever is
stored or typed, and it is the one document that may be cached
([below](#cacheability)): its `ETag` is a hash of its bytes, and a
request whose `If-None-Match` names it is answered 304 with no body. A
client holding one of another contract version fetches it again. It is the account's: built for the account
alone, its static access answers stated and its runtime ones marked
`x-surface.live`. An account the operation's permission does
not admit is refused (403).

**`POST .../schema`**, the body a values document, partial or whole,
answers the envelope narrowed against the values, which is what the
form's AJAX rebuild computes, the same way, and `discarded` after it.
It writes nothing; it was `/refine` until contract 3. Its `$id` is the
read's, the document it stands in for:

1. The values are narrowed to the data surface's keys and overlaid on
   what the operation opens with.
2. A path in the `X-Surface-Stale` request header whose value comes back
   empty stands for the stored value again: the served form of the
   marker the operation form's container posts. The stored value never
   travels; the path does.
3. The form builder's discard rule (`discardedRefinementInput()`) drops
   an answer the new choice of its dependency orphans: it falls back to
   what is stored, and if that is not offered either, it is shown on the
   empty option, `stale`, with the stored value kept on the server.
   Changing the venue answers the library's room as `null`, `stale:
   ["/room"]`, `discarded: ["/room", "/capacity"]`; putting the venue
   back, with `X-Surface-Stale: /room` sent, shows the reading room
   chosen again.
4. A computed key holds the value a write of these values would store:
   computed from what its sources hold, while storage holds nothing for
   it or when it says `when: "change"` and a source moved, else what is
   stored. Typing "Town Hall" as a new venue's name answers its slug,
   `town-hall`, in `values`, so a client shows the slug as the name is
   typed and never sends it
   (why: [decision](decisions.md#a-computed-key-is-a-pure-function-of-its-sources)).

The document is emitted from the form's own overlay,
`refinementOverlay()`, settled to [the same fixed point](forms.md#the-in-form-half-discarding-orphaned-input)
the AJAX rebuild reaches. A key that falls back to a stored value the
edit itself orphaned (a dependency moved away from what is stored, and
the new answer no longer offers the stored value) is an **orphan**: it
is held unanswered, so nothing below it is refined against the value it
stands for, and it is listed in `stale` with `x-surface.stale: true` and
a `null` value, whatever its widget. `discarded` names the inputs
dropped on the way there; `stale` names what is shown standing for a
stored value, orphans and keys the site narrowed away alike, and
`notes` says each again as an entry, code `stale`. So moving the venue
off the library leaves the capacity as declared, `minimum: 1` and no
`maximum`, with no room's description under it, rather than capped by a
reading room no longer on the screen. A client sends the `stale` paths
back in `X-Surface-Stale` with the next narrowing or write; empty there
stands for the stored value again, so a narrowing answers the same
orphan, and a write judges the stored room under the venue the same
document moved, and refuses it, as the form's Save does.

### The fetch per choice

A key `deferred` for its size names, under `x-surface.deferred.fetch`, the full
schema with one of its entries stated
(why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
`GET .../schema?choose=<pointer>=<value>` answers it: the full schema,
built for the account as the plain one is, with each choice stated in
full at its key, as the full schema states it under the limit, and the
rest as the plain full schema says it.

- **A variant**, `choose=/props=surface_examples:card`: its def in
  `$defs` and its one conditional on the frame, nothing for the other
  variants. The key stays open and `deferred`, its `variants` naming the
  defs stated, so the answer accepts another component's props as the
  plain one does, and the pipeline judges them.
- **An entry of a map of keys from the site**, `choose=/limits=student`:
  the map, still open and `deferred`, with that entry's key under its
  `properties`, as the map's def states it under the limit.
- **A key inside a variant** is named with the choice of each key with
  variants on the way, in any order:
  `choose=/settings=surface_component&choose=/settings/props=surface_examples:card`.
  A choice at a key whose variants are spelled out only says which
  variant to pass through. Inside a collection, any item's index names
  the key in every item, which are one structure.
- **Several choices** are stated together, any number, one `choose`
  each; PHP's own query parsing keeps the last of several, so the
  server reads the query string as it arrived.
- **What it depends on**: what the plain full schema depends on, the
  provider that listed the variants (its tags) and
  `config:surface.settings` among it, and `url.query_args`; its
  `$id` is the path with the choices, and its `ETag` a hash of its
  bytes, so a client may hold the answer until a component changes.
- **A choice that names nothing** is a 404 problem naming the pointer:
  no key there, a key whose list is not the site's, a key with variants
  on the way that no choice names, or a value the list does not hold;
  the list's values are named under `values` when there are no more of
  them than the site list limit. A `choose` that is not a pointer, `=`,
  then a value, is a 400.

```
GET /surface-api/event.spotlight/schema?choose=/props=surface_examples:card

200 Content-Type: application/schema+json
Contract-Version: 3
ETag: "0726633d342c2ec5df9f4a31e50e8746d3c2d6da4fac829e5a602937692ed27b"

{"$id":"https://example.com/surface-api/event.spotlight/schema?choose=/props=surface_examples:card",
 "properties":{"props":{"title":"Props","type":["object","null"],
   "x-surface":{"by":"component","variants":{"surface_examples:card":"#/$defs/event.spotlight.props.surface_examples:card"},
                "deferred":{"fetch":"https://example.com/surface-api/event.spotlight/schema?choose=/props={value}"},...}},...},
 "allOf":[{"if":{"properties":{"component":{"const":"surface_examples:card"}},"required":["component"]},
           "then":{"properties":{"props":{"title":"Props","$ref":"#/$defs/event.spotlight.props.surface_examples:card"}}}}],
 "$defs":{"event.spotlight.props.surface_examples:card":{...}}, ...}

GET /surface-api/event.spotlight/schema?choose=/props=nope:none

404 Content-Type: application/problem+json

{"type":"about:blank","title":"Not Found","status":404,"detail":"The key of event.spotlight at /props has no nope:none to choose; it has 312, which the full schema lists.","pointer":"/props"}
```

The narrowing, `POST .../schema` and the narrowing tool, answers as
before, for an invoker that prefers to ask with its values.

### The write

**`PATCH`** (the default update), **`POST`** on the collection (the
default create) and **`POST .../{x}:{operation}`** (any other) run the
pipeline over the values document the body is: `submit()` with the
access answer, and a write when nothing is refused. The body is the
values themselves, no `values` wrapper: an update's a patch, a key left
out keeping what is stored and `null` clearing it
([semantics](semantics.md#absent-null-or-a-value)), which is RFC 7396's
rule, so `PATCH` takes `application/merge-patch+json` as well as
`application/json`; a create's the keys it sends, the rest taking their
defaults. Every value was sent on purpose, so nothing is discarded, the
full-submit rule: a stale path sent empty is read as the stored value,
and the run judges it, so a room left stale after its venue moved is
refused.

| Request | Means |
| --- | --- |
| `?dryRun=true` | Run through prepare and stop before commit: nothing is written, and the answer is the one a write would give, with `x-surface-dry-run: true`. Always 200, never a `Location`. It was `/validate`. |
| `If-Match: "<ETag>"` | Write only if what is stored is still what that `ETag` named; 412 otherwise, nothing written. `*` matches whatever is stored. Opt-in: with none, the last write wins ([below](#if-match)). Checked on a dry run too. |
| `X-Surface-Stale: /room, /ticket/price` | The stale paths the envelope named that the body sends empty: each stands for the stored value. |
| `X-CSRF-Token` | The session's token, with the session cookie. |

It answers the one result shape every door answers a run in
(why: [decision](decisions.md#one-result-at-every-door)), every key always
present, then three keys of its own:

| Key | Holds |
| --- | --- |
| `ok` | Whether nothing was refused; `true` on every 2xx. |
| `committed` | Whether the values were written; `false` on a dry run and for a read-only operation. |
| `changed` | Whether the write moved a stored value; `false` unless committed. |
| `changes` | The JSON Pointer of each stored value the write changes, or on a dry run would change, a map by its own keys, a list whole: a plan, empty when the values are what is stored (why: [decision](decisions.md#absent-keeps-null-clears)). |
| `values` | What `accept()` made of the input, as the read tool would answer it once written: no key the build left out for this account, no secret, no key that does not apply, and no key never stored that the run left at its default. |
| `violations` | Empty on a 2xx; a refusal is [problem details](#problem-details). |
| `notes` | Each value sent for a locked key that the key did not take, `{path, code, message}`, code `locked`, once per key: "Machine name is locked and kept as stored; the value sent was not taken." The key keeps what it holds and nothing is refused (why: [decision](decisions.md#locked-at-the-top)); a value sent as it is held says nothing. |
| `stale` | Each stale reference kept, `{path, code, message, stored}`, code `stale`, with the stored value it keeps: the operation form's warning, said out loud. |
| `outputs` | The declared outputs as storage reads them back after the write (`StorageAdapter::outputs()`), the tool's same answer; on a dry run, and for an operation that changes nothing, what its `prepare()` answered under an output's name; `{}` when none are declared or none was answered. |
| `contract` | On a write, the envelope rebuilt from what is stored now, its `$id` the read's, so a client re-renders without a second request; `null` otherwise. Its fingerprint is the answer's `ETag`. |
| `created` | On a write by an operation that creates, where the created thing now lives: `{resource, operation, parameters}` ([below](#where-a-created-thing-lives)); otherwise `null`. |
| `x-surface-dry-run` | `true`, on a dry run only. |

Every path is a JSON Pointer (RFC 6901): `/contact/email`, the whole
call the empty pointer, `~` and `/` inside a key written `~0` and `~1`.
The message is plain text, rendered for a person as the form renders
it, a key it depends on and a value by their labels alone; the tool
renders the same refusal for a machine, a key by its path and a value
by what is sent with its label beside it; the path and the code are the
same at both doors, so a program calling the API branches on them
(why: [decision](decisions.md#one-result-at-every-door)).

| Status | When |
| --- | --- |
| 200 | A run that wrote an update, checked a read, or was dry; with the update's new `ETag`. |
| 201 | A create written, with `Location`, the created thing's read (`/surface-api/node.type/recipe`); no `Location` when no operation addresses it (a field added to a bundle). |
| 400 | A body that is not a JSON object, or a parameter the operation needs given nowhere. |
| 403 | Access refused, at the route or by the pipeline for the whole call (code `access` or `permission`); the session's token missing. |
| 404 | A resource, an operation or an id that names nothing. |
| 405 | A method the path does not answer, with `Allow`: a `POST` on a singleton, a `PATCH` on a collection, a `DELETE` where no delete is, a write to a resource with no storage. |
| 410 | A contract 2 path ([gone](#gone)). |
| 412 | `If-Match` no longer names what is stored ([conflict](#conflict)). |
| 415 | A body of a media type the method does not take; a `PATCH`'s answer names them in `Accept-Patch`. |
| 422 | The values were refused ([refused](#refused)). |

What storage rehearsed is not served: it holds what the caller may not
see, so a dry run answers the accepted document and its verdict
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

Example 2, the harbour's deck written with `PATCH
/surface-api/registration.step2`, `If-Match` the read's `ETag`:

```json
{
  "ok": true, "committed": true, "changed": true,
  "changes": ["/title", "/venue", "/room", "/capacity"],
  "values": {"title": "Autumn meetup", "open": true, "venue": "harbour", "room": "harbour_deck", "capacity": 90},
  "violations": [], "notes": [], "stale": [], "outputs": {},
  "contract": {
    "resource": "registration.step2", "operation": "configure", "label": "Configure registration (step 2)",
    "schema": {...},
    "values": {"title": "Autumn meetup", "open": true, "venue": "harbour", "room": "harbour_deck", "capacity": 90},
    "stale": [], "notes": []
  },
  "created": null
}
```

A content type added with `POST /surface-api/node.type` as
`served_demo` answers 201, `Location:
https://example.com/surface-api/node.type/served_demo`, the create
operation's envelope as `contract`, and:

```json
"created": {"resource": "node.type", "operation": "update", "parameters": {"type": "served_demo"}}
```

The operation form's cosmetic message and redirect are its route's;
their HTTP equivalents are the status, `Location` and `created`, and the
client says the rest.

### The delete

**`DELETE /surface-api/{resource}/{id}`** runs the path's default
delete: an operation that says `#[Operation(deletes: TRUE)]` (and
`destructive: TRUE`), whose kind is `delete`, named `delete` or the
only delete there
(why: [decision](decisions.md#a-placed-block-is-a-resource)). A delete takes
no values, so the request has no body; one sent is held to JSON and each
of its keys refused, code `unknown_key`. Nothing is accepted or
validated: the storage rehearses the removal, and removes the thing,
whatever its stored values hold, so a placed block whose region its
theme no longer has can still be removed.

| Request | Means |
| --- | --- |
| `?dryRun=true` | Rehearse the removal and remove nothing; `x-surface-dry-run: true`. |
| `If-Match: "<ETag>"` | Remove only if what is stored is still what that `ETag` named; 412 otherwise. |
| `X-CSRF-Token` | The session's token, with the session cookie. |

It answers 200 with the one result: `values` the values the thing held,
`changes` `[""]`, the empty pointer, the whole thing, `committed` and
`changed` true, and `outputs` `{}`, `contract` and `created` null, with
no `ETag`: the thing is gone, and there is no envelope to rebuild. A thing
that is not there is a 404, as on any path. A delete that is not its
path's default is a custom method, `POST .../{x}:{operation}`, its
envelope `GET .../{x}:{operation}` as any other's; the placement's
`delete` is the default, `DELETE /surface-api/block.placement/{id}`.

### Problem details

Every error of the API is problem details, RFC 9457,
`application/problem+json`, never Drupal's HTML error page
(`ProblemDetailsSubscriber`): `type`, `title`, `status`, `detail`, and
for a refusal the result's entries as extension members. Three types
are the module's own, each a heading below; every other status is
`about:blank`, its title the reason phrase.

#### Refused

422, the values refused, nothing written: `errors` are the run's
violations, `{path, code, message}`, and `notes` and `stale` are the
result's. Example 2's harbour sent with the library's garden room:

```json
{
  "type": "https://git.drupalcode.org/project/surface/-/blob/1.0.x/docs/served-contract.md#refused",
  "title": "The values were refused",
  "status": 422,
  "detail": "One value was refused; nothing was written.",
  "errors": [{"path": "/room", "code": "no_such_choice", "message": "The value you selected is not a valid choice. This applies when Venue is Harbour Centre. Choose Auditorium or Upper deck."}],
  "notes": [],
  "stale": []
}
```

A refusal of the whole call has an empty path: a resource locked whole,
code `locked`, is a 422; one by access (`access`, `permission`) a 403
and one of a subject that names nothing (`not_found`) a 404, with the
same members.

#### Conflict

412, `If-Match` no longer names what is stored: one error of the whole
call, code `conflict`, "The stored values changed since this form was
loaded. Reload it to see them, then make your changes again.", and
nothing written.

#### Gone

410, a contract 2 path: `/surface-api/{resource}/{operation}` and its
`/schema`, `/refine`, `/validate` and `/submit`. `moved` names the
method and path that replace it, the old query's parameters moved into
the path:

| Contract 2 | Contract 3 |
| --- | --- |
| `GET .../registration.step2/configure` | `GET /surface-api/registration.step2` |
| `GET .../registration.step2/configure/schema` | `GET /surface-api/registration.step2/schema` |
| `POST .../registration.step2/configure/refine` | `POST /surface-api/registration.step2/schema` |
| `POST .../registration.step2/configure/validate` | `PATCH /surface-api/registration.step2?dryRun=true` |
| `POST .../registration.step2/configure/submit` | `PATCH /surface-api/registration.step2` |
| `GET .../node.type/edit?type=article` | `GET /surface-api/node.type/article` |
| `POST .../node.type/add/submit` | `POST /surface-api/node.type` |
| `GET .../event.program/check` | `GET /surface-api/event.program:check` |

```json
{
  "type": "https://git.drupalcode.org/project/surface/-/blob/1.0.x/docs/served-contract.md#gone",
  "title": "This path is gone",
  "status": 410,
  "detail": "Contract 2 addressed an operation by its path; contract 3 addresses the resource, and the method is the operation: PATCH /surface-api/registration.step2?dryRun=true.",
  "moved": {"method": "PATCH", "href": "/surface-api/registration.step2?dryRun=true"}
}
```

So a second segment that names one of the resource's operations is
read as a contract 2 path: a thing whose id is an operation's id (a
content type named `update`) cannot be addressed by its path, and
neither can one whose id is `schema`. Contract 2's paths carried the
ids of their day, so `add`, `edit` and `remove` there are read as the
operation renamed from them, `create`, `update` and `delete`, and are
gone too; a thing named one of those three is not addressable by its
path either.

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
type's `update(NodeTypeInterface $type)` is answered by the `type` the
write accepted, and its read is the `Location`. A field added at
`field.instance:create` lives at its `update(string $entity_type, string
$bundle, string $field_name)`, answered by the identity the write
accepted: `Location: /surface-api/field.instance/node/article/field_x`.
Example 2's `configure` creates nothing, so `null`.

### If-Match

Optional, and off unless a client sends it. The read's `ETag` is a
fingerprint, an HMAC (the site's hash salt) of the resource's id and
the stored values `load()` returned, narrowed to the data surface's keys
(`ServedOperations::fingerprint()`). A write may send it back as
`If-Match`. When it is sent and no longer matches what storage holds
when the write arrives, the write is refused before the pipeline runs,
412 [conflict](#conflict), with nothing written. When it is not sent,
the last write wins, as on the operation form
(why: [decision](decisions.md#a-fingerprint-is-opt-in)). A tag is compared
by its opaque value, weak or strong: a compressing proxy weakens the tag
it passes on, and the tag names the stored values, which compression
does not change. A narrowing never carries one: it re-reads storage. A
write's answer carries the new one. It is a check, not a lock: a write
between the comparison and the commit, a few milliseconds, still wins.
Contract 2 sent it as the body's `fingerprint`.

### Cacheability

The envelope carries what the refined data surface and every option list
in it depend on, those an [enumerated](#exact-where-it-can-be)
conditional was read from included, the access answer's own, and
`url.query_args`, but is never stored: the values in it come from
storage, and storage says nothing about how long what it loaded holds
(why: [decision](decisions.md#a-served-contract-is-never-stored)). It is
answered `Cache-Control: no-store, private`, which also keeps its `ETag`
(core strips the tag from a response it marks uncacheable itself). The
full schema carries the same metadata, and
`languages:language_interface` for its labels, but is not max-age 0: no
stored value is in it, so what it says it depends on is all it depends
on. With `?choose=`, the same: the query string is one of its cache
contexts, and the provider's tags are among what it depends on, so it
holds until the site's list changes. Both carry an `ETag` of their
bytes, set after core's response subscriber, which would otherwise drop
a controller's tag from a response it may not cache for a session, and
`If-None-Match` is answered 304 (`SchemaTagSubscriber`). Its access is the account's: it varies by what the static answers
read (`user.permissions`, `user.roles`) and by the cache contexts of
the runtime answers it set aside, which chose their kind, and never by
their tags or max-age, since it states none of their answers. An option list varies by what it read: a reference's targets by
`user.permissions`, and by `user.node_grants:view` for a node or a
comment, whose selection node grants narrow, so a cached schema is never
another account's labels
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## An OpenAPI document

`GET /surface-api/openapi.json`
(and `/surface-api/openapi.yaml`, `application/yaml`) answers an
OpenAPI 3.1 document of the API above, for every operation that can be
asked on its own, so an OpenAPI client, a code generator or a UI drives
a resource from it. It is the base module's own, on a route of its own,
not the contributed `openapi` module's, and needs nothing contributed
(why: [decision](decisions.md#an-openapi-document-is-one-more-door)).
OpenAPI 3.1, because its schema objects are JSON Schema 2020-12, the
dialect both documents are written in. `GET /surface-api/docs` renders
Swagger UI over it, whose "try it out" calls the API with the logged-in
session, sending every `POST`, `PATCH` and `DELETE` with the token
`/session/token` answers and, as `If-Match`, the last `ETag` it saw
for the path, so a write after someone else's is refused (412);
`GET /surface-api/redoc` renders Redoc, to read. Both pages need the
permission `access surface api docs`: each runs a script from a CDN
on the site (Swagger UI 5.29.1 from cdnjs, Redoc 2.5.1 from jsDelivr,
each pinned and held to its integrity hash in `surface.libraries.yml`),
so a site grants them to the roles it chooses. The document is open.

- **Paths.** One path item per resource path, the methods on it as
  [the API](#the-api) answers them: `/surface-api/first.newsletter` with
  `get` and `patch`, `/surface-api/node.type` with `get` and `post` and
  `/surface-api/node.type/{type}` with `get` and `patch`, each with a
  `/schema` path item, `get` and `post`, its `get` taking `choose`, a
  query parameter repeated once per choice (`style: form`, `explode`),
  answering 304 to `If-None-Match` and the `ETag` of its bytes
  ([the fetch per choice](#the-fetch-per-choice)); a custom method is a path item
  of its own, `/surface-api/event.program:check`, `get` and `post`. A
  default delete is `delete` on the thing's path item,
  `/surface-api/block.placement/{id}` with `get`, `patch` and `delete`,
  with no request body and no `ETag` in its answer; its envelope is its
  custom method's, `/surface-api/block.placement/{id}:delete`, `get`. A
  listing is `get` on the collection's path item, `/surface-api/event.venue`,
  its values query parameters, each with its schema, and its answer the
  outputs' component; its envelope is its custom method's,
  `/surface-api/event.venue:list`, `get` and `post`. An update over the
  collection is a custom method run with `patch`,
  `/surface-api/event.venue:reorder`. The lookup is a path item of its
  own where the operation `GET` serves has a key that refers to an
  entity, `/surface-api/event.sponsorship:lookup`, `get`, its `key` an
  enum of those keys' pointers. An
  identity is a path parameter, an operation's other parameters query
  parameters. Each call is tagged with the resource's id. The summary
  is the operation's label and the description its description; how it
  behaves is `x-surface-kind` on every call, and on its run
  `x-surface-idempotent`, `x-surface-destructive` and
  `x-surface-creates`. `dryRun` is a query parameter; `If-Match`,
  `X-Surface-Stale` and `X-CSRF-Token` header parameters; the read
  answers `ETag` and `Link`, a written create `Location`. Every answer
  carries the `Contract-Version` header; every error is `ProblemDetails`.
- **Components.** Each operation's full schema, as `emitFull()` writes
  it with no widget hints and no `$id`: each def under `$defs` a
  component named by its def id, the root the operation's values
  component (`first.newsletter.configure`), each def pointer, in a
  `$ref` or `x-surface.variants`, pointing at the component, and every
  other `x-surface` keyword where it was. A create's body takes the
  values component; any other's its patch
  (`first.newsletter.configure.patch`), the same with every `required`
  that can be dropped without narrowing dropped, since a key left out
  keeps what is stored. A def the patch reaches has a patch of its own
  only where it differs, a key left out of an attached child or a
  chosen variant keeping what is stored too. Declared outputs are a
  component of their own (`<values>.outputs`). Beside them, `Contract`
  (the envelope), `FullSchema`, `NarrowRequest`, `Result`,
  `ResultEntry` (`{path, code, message}`, the path a JSON Pointer),
  `StaleEntry`, `SubmitResult`, `Created` and `ProblemDetails`.
- **The resource in the answers.** Each answer is its shared envelope
  composed with the operation's components, so a UI shows the resource
  under every call: `values` in the envelope, the narrowing and the
  result is the values component or any object, and `schema` and
  `outputs` are objects whose example is the component. The `/schema`
  answer's example is the full schema built for no account.
- **For no account.** Built as the tool definitions are, every key as
  declared and each one an access answer could lock or leave out marked
  `x-surface.live`, so it is the same for everyone and
  cached until discovery, a build it was made from, the site's name, the
  modules or the anonymous role change; an operation that addresses one thing is built knowing no
  subject, as its tool's listing is. The read and `/schema` answer for
  the account that asks; the read's `x-surface-schema` names the
  component its schema was built from for no account.
- **Security.** The session cookie, and basic auth with `basic_auth`
  on; an operation whose permission the anonymous role holds requires
  neither. `x-drupal-permission` names the permission.
- **Examples.** A run's request example is the operation's declared
  defaults, as the pipeline accepts them with nothing stored.

`drush surface:catalog` names, per operation, the OpenAPI operation
that runs it (`<resource>:<operation>:write`, a listing's `:list`),
beside its other doors ([The site's catalog](catalog-command.md)).
`OpenApiDocumentTest` holds the document to the official OpenAPI 3.1
schema in `tests/fixtures/openapi/`, every reference resolving, the
components byte for byte the full schemas and outputs, and the examples
pinned in `tests/fixtures/openapi/examples.json`; `SchemaComponentsTest`
the components, their sharing and patches; `OpenApiEndpointTest` the
same document for an account and for anonymous, as JSON and as YAML,
and the two pages under their permission, each script pinned with its
integrity hash.

## A drawing client that narrows locally

The schema for these answers is still what a drawing client loads first:
it carries the values and the stale paths, its `ETag` the fingerprint, and it is
refined by the runtime refiners for those values. Beside it, once, the
client may fetch the full schema, and from then on answer most changes
itself. The rule, as the React example (`surface_examples_react`)
applies it:

- **Narrow from the full schema.** On a change of a key, collect every
  key that depends on it, transitively, through `dependsOn` in the frame
  they share (the top, or a child's, by path). When none of them is
  marked `refine` or `live`, nor holds one inside it, read each
  key's schema for the new values from the full schema: as declared,
  with the `then` of every conditional on its frame whose `if` the values
  meet once every key it watches holds a value (a declared rule's, which
  the frame's `rules` lists, whatever they hold), and with its
  conditionals, held to what those keys still offer, while one holds
  nothing. A key with variants is its chosen variant's def; a part is its def,
  described for its values.
- **Drop what does not apply.** A conditional whose `if` the values meet
  and whose `then` says `false` for a key leaves the key out of the form,
  of what is sent and of `required`; one that says `required` requires
  it. A key that did not apply when the page was loaded, and applies
  now, holds what is stored, which the page was never handed, so the
  page asks `POST .../schema` for it.
- **Settle as the server does.** The answer must be the server's, so the
  client mirrors the [discard rule](#the-read): an answer the new
  choices orphan is dropped and falls back to what is stored; a stored
  value its moved dependency no longer offers is held unanswered, shown
  on the empty option, and listed in `stale`; and the reading of a select
  (a value no longer offered shown as the empty option; why:
  [decision](decisions.md#the-empty-option-rule)) and of a key holding nothing (its
  default) is the emitter's. A number above a lowered maximum is kept,
  as the server keeps it, and the pipeline refuses it on submit.
- **Fetch when a dependent is `deferred`.** A key left out for its size
  is stated by the full schema fetched with the value chosen: the client
  fills the key's `fetch` with its deciding key's value, joins the
  choices of every deferred key the change reaches into one URL (the
  props, then the slots the props' answer still leaves out), keeps each
  answer for the page's life, and narrows from it, then from it for the
  changes after. With nothing chosen the key is open, as the server
  answers it; a map of keys from the site, which has no deciding key,
  and a fetch that fails, are asked as `refine` is
  (why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
- **Ask when a dependent is `refine` or `live`.** Its rule is not in
  the full schema, so the client posts the values to `.../schema`. It also
  asks for anything it cannot settle: a stale value it was handed as
  `null`, whose stored value only the server knows; a reference left
  unresolved; a conditional naming a key outside its frame. A key that
  refers to an entity past the limit is no list to narrow: the client
  asks the lookup for candidates as text is typed.
- **A dry run and a write always ask.** The pipeline has the last word,
  and the full schema is never stricter than it, but not always as
  strict.

Example 2 narrows every change itself. Example 3 does too; with example
4's module on, a change of the venue asks (the room is `live`, for
the closed rooms), and so does the license and the room: the capacity is
stated exactly, its license rule declared, but the stewards that follow
it, through the compliance structure's port, are `refine`, reading a
number. The pricing is still narrowed
here: the ticket's variants are stated whole. Example 12 past the site
list limit fetches: choosing the hero fetches
`.../schema?choose=/props=surface_examples%3Ahero`, then with
`&choose=/slots=surface_examples%3Ahero` joined, and asks no
narrowing; choosing it again fetches nothing.

## Asking again at the tool door

The write tool never spells out variants the site lists, whatever the
site list limit says: the limit governs the two documents above and
nothing at the tool door. A tool's definition is sent with every request
that offers the tool, and the Tool API cannot say which keys a sibling
chooses, so spelled out, every variant's keys would be one map with none
required: 1.6 MB for example 12 on a site with 312 components
(why: [decision](decisions.md#a-budget-for-what-the-site-decides)). The
key states the keys of the variant the stored values choose and no
other, where the listing reads what is stored (an operation that needs
nothing to start from, or the refined `values` once the parameters
arrive), and none where nothing chooses one, and its description names
both ways to learn the keys of any other: "Its keys depend on component:
listed here are those of surface_examples:card, the component
stored. For another component, fetch GET
/surface-api/event.spotlight/schema?choose=/props={component} once per
component, or call surface:event.spotlight:configure:schema with
your values, to get this key's schema." So changing a prop of the stored
component is one call. A map of keys from the site past the limit is
listed open too, its description naming the fetch of an entry and the
narrowing tool. The fetch is on the HTTP door, and answers
[the fetch per choice](#the-fetch-per-choice), which an agent with an
HTTP client keeps per component; under the limit it answers the full
schema, which states every variant already. The narrowing tool is the
tool door's `POST .../schema`, derived beside the write tool wherever
the operation's listing leaves such a key out
(why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)):

| | |
| --- | --- |
| Id | `surface:<resource>:<operation>:schema` |
| Kind | Read: read only and idempotent, never destructive |
| Inputs | The operation's parameters, as the write tool declares them, and `values`, optional and open: what the caller has so far |
| Outputs | `schema`, the schema for these answers with no widget hints, and `values`, the values settled so far, computed keys and defaults included |
| Access | The write tool's, for the same parameters; the answer is built for the account that calls it |

The answer is what `POST .../schema` answers for the same values and
account: the values sent, narrowed to the resource's keys, laid over
what the operation opens with, an orphaned answer dropped, and the
schema emitted for that. So an agent makes two calls, the narrowing
then the write:

```bash
drush tool:run surface:event.spotlight:configure:schema --uid=1 --input='{"values":{"component":"surface_examples:call_to_action"}}'
drush tool:run surface:event.spotlight:configure --uid=1 --input='{"values":{"component":"surface_examples:call_to_action","props":{"text":"Buy a ticket","url":"https://example.com/tickets"}}}' --input=dry_run=true
```

The first answers the call to action's props under
`schema.$defs["event.spotlight.props.surface_examples:call_to_action"]`
(its text, address, style, window and tags, the text and the address
required) and the values it would start from; the second sends them.
A choice changed asks again. The tool is no HTTP path, so the OpenAPI
document has none for it: the HTTP narrowing is already there.

## What a write needs

What this list asked for before Submit was wired, and where each is now:

- A write running `submit()` without `dry_run`, the same body and the
  same stale rule as the dry run. **Done**; the cosmetic message and
  redirect have HTTP equivalents in the status, `Location` and
  `created`, and the app says "Saved" itself.
- Its stale references said out loud, as the form's warning is.
  **Done**: `stale` on every answer.
- An operation that creates (`create`) answering where the created thing
  now lives, so the app can move to its `update` operation's page. **Done**
  for any resource whose non-creating operation's parameters the outputs
  or identity answer: the content type, and since its edit takes the
  field by its parts, the field instance
  ([above](#where-a-created-thing-lives)).
- A decision on concurrent edits. **Done**: the opt-in
  [`If-Match`](#if-match); without it the last write wins, as on the
  form.
