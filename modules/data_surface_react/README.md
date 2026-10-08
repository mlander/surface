# Data Surface - React

A surface's form, rendered in React from a contract the module serves.
One situation of one surface becomes three endpoints and a page:

| | Path | What it does |
| --- | --- | --- |
| Page | `GET /surface-react/{surface}/{situation}` | Mounts the React app, titled with the situation's label. |
| Contract | `GET /surface-api/{surface}/{situation}` | The JSON contract with the values the form opens with. |
| Refine | `POST /surface-api/{surface}/{situation}/refine` | The contract re-narrowed against in-progress values: the AJAX rebuild's equivalent. |
| Validate | `POST /surface-api/{surface}/{situation}/validate` | The pipeline's dry run: `{valid, violations, stale, values, prepared}`, nothing written. |

`{surface}` is the `#[Surface]` id (`registration.step2`), `{situation}`
the situation id (`configure`). A situation's parameters arrive in the
query string by the situation method's parameter names, or in a POST
body's `parameters`: `/surface-react/node.type/edit?type=article`.
Access on all four is the situation's: its permission, then the
surface's access class through `Surfaces::access()`, no opinion read as
a refusal. The two POSTs want the session's CSRF token from
`/session/token` in an `X-CSRF-Token` header.

The contract format, the endpoints' bodies and the widget mapping are in
[the served contract](../../docs/served-contract.md).

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
  fieldset for a map or an attached part, the chosen variant's fieldset
  for a slot (nothing while it is unresolved), and a minimal repeatable
  for a list with no option list. Radios are in the vocabulary and the
  app, and the emitter never asks for them, because the Form API
  mapping renders none. Locked keys are disabled and say why; a stale
  value shows on the empty option and stands for the stored value,
  which stays on the server.
- **Refines** on a change to any key something depends on: it posts the
  values to `/refine` (debounced) and re-renders from the answer,
  keeping what was touched since the request left.
- **Validates**: the Validate button posts to `/validate` and shows each
  refusal beside its field, by path, and in a summary.
- **Does not write.** Submit is present and disabled. What a real write
  needs is at the end of [the served contract](../../docs/served-contract.md#what-a-write-needs).

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
| `Kernel\ServedContractTest` | The emitter over examples 1 to 3, the content type surface and the demo block surface: labels, `oneOf` titles, bounds, the venue to room dependency, the slot's conditional, the locked machine name on edit; every schema checked by opis/json-schema (draft 2020-12) and against the vendored draft-07 meta-schema. |
| `Functional\ServedContractEndpointsTest` | 200 and 403, the JSON shape, refine narrowing the room by the venue, validate refusing a wrong room and a capacity over the room's and accepting a valid payload, nothing written; the landing page's React links. |
| `app/src/test/widgets.test.tsx` | Each widget from a schema fragment, the empty option rule, locked, slot resolution, the list. |
| `app/src/test/SurfaceForm.test.tsx` | The app against a mocked server serving the emitter's own contracts: refine on a dependency, the stale room, validate's inline and summary messages. |
