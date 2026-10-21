# Security review, 2026-10-20

Branch `next` at fad37cc. Paths are relative to the module unless they
start with `web/`.

## 1. Standpoint

I read the module as an application security reviewer who holds an
agent's or a low-privilege account's access to the tool door, the React
endpoints (`/surface-api/...`) and the generated forms, and who wants
values, keys or secrets that account was never meant to see or change.
I hold the module to its own promises: omitted is omitted both ways
(`docs/decisions.md:1692`), a read leaves a secret out
(`docs/decisions.md:2139`), a refused caller learns nothing of what
storage holds (`src/Pipeline/DataSurfacePipeline.php:1666-1672`), and a
cached document is the asking account's (`docs/decisions.md:1414`).

How I verified: each path read end to end, then exercised on the site as
uid 2, a probe account I created with one role (`administer site
configuration`, later `access content`, `edit any page content` and the
draft transition), logged in through `ddev drush uli`, and deleted with
its role afterwards (`user:cancel --delete-content`, `role:delete`).
Only dry runs, the read tool and GETs were sent; nothing was written.
The secret paths were exercised through the pipeline service with
`drush php:eval`, since no installed resource declares a secret.

## 2. The five findings that most matter

### 2.1 A dry run discloses keys access omitted, through `prepared`

**Severity: high** for any site whose access classes or alters omit
keys (field permissions, example 10's workflow), medium otherwise.

**Problem.** `StorageAdapter::prepare()` puts the stored value of every
omitted key back into what it hands storage, so the write does not drop
what the caller never saw (`src/ResourceBuild/StorageAdapter.php:133-142`).
The storage's prepared artifact therefore carries those values
(`src/ResourceBuild/StorageAdapter.php:178`), `preview()` returns that
artifact as it is (`src/ResourceBuild/StorageAdapter.php:236-243`), and
both doors hand the preview to the caller: the write tool as `prepared`
on a dry run or a `readOnly` operation
(`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:208-210`),
and React `/validate` as `prepared`
(`modules/data_surface_react/src/Controller/ResourceApiController.php:230-233`, `:242`).
The read tool, which filters, is not the only read.

**Exploit path (reproduced).** Example 10's workflow alter omits the
fees for an account without `see event fees`
(`modules/data_surface_examples_workflow/src/Alter/FeesWorkflowAlter.php:49-53`).
As uid 2 with only `administer site configuration`, the read tool
answered `{"headline":...,"sessions":8,"published":false}`, fees
correctly absent. The write tool with `{"values":{},"dry_run":true}`
answered `"prepared":{...,"fees":{"standard":20,"concession":10}}`, and
`POST /surface-api/event.program/configure/validate` with `{"values":{}}`
and a CSRF token answered the same `prepared`. For content entities the
same happens to a field whose view access is denied: the omitted field
is copied into `fields` of the artifact
(`modules/data_surface_entity/src/Storage/ContentEntityStorage.php:155-161`),
so an editor with update access reads a field-permissions "private"
field by sending one valid value and `dry_run: true`.

**Change.** Stop serving storage's own shape at the doors: `prepared`
stays on `DataSurfaceResult` for PHP callers, and the tool and React
answer `values` (already projected through the built surface) and
`notes`.

Before, `ResourceOperationTool.php:208-210`:

```php
if (!$result->committed && $result->prepared !== NULL && $storage instanceof StorageAdapter) {
  $outputs = [OperationInputs::PREPARED => $storage->preview($result->prepared)];
}
```

After:

```php
// A dry run answers what was accepted, in the resource's shape; what
// storage rehearsed may hold what this account may not see.
// Decision: see docs/decisions.md#omitted-is-omitted-both-ways.
```

and in `ResourceApiController::validate()` the `$prepared` block and the
`'prepared' =>` key go. If a preview is wanted, it must be projected
through the same omitted paths and secrets as the read tool, which
storage's own layout (third-party layouts, encrypted settings) makes
hard to do right; dropping it is the safer cut.

**Cost.** A behavior change and one output removed from
`OperationInputs::outputs()` (`modules/data_surface_tool/src/OperationInputs.php:532-537`).
**Breaks** callers reading `prepared` (the React type declares it
`unknown`, `modules/data_surface_react/app/src/contract.ts:170`; the
example in `docs/served-contract.md:942-950`) and any recorded
fixtures that carry it.

### 2.2 The write tool and `/validate` echo stored secrets

**Severity: medium.** A write-only value becomes readable to every
account that may write it, and lands in agent transcripts, ECA tokens
and Tool API logs.

**Problem.** A secret left out keeps what is stored:
`acceptSecret()` returns the fallback, which is the stored value
(`src/Pipeline/DataSurfacePipeline.php:653-660`). That is right for the
write, but `$result->values` is then returned unfiltered as the write
tool's `values` output on every success, dry or committed
(`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:219-222`),
under an output declared as a bare `map` that promises "every declared
default and stored value filled in"
(`modules/data_surface_tool/src/OperationInputs.php:522-526`). React
`/validate` returns `document($surface, $result->values)`
(`modules/data_surface_react/src/Controller/ResourceApiController.php:241`),
and `document()` does not mask (`src/Pipeline/DataSurfacePipeline.php:222-235`).
The read tool's filter (`modules/data_surface_tool/src/OperationInputs.php:250-259`,
`modules/data_surface_tool/src/InputDefinitions.php:859-882`) is applied
nowhere else, and `ReadToolTest::testSecretIsNeverReturned()` asserts
only the read (`tests/src/Kernel/ReadToolTest.php:394-433`).

**Exploit path (reproduced at the pipeline).** A surface with
`endpoint` and a secret `token`, storage holding `S3CRET-VALUE`,
submitted `{"endpoint":"https://y"}` with `dry_run: TRUE`: `values`,
`document()` and the prepared artifact all carried
`"token":"S3CRET-VALUE"`. Through the tool that is
`tool:run data_surface:<resource>:<op> --input='{"values":{},"dry_run":true}'`
for any account holding the operation's permission, for example an
editor delegated a settings form whose API key the site meant to keep
from them, or an agent whose tool results go to its model provider.

**Change.** One outbound projection, the read tool's, applied to every
document a door returns.

Before, `ResourceOperationTool.php:219-222`:

```php
[
  OperationInputs::VALUES => $result->values,
  OperationInputs::COMMITTED => $result->committed,
] + $outputs + ...
```

After:

```php
[
  // Decision: see docs/decisions.md#a-read-leaves-a-secret-out.
  OperationInputs::VALUES => $this->operationInputs->outbound($built, $result->values),
  OperationInputs::COMMITTED => $result->committed,
] + $outputs + ...
```

where `OperationInputs::outbound()` is the last line of `stored()`
(`withoutSecrets(..., secrets($built))`) extracted, and React's
`validate()` calls the same before `document()`.

**Cost.** Docs and one method; the `values` output's description
changes. **Breaks** nothing in the examples (none declares a secret);
an adopter that read a secret back from a dry run was relying on the
leak.

### 2.3 Referenceable option lists are cached per permission set, not per account

**Severity: medium** on a site with a node grants module (content
access, group, per-term permissions); low otherwise. Not reproducible on
this site, whose dynamic page cache bin is the null backend
(`web/sites/default/settings.local.php:35`).

**Problem.** The full schema is the one document the module lets Drupal
cache: `/schema` is a `CacheableJsonResponse` with no max-age
(`modules/data_surface_react/src/Controller/ResourceApiController.php:146-158`),
whose cacheability comes from the options it enumerates. An entity
reference's options are the selection handler's referenceable entities
with their labels (`modules/data_surface_entity/src/Plugin/DataSurfaceOptionsResolver/ReferenceableOptions.php:100-104`),
declared to vary by `user.permissions` alone (`:107-109`). Core's node
selection leaves the published filter to grants modules when any exist
(`web/core/modules/node/src/Plugin/EntityReferenceSelection/NodeSelection.php:32-33`),
and node access results then vary by `user.node_grants:view`, which the
option set does not declare. On this site, as admin, the schema for
`entity.content:node.page/add` enumerated user labels
(`"const": 2, "title": "ds_sec_probe"`), and its response headers said
`user.permissions` was the only account context.

**Exploit path (by reading).** User A and user B share roles; a grants
module lets A view an unpublished or private node B may not. A requests
`/surface-api/<resource with a node reference>/<op>/schema`; the
dynamic page cache stores it under the permission hash; B requests the
same URL and receives A's labels. The second instance of the same
shape is documented as open: a static tool definition is built for
whichever account triggers discovery and cached for everyone
(`docs/decisions.md:2152-2165`), so the MCP bridge can list keys and
choices to an account for which they are omitted.

**Change.** Carry the selection's own cacheability, and when it cannot
be known, say the account.

Before, `ReferenceableOptions.php:107-109`:

```php
$cacheability = (new CacheableMetadata())
  ->addCacheTags($target === '' ? [] : [$target . '_list'])
  ->addCacheContexts(['user.permissions']);
```

After:

```php
$cacheability = (new CacheableMetadata())
  ->addCacheTags($target === '' ? [] : [$target . '_list'])
  // Selection runs entity query access: node grants, owner-based
  // unpublished rules. Only the account bounds what it read.
  ->addCacheContexts(['user']);
```

**Cost.** A behavior change: the schema of a resource with a reference
key is cached per user, and its key reads runtime under
`AccessAnswer::kindOf()`-style reasoning, so it is marked rather than
enumerated in the tool listing. **Breaks** baselines that pinned the
enumeration for node references; users and media are permission-only in
core and could keep `user.permissions` behind a per-target allowlist.

### 2.4 Secrets inside collection items are erased by any write that sends the list

**Severity: medium (integrity).** Not an escalation: it destroys
credentials on the ordinary read, edit, write loop the docs teach an
agent.

**Problem.** A collection sent is accepted item by item "over the
child's defaults and not over what is stored at the same position"
(`src/Pipeline/DataSurfacePipeline.php:497-499`, `:564`), so a secret an
item leaves out falls back to the child's default, `NULL`, not to what
is stored. The read tool removes item secrets
(`docs/decisions.md:2139-2150`), so a caller cannot send them back, and
`kept()` does not merge lists (`src/Pipeline/DataSurfacePipeline.php:280-282`).

**Exploit path (reproduced at the pipeline).** Stored mirrors
`[{host: east, password: mirror-one}, {host: west, password: mirror-two}]`;
input `{"mirrors":[{"host":"east"},{"host":"west-2"}]}`, the read tool's
answer with one host renamed. The prepared artifact was
`[{"host":"east","password":null},{"host":"west-2","password":null}]`,
valid, no note.

**Change.** Refuse rather than erase: an item that leaves out a secret
the stored list holds at that position is a violation naming the path,
until items carry an identity to match by. In `acceptCollection()`, pass
the stored item as `$current` for secrets only, or have `validate()`
refuse `mirrors.N.password` when stored holds a value and the accepted
item holds none. **Cost.** A behavior change and one message. **Breaks**
callers that cleared item secrets by omission, which nothing documents.

### 2.5 The React door does its work before the operation's permission, and its refusals name what exists

**Severity: low.** Exploitable as an existence oracle for anonymous; the
pre-permission work is not a measurable amplifier on this site.

**Problem.** The route access check resolves the operation before it
asks anything: it decodes a POST body, invokes the operation method
(loading the entity a parameter names), builds the whole resource and
composes its storage (`modules/data_surface_react/src/Access/ServedOperationAccessCheck.php:71-78`,
`modules/data_surface_react/src/ServedOperations.php:103-110`), and only
then asks the permission (`src/ResourceBuild/Resources.php:2028-2042`).
A failure becomes `AccessResult::forbidden($e->getMessage())`
(`ServedOperationAccessCheck.php:79-81`), and core prints the reason for
`?_format=json`. The tool door does the same through the Tool API:
setting `id` triggers `refineInputDefinition()`, which builds the
resource and loads storage into the input's defaults
(`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:121-132`,
`modules/data_surface_tool/src/OperationInputs.php:282-290`) before
`checkAccess()` runs, and tools whose permission is `%key` or absent
declare none to gate first (`modules/data_surface_tool/src/Plugin/Derivative/ResourceOperationToolDeriver.php:318-321`).

**Exploit path (reproduced, anonymous).**
`/surface-api/entity.content:node.page/edit?id=4&_format=json` answered
"No valid transitions exist for given account." for unpublished node 4;
`?id=99999` answered "there is none with the id 99999". The same
distinguishes media, easy_email and `field_config` ids. Timing was flat
(0.41 to 0.50 s against 0.43 s for `/admin/config`).

**Change.** Ask the static permission from the route before reading the
request, and refuse with one sentence.

Before, `ServedOperationAccessCheck.php:71-81`:

```php
try {
  try {
    $served = $this->servedOperations->fromRequest($resource, $operation, $request);
  }
  ...
catch (\InvalidArgumentException $e) {
  return AccessResult::forbidden($e->getMessage())->setCacheMaxAge(0);
}
```

After:

```php
$permission = $this->servedOperations->staticPermission($resource, $operation);
if ($permission !== NULL && !$account->hasPermission($permission)) {
  return AccessResult::forbidden()->cachePerPermissions();
}
try {
  ...
}
catch (\InvalidArgumentException) {
  return AccessResult::forbidden('The operation cannot be served with these parameters.')->setCacheMaxAge(0);
}
```

**Cost.** One method on `ServedOperations` reading
`OperationDefinition::staticPermission()`; the message moves to the log.
**Breaks** nothing in the examples; a client parsing the 403 text loses
it.

### Checked and held

- **Mass assignment.** Locked keys hold: fees sent while locked were
  noted and kept (`src/Pipeline/DataSurfacePipeline.php:350-364`);
  `uid`, `status`, `promote`, `sticky`, `created` sent for node 4 by a
  page editor were noted and kept; omitted and unshown keys were refused
  as unknown (`:150-153`). A withheld variant cannot be chosen, since
  the deciding key is narrowed to offered variants
  (`src/DataSurfaceBuilder.php:467-493`).
- **Top lock.** Every write uses `build()`, never `buildStatic()`, so a
  runtime lock set aside for the listing applies to the write.
- **CSRF.** The three POSTs require the header token
  (`modules/data_surface_react/data_surface_react.routing.yml:41`, `:50`, `:59`); a
  POST without it was 403.
- **`%key`.** The placeholder is filled from what the operation knows,
  and every shipped template's value also names the target, so no
  attacker-chosen value maps to a held permission
  (`src/ResourceBuild/Resources.php:3063-3074`).
- **Fail-open by construction, not exploited today.** A context handed
  whole asks no permission of the child
  (`src/ResourceBuild/Resources.php:1535-1541`), and a child permission
  whose `%key` cannot be filled says nothing (`:1660-1662`,
  `docs/decisions.md:1673`). Both are one author's mistake away from a
  bypass; I would make the second a refusal outside a static listing.

## 3. Borrow

- **One outbound projection, as API Platform's normalization groups and
  GraphQL field resolvers have.** Every document leaving a door goes
  through one function of the built surface: omitted paths out, secrets
  out, nothing storage-shaped. It transfers because the module already
  has the static facts it needs (the built surface, `omittedKeys()`,
  `secrets()`), it adds no concept to a settings page, and it makes
  "never stricter than the runtime" (`docs/decisions.md:2799`) mirrored
  by "never more visible than the read".
- **A secret value object, as PHP's `#[\SensitiveParameter]` and
  Rails' `filter_attributes` do.** The accepted value of a secret key is
  a small object whose `jsonSerialize()` and `__debugInfo()` answer
  `null`; storage unwraps it in `prepare()`. Findings 2.2 and 2.4 then
  fail safe whatever a door forgets. The concept already exists
  (`docs/concepts.md`, row "Secret"); only its runtime carrier changes.
- **Secure defaults, as Drupal core's route access does.** Neutral is
  already a refusal at the top (`docs/decisions.md:1820`, "Neutral is a
  refusal"); the same rule at a child, for a permission that cannot be
  named, costs one line and closes the fail-open case above.

## 4. Cut

- **`prepared` at the doors** (finding 2.1). It is a debugging aid in
  storage's private shape, and it is the one output no access rule
  reads. The concept row "Pipeline, dry run" keeps its meaning with
  `values` and `notes`.
- **Exception text in access reasons.** `ServedOperationAccessCheck`
  and `ResourceOperationToolBase::checkAccess()`
  (`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationToolBase.php:73-75`)
  pass exception messages out as reasons; fold them into the logger.
- **"Handed whole skips the permission."** Fold it into the one
  invocation model: the child's operation permission is asked however
  its context arrived, the same-permission shortcut
  (`src/ResourceBuild/Resources.php:1662`) covering the field case that
  motivated it.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `AccessAnswer::omitted()` | `AccessAnswer::hidden()` | "Omitted" reads as "not sent"; a reviewer must learn it means "may not be seen", which is the property finding 2.1 broke. Concept word, so allowed. |
| `DefinitionMetadata::setSecret()` | `setWriteOnly()` | Says what every door must do, and matches the `writeOnly` the documents already emit (`src/Contract/ContractEmitter.php:2503-2504`). The stored metadata key `secret` could stay. |
| `ResourcesInterface::buildStatic()` | `buildForAccount()` | "Static" also names static refiners and static methods; this build is the account's, which is what a cache key must reflect. |
| `prepared` (tool output, React key) | removed, or `rehearsed` if kept | It is storage's rehearsal, not the caller's document. A wire key, so a rename crosses `docs/decisions.md#the-product-keeps-its-name`; removal is the change I recommend. |
| `DataSurfaceAccess::decisive()` | `refuseNeutral()` | Says the security property, neutral becomes forbidden, at the call site. |
| `x-surface.access: "runtime"` | keep | Wire identifier; changing it crosses the product-name decision for no security gain. |

## 6. Verdict

I would adopt it behind the form and the read tool today, because the
access model is careful (three answers, the stricter standing, locks
that hold at every door, CSRF on every POST) and every mass assignment
I tried was held. The caveat is that the write tool and React
`/validate` must not be exposed to accounts or agents for which any key
is omitted or secret until `prepared` is gone and `values` is projected
like a read. One change, a single outbound projection applied to every
document any door returns, would turn my answer into an unqualified
yes.
