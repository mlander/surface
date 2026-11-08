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
string), `changed` (the top-level keys moved, as JSON Pointers:
`/title`) and `staged` (when it was first staged). It lists no values: a
value is read through the thing's own read tool or `GET`, with the
stage named, which leaves out secrets and keys the account may not see
as every read does.

`apply` answers `applied`, the same list with `changed` a boolean and,
once written, `id`, the id each write landed on as a string. Its
`changes` is `['']`: a structure with no keys has no document to
compare, so the pipeline answers the whole call as the one change, as a
delete does. `apply` declares `destructive` and is not idempotent.

## Naming a stage

### The `stage` input

Every write, read and narrowing tool of a resource whose storage can be
staged ([below](#how-a-storage-opts-in)) takes one more input, `stage`,
last (on a write tool, right after `dry_run`): the stage's id, from
`surface:surface.stage:create`. Empty, its default, means permanent
storage. The stage's own six tools take none, nor does the tool of a
config resource or of a resource kept in State.

```
drush tool:run surface:entity.content:node.page:update --uid=1 --json \
  --input='{"id":"3","stage":"9033…","values":{"title":{"value":"Moved"}}}'
```

- A write keeps the values in the stage. It answers the shape every
  write answers: `committed: true` (the storage it was handed, the
  stage, holds them), `changes` against the stage's view (what this
  call moved since the last staged write), and the message "Edit Basic
  page: the values were accepted and staged in 9033…." A write that
  moves nothing says nothing was written, as without a stage.
- A read answers the thing as it stands in the stage, and without the
  input what is stored.
- The refined `values` input defaults each key to the staged view, so
  a caller reading the listing after naming the stage sees what it
  staged.
- A narrowing tool narrows over the staged view.

An id that names no stage is the caller's input to correct, code
`not_found`. Another account's stage is refused, code `access`, before
anything is built, and the message names the stage.

### The `X-Surface-Stage` header

The HTTP door takes the same id in a request header,
`X-Surface-Stage` (`ServedPaths::STAGE_HEADER`), on every path of a
resource that can be staged. Not a query parameter, which a listing
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

A header that names no stage is 404. Another account's stage is 403,
answered by the route's access check before anything is built. The
header on a path of the stage itself is 400: a stage is not staged.

The OpenAPI document describes the header once, as the parameter
`SurfaceStage`, and references it from the envelope, the narrowing and
every write of each resource that can be staged.

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
try again, as the entity example's confirm page does. The module's own
doors do not catch it: the tool door answers it as a runtime failure
naming the class, and the HTTP door as a server error.

(why: [decision](decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context))

## How a storage opts in

A stage takes a write only for a resource whose storage class
implements `Pipeline\TransactionalStorageInterface`; for any other
resource no tool takes the input, and a write named into a stage
anyway (through the header, or from PHP) is refused at prepare, code
`does_not_apply`, naming the class. The interface is a promise:
`commit()`, and `delete()` where the storage also implements
`DeletableStorageInterface`, write through the database connection
alone, so the transaction apply opens undoes the write whole.

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

A storage that caches nothing leaves the body empty, as the stage
fixture's row in a database key-value collection does:

<!-- excerpt: tests/modules/surface_stage_test/src/Storage/LedgerStorage.php -->

```php
public function rolledBack(): void {
  // The database's key-value collection keeps no cache to drop.
}
```

[Storage: Opting into staging](storage.md#opting-into-staging) says
what the promise rules out.

## What is refused for now

Each is refused at the staged write's prepare, a violation of the whole
call:

- **A create**, code `does_not_apply`: a staged create would need an id
  before it exists, and local ids are not built.
- **A resource that holds a secret**, code `does_not_apply`: a stage
  keeps its values in the clear, so a secret would sit unsealed in the
  record. Refused whether the secret is sent or not.
- **Config**, and anything kept in State: their storages do not
  implement `TransactionalStorageInterface`, since config and State keep
  caches beside the database that a rollback leaves stale.
- **A second resource over one thing**, code `conflict`: a page staged
  through `entity.content:node.page` cannot be staged again through
  `entity.content:node` in the same stage. One thing is also written
  through one operation per stage, except that a delete replaces what
  the stage holds for it ([A staged delete](#a-staged-delete)).
- **A write to a thing the stage removed**, a second delete among them,
  code `not_found`: "This was removed in the stage; the removal stands
  until the stage is applied or discarded."
- **The stage's own storage**: a stage is not written into a stage.
- **A write into a stage that was applied**, code `does_not_apply`, and
  one that expired or was discarded, code `not_found`.

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

## A flow by hand: bulk delete from the content list

The entity example builds the first flow by hand, over core's content
list (`modules/surface_examples_entity`): a bulk delete that stages,
then confirms over the stage. Flows are not declared yet; this one is
code.

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
none of its items: how a form door shows a run's outputs is decided with
declared flows.
(why: [decision](decisions.md#a-destructive-bulk-operation-stages-then-confirms-over-the-stage))

## Apply

Apply runs in two passes, as every write does: `prepare()` rehearses,
`commit()` writes.

1. **Prepare**, with no lock and no side effect. For each write, in
   position order: the operation is built again from its recorded
   arguments, its access is asked again for the owner, what is stored
   now is compared with the token, and the pipeline runs dry over the
   keys the stage moved. Every refusal is filed under
   `/writes/<position>` (a conflict or an access refusal at
   `/writes/<position>`, a value at `/writes/<position>/<key>`) and all
   of them are answered together, so one answer names every write that
   would fail. A dry run of apply stops here.
2. **Commit.** Under the stage's lock, the record and every token are
   checked again; then each rehearsed write is committed in position
   order, in one database transaction. If one throws, the transaction
   rolls back, each storage whose write had started is told
   (`rolledBack()`), and the stage stays open.

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
  translation, and a stage inherits that.
- **No local ids yet.** A thing created in a stage cannot be named or
  referred to by a later write in the same stage, because staged
  creates are not built.
- **An id that names nothing on the stage's own paths.** The stage's
  operations declare no permission, so at the tool door's access check
  (`drush tool:run`, MCP) and the HTTP route's, an id that names no
  stage gets the door's one fixed refusal, as for any operation that
  declares none; a tool called from PHP, which runs no access check,
  answers `not_found`. A stage named through the `stage` input or the
  header that names nothing is not found.
- **A required key after a staged delete.** The pipeline validates a
  write before the storage sees it, and a load through the stage
  answers no values for a thing it removed. So an update of that thing
  that leaves out a required key is refused for the key, code
  `required`, before it can be refused as removed in the stage; one
  that sends every required key is refused `not_found`.
- **A delete in a workspace** is refused, code `access`
  ([above](#stages-and-workspaces)).
- **Revisions at apply time.** An entity apply saves a new revision
  where the resource carries `revision_log`, dated when apply runs, by
  the owner; when the write was staged is in the stage, not on the
  revision.

The pipeline's [stages](pipeline.md#the-stages) (access, accept,
validate, prepare, commit) are the steps of one call; a stage on this
page is a set of calls kept for later.
