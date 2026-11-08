# Staging

Use this page when writes should be kept and made later, together, or
not at all: an agent that edits several pages and asks a person to
check them first, a change reviewed before it goes live, a set of edits
that must land in one transaction. A stage holds those writes; `apply`
makes them; `delete` discards them.

A stage is not a second copy of the site. Nothing renders from it: only
the doors of this module read through it, and only when the caller
names it.

## The model

**A stage is a resource of the base module**, `surface.stage`
(`Resource\StageResource`). It has identity, storage, an access class
and operations like any other resource, so the build derives its tools
and the HTTP door serves its paths. Its id is a UUID that storage gives
it when it is opened.

**The record.** Each stage is one row of the expirable key-value
collection `surface.stage` (`Staging\StageStore`, the service
`surface.stages`): its owner, its label, its status (`open` or
`applied`), and its writes in the order they were first staged. It is
kept for the site's temp store expiry (`tempstore.expire`, a week by
default) after its last write, then it expires and names nothing. Every
write of a record takes a lock named for the stage.

**The owner.** The account that opened the stage is the only one that
may use it (`Access\StageAccess`): read it, rename it, write into it,
read through it, list it, apply it, discard it. An anonymous account may
not open one; a client with basic auth is an account.

**A staged write.** A write through a resource's own operation, with a
stage named. The pipeline runs as for a real write: access, accept,
validate, and the storage's own `prepare()` with every check it makes,
so a staged write is refused for what a real write would be refused
for. Only `commit()` differs: it writes the stage's record, never the
storage. The record keeps one entry per thing (the resource and its
identity): the whole document the pipeline merged, and the top-level
keys this stage moved, which are what apply sends. A second staged
write to the same thing builds on the first, since a load through the
stage answers what the stage holds before what is stored.

**The token.** The first time a stage reads a thing from storage, it
keeps a fingerprint of what was stored (`Pipeline\Fingerprint::of()`,
the hash the HTTP door serves as an `ETag`). Apply compares it with what
is stored then; a difference refuses that write as a conflict.

(why: [decision](decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection))

## The six operations

| Operation | Tool | HTTP | Does |
| --- | --- | --- | --- |
| `create` | `surface:surface.stage:create`, `values: {label}` | `POST /surface-api/surface.stage` | Opens an empty stage owned by the account. 201, with the stage's path in `Location`; its id is the `id` output. |
| `read` | `surface:surface.stage:read`, `id` | `GET /surface-api/surface.stage/{id}` | The stage's label. |
| `update` | `surface:surface.stage:update`, `id`, `values: {label}` | `PATCH /surface-api/surface.stage/{id}` | Renames it. |
| `changes` | `surface:surface.stage:changes`, `id`, `values: {}` | `POST /surface-api/surface.stage/{id}:changes` | Lists the staged writes. `GET .../{id}:changes` is its envelope, as for any custom method. |
| `apply` | `surface:surface.stage:apply`, `id`, `values: {}`, `dry_run` | `PATCH /surface-api/surface.stage/{id}:apply`, `?dryRun=true` | Makes every staged write, or with a dry run checks them and writes nothing ([Apply](#apply)). |
| `delete` | `surface:surface.stage:delete`, `id` | `DELETE /surface-api/surface.stage/{id}` | Discards the stage and every write it holds. Nothing it holds is written. |

`changes` answers `items` and `total`. Each item is one write:
`position` (the order apply makes it in), `resource`, `operation`,
`kind`, `parameters` (a list of `{name, value}`, each value as a
string; for a create, its local id or the name its caller gave),
`changed` (the top-level keys moved, as JSON Pointers:
`/title`) and `staged` (when it was first staged). It lists no values: a
value is read through the thing's own read tool or `GET`, with the
stage named, which leaves out secrets and keys the account may not see
as every read does.

`apply` answers `applied`, the same list with `changed` a boolean and,
once written, `id`, the id each write landed on as a string; a create's
`parameters` still carry its local id beside it. A thing with no id, a
settings object, has empty `parameters` in both lists and no `id`.
Apply's own `changes` is `['']`: a structure with no keys has no document to
compare, so the pipeline answers the whole call as the one change, as a
delete does. `apply` declares `destructive` and is not idempotent.

## Naming a stage

### The `stage` input

Every write, read and narrowing tool of an operation a stage applies to
([below](#where-a-stage-applies)) takes one more input, `stage`, last
(on a write tool, right after `dry_run`): the stage's id, from
`surface:surface.stage:create`. Empty, its default, means no stage:
permanent storage. A content entity, a config object and a config
entity can be staged. The stage's own six tools take none, nor does the
tool of a resource kept in State, a listing, or an operation that names
no one thing, such as the venues' reorder.

```
drush tool:run surface:entity.content:node.page:update --uid=1 --json \
  --input='{"id":"3","stage":"9033…","values":{"title":{"value":"Moved"}}}'
```

- A write keeps the values in the stage. It answers the shape every
  write answers: `committed: true` (the storage it was handed, the
  stage, holds them), `changes` against the stage's view (what this
  call moved since the last staged write), and the message "Edit Basic
  page: the values were accepted and staged in 9033…." A write that
  moves nothing says the values are what is staged, so nothing was
  written: "Edit Basic page: the values were accepted, and they are what
  is staged in 9033…, so nothing was written." A create
  whose storage gives the id answers a local id, `id: -1`, which names
  the thing only with this stage, and every create says nothing is
  created until it is applied
  ([A staged create](#a-staged-create)).
- A read answers the thing as it stands in the stage, and says the
  values staged in the stage were read; without the input, what is
  stored.
- For an operation with parameters, the refined `values` input defaults
  each key to the staged view, so a caller reading the listing after
  naming the stage sees what it staged. A settings object's `configure`
  has no parameters and no refined input: its `values` input describes
  what is stored, and the staged view is read through its read tool with
  `stage`.
- A narrowing tool narrows over the staged view.

An id that names no stage is the caller's input to correct, code
`not_found`. A call refuses another account's stage, code `access`,
before the thing the parameters name is asked about or anything is
built, and the message names the stage. The Tool API runner (`drush
tool:run`, MCP) asks the tool's access check before the call, and that
check builds without a stage the account may not use: where the thing
would refuse without it, as `id: -1` does, the runner answers its fixed
refusal, `access_denied`, which does not name the stage.

### The `X-Surface-Stage` header

The HTTP door takes the same id in a request header,
`X-Surface-Stage` (`ServedPaths::STAGE_HEADER`), on every call a stage
applies to. Not a query parameter, which a listing
reads as a value, and not a path segment, which is the thing's
identity.

```
PATCH /surface-api/entity.content:node.page/3
Content-Type: application/merge-patch+json
X-Surface-Stage: 90330186-6d51-43bf-a4cd-d7f144e63c1e

{"title": {"value": "Moved"}}
```

- `PATCH`, an update, keeps the values in the stage. The answer is
  the one every write answers; it names no stage, since the caller
  did. Its `contract` is the envelope of the staged view and its `ETag`
  that view's fingerprint.
- `POST` on the collection, a create, keeps the whole document in the
  stage ([A staged create](#a-staged-create)). The answer is 200, not
  201: `committed: true`, `outputs.id` the local id (`-1`), `contract`
  the create's envelope, `created` naming where the thing lives in the
  stage, `{resource, operation: "update", parameters: {id: -1}}`, no
  `Location` and no `ETag`. Nothing is created until the stage is
  applied.
- `DELETE`, a delete, keeps the removal in the stage
  ([A staged delete](#a-staged-delete)). The answer is the one every
  delete answers: `committed: true`, `changes: [""]`, the values the
  thing held, no `contract` and no `ETag`. The thing is removed only
  when the stage is applied.
- `GET` answers the staged view and its `ETag`; without the header,
  what is stored and its `ETag`.
- `If-Match` is held to the view the header names: with the staged
  `ETag` a staged write passes, with the stored one it is 412.
- `POST .../schema` narrows over the staged view. `GET .../schema`
  ignores the header: the full schema reads nothing stored.
- Every answer stays `Cache-Control: no-store, private`.

The route's access check (`ServedOperationAccessCheck`) reads the
header's stage before the controller runs, so the answers come in this
order. Another account's stage is 403 on any call, answered before the
thing the path names is asked about or anything is built. The header on
a path of the stage itself is 400: a stage is not staged. The header on
any other call a stage does not apply to, a read included (a resource
kept in State, the venues' listing or reorder), is 422 with one
violation at no path, code `does_not_apply`: "A stage does not apply to
this operation.", whether or not the stage exists. The controller
answers both (`ResourceApiController::served()`), before it reads the
stage the header names. A header that names no stage is 404 only on a
call a stage applies to.

The OpenAPI document describes the header once, as the parameter
`SurfaceStage`, and references it from the envelope, the narrowing and
every write of each operation a stage applies to.

### The `stage` query on a form page

An operation form page reads `?stage=<id>` from its query and writes
into that stage instead of storage, by the same rule as the tool and
the header. [The form door](#the-form-door) says what the page does
with it.

### From PHP

A stage is opened as any thing is created, and its id is what the
storage reports after the run, read through the pipeline's `outputs()`:

```php
$resources = \Drupal::service('surface.resources');
$pipeline = \Drupal::service('surface.pipeline');
$create = $resources->operation('surface.stage', 'create');
$built = $resources->build('surface.stage', $create);
$storage = $resources->storage('surface.stage', $create, $built, $account);
$result = $pipeline->submit($built, ['label' => 'Edits for review'], $storage, access: $resources->access('surface.stage', $create, $account));
$id = $pipeline->outputs($built, $storage, $result)['id'];
```

`ResourcesInterface::storage()` takes the stage as its fifth argument
and wraps the storage it builds otherwise; the pipeline runs unchanged.
Ask the stage's access first, as the doors do:

```php
$stage = \Drupal::service('surface.stages')->named($id)->withArguments(['id' => 3]);
$update = $resources->operation('surface.stage', 'update', ['id' => $id]);
if ($resources->access('surface.stage', $update, $account)->isAllowed()) {
  $context = $resources->operation('entity.content:node.page', 'update', ['id' => 3]);
  $built = $resources->build('entity.content:node.page', $context);
  $storage = $resources->storage('entity.content:node.page', $context, $built, $account, $stage);
  $result = $pipeline->submit($built, $values, $storage);
}
```

`withArguments()` hands the operation's parameters by name, which the
stage records beside the write so apply can build the operation again.

To write to a thing the stage creates, hand the stage to `operation()`
too, as the doors do: the local id then names the staged create, and
the context is that create's
([A staged create](#a-staged-create)):

```php
$context = $resources->operation('entity.content:node.page', 'update', ['id' => -1], $stage);
$built = $resources->build('entity.content:node.page', $context);
$storage = $resources->storage('entity.content:node.page', $context, $built, $account, $stage);
$result = $pipeline->submit($built, ['title' => ['value' => 'Renamed']], $storage);
```

Without the stage, `-1` names nothing (`NamesNothingException`).

A read is a run with no values: the stage's `read` resolves to the
update's context, so the run writes nothing and answers what is stored
in `$result->values`, the stage's label among them (ask its access
first, as above):

```php
$read = $resources->operation('surface.stage', 'read', ['id' => $id]);
$built = $resources->build('surface.stage', $read);
$storage = $resources->storage('surface.stage', $read, $built, $account);
$result = $pipeline->submit($built, [], $storage); $label = $result->values['label'];
```

`Staging\StageRaceException` is what a staged write, an apply or a
stage's own create, rename or discard throws on a race: the stage's
lock was not had, or its record moved under the operation (applied,
expired or discarded, a write the rehearsal did not see, or a staged
thing changed since its token was taken; [Apply](#apply)). PHP code
that applies or writes into a stage catches it and tells the person to
try again, as the entity example's confirm page does. The tool door
answers it as a runtime failure naming the class, and the HTTP door as
a server error. The form door catches it where it writes into a stage,
opens one for a flow's first step, applies one and discards one: the
page says the stage changed and to try again, as an error, and is built
again. A flow's first step whose write is refused or races after it
opened the stage deletes that stage, so no empty one is left.

(why: [decision](decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context))

## Where a stage applies

One rule, `Staging\Stageable`, says where a stage applies. It decides
the four doors and prepare: the tool's `stage` input, the OpenAPI
`SurfaceStage` header, the HTTP door's header, the operation form's
`?stage` query and the staged storage's prepare. Prepare alone also checks that the storage of each routed child
is transactional, and refuses the write, code `does_not_apply`, where
one is not. A resource that routes a child to such a storage still
offers the stage input and the header, and is refused at the write. A
stage applies to a call when both hold:

- **The storage opts in.** The resource's declared storage class
  implements `Pipeline\TransactionalStorageInterface`
  ([below](#how-a-storage-opts-in)).
- **The call names a thing.** It creates, or its resource has no
  identity keys (a settings object is one thing), or its parameters name
  at least one of them. The venues' reorder writes several venues and
  names none, so no stage applies to it. A read-only operation other
  than the read of one thing, a listing, reads no one thing.

(why: [decision](decisions.md#one-rule-says-where-a-stage-applies-at-every-door))

## How a storage opts in

A stage takes a write only for a resource whose storage class
implements `Pipeline\TransactionalStorageInterface`; for any other
resource no tool takes the input, the header is 422, and a write named
into a stage anyway from PHP is refused at prepare, code
`does_not_apply`, naming the class. The interface is a promise:
`commit()`, and `delete()` where the storage also implements
`DeletableStorageInterface`, write their permanent state through the
database connection alone, so the transaction apply opens undoes the
write whole; what they put in caches or in memory is undone in
`rolledBack()`. The content entity storage of the entity example
implements it, and so do the two config storages, `ConfigObjectStorage`
and `ConfigEntityStorage`, for every subclass.

Its one method, `rolledBack()`, is called after an apply rolls back, on
the storage of every write whose commit had started. A storage drops
there what it cached beside the database. The entity example resets the
entity storage's cache of what it saved or deleted:

<!-- excerpt: modules/surface_examples_entity/src/Storage/ContentEntityStorage.php -->

```php
public function rolledBack(): void {
  foreach ($this->written as $type => $ids) {
    $this->entityTypeManager->getStorage($type)->resetCache(array_values($ids));
  }
  $this->written = [];
}
```

A config storage leaves it empty: apply itself resets what config keeps
beside the database ([Config in a stage](#config-in-a-stage)). A storage
that caches nothing leaves the body empty too, as the stage fixture's
row in a database key-value collection does:

<!-- excerpt: tests/modules/surface_stage_test/src/Storage/LedgerStorage.php -->

```php
public function rolledBack(): void {
  // The database's key-value collection keeps no cache to drop.
}
```

[Storage: Opting into staging](storage.md#opting-into-staging) says
what the promise rules out.

## What is refused

Each is refused at the staged write's prepare, a violation of the whole
call:

- **Anything kept in State**, code `does_not_apply`: its storage does
  not implement `TransactionalStorageInterface`, since State keeps a
  cache beside the database that a rollback leaves stale. The message
  names the storage class.
- **A routed child a stage cannot hold**, code `does_not_apply`: a
  resource whose child is routed to a storage that does not implement
  `TransactionalStorageInterface`, since apply commits the child in the
  same transaction. The message names the child's key and its storage
  class. No shipped resource routes a child under a storage a stage
  takes.
- **An operation that names no thing**, code `does_not_apply`: one that
  creates nothing, of a resource with identity keys, whose parameters
  name none of them, such as `event.venue:reorder`. The message names
  the operation and the identity keys.
- **A second resource over one thing**, code `conflict`: a page staged
  through `entity.content:node.page` cannot be staged again through
  `entity.content:node` in the same stage, and the catering staged
  through `event.catering` cannot be staged again through
  `event.catering.method`, which keeps the same config object: two
  resources of one storage class whose contexts know no identity are one
  thing. One thing is also written
  through one operation per stage, except that a delete replaces what
  the stage holds for it ([A staged delete](#a-staged-delete)).
- **A write to a thing the stage removed**, a second delete among them,
  code `not_found`: "This was removed in the stage; the removal stands
  until the stage is applied or discarded."
- **The stage's own storage**: a stage is not written into a stage.
- **A write into a stage that was applied**, code `does_not_apply`, and
  one that expired or was discarded, code `not_found`.

## Config in a stage

A config object (`ConfigObjectStorage`) and a config entity
(`ConfigEntityStorage`) are staged as any thing is: the write is
validated against the config schema and rehearsed at staging and again
at apply, a read through the stage answers the staged view, and apply
saves the config in position order. A config object is one thing with
no id: `changes` and `applied` list it with `parameters: []`, and
`applied` gives it no `id`. A config entity is named by its id, and a
staged create by the name its caller gave, or by the machine name
computed from its label. A key the stored object leaves out and the
write prepares empty, such as `third_party_settings` where no module's
keys are stored yet, is not a key the stage moved, so apply does not
send it. A settings object's `configure` tool has no refined `values`
input, since the operation takes no parameters: the input describes
what is stored, and the staged view is read through the read tool with
`stage`.

**What apply resets after a rollback.** A rollback undoes the config
table's rows, but not what config keeps beside them in the running
process and in the cache bin. While apply's transaction is open, an
internal subscriber (`surface.config_writes`) records the name of every
config that is saved, deleted or renamed, by a config storage, a
subclass that saves more, a dependent core saves or deletes in a cascade,
or a hook. After the rollback, apply releases the transaction, so the
cache tags the writes invalidated are discarded, and then, for those
names:

- deletes each name's item from the `cache.config` bin, which also tells
  every web server's fast cache (APCu) that its copy is out of date;
- resets the config factory's objects of each name and the cached
  storage's lists by prefix;
- empties the entity memory cache, and clears the cached bundles and
  field definitions, which a bundle entity's save rebuilt;
- resets State's cache.

Config objects other code already holds keep what they were handed.

**The schema-change limit.** On a database without transactional
schema changes, such as MySQL and MariaDB, a staged write whose commit
changes the database's schema commits the transaction at once: a field
storage created or deleted, or a bundle delete that removes the bundle's
fields and with them a field storage. PostgreSQL and SQLite roll schema
changes back. Where the database cannot, the writes at and before the
failing one stand when a later write fails, and the write that changed
the schema may itself stand in part. Apply still runs the resets, marks
the stage `voided`, and answers the failure as a `RuntimeException`
whose message starts "Writes at and before position N were made
permanent by a schema change and could not be rolled back; the stage is
voided. Discard it.", N the position that failed. A voided stage holds
no writes; its `applied` lists the writes that completed and its
`voided` the position. Apply and every staged write into it are refused,
code `does_not_apply`, naming the position; only `delete` acts on it.
When every write succeeds after such a change, the writes are permanent
already and the stage is applied as usual. None of the shipped resources
a stage takes makes such a write: the field resources keep their own
storages, which a stage does not take.

**The header on a call a stage does not apply to** is 422 at the HTTP
door ([The `X-Surface-Stage` header](#the-x-surface-stage-header)).

(why: [decision](decisions.md#config-is-staged-and-apply-resets-what-its-rollback-leaves-behind),
[decision](decisions.md#two-identity-less-entries-of-one-storage-class-are-one-thing))

## Secrets in a stage

A resource that declares a secret can be staged (`event.webhook`'s
`api_key`, `event.mailer`'s credential). The stage's record holds the
whole document the pipeline merged, the secrets stored for the thing
among them, so it seals them:

- **Sealed with the site's codec.** Before the record is written, each
  string at a secret path is encrypted with the `surface.secret_codec`
  service, whose key the shipped codec derives from the site's hash
  salt. The entry records the paths it sealed, under `sealed`, each a
  list of segments: `[["token"]]`, a list's position as a string. NULL
  and the empty string are kept as they are.
- **Opened only by those paths.** A read through the stage by its owner
  opens the recorded paths, so a later write that sends no secret keeps
  the staged one; every door leaves the secret out of what it answers,
  as every read does. Apply opens them before it sends the values. A
  value that only looks sealed, at a key that is not secret, is never
  opened.
- **`changes` carries paths only.** A staged secret is listed as
  `/token` in `changes` and in a dry run, never with its value.
- **A cleared secret.** A write that sends the clear marker
  (`SurfacePipelineInterface::CLEAR_SECRET`) leaves NULL at the path,
  which at apply would mean "keep what is stored". The entry records the
  path under `cleared`, and apply sends the clear marker there, so the
  secret is removed. A later write that sets the secret again takes the
  path out of `cleared`. Only a key the write holds is a clear: every
  parent on the path is a map or list in the write's values and the key
  is present with nothing in it. A secret removed with its parent, a map
  set to NULL or a list that shrank, is no clear, and apply builds no
  parent again.
- **The hash salt.** A stage sealed under a hash salt the site no longer
  has cannot be opened. A read through it leaves the sealed secret out,
  as every read does; a staged write into it and its apply are refused,
  code `does_not_apply`, "The stage … was sealed under another hash salt
  and cannot be opened; discard it." Discard such a stage and stage the
  writes again.

Permanent storage is unchanged: a resource's storage keeps its secrets
as it always did, in the clear unless its layout seals them
([Storage: Secrets at the codec](storage.md#secrets-at-the-codec)).

(why: [decision](decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths))

## A staged create

A create tool takes `stage` as every write tool of a resource a stage
takes does, and the HTTP door takes the header on `POST`:

```
drush tool:run surface:entity.content:node.page:create --uid=1 --json \
  --input='{"stage":"9033…","values":{"title":{"value":"New"},"field_description":{"value":"In the hall."}}}'
```

- **Whole.** A staged create is validated and rehearsed as a real
  create, at staging and again at apply: every required key, the
  entity's own validation and, for an id the caller names, the
  storage's check that no stored thing has it. Its first write sends
  what a create requires; later writes in the stage change it
  (why: [decision](decisions.md#a-staged-write-validates-as-a-real-write)).
- **The entry.** An entry of kind `create`, added at the stage's next
  position: no arguments (a create takes no parameters), no token
  (nothing is stored to compare), the whole document the pipeline
  accepted, every key changed, and its address.
- **The address, its local id.** Written once, at the create's first
  write: for an id storage gives on save, the negative of the entry's
  position, `-1` for the first entry, `-3` for the third, an integer as
  the `id` output is typed; for an id the caller names, that name.
  Positions are never reused, so a local id is never reused in its
  stage. It names the thing only together with its stage.
- **The answer.** `committed: true` (the stage holds the create),
  `changes` every key of the document, `outputs.id` the local id (or the
  name the caller gave), and the message "Add Basic page: the values
  were accepted and staged in 9033…; nothing is created until it is
  applied, and a write in the stage names it by -1." A dry run of a
  create has no address yet, and answers no `id`.
- **Writing to it.** An update with the stage and `id: -1` is built as
  the create's own context again, under the update's id, and merges into
  the create: the entry keeps its position, kind and address, its values
  become the merged document and its changed keys the union. The merged
  document is validated and rehearsed whole, as a create, so an update
  that clears a required key is refused `required`. A write that moves
  nothing answers `changed: false` and `changes: []`, writes no record,
  resets no expiry, and says the values are what is staged, so nothing
  was written. A thing a stage creates computes its computed keys from
  the merged sources on every write, as apply will; a key computed
  unless sent keeps a value sent for it. The answer's
  `outputs.id` is `-1`, for a dry run too.
- **Reading it.** A read with the stage and `id: -1` answers the merged
  values, its computed keys among them, and the refined `values` of the
  update tool default to them.
- **Dropping it.** A delete with the stage and `id: -1` takes the create
  out of the stage: no entry, nothing in `changes`, nothing to undo, and
  the next position unchanged, so the next create is still `-3` after
  `-2`. Its message: "Delete Basic page: the create staged in 9033… was
  taken out of it; nothing was created or removed." After that, `-1`
  names nothing, as without the stage: the stage holds no create at it,
  so its context is built as with no stage, and the owner finds no node
  `-1`. The tool's call answers `not_found` ("Not found: …"); the Tool
  API runner answers its fixed `access_denied` and HTTP its fixed 403,
  since the entity resources declare no operation permission (an
  account holding a resource's permission hears not found). Only a
  write whose context was built before the drop and prepared after it
  is refused by the stage, code `not_found`: "The stage no longer
  creates this thing: its create was taken out of the stage."
- **`changes` and `applied`.** `changes` lists the create with `kind:
  "create"` and its address as its parameters, `[{name: "id", value:
  "-1"}]`, or the name the caller gave. `applied` pairs the same
  parameters with `id`, the id storage gave, as a string.
- **Over HTTP**, `POST` with the header answers 200, not 201, since
  nothing was created: `outputs.id` the local id, `contract` the
  create's envelope, `created` `{resource, operation: "update",
  parameters: {id: -1}}`, the update that reaches it in the stage, no
  `Location` (a `Location` has to resolve without a header) and no
  `ETag`. `GET` and `PATCH` at `/-1` with the header answer 200 with
  the staged view's `ETag`, and `If-Match` compares staged with staged;
  `POST .../schema` at `/-1` with the header answers 200 narrowed over
  the staged view, with no `ETag`, as a narrowing has none without a
  stage; `DELETE` at `/-1` with the header answers 200.
  `/-1` without the header is the route's fixed refusal, 403.
- **Access.** A write, a read and a delete of `-1` are asked as the
  bundle's create access, the access of the thing they are about: an
  account that may create pages but not edit them reads back and
  changes its own staged create. (Node's create access asks `access
  content` too, so the account holds it.) The stage's own access is asked
  first.
- **A name the caller gives.** Where the caller names the thing (an
  entity type whose id is a string with no default), a later write in
  the stage names it by that name, and the name is fixed: a write that
  sends another is refused, code `no_such_choice`. A second staged
  create of the same name through any resource of the same storage
  class is refused at the name, code `taken`: "A … named first is
  created in this stage already, at position 1." A name already stored
  is refused by the storage, as without a stage; one stored by another
  path after staging is refused at apply, at `/writes/<position>/id`,
  code `taken`.
- **What is refused.** A reference to `-1` from another value, staged or
  not, code `valid_reference`, "This entity (node: -1) cannot be
  referenced.": the reference check reads permanent storage and sees no
  stage. `-1` through another resource of the same entity type
  (`entity.content:node` after a create through
  `entity.content:node.page`) names nothing: the address belongs to the
  resource the create was staged through. `-1` without the stage names
  nothing. A rename of a caller-named create is refused, as above.
- **Apply** rehearses each create as a real one from its whole merged
  document, create access asked again for the owner, and creates it
  once, in its place in position order. A rollback leaves nothing
  created.

(why: [decision](decisions.md#a-staged-create-is-whole-and-rehearsed-as-a-create),
[decision](decisions.md#a-staged-create-is-named-by-a-local-id-and-a-later-write-merges-into-it),
[decision](decisions.md#a-stage-decides-the-context-of-a-thing-it-creates))

## A staged delete

A delete tool takes `stage` as every write tool of a resource a stage
takes does, and the HTTP door takes the header on `DELETE`:

```
drush tool:run surface:entity.content:node.page:delete --uid=1 --json \
  --input='{"id":"3","stage":"9033…"}'
```

- **The entry.** The stage records the delete as an entry of kind
  `delete`, its values empty and its one changed key the empty pointer,
  `changed: [""]`: the whole thing, as the pipeline answers `changes:
  [""]` for any delete. Its token is what was stored when the thing was
  first read through the stage. `changes` lists it with `kind:
  "delete"` and `changed: [""]`.
- **The answer.** `committed: true` (the stage holds the removal),
  `changes: [""]`, the values the thing held in the stage's view, and
  the message "Delete Basic page: the removal was accepted and staged
  in 9033…; nothing was removed." A dry run with `stage` says what a
  dry run of any delete says, and stages nothing.
- **Read through the stage**, the thing answers nothing: a load
  through the stage answers no values for a thing it removed. Without
  the stage, what is stored, which is still there.
- **Delete, then delete or update.** A second delete of the thing, and
  an update of it, are refused, code `not_found`, "This was removed in
  the stage; the removal stands until the stage is applied or
  discarded." No operation discards one write; discarding the stage
  discards them all. Through another resource over the same thing the
  refusal is the `conflict` above.
- **Update, then delete.** A delete of a thing the stage already holds
  an update of replaces that entry where it stands: the entry keeps its
  position, when it was first staged and its token, and takes the
  delete's operation, its arguments, empty values and `changed: [""]`.
  Apply then removes the thing in the place the update held.
- **Apply** rehearses the delete as a real one, its access asked again
  (delete access, for the entity example), the token compared with what
  is stored, and then deletes the thing as its storage deletes: a
  content entity with every translation and revision it has, removed
  by core's storage, kept marked deleted where a module such as trash
  replaces it ([Entities: The storage](entities.md#the-storage)).
  `applied` lists it with `kind:
  "delete"` and `id`, the thing's id as a string. A rollback leaves it
  in place.

## The form door

Every operation form page (`SurfaceOperationForm`,
[Forms](forms.md#the-operation-form)) reads `?stage=<id>` from its
query. With one, the page writes into the stage: the operation's
context is built through it, its storage is the staged storage, and
the pipeline runs unchanged over it. Nothing is written to storage.

- **Only the operation form.** A page served by an operation through
  anything else, a React page or a controller, refuses a stage in its
  query: "A stage does not apply to this page: only an operation form
  reads the "stage" query." So no page writes to storage while its URL
  names a stage.
- **Who may.** The route's access check asks the operation's permission
  first. Then it and the form ask the same three things, in this order:
  whether a stage applies to the route's operation
  ([above](#where-a-stage-applies)), refused "A stage does not apply to
  the "create" operation of route_test.card (does_not_apply), so this
  page takes no "stage" in its query."; whether the stage exists, a page
  not found when it does not; and the stage's own access, which only
  its owner holds, refused "The stage is another account's.". Then the
  thing's access, as without a stage; both have to allow. Nothing the
  stage holds is read for an account its access refuses.
- **Links.** Access asked about a link reads the current request's
  query only for a link to the current route, so the page's own links
  agree with it. A link to any other route is judged with no stage, at
  its first step: a link to a flow page that carries another account's
  stage is drawn, and answers 403 when followed.
- **A local id.** The context is built through the stage, so a path
  that carries a staged create's local id (`/surface-entity/node/-1/edit`)
  names what the stage creates, where the route's requirement admits a
  negative id.
- **The messages.** The page says what was staged, as the tool door
  does, and names the stage by its label, or its id when it has none:
  "Configure registration (step 1): the values were accepted and staged
  in Registration; nothing is written until it is applied."; values that
  are what the stage holds already, "... they are what is staged in
  Registration, so nothing was written."; a create, "... nothing is
  created until it is applied, and a write in the stage names it by -1.";
  a delete, "... the removal was accepted and staged in Cancel; nothing
  is removed until it is applied."; a delete of a staged create, "...
  the create staged in Cancel was taken out of it; nothing was created
  or removed."
- **No cosmetic message or redirect.** Under a stage the cosmetic
  layer's message and redirect are not asked: they say a thing was
  saved and go to pages that drop the query. The page stays where it
  is, the stage still in its query. The cosmetic layer still arranges
  the form. Submit or validate handlers a cosmetic layer adds to the
  form still run under a stage, so they must not write to storage.
- **A delete's confirmation** says "... trip is removed when the stage
  is applied; nothing is removed now." instead of "this cannot be
  undone".
- **Caching.** Every operation form page varies by `stage` and `step`
  in its query (`url.query_args:stage`, `url.query_args:step`); one that
  names a stage has max-age 0.

(why: [decision](decisions.md#one-rule-says-where-a-stage-applies-at-every-door))

## Flows

A flow draws one operation's form over several steps into one stage,
one group a step, and ends in a confirm page that applies the stage. It
is declared on the operation form route, never on the resource: the
build, the tools and the HTTP door do not see it.

```yaml
surface_examples_node_type.flow:
  path: '/admin/structure/types/manage/{type}/surface-flow'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Edit content type, step by step (resource)'
    _surface_resource: 'Drupal\surface_examples_node_type\Resource\NodeTypeResource'
    _surface_operation: 'update'
    _surface_cosmetics: 'surface_examples_node_type.form_cosmetics'
    _surface_flow: { steps: [submission, workflow, display], redirect: entity.node_type.collection }
```

- **Steps.** `steps` lists group ids of the route's operation, the
  order they are drawn in. `label`, optional, is the stage's label, the
  operation's label by default. `redirect`, optional, is the route the
  page goes to once the stage is applied, handed the flow route's own
  parameters its path takes; without it the page stays on the confirm
  page. The first step also draws every key in no group (here the name,
  the machine name and the description). A key in a group no step names
  is never drawn and keeps what it holds.
  Every step writes through the route's one operation: a stage refuses
  a second operation on one thing.
- **The query.** `?step=N` names the step, from 1, and `?stage=<id>`
  the stage. The first step needs no stage: its submit opens one as the
  current user and stages the step's keys into it. Every later step
  needs the stage; without one it is refused, and a step the flow does
  not have is a page not found.
- **Next and Back.** Next stages the step's keys through the pipeline
  and goes to the next step, with the stage kept, or to the confirm page
  after the last. Back goes to the step before and stages nothing. A
  `destination` in the query is ignored: Next, Back, Discard and Apply
  go where the flow says. A key the step does not draw says nothing,
  and the staged load overlays what earlier steps staged, so a later
  step sees earlier values, and apply sends only the keys that moved.
- **A flow over a create.** The first step stages the create; each
  later step writes into it, as the one create of the resource the stage
  holds through the operation. A later step whose create was taken out
  of the stage is refused, "... its create was taken out of the stage;
  start the flow again.": a fresh create there would stage a second
  thing. The confirm page needs no create, so it still applies or
  discards the stage.
- **The confirm page**, `?step=confirm`, lists the stage's `changes` as
  a table: each write's position, resource, operation, kind, parameters
  and the keys it moved. It says "Each is checked again when it is
  made. They are made together or not at all." When a write is a delete,
  or its operation is destructive, it says "One of these cannot be undone
  once applied." and Apply is a danger button. Back goes to the last
  step. "Discard the stage" deletes the stage through its `delete` and
  goes back to the first step with no stage; it is a button, since a
  link may not delete. "Apply the stage" applies what the page listed:
  the page posts back a fingerprint of the stage's writes as it drew
  them, and when the stage holds other writes by then, written from
  another tab or another door, Apply is refused, "The stage changed
  since this page was drawn; it is listed again below.", and the page
  lists them. Otherwise it rehearses the apply when the page is
  submitted, each refusal said on the page and nothing written, then
  applies it through the stage's `apply`: "Applied 2 writes.", and the
  page goes to the flow's `redirect`, or stays and shows the stage
  applied. The cosmetic layer's redirect is not asked: it is handed an
  operation's result, not the stage's apply. An applied or voided stage
  shows its status and what apply made, and no Apply. This page is how a form door shows a run's outputs:
  `changes` before the apply, `applied` after.
- **What a flow can stage.** Updates, and creates whose required keys
  with no default are all drawn at the first step: each step is
  validated as the whole write, as every staged write is
  ([A staged create](#a-staged-create)).

What is refused, and where:

- **When the router is rebuilt**, what the route alone shows: a
  `_surface_flow` that is no map of `steps`, `label` and `redirect`, a
  `label` or `redirect` that is no non-empty string, steps that are no
  list of distinct group ids, a flow on a route that names no operation,
  and a flow on a route whose `_form` is not the operation form. The
  rebuild stops, naming the route.
- **When the route is matched**, by its access check and by the form,
  what needs the resource built, each refused saying why: an operation a
  flow cannot stage, a delete, a read-only operation, or one a stage
  does not apply to; a step that names a group the operation does not
  have; a key drawn before a key
  its refinement depends on, "... draws "guide" at step 1, before
  "region" at step 2, and "guide" is refined by the value of "region"
  ..."; and for a create, a required key with no default drawn after the
  first step, naming the key. A key a rule requires only for some values
  cannot be seen before values exist, so it is not checked there: the
  pipeline refuses the step that leaves it empty.

(why: [decision](decisions.md#a-flow-is-declared-on-the-route-that-draws-it-not-a-resource),
[decision](decisions.md#a-step-order-is-checked-against-the-refiner-graph-and-a-creates-required-keys-at-match-time),
[decision](decisions.md#a-flow-ends-in-a-confirmation-page-that-shows-the-stages-changes-and-applies-it))

## A flow by hand: bulk delete from the content list

The entity example builds a flow by hand, over core's content list
(`modules/surface_examples_entity`): a bulk delete that stages, then
confirms over the stage. Declared flows ([above](#flows)) draw one
operation over one thing; this one stays code, since it stages a
delete of many things, chosen on a listing.

- **The action.** The example ships a `system.action` config entity,
  "Delete content through a stage" (plugin
  `surface_entity_stage_delete`, type `node`), which the content list's
  bulk form offers beside core's "Delete content". The bulk form runs it
  on the rows its `access()` lets through, the node's own delete access
  as for core's. The list has one row per translation and hands the
  action each row's translation, so two rows of one node are one node.
  The action asks the delete access of each node through
  `entity.content:node`, leaving a refused one out with a message; opens
  a stage as the current user, labeled with the count of the nodes
  allowed, "Delete 2 items"; stages one delete per node; and keeps
  `['stage' => <id>, 'ids' => [...]]` in the private temp store
  collection `surface_examples_entity` under `stage_delete`, one
  selection per account, as core's delete action keeps its own. A
  delete the stage refuses is left out with a message too, so the label
  may count more nodes than the page asks to delete; with no node
  allowed, no stage is opened. Nothing is removed.
- **A limit of the example.** A translation's row stages the removal of
  the whole node with every translation, as the storage deletes
  ([Entities: The storage](entities.md#the-storage)), where core's own
  "Delete content" removes the one translation.
- **The confirm page.** The bulk form sends the account to the action's
  `confirm_form_route_name`, `/surface-entity/delete`, a
  `ConfirmFormBase` page. It lists the selected nodes as they are
  stored, since each is there until the stage is applied, names the
  stage by its label, asks "Delete 2 items?", and is never cached. The
  label is read by running the stage's `read`, which the module resolves
  to the update's context, through the pipeline with no values, a run
  that writes nothing and answers what is stored in `$result->values`
  ([From PHP](#from-php)); the read tool and the HTTP door read stored
  values through the storage's `load()` instead. Before it asks, it
  runs the stage's `changes` the same way and reads `total` through the
  pipeline's `outputs()`: with `total` 0 (the stage was applied through
  another door, or holds no write) it clears the selection and goes back
  to the content list, "The stage of this selection holds nothing to
  apply: it was applied or emptied. Select the content again to stage
  removals afresh." With no selection, or a stage that expired or was
  discarded, it goes back to the content list with a message, the
  selection cleared; with a stage the account may not read, it goes
  back with the selection kept.
- **Delete** runs the stage's `apply` through the pipeline for the
  current user, the stage's owner ([Apply](#apply)): each delete is
  checked again, its delete access and its token, and all are made in
  one transaction. Committed, the page says "Deleted 2 items.", the
  count of `applied`, clears the selection and goes back to the content
  list, or to the `?destination` the bulk form set.
- **A refusal** removes nothing. A refusal of a write is shown as one
  error per refusal, by its path and its code, `/writes/1:
  entity.content:node 3: what is stored changed since it was staged.
  (conflict)`, the stage and the selection kept, and a last line that
  ends in one of two ways. A `conflict` (a selected node changed since
  it was staged) or a `not_found` (one was removed elsewhere) never
  clears: the stage keeps the token it took and refuses a second delete
  of the node, `not_found`, so the page says to select the content again
  on the content list, which opens a new stage and replaces the
  selection. Any other refusal, an `access` one among them, says the
  stage keeps every delete; it clears once its cause does (the account
  may delete the node again), and the page can then be submitted again.
- **A refusal of the whole stage**, `does_not_apply` at no path, "The
  stage was applied; nothing is pending." (applied through another
  door since the page was built) or "Nothing is staged.", clears the
  selection and goes back to the content list with that message alone:
  no delete is left to keep.
- **A race.** A stage that changed while it was being applied is
  refused with "The stage changed while it was being applied (another
  request, an edit to a selected item, or the stage expired, was
  discarded or was applied); try again.": the page catches the
  `StageRaceException` apply throws and is built again, so the selection
  is kept when the lock was not had or a selected item changed, and
  cleared when the stage was applied, expired or discarded by then.
- **Cancel** is a link back to the content list, and a link may not
  discard: the stage expires as any does, and the next selection
  replaces this one in the temp store.

The page reads the stage's `changes` for its `total` alone and lists
the nodes as they are stored. A declared flow's confirm page lists the
items of `changes` before the apply and `applied` after
([Flows](#flows)), which is how a form door shows a run's outputs.
(why: [decision](decisions.md#a-destructive-bulk-operation-stages-then-confirms-over-the-stage))

## Apply

Apply runs in two passes, as every write does: `prepare()` rehearses,
`commit()` writes.

1. **Prepare**, with no lock and no side effect. For each write, in
   position order: the operation is built again from its recorded
   arguments, its access is asked again for the owner, what is stored
   now is compared with the token, and the pipeline runs dry over the
   keys the stage moved (a create, which has no token, over its whole
   merged document). Every refusal is filed under
   `/writes/<position>` (a conflict or an access refusal at
   `/writes/<position>`, a value at `/writes/<position>/<key>`) and all
   of them are answered together, so one answer names every write that
   would fail. A dry run of apply stops here.
2. **Commit.** Under the stage's lock, the record and every token are
   checked again; then each rehearsed write is committed in position
   order, in one database transaction. If one throws, the transaction
   rolls back, what config kept beside the database for the config the
   writes touched is reset ([Config in a stage](#config-in-a-stage)),
   each storage whose write had started is told (`rolledBack()`), and
   the stage stays open. On a database without transactional schema
   changes, a write that changed the schema made the writes at and
   before it permanent: the stage is marked `voided` instead
   ([Config in a stage](#config-in-a-stage)).

**Conflict is what the `ETag` is.** Any change to the thing's carried
keys since it was staged refuses that write, code `conflict`, "what is
stored changed since it was staged", even a change to a key the stage
did not move. Read the thing, stage again, apply again.

**The record is kept, marked applied.** Its writes are emptied, what
apply made is recorded, and the stage still answers: `read`, and
`changes` with `total: 0`. A second apply is refused, code
`does_not_apply`, "The stage was applied; nothing is pending." The
record expires as any record does, or `delete` removes it.

**Applying from elsewhere.** `Resources::build()` builds for the current
user, so apply builds each write's data surface for the current user
while it writes as the owner; the stage's access refuses every account
but the owner, so the two agree at the tool and HTTP doors. Code that
applies a stage for its owner from elsewhere, a queue worker, sets the
owner as the current user first, with core's account switcher, and
switches back after
(why: [decision](decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner)).

## Stages and Workspaces

The active workspace is a fact of the request, so neither the stage nor
its access asks it. A staged write reads the workspace's view of the
thing and an apply writes it, as any save does:

- **Opened, written and applied in one workspace**, the apply writes
  that workspace: a pending revision there; Live is untouched.
- **Staged in a workspace that holds its own revision of the thing, and
  applied in Live**, the token was taken of the workspace's view and
  does not match Live's: the write is refused, code `conflict`.
- **Staged in a workspace whose view of the thing matches Live's** (the
  thing was never edited there), **and applied in Live**, the token
  matches and the apply writes Live, as a save in Live would.

A stage does not remember the workspace it was opened in. If it ever
should, the door would hand the workspace id to the stage as an
argument; storage and access would still not read it.

**A delete in a workspace** is refused by core. Workspaces forbids delete
access, in a workspace that is not Live, to an entity the workspace did
not create, and the entity example's access asks core's delete access:
a delete there is refused, code `access`, whether it is made at once,
staged, or staged in Live and applied in the workspace (at
`/writes/<position>`), and nothing is removed. An entity created in the
workspace may be deleted there. Code that runs a delete without asking
access meets core's own refusal, an exception from its predelete hook,
"This content item can only be deleted in the Live workspace."

## Limits

- **Invisible to render, Views and queries.** A staged value is read
  only through the doors that name the stage. A page, a listing, a View
  or an entity query reads permanent storage.
- **One translation.** The entity example writes the default
  translation, and a stage inherits that; a config object's translations
  are not staged.
- **A schema change at apply**, on a database without transactional
  schema changes, such as MySQL and MariaDB, makes the writes at and
  before the failing one permanent and voids the stage; discard it
  ([Config in a stage](#config-in-a-stage)). PostgreSQL and SQLite roll
  schema changes back.
- **A local id names a thing only with its stage**: no `Location` is
  sent for it, it cannot be referenced, and `-1` without the header is
  refused.
- **An id that names nothing on the stage's own paths.** The stage's
  operations declare no permission, so at the tool door's access check
  (`drush tool:run`, MCP) and the HTTP route's, an id that names no
  stage gets the door's one fixed refusal, as for any operation that
  declares none; a tool called from PHP, which runs no access check,
  answers `not_found`. A stage named through the `stage` input that
  names nothing is not found; one named through the header is 404 on a
  call a stage applies to, and 422 on any other
  ([The `X-Surface-Stage` header](#the-x-surface-stage-header)).
- **A required key after a staged delete.** The pipeline validates a
  write before the storage sees it, and a load through the stage
  answers no values for a thing it removed. So an update of that thing
  that leaves out a required key is refused for the key, code
  `required`, before it can be refused as removed in the stage; one
  that sends every required key is refused `not_found`.
- **A flow over a create** writes each later step into the one create
  of the resource the stage holds through the operation. A stage that
  holds two, one staged through another door, is refused at the flow's
  steps, naming both positions: open the flow without a stage. Its
  confirm page still applies or discards it.
- **A stage gone before Apply or Discard.** A stage that expired or was
  discarded before its confirm page is posted is a page not found, as
  the page is when it is drawn: the stage is read before any button's
  handler runs.
- **A local id in a form page's path** needs a route requirement that
  admits a negative id; the entity example's `\d+` admits none, so its
  staged creates are edited through the tool and the HTTP door.
- **A delete in a workspace** is refused, code `access`
  ([above](#stages-and-workspaces)).
- **Revisions at apply time.** An entity apply saves a new revision
  where the resource carries `revision_log`, dated when apply runs, by
  the owner; when the write was staged is in the stage, not on the
  revision.

The pipeline's [stages](pipeline.md#the-stages) (access, accept,
validate, prepare, commit) are the steps of one call; a stage on this
page is a set of calls kept for later.
