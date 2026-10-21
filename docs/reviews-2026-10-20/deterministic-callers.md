# Review: deterministic callers

## 1. Standpoint

I review as an operator and a test author who calls the module with no human in the loop: `drush tool:run` in a deploy script, the ECA model in `demo-config/eca/`, config import across environments, the contract diff in CI, and a contrib module's kernel test for its own resource. I hold the module to one standard: the same call, made twice or made from another environment, must give an answer a program can branch on without reading English.

Everything below was checked on the `next` branch at fad37cc with `ddev drush tool:run ... --json` dry runs, `ddev drush data_surface:contract-diff` without `--write`, one State flag set and deleted again, and `ExamplesToolTest` (OK, 4 tests, 71 assertions).

## 2. The five changes, ranked

### 1. A refusal is data with a code, in one shape at every door

**Problem.** A derived tool's refusal reaches a script only as one translated sentence. `violationSummary()` joins `path: message` pairs with spaces (`modules/data_surface_tool/src/ResultReportingTrait.php:37-43`), and `doExecute()` passes that string to `ExecutableResult::failure()` with no context values, no violation list and the default category (`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:198-201`), although the Tool API's `failure()` accepts both (`web/modules/contrib/tool/src/ExecutableResult.php:126`). Verified: a dry run with a bad room and capacity on `data_surface:registration.step3:configure` answers `{"success":false,"message":"The values were refused: room: The value you selected is not a valid choice when Venue is Riverside Hall; choose Main hall (riverside_main) or East room (riverside_east). capacity: This value should be between 1 and 100 ... ticket.price: Price is required.","outputs":[]}`. No delimiter in that string is safe to split on, since the messages contain colons and periods. When the Tool API refuses the same tool one layer earlier, the JSON shape changes: `data_surface:node.type:add` without `title_label` answers `{"success":false,"error":"Invalid input:\nvalues.title_label: This property is required. ...","error_type":"invalid_input"}`. The key is `error` instead of `message`, lines are newline-separated, and paths carry a `values.` prefix. The text also moves with the site. The same missing title reads "Event title is required." in `tests/src/Kernel/ExamplesToolTest.php:292` but "Public event title is required." on this site, because the compliance alter relabels it. It also changes with the site language. The second call of a create, the idempotence case, is refused as "type: A content type with the machine name page already exists.", so an "ensure it exists" script has to match that string. The code exists at the source and is thrown away: the pipeline wraps Symfony violations as `new Violation($name, $path, ViolationMessage::of($violation))` (`src/Pipeline/DataSurfacePipeline.php:787`, `:1298`), and `Violation` carries only `key`, `path`, `message` and `stale` (`src/Pipeline/Violation.php:50-55`). The HTTP door answers lists of `{path, message}` (`modules/data_surface_react/src/Controller/ResourceApiController.php:236-239`, `:455-460`), and the tool answers `notes` as a `{path: message}` map (`ResultReportingTrait.php:94-100`). That makes three shapes for one result.

**Change.** Give `Violation` a `code`. Where the refusal came from a Symfony constraint, map that constraint's error code to a short stable name (`choice`, `range`, `length`, `regex`, `unique`). Where the module refused, use its own (`required`, `unknown_key`, `type`, `does_not_apply`, `cannot_be_cleared`, `access`, `locked`, `stale`, `not_taken`). Declare `violations`, `stale` and `notes` as tool outputs, each a list of `{path, code, message}` exactly as HTTP answers them. Also pass the violations to the Tool API's own failure channel, with `values.<path>` property paths, because that channel is the one MCP, the AI connector and `eca_tool` read.

Before, `ResourceOperationTool.php:198-201`:

```php
if (!$result->isValid()) {
  return ExecutableResult::failure($this->withNotes($this->t('The values were refused: @violations', [
    '@violations' => $this->violationSummary($result->violations),
  ]), $notes));
}
```

After:

```php
if (!$result->isValid()) {
  return ExecutableResult::failure(
    $this->withNotes($this->t('The values were refused: @violations', [
      '@violations' => $this->violationSummary($result->violations),
    ]), $notes),
    $this->reported($result),
    $this->category($result),
    $this->constraintViolations($result->violations, OperationInputs::VALUES),
  );
}
```

```json
{"success": false, "message": "The values were refused: ...",
 "outputs": {"violations": [
   {"path": "room", "code": "choice", "message": "The value you selected is not a valid choice when Venue is Riverside Hall; ..."},
   {"path": "ticket.price", "code": "required", "message": "Price is required."}],
  "stale": [], "notes": []}}
```

**Cost.** One optional constructor argument on `Violation`, three declared outputs, and the codes join what `docs/evolution.md#what-is-promised` promises. Messages stay outside that promise. **Breaks.** None of the example contracts, because the schemas do not change. Callers that read `notes` as a map, such as `tests/src/Kernel/KeysFromSiteTest.php:221`, change to a list. This leaves `docs/decisions.md#a-refusal-names-what-it-depends-on` intact: the sentence is still written for a person or a model, and the code sits beside it for a program.

### 2. Writing back what was read changes nothing, and a result says what changed

**Problem.** Read-modify-write is the standard pattern for scripts, and here it is not a fixed point. I passed the read tool's `values` for step 3 unchanged to a dry run of the write tool. The read gave `"phone": ""` and `"note": ""`, as installed by `modules/data_surface_examples/config/install/data_surface_examples.registration_step3.yml:8,11`. The write prepared `"phone": null` and `"note": null`, and it added `third_party_settings.data_surface_examples_compliance.stewards: 1`, which the read had left out. The cause is that `''` left out is kept, but `''` sent is "something said, and clears an optional key" (`src/Pipeline/DataSurfacePipeline.php:393-395`, `docs/semantics.md:50`). So the first write from a script, from the form, or from the ECA model that runs on every page save (`demo-config/eca/eca.eca.surface_registration.yml:20-28,53-57`) rewrites the config object, and `config:status` reports drift against a sync directory exported before that write. A script cannot detect this: `ConfigObjectStorage::commit()` always calls `save()` (`src/Storage/ConfigObjectStorage.php:153-163`), and success reports `committed: true` whether or not anything moved. The module already has the comparison it needs. `takes()` compares a sent value and a held value in accepted form to decide whether a locked key gets a note (`DataSurfacePipeline.php:439-456`).

**Change.** (a) When a sent value's accepted form equals the accepted form of what is held, keep the stored representation. That is the `takes()` rule applied to every key, so clearing a key that holds nothing is a no-op. (b) Add `changes` to `DataSurfaceResult`: the paths whose prepared value differs from what `load()` returned, reported on a dry run too, so a dry run becomes a plan. (c) Skip `commit()` when `changes` is empty.

Before, `ResourceOperationTool.php:219-223`:

```php
[
  OperationInputs::VALUES => $result->values,
  OperationInputs::COMMITTED => $result->committed,
] + $outputs + ($stale === [] ? [] : ['stale' => $stale]) + ($notes === [] ? [] : ['notes' => $notes]),
```

After:

```php
[
  OperationInputs::VALUES => $result->values,
  OperationInputs::COMMITTED => $result->committed,
  // ['third_party_settings.data_surface_examples_compliance.stewards'] on the round trip above.
  OperationInputs::CHANGES => $result->changes,
] + $outputs + $this->reported($result),
```

**Cost.** A behavior change: a no-op write no longer saves, so config save events stop firing for it. One new output. **Breaks.** No contract changes. A form saved untouched stops normalizing `''` to `null`, which is the point. Tests that assert a write message on an unchanged submit need a new expected string.

### 3. Access and lock refusals carry the Access category

**Problem.** I set `data_surface_examples_workflow.state` to `published`, ran the program tool, and deleted the flag again. The refusal was `@access: This may be seen and not changed, so nothing was written: ...` with `FailureCategory::Input`, and `isCorrectable()` returned TRUE. The AI connector attaches the input schema to every correctable failure, which invites the agent to retry (`web/modules/contrib/tool/modules/tool_ai_connector/src/Plugin/AiFunctionCall/ToolPluginBase.php:367`). Drush gives `error_type: access_denied` only to refusals that happen before execution (`web/modules/contrib/tool/src/Drush/Commands/ToolRunCommand.php:175-183`), so this one prints a plain failure. The tool's own access refusals also default to Input (`ResourceOperationTool.php:183-185`, `ResourceOperationReadTool.php:50-52`). The tool cannot tell a lock from the result either. `DataSurfaceResult::isAccessRefused()` is FALSE for a resource locked whole (`src/Pipeline/DataSurfaceResult.php:80-82`), because a locked answer counts as allowed (`docs/decisions.md#locked-at-the-top`). That leaves the pseudo-key `@access` (`src/Pipeline/DataSurfacePipelineInterface.php:43`) as the only way to recognize it.

**Change.** Choose the category from the result. Codes `access` and `locked` (from change 1) map to Access, a storage exception maps to Runtime, and everything else maps to Input.

```php
// Before, ResourceOperationTool.php:183-185
if (!$access->isAllowed()) {
  return ExecutableResult::failure($this->t('You do not have permission to do this.'));
}
// After
if (!$access->isAllowed()) {
  return ExecutableResult::failure($this->t('You do not have permission to do this.'), category: FailureCategory::Access);
}
```

**Cost.** A few lines once codes exist. **Breaks.** Nothing. An ECA model that reads `[result:category]` gets the right answer.

### 4. One PHP door, and a test trait a contrib module can use

**Problem.** A deploy hook or a contrib module's kernel test has to assemble operation, build, storage, access and submit by hand. Eight of the module's own test classes each write this as a private helper, and they disagree on access. `tests/src/Kernel/LockedAccessTest.php:104-108` passes `access:`. `ExamplesComplianceTest.php:170-175` and `FullSubmitTest.php:110-115` do not, and a NULL answer "means nothing was asked" (`src/Pipeline/DataSurfacePipelineInterface.php:288-294`), so those helpers would write for an account the operation refuses. The README's PHP example passes no access either (`README.md:117-121`). The form makes neutral a refusal with `DataSurfaceAccess::decisive()` (`src/Form/DataSurfaceOperationForm.php:255`), so the doors assemble the call three different ways. The best testing assets are the agreement traits (`tests/src/Traits/StaticRefinerAgreementTrait.php:70`, `StaticAccessAgreementTrait.php:58`, `ContractValidationTrait.php:40`). A contrib module can autoload them, but no page tells an adopter they exist (only `docs/refinement.md:314` names one, in passing), the base class depends on the `data_surface_test` module (`tests/src/Kernel/DataSurfaceKernelTestBase.php:17`), and the never-stricter matrix is 1352 lines of hand-written rows (`tests/src/Kernel/Matrix/AdvertisedSchemaAcceptanceTest.php`).

**Change.** Add `ResourcesInterface::submit(string $resource, string $operation, array $values, array $arguments = [], bool $dry_run = FALSE, ?AccountInterface $account = NULL): DataSurfaceResult`, which applies decisive access the way the form does, and route the tool, the form and HTTP through it. Ship `Tests\data_surface\Traits\ResourceTestTrait` with `submitOperation()`, `assertRefused($result, $path, $code)`, and `assertContractHolds($resource, $operation)`. The last runs both agreement traits plus a never-stricter pass over documents sampled from the full schema, using the existing `StaticRefinerAgreementTrait::samples()` (line 282). Document it in a "Testing your resource" section.

Before, `ExamplesComplianceTest.php:170-175`:

```php
protected function submit(array $input, bool $dry_run = FALSE): DataSurfaceResult {
  $resources = $this->container->get('data_surface.resources');
  $context = $resources->operation(RegistrationStep3Resource::class, 'configure');
  $surface = $resources->build(RegistrationStep3Resource::class, $context);
  return $this->pipeline()->submit($surface, $input, $resources->storage(RegistrationStep3Resource::class, $context, $surface), $dry_run);
}
```

After, in a contrib module's kernel test:

```php
use ResourceTestTrait;

public function testNewsletterSettings(): void {
  $result = $this->submitOperation(NewsletterResource::class, 'configure', ['frequency' => 'daily'], dry_run: TRUE);
  $this->assertRefused($result, 'frequency', 'choice');
  $this->assertContractHolds(NewsletterResource::class, 'configure');
}
```

**Cost.** One public method, one trait and one docs section. Authoring adds no concept. **Breaks.** Nothing shipped. Helpers that were silently skipping access start to refuse.

### 5. The contract diff fails closed and speaks JSON

**Problem.** The command is the gate an adopter's CI runs, and in four cases it passes when it should fail. First, with `--dir=/nonexistent/typo` it reported "16 differences, 0 breaking" and exited 0, because a declaration with no baseline is classed as compatible (`src/Contract/ContractBaselines.php:179`). `ContractBaselineTest` fails in the same case (`docs/evolution.md:232-234`), so the test and the command disagree. Second, one directory holds three modules' baselines (19 files in `tests/fixtures/contracts/`, where `docs/evolution.md:212` says "nine files"). Running `--module=data_surface_first` against that directory reports the other 17 as "no longer declared", breaking. With `--write --allow=...`, the command would record allowances for them and then unlink them (`src/Drush/Commands/ContractDiffCommand.php:122-133`). Third, the output is a table only: `--format=json` is refused as "The --format option does not exist". Fourth, emission runs as uid 1 (`ContractDiffCommand.php:89`), which gives different documents on a site that turns the super user policy off. Separately, `tests/fixtures/contracts/event.sales.json:34` pins the `speaker` key, which example 9 reads from site config. A config change in one environment therefore fails a code gate run there.

**Change.** In compare mode, a missing baseline fails. Each baseline records its `module`, and `--write` touches only files whose module is listed. Add `--format=json`. Take the account from `--uid`. Mark a document built from keys that come from the site in its verdict.

```php
// Before, ContractDiffCommand.php:131-133
foreach (array_diff_key($committed, $current) as $id => $ignored) {
  unlink($directory . '/' . $id . '.json');
}
// After
foreach (array_diff_key($committed, $current) as $id => $baseline) {
  if (in_array($baseline['module'] ?? NULL, $modules, TRUE)) {
    unlink($directory . '/' . $id . '.json');
  }
}
```

**Cost.** The 19 baselines are rewritten once, and a missing baseline now fails the command. **Breaks.** An adopter's first run needs `--write`, which is already the documented flow.

## 3. Borrow

- **JSON Merge Patch (RFC 7396), by name.** For objects, "absent keeps, null clears, value replaces" (`docs/decisions.md#absent-keeps-null-clears`) is merge patch. Saying so, with its three deviations listed (a list sent `null` keeps, `docs/semantics.md:64-67`; a secret clears only with its marker; an always-held key refuses `null`), lets a script compute the minimal document from (read, desired) with an off-the-shelf library. That only works once change 2 makes read then write a fixed point. It costs a docs paragraph and adds no concept.
- **Terraform plan, Ansible `changed`.** A dry run that returns `changes` is a plan, and CI can assert "no drift" as an empty plan against production. This fits the rule that refinement only narrows: a plan reads what the pipeline would do and states nothing new.
- **RFC 9457 problem details.** A stable `type` or `code` beside a human `detail`. This keeps `docs/decisions.md#a-refused-submit-is-an-answer` (200 with data) and only adds the machine field.
- **ETag and If-Match.** The opt-in fingerprint (`docs/decisions.md#a-fingerprint-is-opt-in`, `modules/data_surface_react/src/ServedOperations.php:205`) protects only React. Moving it to the main module, returning it from the read tool, and accepting an optional `fingerprint` on the write tool gives two cron jobs compare-and-set, and it stays opt-in as decided.
- **Schema-driven property tests (Schemathesis).** Generate documents from the full schema and assert the pipeline accepts what the schema accepts. That is the never-stricter invariant, and `samples()` already does half of it. Change 4's `assertContractHolds()` is where it would live.
- **Expand and contract for the ledger.** The decision to keep the version in the stored value because "Core records its updates in the site's database, which no export carries" (`docs/decisions.md:3338`) is right. Two operator hazards remain in the design. "Code older than its data is refused at load" (`docs/evolution.md:180-181`) makes a rollback, or the overlap of a blue-green deploy, a hard stop at every door. And a step that is pure is not necessarily idempotent: the example step (`docs/evolution.md:135-142`) blanks `sender_name` if it runs again on data whose stamp was lost. Stamps do get lost, for example when uninstalling `data_surface` strips `third_party_settings.data_surface` from a config entity (`core/lib/Drupal/Core/Config/Entity/ConfigEntityBase.php:486-489`). Require steps to guard on shape, and ship a test helper that asserts `migrate(migrate($x, 1), 1) === migrate($x, 1)`.
- **Consumer contracts (Pact).** ECA models store tool input keys in config (`eca.eca.surface_registration.yml:57`). The contract diff could check config entities that name a `data_surface:` tool against today's listing, so renaming a key fails before the model breaks at runtime.

None of these adds an authoring concept. A settings page stays at the four in `docs/concepts.md:4-6`, and both documents stay as emitted.

## 4. Cut

- **Three result shapes, folded into one.** The concept rows "Tool and read tool", "Served contract" and "Pipeline, dry run" (`docs/concepts.md:50-52`) each describe a different answer. Keep the three rows and point them at one "What a call answers" section: `committed, valid, values, prepared, changes, violations, stale, notes`, with the same keys at every door.
- **`@access` as a key.** It is a path no property has (`DataSurfacePipelineInterface.php:43`), and a script that maps paths to keys trips on it. Use path `""` with code `access` or `locked`. The refusal stays a violation, as `docs/decisions.md#locked-at-the-top` wants.
- **Notes appended to the message.** `withNotes()` (`ResultReportingTrait.php:113-122`) makes the success message vary with what was sent. Once `notes` is a declared list, keep one stable sentence per outcome. An MCP client still gets the list as structured content.
- **The read tool's own meaning of `values`.** It returns storage raw, and the write tool returns accepted values (`modules/data_surface_tool/src/OperationInputs.php:520-526`). Have the read return what a dry run with nothing sent accepts. That is one meaning of `values`, and it fixes change 2 from the read side as well.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `notes` (tool output, HTTP key, `DataSurfaceResult::$notes`) | `not_taken` | A note sounds safe to ignore, but this one means the value was dropped while the call exited 0. A wire change that does not touch the word surface. |
| `@access` path | `""` with `code: access` or `locked` | A key that no property has. |
| `committed`, labeled "Written" (`OperationInputs.php:527-530`) | keep `committed`, label "Committed" | The label and the name disagree. |
| `kind: update` (`x-surface.operation.kind`) | `kind: patch` | An update here is a merge, and scripts read "update" as replace. A wire value with no surface word, so it is a contract note, not a crossing of `docs/decisions.md#the-product-keeps-its-name`. |
| contract-diff `--dir` | `--baselines` | Says what the directory holds. It is resolved from the docroot, not the project root. |
| `licence`, "Event licence" (compliance mount) | `license` | American spelling, as core uses. The key rename is a key removed plus a key added, so it is breaking and belongs before the first tag. |
| Three tools labeled "Configure registration" (`tool:list`, steps 1 to 3) | "Configure registration, step 1" and so on | `tool:list` and ECA's action picker show three identical labels. |
| `data_surface:contract-diff`, `x-surface.version` | keep | Product and wire names, under the decision. |

## 6. Verdict

I would adopt it for settings resources that Drush, ECA and CI drive, because the contract, the dry run and the partial update semantics are better thought through than anything I script against in core. The caveat is that today a script must string-match translated sentences that change with enabled alters, must treat exit 0 with `notes` as a partial failure, and gets a config diff from its first read-then-write round trip. The one change that would turn my answer into an unqualified yes is a stable `code` on every violation, carried in one declared result shape at every door.
