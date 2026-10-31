# The site's catalog

The catalog is a command, not a concept: it lists what a site has,
every resource and structure the module finds, each operation with the
doors it reaches, and what failed to build. Run it after
[Your first resource](first-resource.md) to check that your resource is
found and which doors it reaches. [Resources](introduction/02-resources.md),
[Operations](introduction/09-operations.md) and
[Doors](introduction/12-doors.md) introduce the words it prints.

`drush surface:catalog` prints the live
map of a site: every resource and structure discovery finds, from any
module, derived and read-back ones included, with what each is called
and is for where it says (its `label:` and `description:`, or the
plugin's that holds it); per operation its id, kind,
label, permission, parameters and the doors it reaches; and every
refusal discovery or a build raised, and every route written wrong for
the operation it serves, with its message and the file it points at.
[The catalog](catalog.md) is this command's markdown for the modules in
this repository, a label beside each id in its table and a `Label` and
a `Description` line in each entry that has them, and
[the catalog page](#the-catalog-page) shows the same on the site, each
door linked. The table of operations is one row per operation, as
before.

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
| `form` | The path of each form route a module declares for the operation and the route's name, then in brackets what the route says of the operation's keys beside its two naming defaults: `/contact/support (example.support) [pins to: support; fixes department: 3; fills subject: Support request; starts message from ?message]`, each parameter a plain default pins, each key it fixes or fills with the value as written (a reference as `{name}`), and each key a link may start with the query parameter it reads. A route whose resource id holds a variable of its path is listed under every resource whose id it fills, its path filled for each. | any (`_surface_resource` on the route) |
| `http` | The method and path that run it under contract 3: `PATCH /surface-api/first.store`, a listing's `GET` on the collection. | `surface` |
| `tool` | The derived tool, `surface:<id>:<operation>`. | `surface`, with `tool` |
| `read tool` | The read tool beside it, for an operation that changes a thing that exists. | `surface`, with `tool` |
| `schema tool` | The narrowing tool beside it, `surface:<id>:<operation>:schema`, where its listing leaves a key's entries out: variants the site lists, or a map of keys from the site past the budget (why: [decision](decisions.md#a-budget-for-what-the-site-decides)). | `surface`, with `tool` |
| `openapi` | The OpenAPI operation that runs it: `<id>:<operation>:write`, a listing's `:list`. | `surface` |
| `react` | The React page its form mounts on, `/surface-react/<id>/<operation>`, then each page a module serves it at, a route whose controller is `ResourceReactPage::served()`, named as the `form` door names a route. | `surface_examples_react` |

Only an operation that can be asked on its own reaches the HTTP, tool
and OpenAPI doors and a React page of its own: its resource names
storage, no plugin's configuration is it, and its permission names no
placeholder its parameters cannot fill. A structure a plugin uses has
none; its plugin's host configures it. A route a module declares is
listed for any operation it serves, under `form`, or under `react` for
a React page. A module that opens a door of its own
implements `ResourceBuild\CatalogDoorsInterface` as a service tagged
`surface.catalog_doors`, and `ResourceBuild\CatalogDoorLinksInterface`
beside it to link the door on the catalog page.

## The catalog page

`/admin/config/development/surfaces` (Configuration, Development, "Data
surface catalog"), for a role with the restricted permission "View the
data surface catalog" (`access surface catalog`), shows what the
table lists, from the same `SiteCatalog` data: one row per operation
with its kind, permission and parameters, and its doors, each linked
where it has a URL: the form route (the first whose every path variable
the resource's id fills or a default gives), the HTTP path (one that
names no parameter), the React page, and the OpenAPI operation in the
API reference (`/surface-api/redoc#operation/<operationId>`); a tool id
is shown as text. A second table, Refused, lists every message and the
file it points at. Each resource or structure links to its own page,
`/admin/config/development/surfaces/<id>`, which shows what `--resource`
does: its facts (its label and description first, where it has them),
its operations and doors, and every key. The listing is
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
(`block.surface_component.props.*`) with how many; `--format=json`
lists every one, and `--resource=<name>` shows one in full.

## One in full

`--resource=<id>` names the resource by its label and description,
where it has them, then builds it in its first operation that needs
nothing (a structure with no operation as a host builds it) and lists
every key as the build seals it, a child's, a variant's and a
collection item's under their dotted path (`rooms.*.seats`): its type,
whether it is required, its default, its constraints (less the two its
type and requiredness add), whether it is locked, secret or computed
and from what, the refiners on it and what they watch, the keys its
declared rules read, whether a runtime refiner narrows it, the module
that added it, the child, variants or items at it, and the widget that
draws a key holding one value, by the word it answers to (a module's
widget that claims the key among them,
[Widgets](widgets.md#a-module-serves-a-widget-to-every-key-of-a-kind)).
Beside the keys,
the operations with every door, and who answers access: the operation's
permission alone, or the access class and the alters that answer, and
whether the answer at the top is static or runtime.

Variants the site lists past `surface.settings:site_list_limit`
are listed by value, `listed: false` in the JSON and a note in the
table, and their keys are not walked: a structure walked once per place
it is used grows with the product of the lists, which exhausted 3 GB
for a placed block on a site with 312 components
(why: [decision](decisions.md#a-budget-for-what-the-site-decides)). Each
variant's structure is still listed under "Read from a schema".

## What was refused

Every operation of every resource and structure is built, as its tool
listing is: in its own context when it needs nothing, in a context of
its id alone when it needs a subject. What a build refuses is listed
with its message and the file the message points at, the first class it
names, else the resource's own class, from the Drupal root. A refusal
discovery raises (an attribute misplaced, two classes claiming one id)
is one refusal of the whole site, since nothing can be read past it.

Every route that serves an operation that builds is judged beside it,
and a route written wrong is listed under Refused (its stage `route` in
the JSON, beside `discovery` and `build`), its message naming the route
as every visitor of it is refused. A route whose path has no variable,
and which fixes or fills keys or pins a parameter, is read as its access
check reads it: its literal seeds are held once, by the build the build
cache keeps, so the first request to the page reads what the catalog
held, and a seed the resource refuses, a plain default nothing reads or
one the operation refuses is listed. A route with a variable in its
path is the URL's to finish, so it is listed among the doors and not
held: what it gets wrong for some value is refused when that URL is
matched. Each key a route lets a link start is found on the operation's
build, whatever the route's path holds, so a query path that names no
key, is out of a prefill's reach or names a secret is listed before a
visitor meets it; a query naming an identity key that the path of an
update supplies is the exception, since the catalog's build knows no
subject, and the form's first draw refuses it
([forms](forms.md#refusal-by-who-chose-the-value)). `--refused` lists
only what was refused, routes included, and exits 1 when it lists any,
so a check can run it.

Every build is for no account, asked as the anonymous one, as the tool
listing is (why: [decision](decisions.md#a-static-tool-definition-is-no-accounts)), so
the map is the same whoever runs the command. On this module's
development site it takes about 10 seconds for 35 resources, 73
structures, 153 operations and 3,133 structures read from a schema.
