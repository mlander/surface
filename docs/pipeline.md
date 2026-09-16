# The pipeline

`data_surface.pipeline` (`Pipeline\DataSurfacePipelineInterface`) is the
one entry point from raw values to stored values. Whether values arrive
through Form API, a service endpoint, a config action, or an agent, they
pass the same stages.

```
         ──access──▶ ──accept──▶ values ──validate──▶ values ──prepare──▶ prepared ──commit──▶ storage
input    (provider)  (surface)            (surface)             (target)              (target)
```

Beside those, and running the other way, is `conformOutput()`: the same
kind of check applied to what the host *emits*, against the surface's
[output definitions](outputs.md). It is not a stage of `submit()`,
because emitting is the host's own act rather than something the
pipeline does.

Only `commit()` has side effects, which is what makes a dry run a matter
of not calling it.

The pipeline is also the **only** place values are validated. A surface
is pure data and reaches no service; running a surface's constraints
needs the typed data manager, so validation belongs here — and putting it
here is what guarantees every validation refines the surface against the
values first, which a caller holding only a surface could forget to do.

## The stages

### access

```php
public function surfaceAccess(string $operation = 'configure', ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface;
```

Not a method on the pipeline: it is a method on the **provider**, and the
pipeline is one of its callers. One answer per coordinate and account,
resolved once, read by the generated form, a Drush command, a config
action, an agent and the future endpoint — which is what keeps a route's
gate and a payload's gate from becoming two different rules.

The coordinate is the same pair `getDataSurface()` takes, in the same
order: a verb that never carries identity, then an opaque subject id the
provider resolves for itself, `NULL` when the provider is its own
subject. It is what the Phase B endpoint will address a surface by, so
the surface a payload asks for and the answer that gates it are named
one way. See
[the operation and subject pair](declaring-a-surface.md#the-operation-and-subject-pair).
An operation or a subject the provider cannot place is **refused** here
rather than thrown over, because something has to be told no.

The answer is core's `AccessResultInterface`, so it carries a reason and
its own cacheability, and the third state carries weight:

| Answer | Means | Effect |
| --- | --- | --- |
| **Forbidden** | The provider refuses this operation. | `submit()` stops **before `$target->load()`**. Nothing is read, accepted, prepared or written. |
| **Neutral** | No opinion. | Nothing changes. The host's own gates stand exactly as they stood. |
| **Allowed** | An affirmative grant. | Nothing is bypassed: the host's gates and every later stage still apply. |

Neutral is the default, on `DataSurfaceHostTrait`, so a provider that has
not answered for itself behaves exactly as it did before this stage
existed. `NULL` for the account means the current user.

Combining a host's own answer with a provider's is one call, and writing
it by hand is the way to get it wrong — `allowed()->andIf(neutral())` is
neutral, and neutral is not allowed, so every provider that never adopted
the method would silently close its host's door:

```php
use Drupal\data_surface\DataSurfaceAccess;

$access = DataSurfaceAccess::gate(
  $field->access('update', $account, TRUE),   // What the host decided.
  $item->surfaceAccess(account: $account),    // What the provider says.
);
```

A provider may **refuse** what a host allowed. It may never **allow**
what a host refused.

One trap worth stating, because core's helpers set it:
`AccessResult::allowedIfHasPermission()` and an entity access handler
both answer **neutral** when the answer is no, since another checker
might still allow it. A route reads that neutral as a refusal — a route
requires allowed — while this gate reads it as "nothing to say". A
provider that is where the rule is written down therefore says no out
loud, or the same account is turned away by the route and let through by
the payload:

```php
return DataSurfaceAccess::decisive(
  $type->access('update', $account, TRUE)->andIf($permission),
  'Editing a content type through this module needs both permissions.',
);
```

`decisive()` returns an allowed or already-forbidden answer untouched,
and turns neutral into a refusal that keeps the assembled answer's
cacheability. It is for the provider that owns the operation, and only
for it: a host trait's neutral default is still the right answer for
every provider that owns nothing.

`setConfiguration()` deliberately does not consult access. A host
constructs its plugins from what it has already stored — core's block,
condition and action bases all call `setConfiguration()` from inside
their own constructors — so gating there would ask whether the current
account may configure a block during a cache warm, a cron run, or an
anonymous page view. The gate belongs where something is written, which
is `submit()`.

### accept

```php
public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array;
```

Produces a complete, typed value set from partial, untyped input. Values
merge in one order at every level: the surface's **declared defaults**,
then the **current stored values** (so a partial update is legal), then
the **input**, cast through each definition.

Locked keys take what storage holds, falling back to the declared
default, and ignore whatever was submitted for them. Keys the surface
does not declare are refused rather than dropped, at any depth, and so is
input in a shape the definition cannot hold.

It needs no services. After it runs, every surface key is present and
every value is either `NULL` or a configured value in its declared type.
That is the shape `validate()`, a target and any consumer can rely on.

The rules it applies — what counts as configured, the casting table, how
lists and maps merge — are [Value semantics](semantics.md), which is the
reference for this page.

### validate

```php
public function validate(DataSurfaceInterface $surface, array $values, array $current = []): ViolationSet;
```

Refines the surface against the values first, then runs the constraints,
so the constraints the values are held to are the ones the values
themselves selected — the same set the generated form rendered.

It answers with a `ViolationSet`: a countable, iterable collection of
`SurfaceViolation` objects, each carrying the surface `key` it is filed
under, the `path` within that key's value (`''` for the value itself)
and an unrendered `message`. `isEmpty()` means valid.

```php
$violations = $pipeline->validate($surface, $values);
$violations->isEmpty();          // Nothing was refused.
$violations->keys();             // The refused surface keys, in order.
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
refusal. A value that the refined surface will not take, which is
*exactly what is stored* for that key, and which is no longer among the
values the key offers, is **stale**: nothing about this run tried to
change it, so refusing it would punish a caller for something the site
did. The full rule and its two deliberate boundaries are in
[value semantics](semantics.md#stale-values-the-third-state).

A stale entry is a `SurfaceViolation` with its `stale` flag set, and the
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
public function conformOutput(DataSurfaceInterface $surface, array $output, array $input_values = []): ViolationSet;
```

The output half of `validate()`, and the only stage that runs after the
host has done its work rather than before. It refines the surface's
[output definitions](outputs.md) against the input values, strips the
`Omitted` sentinel, refuses keys no output declares, and checks what is
left against each definition's type and constraints — answering with the
same `ViolationSet` everything else answers with.

Nothing is cast. Input arrives from people and payloads, so `accept()`
coerces it; an output is produced by code that had the declaration in
front of it, so a wrong type is a violation rather than a notation.

It is not part of `submit()`. Emitting is the host's own act, not a
pipeline stage, and the pipeline has no way to run it; what the pipeline
offers is the check, so a host — or a generated test — can hold the
emitted array to what the surface advertised.

### prepare

```php
public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceTargetInterface $target): PreparedValues;
```

Hands the values to a target for shaping. Writes nothing. Answers with a
`PreparedValues`: the accepted `values`, the storage-shaped `artifact`,
and any calculated `dependencies`. This is the preview artifact —
everything a renderer or a diff needs, nothing stored.

### commit

```php
public function commit(PreparedValues $prepared, DataSurfaceTargetInterface $target): void;
```

Writes the artifact. The only stage with side effects.

### submit

```php
public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceTargetInterface $target, bool $dry_run = FALSE, ?AccessResultInterface $access = NULL): DataSurfaceResult;
```

Runs every stage, stopping at the first that refuses the values, so a
caller reads one result rather than orchestrating five calls. The target
is both where current values are read from and where the result is
written.

`DataSurfaceResult` carries `values`, `violations`, the `prepared`
artifact when the values were valid, `committed`, and the `access` answer
the run was gated by. `isValid()` is the question most callers ask.

A committed result can still carry violations, and there is exactly one
way that happens: the set holds stale references and nothing else. They
travel on rather than being replaced by an empty set, because that is
how a caller with no form in front of it learns there is something to
re-choose.

The access answer is the caller's own, already resolved, rather than a
provider the pipeline would have to hold — no closures, and nothing in
the pipeline that knows what a provider is. A host asks its provider for
the coordinate it is running — the operation, and the subject when the
provider owns more than itself — and hands the answer over:

```php
$result = $pipeline->submit(
  $surface,
  $values,
  $target,
  access: $provider->surfaceAccess($operation, $subject),
);
```

`NULL` is not "allowed": it means nothing was asked, which is what every
caller written before this stage existed means, and what a host that
gates somewhere else means.

A forbidden answer comes back as one violation under the reserved key
`DataSurfacePipelineInterface::ACCESS_VIOLATION_KEY`, which is `'@access'`:
a surface key is a definition name and no definition is named with a
leading `@`, so the whole `@` prefix is the pipeline's namespace for a
refusal belonging to no particular key. The violation's message is the
refusal's reason, as a message object like every other violation's, and
`isValid()` is `FALSE` for the same reason an out-of-range number makes
it false — a caller that only branches on `isValid()` needs to know
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

"This provider had nothing to say" is a conclusion like any other: it can
stop being true when a permission or a config entity changes, so its
cacheability is as real as a refusal's.

## Dry runs

`submit()` takes a `$dry_run` flag: everything up to and including
`prepare()` runs, and the artifact comes back with `committed` false.
Nothing has been written, and the caller has the exact object a commit
would have stored.

For the config object target there is a stronger kind of dry run: a
`Config` holds the storage it was constructed with, so one pointed at a
memory storage with a private event dispatcher commits for real into a
bin nobody else reads. Config entities have no equivalent. Both are in
[Targets](targets.md#dry-runs).

## Who asks the access question

The provider answers; the host decides when to ask. The rule that keeps
the two halves honest is that a host must never spell the question twice:

- **`data_surface_demo_node_type`** states its two route requirements
  once, in `NodeTypeSurfaceProvider::surfaceAccess()` — the entity's own
  create or update answer, ANDed with the module's permission — and the
  routes, the operation link in the content type listing, and the form's
  own write all read that one answer, asking for it with the same pair:
  `('add', NULL)` or `('edit', <machine name>)`.
- **The field tools** ask the field item's `surfaceAccess()` beside the
  entity and field checks they already ran, in both `checkAccess()` and
  `doExecute()`, because `execute()` is callable from PHP with no check
  in front of it.
- **Blocks, formatters, conditions and actions** keep the neutral
  default. A host's own `access()`, where it has one, is a different
  question: an action's asks whether the action may be *executed* on an
  object and a block's asks whether the block may be *seen*, while
  `surfaceAccess()` asks whether an account may *configure* the values.
  An action anyone may run is very often one only an administrator may
  reconfigure. (That collision is also why the provider method is not
  called `access()`: `ActionInterface` and `BlockPluginInterface` already
  own that name with incompatible signatures.)

## Violations are objects

Every message in a violation is a `TranslatableMarkup` — the
surface's own required message, the pipeline's unknown-key and shape
refusals, and whatever a constraint or a config schema produced — and it
stays one the whole way out: through `DataSurfaceResult`, through
`FormStateInterface::setError()`, into a caller's own report.

Placeholders are still placeholders until something renders the message,
so a value containing markup is escaped once, by whoever prints it,
rather than escaped at build time and escaped again on the page.

There are exactly three places a message becomes text, and all three are
exception messages, because PHP has nowhere to put an object in one.

## Exceptions

| Exception | Thrown by | When |
| --- | --- | --- |
| `UnknownKeysException` | `accept()` | The input carries a key no definition declares, at any depth. |
| `ShapeMismatchException` | `accept()` | The input carries a value in a shape its definition cannot hold — a string where a map was advertised. Names the dotted path and what was expected. |
| `TargetViolationsException` | `prepare()` | The target's storage refuses the values; a config schema, usually. |

All three render their message through `Pipeline\ViolationSummary`, which
names the first five entries and counts the rest; the full list is still
on the exception. `DataSurfaceConfigurationTrait::setConfiguration()`
throws a fourth refusal the same way.

`submit()` catches all three and turns them into ordinary violations on
the surface keys that carried them, rather than letting them escape. So a
caller that sends a misspelled key, the wrong shape, or something the
config schema refuses gets the same path-aware report as one that sends
an out-of-range number, instead of watching the value vanish. That is the
bargain: **a caller learns about what it got wrong**. Call the stages
individually and you handle the exceptions yourself.

## The host-owns-the-write split

Some hosts save for themselves. Field UI copies whatever a settings
element produced onto the field config entity and saves it, so a field
type's settings form runs accept, validate and **prepare**, writes the
prepared artifact into form state, and deliberately does not commit —
calling both would save the field twice.

That is why the transform lives in `prepare()` and not in `commit()`. A
caller that does own the write reaches the same transform through
`submit()` and gets the same stored settings. The target owns the
transform; the host owns the write.

## Calling it without a form

```php
$result = \Drupal::service('data_surface.pipeline')->submit(
  $block->getDataSurface(),
  ['headline' => 'Latest', 'limit' => '5'],
  new PluginConfigurationTarget($block),
);
if (!$result->isValid()) {
  // $result->violations, a ViolationSet.
}
```

`'5'` arrives as a string and is stored as the integer `5`, because the
definition says `integer` and the casting table says a numeric string
means the same number. That is the whole claim: the form and this call
are the same call.
