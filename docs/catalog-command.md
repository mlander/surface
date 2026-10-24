# The site's catalog

`drush surface:catalog` (alias `data_surface:catalog`) prints the live
map of a site: every resource and structure discovery finds, from any
module, derived and read-back ones included; per operation its id,
kind, label, permission, parameters and the doors it reaches; and every
refusal discovery or a build raised, with its message and the file it
points at. [The catalog](catalog.md) is this command's markdown for the
modules in this repository, and [the catalog page](#the-catalog-page)
shows the same on the site, each door linked.

```
drush surface:catalog                         # a table, one row per operation
drush surface:catalog --format=json           # resources, derivers, refused
drush surface:catalog --format=markdown       # what docs/catalog.md is
drush surface:catalog --resource=first.store  # one in full, its keys too
drush surface:catalog --refused               # only what did not build
```

## The doors

A door is a way a caller asks for an operation. Each is listed only
where the module that opens it is installed:

| Door | What it is | Module |
| --- | --- | --- |
| `form` | The path of each form route a module declares for the operation, and the route's name. | any (`_data_surface_resource` on the route) |
| `http` | The method and path that run it under contract 3: `PATCH /surface-api/first.store`, a listing's `GET` on the collection. | `data_surface_react` |
| `tool` | The derived tool, `data_surface:<id>:<operation>`. | `data_surface_tool` |
| `read tool` | The read tool beside it, for an operation that changes a thing that exists. | `data_surface_tool` |
| `openapi` | The OpenAPI operation that runs it: `<id>:<operation>:write`, a listing's `:list`. | `data_surface_openapi` |
| `react` | The React page its form mounts on, `/surface-react/<id>/<operation>`. | `data_surface_react` |

Only an operation that can be asked on its own reaches the HTTP, tool,
OpenAPI and React doors: its resource names storage, no plugin's
configuration is it, and its permission names no placeholder its
parameters cannot fill. A structure a plugin uses has none; its
plugin's host configures it. A module that opens a door of its own
implements `ResourceBuild\CatalogDoorsInterface` as a service tagged
`data_surface.catalog_doors`, and `ResourceBuild\CatalogDoorLinksInterface`
beside it to link the door on the catalog page.

## The catalog page

`/admin/config/development/surfaces` (Configuration, Development, "Data
surface catalog"), for a role with the restricted permission "View the
data surface catalog" (`access data surface catalog`), shows what the
table lists, from the same `SiteCatalog` data: one row per operation
with its kind, permission and parameters, and its doors, each linked
where it has a URL: the form route, the HTTP path (one that names no
parameter), the React page, and the OpenAPI operation in the API
reference (`/surface-api/redoc#operation/<operationId>`); a tool id is
shown as text. A second table, Refused, lists every message and the file
it points at. Each resource or structure links to its own page,
`/admin/config/development/surfaces/<id>`, which shows what `--resource`
does: its facts, its operations and doors, and every key. The listing is
built for no account, and the page says so: the documents a request
answers (the schema for an account, its tool listing) differ by account.
The command's table prints the page's URL last; `CatalogPageTest` holds
the page's table to the command's rows.

## Read from a schema

A structure read from a schema document has no class: a variant a
provider lists (each single-directory component's props, its slots) or
a def a document refers to, built by the name `schema:<id>:<hash>`. It
is registered when a build reads it, so the command builds every
operation first and then lists them. A site with a theme of components
reads thousands (3,133 on this module's development site), so the table
and the markdown give one row where they are read
(`block.data_surface_component.props.*`) with how many; `--format=json`
lists every one, and `--resource=<name>` shows one in full.

## One in full

`--resource=<id>` builds the resource in its first operation that needs
nothing (a structure with no operation as a host builds it) and lists
every key as the build seals it, a child's, a variant's and a
collection item's under their dotted path (`rooms.*.seats`): its type,
whether it is required, its default, its constraints (less the two its
type and requiredness add), whether it is locked, secret or computed
and from what, the refiners on it and what they watch, the keys its
declared rules read, whether a runtime refiner narrows it, the module
that added it, and the child, variants or items at it. Beside the keys,
the operations with every door, and who answers access: the operation's
permission alone, or the access class and the alters that answer, and
whether the answer at the top is static or runtime.

## What did not build

Every operation of every resource and structure is built, as its tool
listing is: in its own context when it needs nothing, in a context of
its id alone when it needs a subject. What a build refuses is listed
with its message and the file the message points at, the first class it
names, else the resource's own class, from the Drupal root. A refusal
discovery raises (an attribute misplaced, two classes claiming one id)
is one refusal of the whole site, since nothing can be read past it.
`--refused` lists only these and exits 1 when it lists any, so a check
can run it.

Every build is for no account, asked as the anonymous one, as the tool
listing is ([A static tool definition is no
account's](decisions.md#a-static-tool-definition-is-no-accounts)), so
the map is the same whoever runs the command. On this module's
development site it takes about 10 seconds for 35 resources, 73
structures, 153 operations and 3,133 structures read from a schema.
