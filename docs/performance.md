# Performance

What a request pays, and what is paid once. The build of a resource for
no account is kept across requests; a variant the site lists is built
only when a door reads it; the operation form prepares a write once; the
OpenAPI document is kept and served with an `ETag`. Every other build is
per request, kept by the request's memo. The reasons are in
[Decisions](decisions.md#the-static-build-is-cached-the-rest-is-per-request);
what was paid once before this is in [What is paid once and what per
request](decisions.md#what-is-paid-once-and-what-per-request).

## Method

Measured on the development site: 312 components from its themes, 20
field types, 35 resources, PHP 8.4 with assertions on, in ddev on a
Docker VM of 14 CPUs and 8 GB. Two settings of that site shape every
number below, and are left as they are:

- Its `settings.local.php` puts the discovery bin on the null backend,
  so every request discovers every plugin type again, this module's
  resources among them.
- ECA collects its actions on every request, and `eca_tool` derives one
  action per tool, so every request, a Drush command's included, derives
  every tool definition again.

So on this site every request pays what another site pays only after a
cache rebuild: a tool listing, which builds every operation for no
account. That is where most of the time below went.

What was measured, each with a small script outside the module:

- **The declared build** of four operations, each in a Drush process of
  its own: the first `buildDeclared()` in the process; the same build
  with the request's memo forgotten, which is what a later request pays
  (the median of five); and the size of what is kept for it.
- **Pages**, with curl as an administrator, three requests each, the
  median: example 12 (the spotlight, a component and its slots), example
  13 (a placed block), the catalog page, the OpenAPI document and the
  newsletter's full schema.
- **The form submit**: the newsletter's operation form fetched and
  posted back with new values, three times, the median of the POST.
- **The tool listing**: `drush cr` then `drush tool:list`, timed whole,
  three times; and the tool definitions derived again inside one process,
  with the build cache emptied first and with it kept.

## Before and after

| What | Before | After |
| --- | --- | --- |
| `event.spotlight:configure`, declared, again in a process | 434 ms | 0.7 ms, read from the build cache |
| `block.placement:create`, declared, again in a process | 355 ms | 0.2 ms |
| `registration.step3:configure`, declared, again in a process | 0.2 ms | 0.3 ms |
| `first.newsletter:configure`, declared, again in a process | 0.06 ms | 0.1 ms |
| The spotlight, serialized | 10.9 MB, every component built | 374 KB kept, each component as what builds it |
| The placed block, serialized | 11.1 MB | 50 KB kept |
| The first declared build in a Drush process | 2.3 to 2.7 s | 0.47 to 0.57 s |
| `/surface-examples/12` | 3,008 ms | 1,133 ms |
| `/surface-examples/13` | 4,424 ms | 1,717 ms |
| `/admin/config/development/surfaces` | 3,463 ms | 1,903 ms |
| `/surface-api/openapi.json` | 3,418 ms | 52 ms (1,317 ms the first time after a cache rebuild); 304 when the client names its `ETag` |
| `/surface-api/first.newsletter/schema` | 1,923 ms | 240 ms |
| The newsletter form, POST | 1,944 ms | 251 ms |
| Tool definitions derived again in a process | 1,464 ms, 59 builds | 72 ms, none built, 59 read from the build cache |
| The same, the build cache emptied | 1,464 ms, 59 builds | 218 ms, 59 builds |
| `drush cr`, then `drush tool:list` | 9.4 s | 5.1 s |
| `drush tool:list` again | 9.5 s | 5.0 s |
| `drush php:eval '1;'`, for scale | 4.4 s | 3.8 s |
| `/user/1`, a page that holds nothing of this module | 1.75 s | 0.53 s |

The pages fell by what every request on this site pays for the tool
definitions, and by the builds each page made itself. A build that holds
no list the site decides was already well under a millisecond; reading
it back is no faster than building it, so its row is about the same.

## What is kept, and what drops it

A build for no account is kept in `cache.data_surface_build`, under
`declared:<resource id>:<hash>`. The hash is of the request memo's key
(the resource, everything the context says, its parameters among them,
the kind of build and the reference limit) and the current and
configuration override languages. It is tagged with:

- what the build read: each provider's list, the site's defaults;
- each discovery entry it built from, and discovery's index;
- `config:core.extension`, `config:data_surface.settings` and
  `config:data_surface.defaults`.

Stored with it are the structures it built that were read from a schema
or made for a reference key, so a request that reads it can name them. A
variant the site lists is stored as what builds it: the variant's name,
its context, and once per key the frames around it and the build it was
held in. A request that builds one registers the structures its owner's
list reads again, from that list.

The OpenAPI document is kept in the same bin under
`openapi:<contract>:<hash>` of the site, the language and the session
cookie's name, tagged as the document always was. It is served with
`Cache-Control: no-cache, public` and an `ETag` of its JSON (or YAML), and
a request whose `If-None-Match` names it is answered 304 with no body. It
is no longer a cacheable response for Drupal's page caches: core takes
the `ETag` off a cacheable response sent to a session, and the bin keeps
it for every request instead.

## What each door builds

A variant the site lists is built the first time a door reads it:

| Door | Builds |
| --- | --- |
| The full schema, the tool listing | the variants it states: all of them within the site's list limit, none past it |
| The schema for values, `?choose=`, the narrowing tool | the chosen one |
| The form | the chosen one, to draw it |
| The pipeline | every variant at a key it judges whose document may state a secret: a secret any of them declares is never returned under that key; one read from a document that states none is not built |
| The catalog | all of them, so a refusal inside one is listed, and every structure read from a schema is |

A provider implementing `EntrySchemasInterface` is asked for an entry's
schema only by a door that reads that entry: the form and `?choose=`
for the chosen one, the full schema and the tool listing for those they
state, the pipeline for each one's schema, without building it.

## Memory

Measured 2026-10-28 on the same site, each page a Drush sub-request as
user 1 with warm caches, the peak PHP allocated
(`memory_get_peak_usage(TRUE)`); Drush's bootstrap alone is 24 MB, and
about 34 MB of every page is the tool definitions this site derives on
each request. With `ini_set('memory_limit', '128M')` every page below
answers; before, the four over 128 MB ran out of memory.

| Page | Before | After |
| --- | --- | --- |
| `/surface-examples/12` | 144 MB | 92 to 96 MB |
| `/surface-examples/13` | 184 MB | 88 MB |
| `/surface-react/event.spotlight/configure` | 114 MB | 72 MB |
| `/surface-api/event.spotlight/schema` | 132 MB | 60 MB |
| `.../schema?choose=/props=aries:sq-card` | 132 MB | 60 MB |
| `.../schema?choose=/slots=aries:sq-card` | 132 MB | 88 MB |
| `drush tool:run data_surface:event.spotlight:configure:schema` | 114 MB | 78 MB |
| `/admin/structure/block/surface/add` | 74 MB | 74 MB |
| Every other example page | 70 to 76 MB | 70 to 76 MB |

Where it went, at 312 components:

- **The slots' defs, written on every build.** 13 MB as PHP arrays,
  written by `ComponentSlots::list()` two to four times a request. Now
  written when a slots variant is read (`entryDefs()`), once per process
  for the same components and words.
- **Preparing them.** The JSON pass and three walks each copied the
  document: 18 MB kept beside the list, 36 MB at the peak. Now the
  prepared document shares the list's arrays: 0.2 MB kept, 1.7 MB at the
  peak, and a second build reads the one already prepared.
- **The secrets walk.** Each read of stored values (a form, a tool call)
  built every variant to ask it for secrets: 2,021 on example 12 and
  6,142 on example 13. Now none is built whose document states none.
- **Not the per-entry objects.** The read definitions, attachments and
  holders of 624 variants are about 0.6 MB. The spotlight's build for
  the account now costs 6 MB at 312 components and 5.4 MB at four, most
  of it the components' definitions core reads.

What stays: reading one slots variant reads all the defs (the form,
`?choose=/slots=`), about 42 KB per component, since a slot that holds
any component refers to every one.

## The benchmark

`BuildBenchmarkTest` builds the four operations on its kernel site, with
the examples' four components, reads each back from the build cache nine
times with the request's memo forgotten, and holds the median and the
size kept to a bound:

| Operation | Measured, read back | Bound | Measured, kept | Bound |
| --- | --- | --- | --- | --- |
| `event.spotlight:configure` | 0.04 to 0.07 ms | 1 ms | 30,533 bytes | 48 KB |
| `block.placement:create` | 0.05 to 0.09 ms | 1 ms | 37,278 bytes | 56 KB |
| `registration.step3:configure` | 0.05 ms | 1 ms | 26,065 bytes | 40 KB |
| `first.newsletter:configure` | 0.01 ms | 0.5 ms | 4,907 bytes | 8 KB |

The time bounds leave room for a slower machine, ten to forty times what
this one measured; the size bounds about half again. Built cold on the
same site the four took 18 to 24 ms, 6 to 8 ms, 3.5 to 4 ms and 0.3 to
0.6 ms; that is not held to a bound, since it is most of what a first
request pays in class loading. `DATA_SURFACE_BENCHMARK=<file>` writes
what a machine measures.

## What remains

- **The cold cost.** A cache rebuild empties the build cache, so the next
  tool listing builds every operation once (59 here, 218 ms inside the
  process) and writes each one. What it no longer pays is building every
  component of every theme for each resource that lists them.
- **The per-request refinement.** Every document refines the build for
  its values, once per combination of what a key watches; a form, a
  schema request and a tool call each pay it. It reads values, so it is
  never kept.
- **Builds for an account.** `build()` and `buildStatic()` apply access
  answers while they build, which the kept build cannot be given after
  the fact, so each request builds them once, by the memo. Only their
  variants the site lists are put off.
- **The catalog.** It builds every variant to list every refusal and
  every structure read from a schema, so its page pays what it paid
  before (1.5 s here for its builds, where building everything with each
  operation took 1.3 s).
- **A large theme.** The spotlight's kept build grows by about 1.2 KB per
  component: 374 KB at 312, past memcached's default 1 MB item at about
  850, when memcached refuses it and every request builds it again.
- **What async could not help with.** A build is work one PHP request
  does in order, on one core: the build step reads its declarations, asks
  its providers and seals, and nothing in it waits on a network. Running
  parts of it at once would need several processes and would hand them a
  sealed object each, which is what the cache already avoids paying for.
  BigPipe can send a page before a placeholder inside it is rendered, but
  the form and the documents need the build first.
- **The client's fetches.** A key past the site's list limit is stated by
  value in the full schema, with a fetch per choice
  (`GET .../schema?choose=<pointer>=<value>`). The React app fetches the
  chosen variant when one is chosen; a client that wants several can
  fetch them in parallel, each answered by its own `ETag`. Each fetch is
  a schema request for the account, built per request.
