# Review: AI agents over MCP

## Standpoint

I review as an engineer building tool-using agents over MCP, who reads both what a model is handed (the `tools/list` entry, its `inputSchema`, its description) and what a person reads to wire it up (the two documents, the READMEs). I hold the module to three tests: does a model pick the right tool from the listing, fill it correctly the first time, and recover from a refusal in one step.

Evidence: `tools/list` and twenty `tools/call` requests (dry runs and one read) over `ddev drush mcp:server admin` against the six mappings `demo-config/mcp/map-tools.php` made, `tool:info` and `tool:run` dry runs on the bulletin, ticket sales, program, press, content type and field tools, and `GET /surface-api/{resource}/{operation}/schema` for steps 3, 10 and the press page. Nothing was written.

## The five changes, ranked

### 1. Put the rules the full schema states into the listing, not just their names

**Problem.** For a key another key narrows, the listing says only "Depends on @keys." (`modules/data_surface_tool/src/InputDefinitions.php:215`). The bulletin declares its rules as data, each with a sentence the author wrote (`modules/data_surface_examples/src/Resource/BulletinResource.php:53-59`). Over MCP they arrive as "Items per issue: Depends on frequency." and "Send day: Depends on frequency." Step 3's full schema states every room's ceiling and the license rule as conditionals with descriptions ("Up to 400 for the Main hall.", "Up to 100 without an event licence."). The listing says "Depends on room, third_party_settings.data_surface_examples_compliance.licence.", so a model learns the ceiling only by being refused. Two more facts are lost the same way. The license's `pattern` is not in the MCP schema, though the contract carries `patternMessage`. And a key locked below the top looks writable: the speaker ticket type is a plain nullable boolean in the normalized schema, because `lock()` (`InputDefinitions.php:363-376`) sets a flag the normalizer ignores below the top level (`modules/data_surface_tool/README.md:376-379`). The fresh-eyes log hit the same thing (`docs/fresh-eyes-2026-10-19b.md:28`). The module already has the pattern for this: `runtimeAccessNote()` writes the runtime access marker in words because no keyword reaches the listing (`InputDefinitions.php:1036-1042`).

**Change.** In `withDependencies()`, write each stated conditional for the key from the same enumeration `emitFull()` uses, plus a sentence for a key that may not apply, a key locked for this account, and a pattern.

```php
// Before, InputDefinitions.php:215
$note = $this->t('Depends on @keys.', ['@keys' => implode(', ', $keys)]);
```

```php
// After: the stated rules, capped, then the pointer for what is not stated.
$note = $this->rulesSaid($surface, $path)   // "When frequency is weekly: up to 5 in a weekly issue."
  ?? $this->t('Depends on @keys.', ['@keys' => implode(', ', $keys)]);
```

The listing then reads "Send day: Only when frequency is weekly; required there." and "Items per issue: When frequency is weekly, up to 5 in a weekly issue." For step 3: "Capacity: When room is riverside_main, up to 400; ... When the event license is empty, up to 100." For the speaker ticket type: "Locked: kept as stored; a value sent is not taken."

**Cost.** Listing text only. No new concept, no contract change. The listing grows only on keys that have rules, and the prose can be capped at `CHOICES_SHOWN` sentences, then "and N more".

**Breaks.** The exact strings in `ToolFeedbackTest` and the recorded output at `modules/data_surface_examples/README.md:756-764`. No adopter contract changes. This keeps `docs/decisions.md:1953` ("a key that depends on nothing says nothing more"). Prose cannot be stricter than the runtime because it validates nothing.

### 2. Give an operation a description, and spend the tool description on it

**Problem.** `#[Operation]` takes an id, a label, a permission and three flags, and no description (`src/Resource/Attribute/Operation.php:77-86`). `#[Resource]` has none either (`src/Resource/Attribute/Resource.php:52-58`). So every tool description is the template in `modules/data_surface_tool/src/OperationInputs.php:571-579`: the label, the resource id, the kind, and a 32-word sentence that 38 of the 65 derived tools share word for word. Together those 65 descriptions run to 25,093 characters (counted from the tool plugin manager). In `tools/list`, steps 1, 2 and 3 are all titled "Configure registration", and their descriptions differ only by `registration.stepN`. The one sentence that would help a model choose, "Configures the registration settings, which always exist." (`modules/data_surface_examples/src/Resource/RegistrationStep3Resource.php:31-33`), never leaves PHP.

Three smaller defects sit in the same template:

- **The read tool is named by its Tool API id.** The description points to `data_surface:registration.step3:configure:read`, but over MCP that tool is `tool_api__registration_step3_read` (`demo-config/mcp/map-tools.php:21`).
- **The quotes reach MCP escaped.** The quotes around "configure" arrive as `&quot;configure&quot;`, because the bridge wraps the description in an `@text` placeholder (`mcp_server_tool_bridge/src/Plugin/Derivative/McpToolConfigDeriver.php:117`).
- **Two rules a caller needs are missing.** The template never says that `null` clears a key, which the module itself says departs from the Tool API (`docs/decisions.md:2977`). And it never gives the id shape of a subject parameter: "The id of the field_config the operation is about." (`OperationInputs.php:648`), while the README says `<entity type>.<bundle>.<field name>`.

**Change.**

```php
// Before, RegistrationStep3Resource.php:34
#[Operation('configure', label: new TranslatableMarkup('Configure registration'), permission: 'administer site configuration')]
```

```php
// After: optional, defaulting to the method's docblock summary, read by reflection as the signature already is.
#[Operation('configure', label: new TranslatableMarkup('Configure registration'),
  description: new TranslatableMarkup('Sets the public title, venue, room, capacity, pricing and contact of the event registration form.'),
  permission: 'administer site configuration')]
```

The template becomes: the description, then one line of call rules ("Update: send only the keys to change; a key left out keeps its value, null clears it. The read tool for this operation returns the current values."), with no literal quotes. The emitter writes the same text as the top `description` of both documents.

**Cost.** One optional attribute parameter, no new concept. A settings page still needs four.

**Breaks.** The description assertions in `OperationKindsTest`. Nothing for adopters, since the parameter is optional. In the demo, `map-tools.php` should derive each MCP name mechanically from the tool id, so a name in a description can be matched.

### 3. Make a refusal exact, one violation per line, and in the right failure category

**Problem.** Four things, all in the refusal path:

- **It can name the wrong reason.** With the license "EV-2048" and capacity 150, the refusal reads "capacity: This value should be between 1 and 100 when Room is Main hall and Event licence is set." The ceiling is 100 because the refused license was handed to the rule as empty (`docs/decisions.md:461-470`). But `conditions()` describes the value that was sent (`src/Pipeline/DataSurfacePipeline.php:919-923`), so the sentence gives the opposite of the real reason. A model would clear the license.
- **It uses a different vocabulary from the listing.** The listing names a key by its path. The refusal names it by label ("Event licence"), and an option by label alone ("Main hall"), although `choices()` gives "Main hall (riverside_main)". Labels were chosen for the form's sake (`docs/decisions.md:1881`), but the tool has a renderer of its own.
- **Every violation is on one line.** They are joined with a space (`modules/data_surface_tool/src/ResultReportingTrait.php:42`).
- **Every refusal is in the "input" category.** `ExecutableResult::failure()` defaults to `FailureCategory::Input`, and the tool never passes another (`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:184,199`). So the bridge appends the whole input schema, 8,236 characters on step 3 (`mcp_server_tool_bridge/src/Plugin/mcp_server/Tool/ToolApi.php:268-271`), to "You do not have permission to do this." and to a resource locked whole, where no retry can help. The schema it echoes is the listing's wide one: it offers all six rooms directly under a sentence that says to choose one of two. The reverse case is also wrong. An unknown subject, "there is none with the id \"node.page.field_nope\"", comes back as "Access denied", which a model reads as final, though fixing the id is all it needs. The README still says this case is "refused without saying why" (`modules/data_surface_tool/README.md:297-299`).

**Change.**

```php
// Before, ResourceOperationTool.php:199
return ExecutableResult::failure($this->withNotes($this->t('The values were refused: @violations', [...]), $notes));
```

```php
// After
return ExecutableResult::failure($message, [], $built->lockedWhole() ? FailureCategory::Access : FailureCategory::Input);
// $message: one "path: sentence" per line; the clause names the path and the value,
// "when room is riverside_main (Main hall) and third_party_settings.data_surface_examples_compliance.licence
// is empty (EV-2048 was refused)".
```

Pass `FailureCategory::Access` at line 184 too. `conditions()` should read the values the refiners were handed (`DataSurface::refusedWatchedValues()`, `src/DataSurface.php:639`), not the values sent.

**Cost.** A behavior change in message text. The form's sentence can stay as it is if the path form belongs to the tool alone.

**Breaks.** The exact strings in `ToolFeedbackTest` and the three recorded refusals at `modules/data_surface_examples/README.md:776-778`.

### 4. Make the call envelope say what it does: `values` never null, every output declared

**Problem.** `values` is advertised as `["object","null"]` and optional. At the top, `null` means nothing: `$values[VALUES] ?? []` (`ResourceOperationTool.php:188`), which is the Tool API's reading. One level down, `null` clears (`docs/decisions.md:2958-2991`): `contact: null` was refused as "contact.email: Email is required.", and `ticket: null` cleared the note. A model that has learned "null clears" will expect `values: null` to clear everything. It does nothing.

A call with no `values` is not a no-op either. The dry run of `{"dry_run": true}` accepted `third_party_settings.data_surface_examples_compliance.stewards: 1`, which the read tool shows is not stored. Without `dry_run`, that call writes a default and answers "the values were accepted and written."

On the output side, `notes` and `stale` are returned (`ResourceOperationTool.php:222`) but never declared (`OperationInputs.php:520-538`). The `outputSchema` lists `values`, `committed` and `prepared`, so a model has no field to look for. It reads `isError: false` as done, even when the answer says "Speaker is locked and kept as stored; the value sent was not taken."

**Change.**

```php
// Before, OperationInputs.php:168
$inputs[self::VALUES] = $this->declaredValues($resource, $operation, $neutral);
```

```php
// After: an object, always sent; on an update it may be {} and still requires no key.
$values = $this->declaredValues($resource, $operation, $neutral);
$values->setRequired(TRUE)->addConstraint('NotNull');
$inputs[self::VALUES] = $values;
```

The Tool API's normalizer drops the `null` branch for `NotNull` (`tool/src/Normalizer/ContextDefinitionNormalizer.php:297-316`). Add `notes` and `stale` to `outputs()` as optional maps, with descriptions saying they mean "accepted, but not as sent".

**Cost.** A behavior change at the door: a call without `values` is refused by MCP's own validation. This does not conflict with never stricter: that rule is about the two documents and the accepted document, and a missing `values` is a notation of an empty patch, which the schema need not admit (`docs/served-contract.md:107-113`).

**Breaks.** Callers that send only `dry_run`, and any test that does. Nothing in either document.

### 5. The two documents: one narrowing keyword, a fixed table, and what an agent needs

**Problem.** Four things:

- **The table contradicts itself.** `docs/served-contract.md:303` says `runtime` is "never in the full schema", but `:81` and `:165` say it is, and the emitted full schema for step 3 carries `"runtime": true` on `room`.
- **Four booleans answer one question, and each misleads.** `enumerated`, `declared`, `dynamic` and `runtime` all describe how a key narrows:
  - `enumerated`, on `capacity`, an integer, reads as "this is an enum".
  - `declared` reads as "this key was declared", which every key is.
  - `dynamic` reads as "computed".
  - `runtime` collides with `access: "runtime"`, which is a different fact.

  A client needs one answer: can it compute this key's schema locally, must it ask `/refine`, or is the answer live? `docs/served-contract.md:163,300` already say a client evaluates enumerated and declared conditionals alike.
- **The documents describe the merged document, but an update caller sends a patch.** The full schema's `required: ["title","venue","room","pricing"]` and the license `if` with no `required` (so a missing license reads as none) describe what results after the patch is applied. One clause says so (`docs/served-contract.md:110-113`). An agent that validates its patch against the full schema will resend the required keys, and will apply the 100 ceiling when a license is already stored.
- **Two gaps for agents.** `checkedOnServer` lists plugin ids, such as `DataSurfaceUniqueNodeType` (`tests/src/Kernel/ServedContractTest.php:1353`), not what they check. And an MCP agent cannot reach either document, though `docs/served-contract.md:96` names "an agent planning its values" as their reader.

**Change.**

```json
// Before (step 3, full schema)
"room":     {"x-surface": {"dependsOn": ["venue"], "enumerated": true, "runtime": true}},
"capacity": {"x-surface": {"dependsOn": ["room", "third_party_settings...licence"], "enumerated": true, "declared": true}}
```

```json
// After
"room":     {"x-surface": {"dependsOn": ["venue"], "narrowing": ["stated", "live"]}},
"capacity": {"x-surface": {"dependsOn": ["room", "third_party_settings...licence"], "narrowing": ["stated"]}}
```

`"refine"` replaces `dynamic`. At the top, `x-surface.operation` gains `"sends": "patch"` on an update, and the page gains a section, "Reading it from an agent". `checkedOnServer` becomes a list of `{constraint, message}`. The read tool's output gains `schema`, the full schema for the caller, since an agent reads before it writes.

**Cost.** A wire change inside `x-surface`: contract-diff baselines and the React app's `contract.ts`. It does not rename `x-surface` itself, so it stays on the right side of `docs/decisions.md#the-product-keeps-its-name`.

**Breaks.** `AdvertisedSchemaAcceptanceTest` fixtures, `ContractDiff`, the React app, and any adopter baseline. This needs a contract version, which the module does not have yet.

## Borrow

- **Smithy's `@documentation` and `@examples` traits on operations.** The module already takes "shape" from Smithy. An operation example is a title, an input, and an output or error. A worked call is the strongest first-call aid I know; it beats any schema keyword. It is a static declaration. The acceptance suite can run each example through both documents and the pipeline, so never stricter is tested rather than assumed. As parameters on `#[Operation]`, it costs the concept budget nothing.
- **Error objects with JSON pointers.** MCP's own validation errors already have this shape (`{"pointer": "/values/capacity", "keyword": "type", "message": ...}` in the `-32602` answers I got). Pipeline refusals should match: `{pointer, message, allowed}`, as a JSON line in the text until the bridge carries structured errors. `ExecutableResult::failure()` already accepts a violation list (`tool/src/ExecutableResult.php:126`). The pipeline has every part already: the path, the sentence and the refined list.
- **A distinct title and name, unique per server.** Agent frameworks rank tools by name and title. Read tools titled "Configure registration, read only" and three identical write titles make that ranking a coin toss.

## Cut

- **`dry_run`, `committed` and `prepared` on a read-only operation's tool.** `data_surface:event.program:check` lists all three, but a read-only operation writes nothing at any door (`docs/decisions.md:2023-2036`).
- **The shared boilerplate sentence** in `OperationInputs.php:572,575,579` and the `values` map description (`:500`). Change 2 replaces them.
- **`enumerated` and `declared` as separate markers.** Fold them into `stated` (change 5).
- **`access: "runtime"` repeated on every key** when the answer set aside is the resource's own (`docs/served-contract.md:285`). Example 10's full schema repeats it on `headline`, `sessions`, `published` and `fees`, and the listing repeats a 24-word sentence on each. Say it once at the top, as `readOnly` is said for a resource locked whole.
- **Not cut: the read tool.** It looks foldable into a dry run with no `values`, but a dry run differs from a read: it fills defaults (the steward count above). More importantly, MCP annotates the read tool `readOnlyHint: true`, which clients approve without asking. A dry run through a write tool is `readOnlyHint: false` and may prompt the user. The "Tool and read tool" row in `docs/concepts.md` stays.

## Names and spelling

| Current | Proposed | Reason |
| --- | --- | --- |
| `x-surface.enumerated`, `declared` | `narrowing: ["stated"]` | Read as "is an enum" and "was declared". Wire change inside `x-surface`; does not cross the product-name decision. |
| `x-surface.dynamic` | `narrowing: ["refine"]` | "Dynamic" reads as computed; the meaning is "ask `/refine` with values". |
| `x-surface.runtime` | `narrowing: ["live"]` | Collides with `access: "runtime"`, a different fact. |
| `x-surface.access: "runtime"` | `access: "onCall"` | Says when it is settled, not where it is computed. |
| `checkedOnServer` (list of ids) | `serverChecks` (list of `{constraint, message}`) | Past tense reads as a status flag; ids say nothing to a model. |
| `situation`, `surface` (document keys) | `operation`, `resource` | Crosses the product-name decision; defer to a contract version, as `docs/decisions.md:150-151` already does. |
| Read tool label "Configure registration, read only" | "Current values: Configure registration" | Reads as a variant of the write. |
| "may find it locked or left out" (`InputDefinitions.php:1037`) | "may find it locked or hidden" | "Left out" already means "a key the caller omits" in the same description. |
| Output `committed`, labeled "Written" | One word for both | A model reads the name; a person reads the label. |
| `"configure"` in tool descriptions | `configure`, no quotes | Arrives over MCP as `&quot;configure&quot;`. |
| "Access denied for tool ... there is none with the id" | "Not found: ..." in the input category | A model treats access as final; a wrong id is fixable. |

## Verdict

I would adopt it for agent-facing site configuration today. What sets it apart for agents is that its refusals already say what is allowed for the values sent, its dry runs are real, and its read tool exists. My caveat: the listing a model actually sees is the Tool API's rendering, which says a fraction of what the full schema states, so first calls fail where they need not. What would change my answer from "adopt with care" to "adopt": change 1, the stated rules in the listing, together with the fix to the misleading license refusal in change 3.
