# Data Surface - OpenAPI

An OpenAPI 3.1 document of every resource operation the React submodule
serves over HTTP, so any OpenAPI client, code generator or UI can call a
resource without reading this module's pages, and two pages over it.

| Path | What it answers |
| --- | --- |
| `GET /surface-api/openapi.json` | The document, as JSON. Open to everyone. |
| `GET /surface-api/openapi.yaml` | The same document, as YAML (`application/yaml`). |
| `GET /surface-api/docs` | Swagger UI over the document; "try it out" calls the API with the logged-in session. |
| `GET /surface-api/redoc` | Redoc over the document, to read. |

```bash
drush pm:install data_surface_openapi
```

It needs `data_surface_react`, whose API it describes, and nothing
contributed. The two pages need the permission `access data surface api
docs`: each runs a script from a CDN on the site (Swagger UI 5.29.1 from
cdnjs, Redoc 2.5.1 from jsDelivr, each pinned and held to its integrity
hash, `data_surface_openapi.libraries.yml`), so a site grants them to
the roles it chooses. Swagger UI sends every POST and PATCH with the
token `/session/token` answers, and a write with the last `ETag` an
answer for the same path carried as `If-Match`, so a write after
someone else's is refused (412) as the React page's is.

## What it describes

Every operation that can be asked on its own (its resource names
storage, no plugin holds it, and its permission can be named): the same
operations the Tool API bridge makes tools of. The path is the resource
and the method is the operation (contract 3): one path item per resource
path, `/surface-api/first.newsletter` with `get` (the envelope) and
`patch` (the default update), `/surface-api/node.type` with `get` and
`post` (the default create) and `/surface-api/node.type/{type}` with
`get` and `patch`, each with a `/schema` path item, `get` (the full
schema) and `post` (the schema for values sent). Any other operation is
a custom method, a path item of its own: `/surface-api/event.program:check`,
`get` and `post`.

- **Schemas.** Each operation's full schema, split into components: each
  def a component named by its def id, the root the operation's values
  component (`first.newsletter.configure`), every other `x-surface`
  keyword where the emitter wrote it. A create's request body takes it
  whole; any other's its patch (`first.newsletter.configure.patch`),
  which requires nothing, since a key left out keeps what is stored. A
  def the patch reaches has a patch of its own only where it differs.
  Declared outputs are a component too (`<values>.outputs`). An
  operation that addresses one thing is built knowing no subject, as its
  tool's listing is.
- **The resource in the answers.** Every answer composes its shared
  envelope with the operation's components: `values` is the values
  component or any object, and `schema` and `outputs` show the
  component as their example. The `/schema` answer's example is the
  full schema built for no account, so Swagger UI and Redoc show the
  resource's schema under that path.
- **Facts.** `x-surface-kind` on every call; `x-surface-idempotent`,
  `x-surface-destructive` and `x-surface-creates` on its run. The
  summary is the operation's label, the description its description.
- **HTTP.** `dryRun` a query parameter; `If-Match`, `X-Surface-Stale`
  and `X-CSRF-Token` header parameters; the read's `ETag` and `Link`,
  a written create's 201 and `Location`; a refusal 422, a stale
  `If-Match` 412, access 403, every error `ProblemDetails`
  (`application/problem+json`). `Contract`, `FullSchema`,
  `NarrowRequest`, `Result`, `SubmitResult`, `Created` and
  `ProblemDetails` are shared components, every path in them a JSON
  Pointer; every answer carries `Contract-Version`.
- **Security.** The session cookie, and basic auth when core's
  `basic_auth` is on; `x-drupal-permission` names the operation's
  permission.
- **Examples.** A run's request example is the operation's declared
  defaults.

## For no account

The document is built for no account and as the anonymous account, the
way the Tool API bridge builds its tool definitions: every key as
declared, each one an access answer could lock or leave out marked
`x-surface.access: "live"`. It is the same for every account, open to
every account, and cached until discovery, a build it was made from,
the site's name, the modules or the anonymous role change. The
per-request read and `/schema` answer for the account that asks.

[The served contract](../../docs/served-contract.md#an-openapi-document)
has the format; [the decision](../../docs/decisions.md#an-openapi-document-is-one-more-door)
has why 3.1 and why its own route rather than the contributed `openapi`
module; [Contract 3](../../docs/decisions.md#contract-3-the-operation-is-the-method)
why the paths are what they are.

## Tests

`OpenApiDocumentTest` (kernel: valid against the official OpenAPI 3.1
schema in `tests/fixtures/openapi/`, every reference resolves, the
components are the full schemas and outputs byte for byte, every
answer shows the operation's components, a patch only where one is
needed, the logbook's and the newsletter's paths, methods, statuses
and facts, an operation with a subject as a path with its identity,
the shared components all used, the security
requirement, the cacheability, the examples pinned in
`tests/fixtures/openapi/examples.json`), `SchemaComponentsTest` (unit:
components, sharing, patches, a patch only where it differs) and
`OpenApiEndpointTest` (functional: the same document for an account and
for anonymous, as JSON and as YAML; the Swagger UI and Redoc pages under
their permission, each script pinned with its integrity hash).
