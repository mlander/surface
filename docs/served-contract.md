# The served contract

A surface built in one of its situations, served as a JSON document a
client with no PHP can render a form from, re-narrow as answers change,
and validate against before anything is written. The emitter, the three
endpoints and a React app that renders from them are the experimental
submodule `data_surface_react`.

This is the roadmap's "served contract" in its first shape: one
situation at a time, read and rehearsed, not yet written.

## Where the emitter lives

`Drupal\data_surface_react\ContractEmitter` (`data_surface_react.contract_emitter`)
reads any sealed surface and nothing else, so it could live in the main
module. It lives in the submodule because of one keyword: `x-surface.widget`
is presentation, the Form API mapping said out loud for a renderer, and
the roadmap rejects widget vocabulary in *the* contract. A widget-free
emitter in the main module is roadmap item 4; this one is the React
renderer's.

It is this module's own emitter, not the Tool API's: the Tool API's
context definitions have no place for labelled values, a conditional on
a sibling, or anything the form knows about a key, so the tool bridge's
JSON Schema cannot say them. This one can.

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
| `widget` | The Form API-equivalent hint: `select`, `radios`, `checkbox`, `number`, `text`, `textarea`, `email`, `fieldset`, `slot`, `list`; `null` when no widget would claim the key. |
| `locked` | Identity the situation knows: shown, never changed. |
| `dependsOn` | The sibling keys this key's refiners watch, in its own frame. A client refines when one of them changes. |
| `refined` | Whether the key is narrowed right now, compared with what the surface advertises in this situation. |
| `stale` | The stored value is no longer offered; the key is shown on its empty option, standing for it. |
| `emptyOption` | On a single select: `{show, label}`, by [the empty option rule](decisions.md#the-empty-option-rule): always for an optional select (`- None -`), for a required one only while no valid choice is selected (`- Select -`). |
| `multiple` | On a list of allowed values: a multiple select. |
| `by`, `variants`, `chosen` | On a slot: the deciding key, the values that choose a variant, and the one chosen now. |
| `variant` | On a slot's variant schema: which value it is for. |
| `checkedOnServer` | Constraints no keyword states. |

### A slot is a conditional

A slot's own property fixes no shape. Each variant is a `then` on the
parent, under an `if` naming the deciding key's value as a `const`, so a
validator applies exactly the chosen variant's rules and a renderer reads
the shape the current value selects. Example 3's ticket, abbreviated:

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
library and its reading room:

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
      "capacity": {"title": "Capacity", "type": ["integer", "null"], "minimum": 1, "maximum": 60, "default": 50,
                   "x-surface": {"widget": "number", "dependsOn": ["room"], "refined": true, ...}},
      "open": {"title": "Registration open", "type": ["boolean", "null"], "default": true, ...},
      "venue": {"title": "Venue", "type": "string",
                "oneOf": [{"const": "riverside", "title": "Riverside Hall"}, {"const": "library", "title": "Old Library"}, {"const": "harbour", "title": "Harbour Centre"}],
                "x-surface": {"widget": "select", "dependsOn": [], "emptyOption": {"show": false, "label": "- Select -"}, ...}},
      "room": {"title": "Room", "type": "string",
               "oneOf": [{"const": "library_reading", "title": "Reading room"}, {"const": "library_garden", "title": "Garden room"}],
               "x-surface": {"widget": "select", "dependsOn": ["venue"], "refined": true, "stale": false, ...}}
    },
    "additionalProperties": false,
    "required": ["title", "venue", "room"]
  },
  "values": {"title": "Spring meetup", "capacity": 50, "open": true, "venue": "library", "room": "library_reading"},
  "stale": []
}
```

## The widget mapping

What the emitter's `widget` mirrors is `DataSurfaceWidgetManager`'s
choice, in its weight order ([Widgets](widgets.md)).

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

All four routes take the situation's parameters from the query string
(and a POST body's `parameters`), and are gated by
`_data_surface_react_access`: the situation's permission, then the
surface's access class, through `Surfaces::access()`, with no opinion
read as a refusal. A surface, situation or parameter that names nothing
is refused. The POSTs require `X-CSRF-Token` from `/session/token`
(`_csrf_request_header_token`).

**`GET /surface-api/{surface}/{situation}`** answers the document for
the values the situation form would open with.

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
   ["room"]`, `discarded: ["room"]`; putting the venue back, with the
   stale path sent, shows the reading room chosen again.

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

### Cacheability

The contract carries what the refined surface and every option list in
it depend on, the access answer's own, and `url.query_args`, as HTTP
cache metadata on a `CacheableJsonResponse`. It is never stored: the
values in it come from a target, and a target says nothing about how
long what it loaded holds ([decision](decisions.md#a-served-contract-is-never-stored)).

## What a write needs

Submit is present and disabled. Wiring it is a fourth endpoint and
little else, because every piece already exists:

- `POST .../submit` running `submit()` without `dry_run`, the same body
  and the same stale rule as validate; on success the situation
  form's cosmetic message and redirect, or their JSON equivalents.
- Its stale references said out loud, as the form's warning is.
- A situation that creates (`add`) answering where the created thing now
  lives, so the app can move to its `edit` situation's page.
- A decision on concurrent edits: today the last write wins, as on the
  form.
