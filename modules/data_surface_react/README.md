# Data Surface - React

A resource's form, rendered in React from a contract the module serves.
One operation of one resource becomes five endpoints and a page:

| | Path | What it does |
| --- | --- | --- |
| Page | `GET /surface-react/{resource}/{operation}` | Mounts the React app, titled with the operation's label. |
| Contract | `GET /surface-api/{resource}/{operation}` | The JSON contract with the values the form opens with, and the stored values' `fingerprint`. |
| Full schema | `GET /surface-api/{resource}/{operation}/schema` | The contract for any values: every listable dependency a conditional, the rest `refine` or `live`; no values. Cacheable. |
| Refine | `POST /surface-api/{resource}/{operation}/refine` | The contract re-narrowed against in-progress values: the AJAX rebuild's equivalent. |
| Validate | `POST /surface-api/{resource}/{operation}/validate` | The pipeline's dry run, in the one result shape every door answers: `{ok, committed, changed, changes, values, violations, notes, stale}`, each entry `{path, code, message}`, the message read as the form says it (the tool's names keys by path), the values as the read tool would answer them, nothing written. |
| Submit | `POST /surface-api/{resource}/{operation}/submit` | The write: the same shape, then `outputs`, `contract` and `created`; a refusal is 200 with `ok: false` and `committed: false`. |

`{resource}` is the `#[Resource]` id (`registration.step2`), `{operation}`
the operation id (`configure`). An operation's parameters arrive in the
query string by the operation method's parameter names, or in a POST
body's `parameters`: `/surface-react/node.type/edit?type=article`.
Access on all six is the operation's: its permission, then the
resource's access class through `Resources::access()`, no opinion read as
a refusal. The permission is asked first, before a parameter is read,
and a refusal past it gives one fixed reason, so it never says whether
an id exists
([decision](../../docs/decisions.md#every-door-projects-what-the-read-tool-projects)).
The three POSTs want the session's CSRF token from
`/session/token` in an `X-CSRF-Token` header. A submit may send back the
contract's `fingerprint`; when it is sent and storage changed since, the
submit is refused and nothing is written.

The contract is the main module's: this submodule asks
`data_surface.contract_emitter` for it with the widget hints
(`x-surface.widget`) its app draws from, which the canonical contract
leaves out. The contract format, the endpoints' bodies and the widget
mapping are in [the served contract](../../docs/served-contract.md).

The app reads contract 2 and no other. The PHP side states the version
twice: in `x-surface.contract` at the top of each document's `schema`
(the contract, the full schema, a refine's answer and a write's
`contract`), and in the `Contract-Version` header of every answer of the
five endpoints that is not an error. The app refuses an answer whose header names another
version, and a document whose `x-surface.contract` is not 2 (a document
that states none is of contract 1); the form shows the refusal as its
error ("This page reads contract 2; the server sent contract 1.") and
draws nothing from it. A missing header is no refusal by itself: the
document is checked all the same. In contract 2 the documents name the
`resource` and the `operation` (as do a create's `created` and the
page's `drupalSettings.dataSurfaceReact`), and carry `$id`, the URL they
are served at.

```bash
drush pm:install data_surface_react
```

With `data_surface_examples` enabled, the landing page links each of
examples 1 to 3 "In React": `/surface-react/registration.step1/configure`
and so on.

## What it does and does not

- **Resolves** the contract's references before drawing anything. The
  emitter writes each part (an attached structure, a variant) once,
  under the schema's `$defs`, and refers to it where it is used, with
  what that place says beside the reference, or composed with a
  narrowing (`allOf: [{$ref}, {narrowing}]`) where it says more
  ([reusable subschemas](../../docs/served-contract.md#reusable-subschemas)).
  `inlineSchema()` in `app/src/contract.ts` turns that into one inline
  schema. First it puts the markers back in place (`restored()`): no
  schema below an applicator (`allOf`, `anyOf`, `if`, `then` and the
  rest) carries `x-surface` on the wire, so the object that owns them
  says it, in `x-surface.within` (a JSON pointer from itself to the
  `x-surface` of the schema there) and `x-surface.rules` (the indexes of
  its declared rules' conditionals). Then a local `$ref` (`#/$defs/...`)
  is replaced by its def, less the def's own `title`, which is its id and
  not a label; the keywords beside it are merged over, and a narrowing is
  applied by taking its value for every keyword it states (`properties`
  one by one, `required` as a union). A key with variants loses its
  `anyOf`, the union of its variants' defs, which the conditionals state
  one by one. The form resolves once per contract, on
  load and after each refine or write, and every reader (the fields, a
  chosen variant, the keys a refine watches, where a refusal is
  shown, the panel's rows) reads the result. The panel's JSON is the
  document as served. The main module's
  `Contract\SchemaResolver` is the same resolver in PHP.
- **Renders** one component per `x-surface.widget`, mirroring the Form
  API mapping: select and radios with the empty option rule, checkboxes
  and a multiple select for a list of choices, checkbox, number with
  its bounds and step, text with its maxlength, a time input, textarea,
  email, a
  fieldset for a map or an attached part (none around the modules'
  third-party settings, `x-surface.group`, each module's map being its
  own, as on the form; a module's map carrying `x-surface.after` is
  drawn right after that sibling of the group instead, its values still
  sent under the group), the chosen variant's fieldset for variants
  (the widget hint `variants`, locked with that key; nothing while none
  is chosen), and rows to
  add and remove for a list with no option list, as the list widget
  draws them. Locked keys
  are disabled and say why; a stale
  value shows on the empty option and stands for the stored value,
  which stays on the server.
- **Narrows here, or asks.** On load it fetches the contract and the full
  schema together, once; the full schema reads no value, so it is never
  fetched again. On a change to any key something depends on, it
  collects every key that depends on it, transitively. When none is
  marked `refine` (a static rule the full schema could not list, which
  `/refine` runs for values) or `live` (a rule only the server applies),
  the form narrows itself from the full schema's conditionals, a declared
  rule's read by its own `if` and tightening the key by its `then` (the
  declared `type` a `then` states beside a bound never widens it), by the rules `/refine` applies (an orphaned answer
  dropped to what is stored, a stored value the change orphaned shown on
  the empty option and listed stale), with no request. When one is, or
  anything cannot be settled here, it posts the values to `/refine`
  (debounced) and re-renders from the answer, keeping what was touched
  since the request left. A change made while a refine is out joins it.
  The contract panel says which, in one line: "Narrowed here: room,
  capacity", or "Asked the server: room depends on a live rule"
  ([a drawing client that narrows locally](../../docs/served-contract.md#a-drawing-client-that-narrows-locally)).
  Validate and submit always ask.
- **Saves**: Submit posts the values, the stale paths and the
  fingerprint the contract was loaded with to `/submit`. A refusal shows
  beside its field, by path, and in a summary; a refusal at path `''`
  (someone else saved since the form was loaded) shows in the summary
  alone. A write says "Saved", lists any stale value it kept, and
  re-renders from the contract the answer carries, whose fingerprint the
  next submit sends. A write that created something (`node.type/add`)
  moves to the page where it now lives, `/surface-react/node.type/edit?type=…`.
  The fingerprint is on by default (`SEND_FINGERPRINT` in `api.ts`); a
  page turns it off with `sendFingerprint: false` in its
  `drupalSettings.dataSurfaceReact`.

Below the form, collapsed, the contract: the line on the last change, a
row per key, as the PHP contract panel shows it, then the JSON of the
last contract served and of the full schema.

## The app

The app is `app/`, a Vite + React 18 + TypeScript project. It builds
into `dist/`, which **is committed**, so the module works on a site with
no Node. Rebuild it whenever `app/` changes, and commit the result with
the change:

```bash
cd modules/data_surface_react/app
npm ci            # once; node_modules is ignored
npm test          # Vitest + React Testing Library
npm run build     # type-checks, then writes ../dist/app.js and ../dist/app.css
```

Run them on the host, not inside ddev. The library
`data_surface_react/app` points at `dist/app.js` and `dist/app.css`.

## Tests

| Test | Covers |
| --- | --- |
| `Kernel\ServedContractTest` | The main module's emitter over examples 1 to 3, the content type resource and the demo block structure: labels, `oneOf` titles, bounds, the venue to room dependency, the ticket variants' conditional, the locked machine name on edit, the widget hints off by default and on for this submodule's endpoint; each part defined once under `$defs` and referred to, one def per class attached twice, a site narrowing its def only where it differs, and each document's verdicts on example 3 held to the pipeline's; every schema checked by opis/json-schema (draft 2020-12) and against the vendored draft-07 meta-schema. |
| `Functional\ServedContractEndpointsTest` | 200 and 403, the JSON shape, the full schema's access and content, cacheable and the same across two stored states, refine narrowing the room by the venue, validate refusing a wrong room and a capacity over the room's and accepting a valid payload, nothing written; the landing page's React links. |
| `Functional\ServedSubmitEndpointTest` | Submit writing example 2 and answering the fresh contract; a wrong room refused with nothing written; anonymous, token-less and malformed posts; a stale fingerprint refused with nothing written, and no fingerprint meaning the last write wins; a content type added through it answering `created` at `edit` with its `type`, then edited and deleted. |
| `FunctionalJavascript\ReactNarrowingTest` | In a browser, counting the page's fetches with `performance.getEntriesByType('resource')`: example 2's venue and room narrowed with no `/refine`; example 3 with compliance asking on the venue (a live rule narrows the room) and not on the pricing; the panel's line for each. |
| `app/src/test/narrow.test.ts` | The page's narrowing held to recorded `/refine` answers (`fixtures/refine-*.json`) for chains of changes on example 2 and example 3, compliance off and on: schema, values and stale paths equal keyword for keyword, each local step continuing from the last; the changes asked named by key and marker; a stale value only the server knows, and frames it cannot read, asked. Example 4's declared license rule as the compliance full schema states it, its `if` read by JSON Schema's rules, and narrowed here for a license and a room change (`fixtures/refine-example3-license.json`) once the stewards' `refine` marker is set aside. |
| `app/src/test/contract.test.ts` | The resolver: a reference with keywords beside it, an `allOf` narrowing, a def inside a def, two sites sharing one def, a recursive reference kept, the JSON Pointer escaping; example 3's served contract defining its parts once and read inline; contract 2: a document of another version refused, each `within` entry put back at its pointer (a property left out and a `true` made an object again), the conditionals `rules` lists marked declared, a key's union of its variants and a def's own title dropped. |
| `app/src/test/widgets.test.tsx` | Each widget from a schema fragment, the empty option rule, locked, choosing a variant and locking it with its key, the list; a placed fieldset drawn after the sibling it names, its values sent under its group; the panel's message for each constraint only the server checks. |
| `app/src/test/SurfaceForm.test.tsx` | The app against a mocked server serving the emitter's own contracts: the contract and the full schema fetched once, together; narrowing here with no request (example 2's venue and room, example 3's venue and pricing), the orphaned room stale and sent back by its path, example 3 with compliance asking on the venue and the license, the panel's line; with no full schema, refine on a dependency, the stale room, validate's inline and summary messages; submit's success with stale warnings and the re-render, a refusal, the fingerprint sent and its refusal, the toggle off, and the move to a created thing's page; example 3's parts drawn from their defs, the paid ticket on a pricing change, a refusal on the contact's email shown on that field, and its Compliance fieldset right after the capacity; contract 2 only: a contract, a full schema, a refine or a write of contract 1 refused, by the document or by the `Contract-Version` header, and shown as the form's error. |
