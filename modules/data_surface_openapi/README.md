# Data Surface - OpenAPI

An OpenAPI 3.1 document of every resource operation the React submodule
serves over HTTP, so any OpenAPI client, code generator or UI can call a
resource without reading this module's pages.

| Path | What it answers |
| --- | --- |
| `GET /surface-api/openapi.json` | The document, as JSON. |
| `GET /surface-api/openapi.yaml` | The same document, as YAML (`application/yaml`). |

```bash
drush pm:install data_surface_openapi
```

It needs `data_surface_react`, whose endpoints it describes, and nothing
contributed.

## What it describes

Every operation that can be asked on its own (its resource names
storage, no plugin holds it, and its permission can be named) and needs
no subject: the same operations the Tool API bridge makes tools of. For
each, the five endpoints: `GET /surface-api/{resource}/{operation}`,
`GET .../schema`, `POST .../refine`, `POST .../validate` and
`POST .../submit`; a read only operation has its two GETs alone. An
operation that needs a subject is listed under the top
`x-surface.withASubject`, with its parameters, and not described yet.

- **Schemas.** Each operation's full schema, split into components: each
  def a component named by its def id, the root the operation's values
  component (`first.newsletter.configure`), every other `x-surface`
  keyword where the emitter wrote it. An update's validate and submit
  bodies take its patch (`first.newsletter.configure.patch`), which
  requires nothing, since a key left out keeps what is stored; a
  create's take the full schema.
- **Facts.** `x-surface-kind` on every call; `x-surface-idempotent`,
  `x-surface-destructive` and `x-surface-creates` on the submit. The
  summary is the operation's label, the description its description.
- **Answers.** `Contract`, `FullSchema`, `Result` and `SubmitResult` are
  shared components; a refusal is a 200 with `ok: false`, and every
  answer that is not an error carries `Contract-Version`.
- **Security.** The session cookie, and basic auth when core's
  `basic_auth` is on; `x-drupal-permission` names the operation's
  permission.
- **Examples.** A submit's example is the operation's declared defaults.

## For no account

The document is built for no account and as the anonymous account, the
way the Tool API bridge builds its tool definitions: every key as
declared, each one an access answer could lock or leave out marked
`x-surface.access: "live"`. It is the same for every account, open to
every account, and cached until discovery, a build it was made from,
the site's name, the modules or the anonymous role change. The
per-request GET and `/schema` answer for the account that asks.

[The served contract](../../docs/served-contract.md#an-openapi-document)
has the format; [the decision](../../docs/decisions.md#an-openapi-document-is-one-more-door)
has why 3.1, why its own route rather than the contributed `openapi`
module, and what is open.

## Tests

`OpenApiDocumentTest` (kernel: valid against the official OpenAPI 3.1
schema in `tests/fixtures/openapi/`, every reference resolves, the
components are the full schemas byte for byte, the logbook's and the
newsletter's facts, the security requirement, the cacheability, the
examples pinned in `tests/fixtures/openapi/examples.json`),
`SchemaComponentsTest` (unit: components, sharing, patches) and
`OpenApiEndpointTest` (functional: the same document for an account and
for anonymous, as JSON and as YAML).
