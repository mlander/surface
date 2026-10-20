# Data Surface - React

A resource's form, rendered in React from a contract the module serves.
One operation of one resource becomes five endpoints and a page:

| | Path | What it does |
| --- | --- | --- |
| Page | `GET /surface-react/{resource}/{operation}` | Mounts the React app, titled with the operation's label. |
| Contract | `GET /surface-api/{resource}/{operation}` | The JSON contract with the values the form opens with, and the stored values' `fingerprint`. |
| Full schema | `GET /surface-api/{resource}/{operation}/schema` | The contract for any values: every listable dependency a conditional, the rest `dynamic` or `runtime`; no values. Cacheable. |
| Refine | `POST /surface-api/{resource}/{operation}/refine` | The contract re-narrowed against in-progress values: the AJAX rebuild's equivalent. |
| Validate | `POST /surface-api/{resource}/{operation}/validate` | The pipeline's dry run: `{valid, violations, stale, notes, values}`, the values as the read tool would answer them, nothing written. |
| Submit | `POST /surface-api/{resource}/{operation}/submit` | The write: `{committed, valid, violations, stale, notes, outputs, contract, created}`; a refusal is 200 with `committed: false`. |

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

```bash
drush pm:install data_surface_react
```

With `data_surface_examples` enabled, the landing page links each of
examples 1 to 3 "In React": `/surface-react/registration.step1/configure`
and so on.

## What it does and does not

- **Resolves** the contract's references before drawing anything. The
  emitter writes each part (an attached structure, a slot's variant) once,
  under the schema's `$defs`, and refers to it where it is used, with
  what that place says beside the reference, or composed with a
  narrowing (`allOf: [{$ref}, {narrowing}]`) where it says more
  ([reusable subschemas](../../docs/served-contract.md#reusable-subschemas)).
  `inlineSchema()` in `app/src/contract.ts` turns that into one inline
  schema: a local `$ref` (`#/$defs/...`) is replaced by its def, the
  keywords beside it are merged over, and a narrowing is applied by
  taking its value for every keyword it states (`properties` one by
  one, `required` as a union). The form resolves once per contract, on
  load and after each refine or write, and every reader (the fields, a
  slot's chosen variant, the keys a refine watches, where a refusal is
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
  sent under the group), the chosen variant's fieldset for a slot
  (nothing while it is unresolved), and rows to add and remove for a
  list with no option list, as the list widget draws them. Locked keys
  are disabled and say why; a stale
  value shows on the empty option and stands for the stored value,
  which stays on the server.
- **Narrows here, or asks.** On load it fetches the contract and the full
  schema together, once; the full schema reads no value, so it is never
  fetched again. On a change to any key something depends on, it
  collects every key that depends on it, transitively. When none is
  `dynamic` or `runtime`, the form narrows itself from the full schema's
  conditionals, a declared rule's read by its own `if` and tightening the
  key by its `then`, by the rules `/refine` applies (an orphaned answer
  dropped to what is stored, a stored value the change orphaned shown on
  the empty option and listed stale), with no request. When one is, or
  anything cannot be settled here, it posts the values to `/refine`
  (debounced) and re-renders from the answer, keeping what was touched
  since the request left. A change made while a refine is out joins it.
  The contract panel says which, in one line: "Narrowed here: room,
  capacity", or "Asked the server: room depends on a runtime rule"
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
| `Kernel\ServedContractTest` | The main module's emitter over examples 1 to 3, the content type resource and the demo block structure: labels, `oneOf` titles, bounds, the venue to room dependency, the slot's conditional, the locked machine name on edit, the widget hints off by default and on for this submodule's endpoint; each part defined once under `$defs` and referred to, one def per class attached twice, a site narrowing its def only where it differs, and each document's verdicts on example 3 held to the pipeline's; every schema checked by opis/json-schema (draft 2020-12) and against the vendored draft-07 meta-schema. |
| `Functional\ServedContractEndpointsTest` | 200 and 403, the JSON shape, the full schema's access and content, cacheable and the same across two stored states, refine narrowing the room by the venue, validate refusing a wrong room and a capacity over the room's and accepting a valid payload, nothing written; the landing page's React links. |
| `Functional\ServedSubmitEndpointTest` | Submit writing example 2 and answering the fresh contract; a wrong room refused with nothing written; anonymous, token-less and malformed posts; a stale fingerprint refused with nothing written, and no fingerprint meaning the last write wins; a content type added through it answering `created` at `edit` with its `type`, then edited and deleted. |
| `FunctionalJavascript\ReactNarrowingTest` | In a browser, counting the page's fetches with `performance.getEntriesByType('resource')`: example 2's venue and room narrowed with no `/refine`; example 3 with compliance asking on the venue (the room is runtime) and not on the pricing; the panel's line for each. |
| `app/src/test/narrow.test.ts` | The page's narrowing held to recorded `/refine` answers (`fixtures/refine-*.json`) for chains of changes on example 2 and example 3, compliance off and on: schema, values and stale paths equal keyword for keyword, each local step continuing from the last; the changes asked named by key and marker; a stale value only the server knows, and frames it cannot read, asked. Example 4's declared licence rule as the compliance full schema states it, its `if` read by JSON Schema's rules, and narrowed here for a licence and a room change (`fixtures/refine-example3-licence.json`) once the stewards' dynamic rule is set aside. |
| `app/src/test/contract.test.ts` | The resolver: a reference with keywords beside it, an `allOf` narrowing, a def inside a def, two sites sharing one def, a recursive reference kept, the JSON Pointer escaping; example 3's served contract defining its parts once and read inline. |
| `app/src/test/widgets.test.tsx` | Each widget from a schema fragment, the empty option rule, locked, slot resolution, the list; a placed fieldset drawn after the sibling it names, its values sent under its group. |
| `app/src/test/SurfaceForm.test.tsx` | The app against a mocked server serving the emitter's own contracts: the contract and the full schema fetched once, together; narrowing here with no request (example 2's venue and room, example 3's venue and pricing), the orphaned room stale and sent back by its path, example 3 with compliance asking on the venue and the licence, the panel's line; with no full schema, refine on a dependency, the stale room, validate's inline and summary messages; submit's success with stale warnings and the re-render, a refusal, the fingerprint sent and its refusal, the toggle off, and the move to a created thing's page; example 3's parts drawn from their defs, the paid ticket on a pricing change, a refusal on the contact's email shown on that field, and its Compliance fieldset right after the capacity. |
