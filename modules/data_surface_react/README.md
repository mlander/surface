# Data Surface - React

A surface's form, rendered in React from a contract the module serves.
One situation of one surface becomes four endpoints and a page:

| | Path | What it does |
| --- | --- | --- |
| Page | `GET /surface-react/{surface}/{situation}` | Mounts the React app, titled with the situation's label. |
| Contract | `GET /surface-api/{surface}/{situation}` | The JSON contract with the values the form opens with, and the stored values' `fingerprint`. |
| Refine | `POST /surface-api/{surface}/{situation}/refine` | The contract re-narrowed against in-progress values: the AJAX rebuild's equivalent. |
| Validate | `POST /surface-api/{surface}/{situation}/validate` | The pipeline's dry run: `{valid, violations, stale, values, prepared}`, nothing written. |
| Submit | `POST /surface-api/{surface}/{situation}/submit` | The write: `{committed, valid, violations, stale, outputs, contract, created}`; a refusal is 200 with `committed: false`. |

`{surface}` is the `#[Surface]` id (`registration.step2`), `{situation}`
the situation id (`configure`). A situation's parameters arrive in the
query string by the situation method's parameter names, or in a POST
body's `parameters`: `/surface-react/node.type/edit?type=article`.
Access on all five is the situation's: its permission, then the
surface's access class through `Surfaces::access()`, no opinion read as
a refusal. The three POSTs want the session's CSRF token from
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

- **Renders** one component per `x-surface.widget`, mirroring the Form
  API mapping: select with the empty option rule, checkbox, number with
  its bounds and step, text with its maxlength, textarea, email, a
  fieldset for a map or an attached part (none around the modules'
  third-party settings, `x-surface.group`, each module's map being its
  own, as on the form), the chosen variant's fieldset for a slot
  (nothing while it is unresolved), and a minimal repeatable
  for a list with no option list. Radios are in the vocabulary and the
  app, and the emitter never asks for them, because the Form API
  mapping renders none. Locked keys are disabled and say why; a stale
  value shows on the empty option and stands for the stored value,
  which stays on the server.
- **Refines** on a change to any key something depends on: it posts the
  values to `/refine` (debounced) and re-renders from the answer,
  keeping what was touched since the request left.
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

Below the form, collapsed, the contract: a row per key, as the PHP
contract panel shows it, then the JSON.

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
| `Kernel\ServedContractTest` | The main module's emitter over examples 1 to 3, the content type surface and the demo block surface: labels, `oneOf` titles, bounds, the venue to room dependency, the slot's conditional, the locked machine name on edit, the widget hints off by default and on for this submodule's endpoint; every schema checked by opis/json-schema (draft 2020-12) and against the vendored draft-07 meta-schema. |
| `Functional\ServedContractEndpointsTest` | 200 and 403, the JSON shape, refine narrowing the room by the venue, validate refusing a wrong room and a capacity over the room's and accepting a valid payload, nothing written; the landing page's React links. |
| `Functional\ServedSubmitEndpointTest` | Submit writing example 2 and answering the fresh contract; a wrong room refused with nothing written; anonymous, token-less and malformed posts; a stale fingerprint refused with nothing written, and no fingerprint meaning the last write wins; a content type added through it answering `created` at `edit` with its `type`, then edited and deleted. |
| `app/src/test/widgets.test.tsx` | Each widget from a schema fragment, the empty option rule, locked, slot resolution, the list. |
| `app/src/test/SurfaceForm.test.tsx` | The app against a mocked server serving the emitter's own contracts: refine on a dependency, the stale room, validate's inline and summary messages; submit's success with stale warnings and the re-render, a refusal, the fingerprint sent and its refusal, the toggle off, and the move to a created thing's page. |
