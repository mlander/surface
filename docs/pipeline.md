# The pipeline

This page covers what happens to values after a door receives them,
step by step, and how to submit values from your own code. Use it when
you call the pipeline yourself, or need to know at which step a value
is refused. [A value sent in](introduction/17-a-value-sent-in.md)
introduces the steps in plain words.

`surface.pipeline` (`Pipeline\SurfacePipelineInterface`) is the
one entry point from raw values to stored values. Whether values arrive
through Form API, a service endpoint, a config action, or an agent, they
pass the same stages.

```
         ──access──▶ ──accept──▶ values ──validate──▶ values ──prepare──▶ prepared ──commit──▶ storage
input    (class)     (data surface)       (data surface)        (storage)             (storage)
```

Beside those, and running the other way, is `conformOutput()`: the same
kind of check applied to what the host *emits*, against the data surface's
[output definitions](outputs.md). It is not a stage of `submit()`,
because emitting is the host's own act rather than something the
pipeline does.

Only `commit()` has side effects, which is what makes a dry run a matter
of not calling it.

The pipeline is also the **only** place values are validated. A data
surface is pure data and reaches no service; running its constraints
needs the typed data manager, so validation belongs here, and putting it
here is what guarantees every validation refines the data surface against
the values first, which a caller holding only a data surface could forget
to do.

## The stages

### access

```php
public function access(string $resource, OperationContext $context, ?AccountInterface $account = NULL): AccessResultInterface;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Not a method on the pipeline: it is `ResourcesInterface::access()`, on
the build step, and the pipeline is one of its callers. One answer per
resource or structure, context and account, read by the generated form, a
route, a generated tool, a Drush command and an agent, which is what keeps
a route's gate and a payload's gate from becoming two different rules.

It is two tiers. The operation's permission first, from
`#[Operation(permission:)]`, its `%key` placeholders filled from the
identity the context knows; then, only if that allows, the access class
named by `#[Resource(access:)]` or `#[Structure(access:)]`, which may
hold services and asks the thing itself. A context whose id names no
declared operation (a plugin host's `configure`) has no permission to
check, so the access class is the whole answer, and a class with neither
answers neutral. A variant chosen by a locked deciding key whose
class names an access class of its own is asked too, and may refuse; a
child never allows on its parent's behalf. Every other child's access
shaped its keys when it was built. A plugin host asks through its own
`surfaceAccess()`, which takes the account and the host's operation and
nothing else.

The answer is core's `AccessResultInterface`, so it carries a reason and
its own cacheability, and the third state carries weight:

| Answer | Means | Effect |
| --- | --- | --- |
| **Forbidden** | The resource or structure refuses. | `submit()` stops **before `$storage->load()`**. Nothing is read, accepted, prepared or written. |
| **Neutral** | No opinion. | Nothing changes. The host's own gates stand exactly as they stood. |
| **Allowed** | An affirmative grant. | Nothing is bypassed: the host's gates and every later stage still apply. |

Neutral is what a structure with no access class answers in a host's
context, so a plugin whose structure says nothing about access behaves
exactly as it did before this stage existed. `NULL` for the account
means the current user.

Combining a host's own answer with the structure's is one call, and
writing it by hand is the way to get it wrong:
`allowed()->andIf(neutral())` is neutral, and neutral is not allowed, so
every structure that says nothing would silently close its host's door:

```php
use Drupal\surface\SurfaceAccess;

$access = SurfaceAccess::gate(
  $field->access('update', $account, TRUE),   // What the host decided.
  $item->surfaceAccess(account: $account),    // What the structure says.
);
```

The structure may **refuse** what a host allowed. It may never **allow**
what a host refused.

One trap worth stating, because core's helpers set it:
`AccessResult::allowedIfHasPermission()` and an entity access handler
both answer **neutral** when the answer is no, since another checker
might still allow it. A route reads that neutral as a refusal (a route
requires allowed), while this gate reads it as "nothing to say". A
caller that owns the operation therefore says no out loud, or the same
account is turned away by the route and let through by the payload:

```php
$result = $pipeline->submit($surface, $values, $storage,
  access: SurfaceAccess::decisive($access, 'The resource this form configures may not be written by this account.'),
);
```

`decisive()` returns an allowed or already-forbidden answer untouched,
and turns neutral into a refusal that keeps the assembled answer's
cacheability. A declared operation is owned by whoever runs it, so the
operation form and the generated tools make the answer decisive before
they hand it to the pipeline, as the route requirement does by requiring
allowed. A plugin host's neutral is still the right answer for a
structure that owns nothing.

`setConfiguration()` deliberately does not consult access. A host
constructs its plugins from what it has already stored (core's block,
condition and action bases all call `setConfiguration()` from inside
their own constructors), so gating there would ask whether the current
account may configure a block during a cache warm, a cron run, or an
anonymous page view. The gate belongs where something is written, which
is `submit()`.

### accept

```php
public function accept(SurfaceInterface $surface, array $input, array $current = []): array;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Produces a complete, typed value set from partial, untyped input. Values
merge in one order at every level: the data surface's **declared
defaults**, then the **current stored values** (so a partial update is
legal), then the **input**, cast through each definition.

Locked keys take what storage holds, falling back to the declared
default, and ignore whatever was submitted for them; a value submitted
for one that is not what it holds is noted, once per key, for
`submit()` to hand on (below). Keys the data
surface does not declare are refused rather than dropped, at any depth,
and so is input in a shape the definition cannot hold.

It needs no services. After it runs, every declared key is present but
one a refiner answered [does not apply](refinement.md#when-a-key-does-not-apply)
to these values, and every value is either `NULL` or a configured value
in its declared type. That is the shape `validate()`, the storage and
any consumer can rely on. A key that does not apply is left out when it
was sent nothing or what it holds, and stays, for `validate()` to refuse,
when it was sent anything else; `submit()` hands storage what it holds
there (`kept()`), so it survives until it applies again.

A computed key is accepted last, once every other key of its level is:
its value is computed from what its sources were accepted as, while
storage holds nothing for it or when it says `Compute::ON_CHANGE` and a
source moved, else it holds what is stored. A value sent for it equal to
that is noted and not taken; any other stays, for `validate()` to
refuse, code `computed`, naming the keys it is computed from. Either
refusal of it, `validate()`'s or the storage's in `prepare()`, gains a
sentence naming those keys, and is left out while one of them is
refused itself ([Computed keys](declaring-a-resource.md#computed-keys)).

A collection's item is accepted over the child's defaults, the list
being sent whole, but a secret the item leaves out keeps what is stored
at its position when the list is as long as the one stored; with items
added or removed, an item leaving out a secret stored where it now sits
is noted for `submit()` to refuse by the secret's path, since that
position may hold another item now
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

The rules it applies (what counts as configured, the coercion table, how
lists and maps merge) are [Value semantics](semantics.md), which is the
reference for this page.

### validate

```php
public function validate(SurfaceInterface $surface, array $values, array $current = []): ViolationSet;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Refines the data surface against the values first, then runs the
constraints, so the constraints the values are held to are the ones the
values themselves selected, the same set the generated form rendered.

It answers with a `ViolationSet`: a countable, iterable collection of
`Violation` objects, each carrying the `key` it is filed under, the
`path` within that key's value (`''` for the value itself) and an
unrendered `message`. `isEmpty()` means valid.

```php
$violations = $pipeline->validate($surface, $values);
$violations->isEmpty();          // Nothing was refused.
$violations->keys();             // The refused keys, in order.
$violations->byKey('title');     // The violations on one key.
foreach ($violations as $violation) {
  $violation->fullPath();        // 'extras.badge'
}
```

Iteration is grouped by key, in the order the keys were first refused,
so a summary reads every violation of one key together even when a
config schema reported them interleaved.

#### Stale never blocks

`$current` is what storage holds, and it is what makes one refusal not a
refusal. A value that the refined data surface will not take, which is
*exactly what is stored* for that key, whose dependencies this run left
as they are stored, and which is no longer among the values the key
offers, is **stale**: nothing about this run tried to change it, so
refusing it would punish a caller for something the site did. A run that
moves a dependency (the venue) and sends back the dependent stored
under the old one (the room) has narrowed the list itself, and is
refused. The full rule and its two deliberate boundaries are in
[value semantics](semantics.md#stale-values-the-third-state).

A stale entry is a `Violation` with its `stale` flag set, and the
set holds it to one side:

```php
$violations = $pipeline->validate($surface, $values, $current);
$violations->isEmpty();   // TRUE — stale entries do not count.
$violations->hasStale();  // TRUE — and there is something to re-choose.
$violations->stale();     // The stale entries, in the order found.
```

Stale entries are not iterated, not counted, not named by `keys()`, and
do not make `isEmpty()` false. That is the whole of "stale never
blocks": every reader either asks `isEmpty()` or iterates, so none of
them had to learn anything new to keep saving a value that went stale,
and a reader that wants to say so out loud asks `stale()` on purpose.
The generated form turns them into a warning through the messenger
rather than a form error; the field tools report them as their own
`stale` list beside the settings they saved, so an agent can tell
"re-choose this" from "invalid input".

A key that is stale is reported as stale and not re-judged: whatever
else its constraints would have said is about a value this run is not
changing and could not have chosen.

Passing no `$current` says nothing is stored, so nothing can be stale,
which is right for a caller validating values that are not on their way
to storage. `submit()` passes what it loaded; the generated form passes
the same stored values it extracted against.

### conformOutput

```php
public function conformOutput(SurfaceInterface $surface, array $output): ViolationSet;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The output half of `validate()`, and the only stage that runs after the
host has done its work rather than before. It holds what was emitted to
the data surface's [output definitions](outputs.md), which are never
refined: it strips the `NotEmitted` sentinel, refuses keys no output declares, and checks what is
left against each definition's type and constraints, answering with the
same `ViolationSet` everything else answers with.

Nothing is cast. Input arrives from people and payloads, so `accept()`
coerces it; an output is produced by code that had the declaration in
front of it, so a wrong type is a violation rather than a notation.

It is not part of `submit()`. Emitting is the host's own act, not a
pipeline stage, and the pipeline has no way to run it; what the pipeline
offers is the check, so a host, or a generated test, can hold the
emitted array to what the data surface advertised.

### prepare

```php
public function prepare(SurfaceInterface $surface, array $values, SurfaceStorageInterface $storage): PreparedValues;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Hands the values to the storage, which lays them out as it stores them.
Writes nothing. Answers with a `PreparedValues`: the accepted `values`,
the `artifact` in storage layout, and any calculated `dependencies`.
This is the preview artifact: everything a renderer or a diff needs,
nothing stored.

### commit

```php
public function commit(PreparedValues $prepared, SurfaceStorageInterface $storage): void;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Writes the artifact. The only stage with side effects.

### submit

```php
public function submit(SurfaceInterface $surface, array $input, SurfaceStorageInterface $storage, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): SurfaceResult;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Runs every stage, stopping at the first that refuses the values, so a
caller reads one result rather than orchestrating five calls. The
storage is both where current values are read from and where the result
is written.

`SurfaceResult` carries `values`, `violations`, the `prepared`
artifact when the values were valid, `committed`, the `access` answer
the run was gated by, `notes` and `changes`. `isValid()` is the question
most callers ask.

Every door answers a run in one shape, `SurfaceResult::document()`
(`Pipeline\ResultDocument`): `{ok, committed, changed, changes, values,
violations, notes, stale}`, each key always present, `values` as the
door may return them (what `outbound()` projected), each violation and
note `{path, code, message}` and each stale reference `{path, code,
message, stored}`, the message as plain text, rendered by the door's
choice: for a machine at the tool, as the form renders it at React.
The write tool, the HTTP API (a refusal there as problem details, its
`errors` the violations) and Drush's `tool:run --json` all answer it,
every path a JSON Pointer (why: [decision](decisions.md#one-result-at-every-door)).
Every violation carries a stable `code`; the table is in
[Value semantics](semantics.md#codes).

`notes` are what the caller is told and nothing blocks: a `Violation`
per locked key whose value sent was not taken, "Machine name is locked
and kept as stored; the value sent was not taken." They are no
violation, so `isValid()` never reads them; the write tool and the
React endpoints say them beside the stale references
(why: [decision](decisions.md#locked-at-the-top)).

A secret inside a locked key is never compared: anything sent for one
was not taken, so whether a note is said tells a caller nothing of it.

A committed result can still carry violations, and there is exactly one
way that happens: the set holds stale references and nothing else. They
travel on rather than being replaced by an empty set, because that is
how a caller with no form in front of it learns there is something to
re-choose.

The access answer is the caller's own, already resolved, rather than
something the pipeline would have to hold: no closures, and nothing in
the pipeline that knows what a resource, a structure or a host is. A
caller asks
for the context it is running and hands the answer over:

```php
$resources = \Drupal::service('surface.resources');
$context = $resources->operation(NodeTypeResource::class, 'update', ['type' => 'article']);
$result = $pipeline->submit(
  $resources->build(NodeTypeResource::class, $context),
  $values,
  $resources->storage(NodeTypeResource::class, $context),
  access: SurfaceAccess::decisive($resources->access(NodeTypeResource::class, $context)),
);
```

`NULL` is not "allowed": it means nothing was asked, which is what every
caller written before this stage existed means, and what a host that
gates somewhere else means.

A forbidden answer comes back as one violation of the whole call: no
key and an empty path, since it belongs to no particular key, and the
code `access`; a resource locked whole is refused the same way with the
code `locked` (until 2026-10-20 both were filed under the pseudo-key
`@access`). The violation's message is the
refusal's reason, as a message object like every other violation's, and
`isValid()` is `FALSE` for the same reason an out-of-range number makes
it false: a caller that only branches on `isValid()` needs to know
nothing new. A caller that wants to tell a 403 from a 422 asks
`isAccessRefused()`.

### Cacheability

`$result->access` is kept whatever the answer was, including neutral,
because an access answer carries cache contexts and tags that a caller
rendering or caching the outcome has to merge:

```php
$cacheability = CacheableMetadata::createFromRenderArray($build);
if ($result->access !== NULL) {
  $cacheability->addCacheableDependency($result->access);
}
```

"Nothing to say" is a conclusion like any other: it can stop being true
when a permission or a config entity changes, so its cacheability is as
real as a refusal's.

### outbound

```php
public function outbound(SurfaceInterface $surface, array $values): array;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

What any door may hand a caller of a set of values, the read tool's
projection (`Pipeline\Outbound::project()`): no key the build left out
for the caller, at any depth; no secret, at any depth, under every
variant that keeps it secret and inside each item of a list;
no key that does not apply to the values; a locked key as stored. The
read tool answers what storage loads through it, the write tool answers
its accepted `values` through it, dry run or written, and the HTTP API
writes its `values` document from it. `document()` writes
what it is handed, so a door hands it what this projected. The
`prepared` artifact is never handed to a caller: it is storage's own
shape, with what the caller could not see put back
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## Dry runs

`submit()` takes a `$dry_run` flag: everything up to and including
`prepare()` runs, and the artifact comes back with `committed` false.
Nothing has been written, and a PHP caller has the exact object a commit
would have stored. A door does not hand it on: the write tool and the
HTTP API's `?dryRun=true` answer the accepted values through `outbound()`, and the
run's verdict, which says whether storage's own checks passed.

Config entities write through the container's config factory, so an
entity cannot be pointed at another bin for one call: an unsaved clone,
held to the schema, is as far as a dry run goes. See
[Storage](storage.md#dry-runs).

## Who asks the access question

The resource or structure answers; the caller decides when to ask. The
rule that keeps the two halves honest is that nothing spells the question
twice:

- **`surface_examples_node_type`** states its gate once: the
  operations' permission on `NodeTypeResource`, and the node type
  entity's own create or update answer in `NodeTypeAccess`. The routes'
  `_surface_operation_access` requirement, the operation link in
  the content type listing, the operation form's own write and the
  generated tools all read `ResourcesInterface::access()` in the same
  operation.
- **The field tools** ask the field instance resource's access in their
  operation, which reaches the settings variant's access class too, in
  both `checkAccess()` and `doExecute()`, because `execute()` is
  callable from PHP with no check in front of it. A field type's Field
  UI host asks the field config entity, then the same access class.
- **Blocks, formatters, conditions and actions** ask their structure's
  access class in the `configure` context, which is neutral when it
  names none. A host's own `access()`, where it has one, is a different
  question: an action's asks whether the action may be *executed* on an
  object and a block's asks whether the block may be *seen*, while
  `surfaceAccess()` asks whether an account may *configure* the values.
  An action anyone may run is very often one only an administrator may
  reconfigure. (That collision is also why the host method is not
  called `access()`: `ActionInterface` and `BlockPluginInterface` already
  own that name with incompatible signatures.)

## Violations are objects

Every message in a violation is a `TranslatableMarkup`, and it stays one
the whole way out: through `SurfaceResult`, through
`FormStateInterface::setError()`, into a caller's own report. Why, how a
placeholder is filled in, and the three places a message becomes text
are in [Value semantics](semantics.md#violation-messages-are-objects).

## Exceptions

| Exception | Thrown by | When |
| --- | --- | --- |
| `UnknownKeysException` | `accept()` | The input carries a key no definition declares, at any depth. |
| `TypeMismatchException` | `accept()` | The input carries a value of a type its definition cannot hold, such as a string where a map was advertised. Names the dotted path and what was expected. |
| `VariantMismatchException` | `accept()` | The value at a key with variants carries keys the chosen variant does not declare and another variant does. Names that key, the deciding key and the misplaced keys. |
| `StorageViolationsException` | `prepare()` | What the storage writes to refuses the values; a config schema, usually. |

`UnknownKeysException` and `StorageViolationsException` render their
message through `Pipeline\ViolationSummary`, which names the first five
entries and counts the rest; the full list is still on the exception.
`SurfaceConfigurationTrait::setConfiguration()` throws its refusal
the same way.

`submit()` catches all four and turns them into ordinary violations on
the keys that carried them, rather than letting them escape. So a
caller that sends a misspelled key, the wrong shape, or something the
config schema refuses gets the same path-aware report as one that sends
an out-of-range number, instead of watching the value vanish. That is the
bargain: **a caller learns about what it got wrong**. Call the stages
individually and you handle the exceptions yourself.

## The host-owns-the-write split

Some hosts save for themselves: a field type's settings form runs
accept, validate and **prepare**, and never commits, because Field UI
saves the field. That is why the transform lives in `prepare()` and not
in `commit()`. The storage owns the transform; the host owns the write
([Storage](storage.md#the-host-owns-the-write-split)).

## Calling it without a form

```php
$result = \Drupal::service('surface.pipeline')->submit(
  $block->getSurface(),
  ['headline' => 'Latest', 'limit' => '5'],
  new PluginConfigurationStorage($block),
);
if (!$result->isValid()) {
  // $result->violations, a ViolationSet.
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`'5'` arrives as a string and is stored as the integer `5`, because the
definition says `integer` and the coercion table says a numeric string
means the same number. That is the whole claim: the form and this call
are the same call.
