# The served contract

A surface built in one of its situations, served as a JSON document a
client with no PHP can render a form from, re-narrow as answers change,
validate against before anything is written, and then write through. The
emitter is the main module's; the four endpoints and a React app that
renders from them are the experimental submodule `data_surface_react`.

This is the roadmap's "served contract" in its first shape: one
situation at a time, read, rehearsed and written.

## Where the emitter lives

`Drupal\data_surface\Contract\ContractEmitter`, the service
`data_surface.contract_emitter`, is the module's canonical contract: it
reads any sealed surface and nothing else, and `emit()` answers a
`ServedContract` (the document, and its cacheability beside it). The
contract names no widget. `x-surface.widget` (and `multiple`, its
qualifier on a list of allowed values) is presentation, the Form API
mapping said out loud for a renderer, and the roadmap rejects widget
vocabulary in the contract, so it is written only when a caller asks:
`emit(..., widgets: TRUE)`. `data_surface_react` is that caller; its
endpoints serve the contract with the hints, for its app to draw from.
Everything else under `x-surface` is the surface's own reading of a key
and is always there.

It is this module's own emitter, not the Tool API's: the Tool API's
context definitions have no place for labelled values, a conditional on
a sibling, or anything the form knows about a key, so the tool bridge's
JSON Schema cannot say them. This one can.

## Where to see it

- **Under a situation form.** `data_surface_tool`'s contract panel,
  which the examples' routes name, shows it collapsed as "The contract,
  as JSON Schema", emitted for the situation and the form's values as
  they stand, so it rebuilds with the form. Below it, "What the Tool
  API can advertise" shows the derived tool's schema for comparison.
- **Over HTTP.** `GET /surface-api/{surface}/{situation}` with
  `data_surface_react` enabled: the whole document, widget hints
  included.
- **Not `drush tool:info`.** That prints the Tool API's rendering of the
  derived tool, which cannot say a shape keyed by a sibling: a slot is
  flattened to one map, the variant the stored values choose, or every
  variant's keys with none required before anything chooses
  ([surfaces](surfaces.md), "Emission"). The `allOf` of `if`/`then`
  below is only in this contract.

## The document

| Key | Holds |
| --- | --- |
| `surface` | The `#[Surface]` id. |
| `situation` | The situation id, or `null` for a surface asked for in no situation. |
| `label` | The situation's label. |
| `schema` | JSON Schema 2020-12 for the inputs, `x-surface` on every property. |
| `values` | The values the schema describes now: what the target holds over the surface's defaults (which carry a situation's starting values and known identity), with any in-progress answers over that. Every key is present. A secret is `null`; a stale value is `null`. |
| `stale` | The dotted paths shown on the empty option in place of a stored value. |
| `outputs` | The output schema, when the surface declares outputs. |
| `fingerprint` | On the GET and in a submit's answer: a keyed hash of the stored values the document was built from, for a submit to send back ([the fingerprint rule](#the-fingerprint-rule)); `null` for a surface with no target. Not on a refine. |
| `discarded` | On a refine only: the keys whose answer the new choices orphaned. |

### The schema

Each surface frame — the top level, an attached part, a slot's variant —
is an object with `properties` in declaration order, `required`, and
`additionalProperties: false`.

| The surface says | The schema says |
| --- | --- |
| label, description | `title`, `description` |
| `string`, `email`, `uri`, `integer`, `float`, `boolean` | `type` (`format: email` / `uri` for those types); an optional key also allows `null` |
| `setRequired(TRUE)` | the key in `required`. The surface's meaning: it must hold a value. The contract's `values` always carry every key, so presence is never the question. |
| a declared default, examples | `default`, `examples` |
| `Range` | `minimum`, `maximum` |
| `Length` | `minLength`, `maxLength` |
| `Count` | `minItems`, `maxItems` |
| `Regex` | `pattern` (`not: {pattern}` for `match: false`); a modifier ECMA has no word for leaves it to the server |
| `Email` | `format: email` |
| `NotBlank` | `minLength: 1` |
| any list of allowed values | `oneOf: [{const, title, description?}]`, read from the options service, so the labels travel; never `enum`. An optional key adds an untitled `{const: null}`. |
| a map, an attached part | a nested object |
| a list | `type: array`, `items` |
| a locked key | `readOnly: true` and `const` of the value the situation knows |
| a secret | `writeOnly: true`, no default, no value |
| any other constraint | named in `x-surface.checkedOnServer`: the schema is not the whole story, ask `/validate` |

### `x-surface`

| Keyword | Holds |
| --- | --- |
| `widget` | Only when the caller asks for widget hints (`data_surface_react` does). The Form API-equivalent hint: `select`, `radios`, `checkbox`, `number`, `text`, `textarea`, `email`, `fieldset`, `slot`, `list`; `null` when no widget would claim the key. |
| `locked` | Identity the situation knows: shown, never changed. |
| `dependsOn` | The sibling keys this key's refiners watch, in its own frame; a key an alter mounted, which only that alter may watch, by its dotted path in that frame (`third_party_settings.<module>.<key>`). A client refines when one of them changes. |
| `refined` | Whether the key is narrowed right now, compared with what the surface advertises in this situation. |
| `stale` | The stored value is no longer offered; the key is shown on its empty option, standing for it. |
| `emptyOption` | On a single select: `{show, label}`, by [the empty option rule](decisions.md#the-empty-option-rule): always for an optional select (`- None -`), for a required one only while no valid choice is selected (`- Select -`). |
| `multiple` | With the widget hints, on a list of allowed values: a multiple select. |
| `by`, `variants`, `chosen` | On a slot: the deciding key, the values that choose a variant, and the one chosen now. |
| `variant` | On a slot's variant schema: which value it is for. |
| `checkedOnServer` | Constraints no keyword states. |

### A slot is a conditional

A slot's own property fixes no shape. Each variant is a `then` on the
parent, under an `if` naming the deciding key's value as a `const`, so a
validator applies exactly the chosen variant's rules and a renderer reads
the shape the current value selects. There is no OpenAPI `discriminator`
beside it: OpenAPI's names a property inside the object whose
alternatives it picks, and a slot's deciding key is a sibling of it
([decision](decisions.md#no-openapi-discriminator-on-a-slot)). Example
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
   "then": {"properties": {"ticket": {"title": "Ticket", "type": "object",
     "properties": {"note": {"title": "Note", "type": ["string", "null"], ...}},
     "additionalProperties": false, "x-surface": {"widget": "fieldset", "variant": "free", ...}}}}},
  {"if": {"properties": {"pricing": {"const": "paid"}}, "required": ["pricing"]},
   "then": {"properties": {"ticket": {"title": "Ticket", "type": "object",
     "properties": {
       "price": {"title": "Price", "type": "number", "minimum": 0.01, ...},
       "currency": {"title": "Currency", "type": "string", "default": "EUR",
                    "oneOf": [{"const": "EUR", "title": "EUR"}, {"const": "GBP", "title": "GBP"}, {"const": "USD", "title": "USD"}], ...}},
     "required": ["price", "currency"], "additionalProperties": false, ...}}}}
]
```

### Example 2, abbreviated

The venue narrows the room and the room the capacity, for the stored
library and its reading room, as `/surface-api` serves it:

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
               "oneOf": [{"const": "library_reading", "title": "Reading room"}, {"const": "library_garden", "title": "Garden room"}],
               "x-surface": {"widget": "select", "dependsOn": ["venue"], "refined": true, "stale": false, ...}},
      "capacity": {"title": "Capacity", "description": "Up to 60 for the Reading room.", "type": ["integer", "null"],
                   "minimum": 1, "maximum": 60, "default": 50,
                   "x-surface": {"widget": "number", "dependsOn": ["room"], "refined": true, ...}}
    },
    "additionalProperties": false,
    "required": ["title", "venue", "room"]
  },
  "values": {"title": "Spring meetup", "open": true, "venue": "library", "room": "library_reading", "capacity": 50},
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
| a slot | the chosen variant's `details`; nothing while unresolved | `slot` | `SlotField` |
| `string` with the `multiline` setting | `textarea`, no maxlength, no placeholder | `textarea` | `TextareaField` |
| `email` | `email` | `email` | `TextField` (type email) |
| `string`, `uri` | `textfield`, `url` | `text` | `TextField` (type text, url; password for a secret) |
| `integer`, `float` | `number`, `#min`/`#max` from Range, `#step` 1 or any | `number` | `NumberField` |
| `boolean` | `checkbox`, never `#required` | `checkbox` | `CheckboxField` |
| a list with no allowed values | no stock widget | `list` | `ListField` (add and remove rows) |
| a locked key | `#disabled`, "Fixed for this operation." | `locked: true` | disabled, the same note |

## The endpoints

All five routes take the situation's parameters from the query string
(and a POST body's `parameters`), and are gated by
`_data_surface_react_access`: the situation's permission, then the
surface's access class, through `Surfaces::access()`, with no opinion
read as a refusal. A surface, situation or parameter that names nothing
is refused. The POSTs require `X-CSRF-Token` from `/session/token`
(`_csrf_request_header_token`). A POST body that is not a JSON object is
a 400: the access check reads the situation from the query string alone
when it cannot read the body, and the controller refuses the body.

**`GET /surface-api/{surface}/{situation}`** answers the document for
the values the situation form would open with, and their `fingerprint`.

**`POST .../refine`**, body `{values, stale}`, answers the document
re-narrowed against the values, which is what the form's AJAX rebuild
computes, the same way:

1. The values are narrowed to the surface's keys and overlaid on what
   the situation opens with.
2. A path in `stale` whose value comes back empty stands for the stored
   value again: the served form of the marker the situation form's
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
`accept()` made of the input; `prepared` what the target rehearsed
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
surface with no target. Every key is always present:

| Key | Holds |
| --- | --- |
| `committed` | Whether the values were written. |
| `valid` | Whether nothing was refused; on this endpoint the same answer as `committed`. |
| `violations` | Each refusal, `{path, message}`, as validate's. |
| `stale` | Each stale reference the write kept, `{path, message}`: the situation form's warning, said out loud. Present on a write as on a refusal. |
| `outputs` | The surface's declared outputs as its target reads them back after the write (`SurfaceTargetAdapter::outputs()`), the tool's same answer; `{}` when the surface declares none, as the examples, the content type and the field do. |
| `contract` | On a write, the whole document rebuilt from what is stored now, `fingerprint` included, so a client re-renders without a second request; `null` on a refusal. |
| `created` | On a write by a situation that creates, where the created thing now lives: `{surface, situation, parameters}`; otherwise `null`. |

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
same, with the add situation's empty contract, and:

```json
"created": {"surface": "node.type", "situation": "edit", "parameters": {"type": "served_demo"}}
```

The situation form's cosmetic message and redirect are its route's, and
the API has no route default to read them from; their JSON equivalents
are `committed` and `created`, and the client says the rest.

### Where a created thing lives

`ServedSituations::created()` answers only for a situation whose
context `creates`. It takes the surface's situations in declaration
order and picks the first that does not create and whose every required
parameter is supplied, by name, from the outputs the target read back,
then from the identity keys the write accepted, then from the identity
the creating situation already knew. The answer is checked, not
assumed: the situation is built from those parameters (an entity
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
`fingerprint`, an HMAC (the site's hash salt) of the surface id and the
stored values `load()` returned, narrowed to the surface's keys
(`ServedSituations::fingerprint()`). A submit body may send it back.
When it is sent and no longer matches what storage holds when the
submit arrives, the submit is refused before the pipeline runs:
`committed: false` and one violation at path `''`, "The stored values
changed since this form was loaded. Reload it to see them, then make
your changes again.", with nothing written. When it is not sent (absent
or `null`), the last write wins, as on the situation form
([decision](decisions.md#a-fingerprint-is-opt-in)). A refine never
carries one: it re-reads storage, so a client that took its fingerprint
would always match. A write's answer carries the new one, for the next
submit. It is a check, not a lock: a write between the comparison and
the commit, a few milliseconds, still wins.

### Cacheability

The contract carries what the refined surface and every option list in
it depend on, the access answer's own, and `url.query_args`, as HTTP
cache metadata on a `CacheableJsonResponse`. It is never stored: the
values in it come from a target, and a target says nothing about how
long what it loaded holds ([decision](decisions.md#a-served-contract-is-never-stored)).

## What a write needs

What this list asked for before Submit was wired, and where each is now:

- `POST .../submit` running `submit()` without `dry_run`, the same body
  and the same stale rule as validate. **Done**; the cosmetic message
  and redirect have JSON equivalents in `committed` and `created`, and
  the app says "Saved" itself.
- Its stale references said out loud, as the form's warning is.
  **Done**: `stale` on every answer.
- A situation that creates (`add`) answering where the created thing now
  lives, so the app can move to its `edit` situation's page. **Done**
  for any surface whose non-creating situation's parameters the outputs
  or identity answer (the content type); not for the field instance,
  [above](#where-a-created-thing-lives).
- A decision on concurrent edits. **Done**: the opt-in
  [fingerprint](#the-fingerprint-rule); without it the last write wins,
  as on the form.
