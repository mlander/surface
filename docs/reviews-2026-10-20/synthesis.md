# Synthesis of the thirteen DX reviews, 2026-10-20

The reviews were taken on `next` at fad37cc; `be03a68` only adds them,
so every citation below is against the code as reviewed. Nothing here is
built without the owner's word, item by item. Reviewers are cited by
slug: `laravel`, `php-symfony`, `creative-generalist`, `smithy`,
`drupal-architecture`, `naming`, `form-api`, `deterministic-callers`,
`ai-agents`, `security`, `performance`, `json-schema-tooling`,
`multilingual`. Paths are relative to the module unless they start with
`web/`.

**Keys.** Severity: **high** leaks data, loses data or silently corrupts
what is cached; **medium** gives a wrong answer at a door or breaks a
documented promise; **low** misleads without harm. Cost: **S** is under a
day, **M** a few days, **L** a week or more. "Wire" means a client reads
it and the change needs a contract version.

## 1. Bugs: the fix-now list

Each was reproduced on the site or traced through the code by the
reviewer named, and is incorrect behavior, not taste. These are the
**fix now** list: none needs a design decision beyond the one noted.

| # | Bug | Sev | Found by | Cited at | Fix, in one sentence | Cost |
| --- | --- | --- | --- | --- | --- | --- |
| B1 | A dry run returns the stored values of keys access omitted, through `prepared` (write tool and React `/validate`); reproduced on example 10's fees and a field-permissions field. | high | security | `modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:208-210`; `src/ResourceBuild/StorageAdapter.php:133-142`; `modules/data_surface_react/src/Controller/ResourceApiController.php:230-233` | Stop serving `prepared` at the doors (keep it on `DataSurfaceResult` for PHP callers) and drop it from `OperationInputs::outputs()`. | S |
| B2 | Stored secrets come back in `values` from the write tool (dry or committed) and in React `/validate`'s document. | medium | security | `src/Pipeline/DataSurfacePipeline.php:653-660`; `ResourceOperationTool.php:219-222`; `ResourceApiController.php:241` | Apply the read tool's projection (omitted paths and secrets out) to every document any door returns, one `outbound()` method. | S |
| B3 | A write that sends a collection erases each item's stored secret (falls back to the child default, `NULL`), with no note. | medium | security | `src/Pipeline/DataSurfacePipeline.php:497-499`, `:280-282` | Refuse an item that leaves out a secret stored at that position, naming the path, until items carry an identity. | M |
| B4 | The React door builds, loads the subject and composes storage before asking the permission, and returns exception text as the 403 reason: an anonymous existence oracle for entity ids. | low | security | `modules/data_surface_react/src/Access/ServedOperationAccessCheck.php:71-81` | Ask `OperationDefinition::staticPermission()` first and refuse with one fixed sentence, the message going to the log. | S |
| B5 | Referenceable options (and so the cacheable full schema) vary by `user.permissions` only, though node grants make them per account; a second user with the same roles can receive the first's labels. | medium | security | `modules/data_surface_entity/src/Plugin/DataSurfaceOptionsResolver/ReferenceableOptions.php:107-109` | Declare `user` (or the selection's own cacheability), with an allowlist for permission-only targets such as users and media. | S |
| B6 | The tool listing is cached in the language that discovered it: seven `(string)` casts flatten lazy labels and descriptions, so an agent reads the tool in one language and its keys in another. | medium | multilingual | `modules/data_surface_tool/src/Plugin/Derivative/ResourceOperationToolDeriver.php:166`, `:207`; `modules/data_surface_tool/src/InputDefinitions.php:216`; `modules/data_surface_tool/src/OperationInputs.php:565` | Pass the markup uncast and let `appended()` take `TranslatableMarkup|string`. | S |
| B7 | `PlainTextMarkup::translatable()` rebuilds from the untranslated string, which breaks plurals (renders both forms with a literal `@count`). | medium | multilingual | `modules/data_surface_tool/src/PlainTextMarkup.php:47` | Make it a decorator that holds and renders the original markup. | S |
| B8 | The React page drops the language prefix (`apiBase` from the base path), so `/fr/...` fetches the default language's schema; the app's own strings are English literals with no `Drupal.t`. | medium | multilingual | `modules/data_surface_react/src/Controller/ResourceReactPage.php:96-97` | Build each endpoint with `Url::fromRoute()` and bubble its cacheability; move app strings into `Drupal.t`/`formatPlural` calls that survive the build. | M |
| B9 | `LanguageExistsOptions` returns override-translated language names with no `languages:language_interface` context. | low | multilingual | `src/Plugin/DataSurfaceOptionsResolver/LanguageExistsOptions.php:87-89` | Add the context, as `CountryOptions` does. | S |
| B10 | Refusals are glued from fragments: `Clause` strips an ASCII full stop only, conditions join with `t('and')`, values are the bare words `t('on')`, `t('off')`, `t('set')`, `t('empty')` with no context, and "and @count more" has no plural. | medium | multilingual | `src/Pipeline/Clause.php:51`; `src/Pipeline/DataSurfacePipeline.php:874`, `:878`, `:922-929`, `:968` | One whole, contexted string per condition kind, the constraint's message kept whole, a second sentence "This applies because ...", and `formatPlural()`. | M |
| B11 | The license refusal names the wrong reason: it describes the value sent ("Event licence is set") while the rule was handed the refused value as empty, so an agent clears the license. | medium | ai-agents | `src/Pipeline/DataSurfacePipeline.php:919-923`; `src/DataSurface.php:639` | Describe the values the refiners were handed (`refusedWatchedValues()`), not the values sent. | S |
| B12 | Writing back what the read tool returned changes storage: `''` becomes `null`, and a default absent from storage (`stewards: 1`) is written; `commit()` always saves, and success says `committed` either way. | medium | deterministic-callers (and ai-agents, for a call with no `values`) | `src/Pipeline/DataSurfacePipeline.php:393-395`; `src/Storage/ConfigObjectStorage.php:153-163` | Keep the stored representation where the accepted forms are equal (the `takes()` rule for every key), report `changes`, and skip `commit()` when there are none. | M |
| B13 | A permission refusal and a resource locked whole are reported as correctable `Input` failures, so the bridge and the AI connector append the whole input schema and invite a retry; an unknown subject id comes back as "Access denied", which reads as final. | medium | ai-agents, deterministic-callers | `ResourceOperationTool.php:184`, `:199`; `modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationReadTool.php:50-52`; `src/Pipeline/DataSurfaceResult.php:80-82` | Pass `FailureCategory::Access` for permission and locked-whole refusals, `Runtime` for storage exceptions, and an unknown subject as an `Input` "not found". | S |
| B14 | `notes` and `stale` are returned but never declared as outputs, so a model reads `isError: false` as done when a value was not taken; `prepared` is declared on read-only tools that never write. | medium | ai-agents, deterministic-callers | `modules/data_surface_tool/src/OperationInputs.php:520-538`; `ResourceOperationTool.php:222` | Declare `notes` and `stale` (and, with codes, `violations`) as optional outputs in one shape, and drop the dry-run outputs from read-only operations. | S |
| B15 | A form error names no field when Inline Form Errors is off ("This value should be between 1 and 1000."), an AJAX error is not in the input's `aria-describedby`, and a rebuild announces nothing (WCAG 3.3.1, 4.1.3). | medium | form-api | `src/Form/DataSurfaceFormBuilder.php:1654-1659`, `:1036-1065` | Prefix the element's title where the message is not printed beside it, tie the inline error by id, and send an `AnnounceCommand` with what the rebuild changed. | M |
| B16 | A lock's reason is replaced by the constant "Fixed for this operation."; a form locked whole keeps required markers on disabled elements; a `readOnly` operation shows "Save". | low | form-api | `src/Form/DataSurfaceFormBuilder.php:2154-2163`; `src/Form/DataSurfaceOperationForm.php:212` | Print the access answer's reason, drop markers when locked whole, and label the button with the operation's label. | S |
| B17 | Two "Remove" buttons in a list share one accessible name. | low | form-api | `src/Plugin/DataSurfaceWidget/ListWidget.php:299` | Add a visually hidden "@label @n" suffix. | S |
| B18 | Plugin discovery order bug: `ResourcePlugins::read()` sets `$this->used = []` before its loop, so a registry asked from inside it (via `eca_tool`) discovers without plugins that are their own structure and memoizes, and on production writes, that incomplete set (90 definitions instead of 91). | high | performance | `src/ResourceBuild/ResourcePlugins.php:92` | Fill locals and assign `used` and `own` at the end, so a re-entrant call cannot see a half state. | S |
| B19 | The options memo keys by `spl_object_id()` and keeps every definition, so long-lived workers (MCP stdio, queues, Drush batches) grow by about 2.5 KB per call; `Resources::$staticAnswers` grows per entity id the same way. | medium | performance | `src/Options/DataSurfaceOptions.php:96-98`, `:117`; `src/ResourceBuild/Resources.php:166` | Use a `WeakMap`; bound or re-key the static answers. | S |
| B20 | The pattern subset is not never-stricter: `\d`, `\w`, `\b` under PHP's `u` read Unicode properties and ECMA does not, and `[^\s]` is narrower in ECMA; the schema refuses `٢٠٤٨` and `café` that PHP accepts. | medium | json-schema-tooling | `src/Contract/PatternTranslation.php:167` | Carry `\d \w \b` only without `u` and `\s` only outside a negated class, else mark `checkedOnServer`; add an ajv table to Vitest. | S |
| B21 | `x-surface` is written inside `allOf` members, `if`s and `then`s, so RJSF's merge fails and falls back unmerged (all six rooms offered, the paid ticket empty, `send_day` filled where refused). | medium | json-schema-tooling | `src/Contract/ContractEmitter.php:1349`, `:1933`, `:1767-1768` | No `x-surface` below an applicator: the frame lists its rule indexes and the slot property maps each value to its def. Wire. | M |
| B22 | Strict-mode type gaps: a narrowing writes `maximum` or `properties` without `type`, which ajv's `strictTypes` refuses (6 of 12 live full schemas) and type generators read as an object. | low | json-schema-tooling | `src/Contract/ContractEmitter.php` narrowing output, e.g. `#/allOf/3/then/properties/capacity` | Write the declared `type` beside any type-specific keyword in a narrowing (it is the declared type, so it cannot narrow). | S |
| B23 | `format: "email"` is stricter than Drupal's validator for a format-asserting client (ajv-formats refuses `jörg@example.com`). | low | json-schema-tooling | emitter's email mapping | Emit `idn-email`, which RFC 6531 admits. Wire. | S |
| B24 | The docs table contradicts itself on `runtime`: it says "never in the full schema" while the full-schema section and the markers table say it is, and step 3's full schema carries it. | low | ai-agents | `docs/served-contract.md:303` against `:81`, `:165` | Correct `:303`. | S |
| B25 | The contract diff fails open: a mistyped `--dir` passes ("0 breaking", exit 0); `--module` with `--write` would unlink the other modules' baselines in a shared directory; emission runs as uid 1. | high (CI) | deterministic-callers | `src/Contract/ContractBaselines.php:179`; `src/Drush/Commands/ContractDiffCommand.php:122-133`, `:89` | A missing baseline fails in compare mode, each baseline records its module and `--write` touches only those, and the account comes from `--uid`. | S |
| B26 | A child permission whose `%key` cannot be filled says nothing (fail-open by construction, not exploited today). | low | security | `src/ResourceBuild/Resources.php:1660-1662` | Refuse outside a static listing, as neutral is refused at the top. | S |
| B27 | Constraint messages are bare literals potx never extracts; `patternMessage` is emitted untranslated; the tool respells `{{ max }}` as `%max`, a second locale source. | low | multilingual | `modules/data_surface_examples_compliance/src/Shape/Licence.php:25`; `src/Contract/ContractEmitter.php:2917-2919`; `modules/data_surface_tool/src/InputDefinitions.php:1102-1103` | Accept `t()` for a message and unwrap it at build; emit `patternMessage` through `TranslatableMarkup`. | M |
| B28 | Small stale facts: the tool README says an unknown subject is "refused without saying why"; `docs/evolution.md:212` says nine baseline files where there are 19; `docs/entities.md:215` says "the language it loads in" for what is always the default translation; the stale select reads "- Select -" with an asterisk and no `required` attribute. | low | ai-agents, deterministic-callers, multilingual, form-api | `modules/data_surface_tool/README.md:297-299`; `docs/evolution.md:212`; `docs/entities.md:215`; `src/Plugin/DataSurfaceWidget/OptionsWidget.php:139-152` | Correct each; the stale select's wording is a decision (section 3.5). | S |

**Recommended order.**

1. **B18** (silent, persisted, one line) and **B25** (a CI gate that can delete files).
2. **B1 + B2** as one change: an outbound projection at every door, which is also `security`'s verdict-changer. Then **B3**, **B26**, **B4**, **B5**.
3. **B11**, **B13**, **B14**: small, and each makes an agent's first correction right.
4. **B19**, **B20**, **B24**: one-line or near it.
5. **B6 + B7 + B9**, then **B10** and **B27** together (both touch how a refusal is worded), then **B8** with its `dist/` rebuild.
6. **B12** (needs `changes`, which section 2c lists).
7. **B15**, **B16**, **B17**.
8. **B21 + B22 + B23** last, batched into the one contract version that also carries the wire renames of section 2a, after `ContractDiff` learns the move.

## 2. Decisions, ranked by value against cost

### 2a. Renames

Merged from `naming` and the twelve others, de-duplicated. "Crosses?"
is against [the product keeps its name](../decisions.md#the-product-keeps-its-name)
(`docs/decisions.md:69`); a wire change that does not cross still waits
for a contract version, by the precedent at `docs/decisions.md:150-151`.

| Current | Proposed | Who (n) | Strongest for | Strongest against | Crosses? | Cost | Rec |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `BlueprintAdditionsInterface::with()` | `mixIn()`, `with()` deprecated alias | laravel, creative-generalist, naming (3) | `with*` is the copy prefix on `OperationContext`; the engine already says `Resources::mixIn()` | smithy: `with` is Smithy's IDL word | No | S | do |
| `#[Variant(of:, key:, value:)]` | `#[Variant(of:, slot:, for:)]` | naming (1; laravel and creative-generalist name the same misreading from the parent's side) | `key: 'ticket', value: 'free'` reads "the ticket is free" | breaks every variant class at discovery (loudly) | No | S | do |
| `attachBy()` | `slot()` | laravel, creative-generalist (2) | the glossary word is Slot | naming: the `attach*` family is coherent and the misreading is the child's | No | S | do not (do the `#[Variant]` rename instead) |
| Slot, the concept word | union | drupal-architecture (1) | SDC's `slots:` mean markup to a Drupal reader | the engine, docs and wire (`by`, `variants`) all say slot | No | M | do not |
| `HasOutputsInterface`, `HasStorageLayoutInterface`, `SettingsLayoutInterface` (and `EncryptedSettingsLayout`, `AddressSettingsLayout`) | `DefinesOutputsInterface`, `AltersStorageLayoutInterface`, `StorageLayoutInterface` (and siblings) | naming (1) | one `Defines*`/`Alters*` family; the glossary's word is Layout | adopters implementing them break | No | M | do, before an outside adopter |
| `addDefinition()` returns the blueprint | returns the definition | naming, php-symfony, creative-generalist (3) | every other declaring verb returns what it declared | callers chaining on the blueprint | No | S | do, with `addList()` |
| `Licence`, "Event licence", "Harbour Centre" (class, prose) | `License`, "Event license", "Harbour Center" | laravel, drupal-architecture, naming, deterministic-callers, multilingual (5) | Drupal writes US English; a British source is a near-duplicate for translators | 627 lines in 53 files, mostly prose | No | M | do now |
| `event.licence` (`$defs` id), `licence` (stored key) | `event.license`, `license` | same 5 | as above | stored config and a `$defs` id: a key removed plus a key added (laravel, multilingual: leave it) | wire, examples only | M | do later, in the contract version |
| `ResourceCatalogue`, `data_surface.resource_catalogue`, `docs/catalogue.md` | `ResourceCatalog`, `data_surface.resource_catalog`, `docs/catalog.md` | laravel, drupal-architecture, naming (3) | US English | a service id moves | No (prefix stays) | S | do |
| "labelled", "honour" (prose) | "labeled", "honor" | naming, multilingual (2) | US English | none | No | S | do |
| `Pipeline\Omitted` / `AccessAnswer::omitted()` (one word, two meanings) | `NotEmitted::value()` (naming) or `AccessAnswer::hidden()` (security) | naming, security (2) | "omitted" means two things | `omitted` is an access wire value; renaming the PHP side only would split name and value | No | S | do `NotEmitted` |
| `ResourcesInterface::buildStatic()` | `buildForAccountAlone()` (performance) or `buildForAccount()` (security) | security, performance (2) | "static" is already static refiners and methods; docs say "for the account alone" | none given | No | S | do `buildForAccountAlone()` |
| `OperationContext::withStarting()`, `$starting` | `withDefaults()`, `$defaults` | naming (1) | `docs/decisions.md:409`: starting values are defaults | none given | No | S | do |
| `#[Alters(resource:)]` | `#[Alters(of:)]` (php-symfony) or `$owner` (naming) | php-symfony, naming (2) | it takes structures and shapes too | almost always positional | No | S | do `of:`, matching `#[Variant(of:)]` |
| `Rule::describe()` | `Rule::because()` | laravel (1) | collides with the blueprint's `describe()`, another signature | none given | No | S | do |
| `Rule::require()`, `requires()`, `rulesOut()` | `setRequired()`, `isRequired()`, `isRuledOut()` | php-symfony (1) | `require` is a language construct; core's getter is `isRequired()` | churn | No | S | do |
| `#[DerivesResources(overrides:)]`, `KeyedList::add($id)` | `overriddenBy:`, `add($key)` | naming (1) | reads backwards; the blueprint calls it `$key` | none | No | S | do |
| `DoesNotApply::here()`, `Omitted::value()` | enum cases `::Here`, `::Value` | php-symfony (1) | a guaranteed singleton; helpers leave the author's type | naming keeps `here()`: it reads as the answer it is | No | S | do later, keep `here()` |
| `AccessAnswer::answer(): string`, `kindOf()`, `OperationDefinition::kind()` | `getAnswer()` (naming) or backed enums (php-symfony) | naming, php-symfony (2) | one getter style; exhaustive matches | callers comparing to literals | No | S | do later, with enums |
| `DefinitionMetadata::setSecret()` | `setWriteOnly()` | security (1) | matches the emitted `writeOnly` | the concept row is "Secret" | No | S | do later |
| `DataSurfaceAccess::decisive()` | `refuseNeutral()` | security (1) | says the property at the call site | none | No | S | do later |
| Six pipeline storages in `Storage\`; `ConfigEntityStorage`, `ContentEntityStorage` | move to `Pipeline\Storage\`; `*ResourceStorage` | naming, drupal-architecture (2) | `#[Resource(storage: StateStorage::class)]` looks right and is refused; core short-name clash | adopters' imports | No | M | do, together |
| `src/Resource`, `src/Structure`, `src/Shape`, `src/Alter` | `src/DataSurface/{...}` | drupal-architecture (1) | generic directories other modules use (canvas, mcp_server) | every module's layout moves | No (adds the word) | M | do not; the discovery guard and prefilter (2b) fix the risk |
| `x-surface.operation.readOnly` | drop (`kind: read` says it) | naming, json-schema-tooling (2) | JSON Schema's `readOnly` at the same root means locked whole | MCP's word; keep `#[Operation(readOnly:)]` | No; wire | S | do, in the contract version |
| `x-surface.enumerated`, `declared`, `dynamic`, `runtime`; `access: "runtime"` | see section 3.7 | ai-agents, json-schema-tooling (2) | see 3.7 | see 3.7 | No; wire | M | see 3.7 |
| `checkedOnServer` (plugin ids) | `{constraint, message}` items | ai-agents (1) | ids tell a model nothing | wire | No; wire | S | do later |
| `x-surface.operation.kind: update` | `patch` or `put` | deterministic-callers, smithy | see section 3.8 | see 3.8 | No; wire | S | see 3.8 |
| `notes` | `not_taken` | deterministic-callers (1) | a note sounds ignorable | a code on each entry (2c) says it better | No; wire | S | do not; do codes |
| `@access` path | path `""` with code `access` or `locked` | deterministic-callers (1) | no property has that path | wire | No; wire | S | do, with codes |
| Output `committed` labeled "Written" | label "Committed" | deterministic-callers, ai-agents (2) | name and label disagree | none | No | S | do |
| Three tools titled "Configure registration"; read tool "..., read only" | distinct titles; "Current values: ..." | ai-agents, deterministic-callers (2) | agents and ECA's picker rank by title | example labels only | No | S | do |
| contract-diff `--dir` | `--baselines`, `--dir` kept | deterministic-callers (1) | says what it holds | none | No | S | do |
| `surface`, `situation` contract keys | `resource`, `operation` | ai-agents, smithy, naming, json-schema-tooling (4) | the old words | already decided and deferred (`docs/decisions.md:150-151`) | Yes | M | do later: the one contract version, with `$id` |
| Cosmetic layer, `_data_surface_cosmetics` | Form display, `_data_surface_display` | form-api (1) | Drupal's word for arranging a form | a route key listed in the product-name entry; every adopter's routing changes | No (keeps the word), but a listed key | S | do not, unless 3.4 lands |
| `_data_surface_operation_access: 'TRUE'` | implied by `_data_surface_resource`, still accepted | drupal-architecture (1) | the value says nothing | none | No | S | do |
| `PlainTextMarkup` | `TranslatablePlainText` | multilingual (1) | says what stays true | none | No | S | do, with B7 |
| `Venues::VENUES` const | `Venues::venues()` with `t()` | multilingual (1) | a const cannot hold `t()` | none | No | S | do |
| `ContractEmitter::emit()` | `emitForAnswers()` | smithy (1) | "the schema for these answers" is used 33 times | churn | No | S | do later |
| `childrenMayAnswer()`, `ENUMERATION_CAP`, `ToolDefinitionsInvalidator::remember()` | `anyVariantAnswersAccess()`, `ENUMERATION_CAP_PER_KEY`, `rememberTags()` | performance (1) | each name hides a cost | none | No | S | do |
| `#[UsesStructure]` with no argument | also accept `self::class` | creative-generalist (1) | reads as a missing argument | two spellings | No | S | do later |
| `#[Resource]` | keep | php-symfony (1) | core's attributes carry no `As` | soft-reserved word | No | none | keep |

### 2b. Behavior changes

| What | Who (n) | Strongest for | Strongest against | Cost | Rec |
| --- | --- | --- | --- | --- | --- |
| Stamp the operation id from the attribute; `OperationContext`'s id optional | laravel, php-symfony, creative-generalist, smithy, drupal-architecture (5) | the id is written twice and checked only when asked (`src/ResourceBuild/Resources.php:1963-1971`) | none given | S | do |
| `#[Operation]` on the class, repeatable, meaning the bare context | laravel, php-symfony, creative-generalist, smithy (4) | 38 of 68 operation methods restate their attribute | `docs/decisions.md:838`: two spellings documented twice; the reviewers answer that the class form carries no code | S | do |
| `creates:` on `#[Operation]`, read at discovery, the context's answer checked against it | php-symfony, smithy (2) | the catalogue and the tool bridge stop guessing (`src/ResourceBuild/ResourceCatalogue.php:203-213`, `namesOneThing()`) | reverses `docs/decisions.md:2402` | S | do |
| A violation code on every refusal, one result shape `{path, code, message}` at every door, also passed to the Tool API's failure channel | deterministic-callers, ai-agents (2; multilingual and form-api on the same refusal) | scripts and agents branch without matching translated text that moves with alters and language | codes join what `docs/evolution.md#what-is-promised` promises; `notes` map becomes a list | M | do |
| One violation per line, path vocabulary in the tool's refusal | ai-agents (1) | the listing names keys by path, the refusal by label | the form keeps label wording | S | do, tool only |
| A required key added with no default is breaking | smithy (1) | an old client's next write is refused, the same reason "optional made required" is breaking | `docs/decisions.md:3251-3265`: nothing said before changes meaning | S | do (section 3.2) |
| Seed the blueprint from config schema | drupal-architecture (1) | one declaration, core's; core config (`node.type`) restated looser | laravel, creative-generalist: generate from PHP, check | M | do later (section 3.1) |
| Child permission that cannot be named is a refusal outside a listing | security (1) | closes the fail-open (B26) | none | S | do (with B26) |
| Tighten `addDefinition()` to `DataDefinition`; document refiners' first parameter as `DataDefinition` | php-symfony, creative-generalist (2) | removes the `instanceof` guard that fails silently un-narrowed | an adopter passing a `FieldConfig` | S | do |
| `values` a required, non-null object (`{}` allowed) | ai-agents (1) | top-level `null` means nothing while nested `null` clears | the Tool API's reading; breaks `dry_run`-only calls | S | do later (section 3.6) |
| Discovery: missing-dependency guard and text prefilter in `ResourceCollectorPass` | drupal-architecture (1) | `class_exists()` on every file in `canvas/src/Resource` can stop the container | none | S | do |
| Discovery through a `DefaultPluginManager` that never instantiates | drupal-architecture (1) | core's features (alter hook, derivers, file cache) for free | internal rewrite, deriver authors rename | L | do later |
| Per-derivative discovery entries with narrow tags; read the plugin hosts into the index, not the cid | performance (1) | one 256 KB item today, about 7.5 MB at 120 bundles, cleared by any field save | a missed tag leaves a definition stale | M | do, before entity plus tool on a large site |
| Memoize the neutral build per derivation; cache tool definitions per resource; `lock` around re-derivation | performance (1) | 3 to 4 builds per operation; every field save rebuilds every tool, unlocked | none | S (memo), M (rest) | do memo now, rest later |
| Memoize refined surfaces per emit; compute "a variant may refuse" at discovery | performance (1) | 20 of 55 refines repeat on `field.instance`; one access-answering variant makes every `access()` build | none | S | do |
| A request-scoped memo of sealed builds | performance (1) | three builds per tool call | amends [A build is not cached](../decisions.md#a-build-is-not-cached) | M | do later |
| A locked-whole form drawn as a record (read-only inputs, reason once) | form-api (1) | disabled controls are skipped by Tab | `LockedAccessFormTest` asserts `disabled` | M | do |
| Union tags on the variants: `#[Variant(label:)]`, the deciding key's list derived when none is declared | creative-generalist (1; form-api names the raw `free`/`paid`) | a declared list quietly closes an "always open" slot; options render as machine values | contracts' titles move (compatible) | S | do |
| `narrow($key, when:)` and `ruleOut()` | php-symfony (1) | the condition is required by the signature | every declared rule changes | M | do later |
| A refiner may return a `Narrow` effect in the rule's vocabulary | creative-generalist (1) | one tightening vocabulary; no restated bounds or casts | a new class and dispatcher branch | M | do later |
| `identity:` for the address, `fixed:` for create-only keys | smithy (1) | `field_type` as identity misleads every reader of identity | breaks the field resources' tools | M | do later |
| Derive idempotence for a caller-named create (`put`) | smithy (1) | `node.type:add` is idempotent by the module's own definition | `idempotent:` can already be declared (`docs/decisions.md:1962-1983`) | S | do not; declare `idempotent: TRUE` on `node.type:add` |
| Stop matching a child's operation by the parent's id | smithy (1) | renaming a parent's `edit` silently moves children | content entity loops must name each child | M | do later |
| Read tool returns what a dry run with nothing sent accepts | deterministic-callers (1) | one meaning of `values` | ai-agents: a dry run fills defaults, the read must not | S | do not (B12 fixes the round trip) |
| `entity.config` deriver; `ConfigEntityStorage` a resource storage | drupal-architecture (1) | config entities are the most core-shaped resources | `data_surface:node.type:add` becomes `data_surface:entity.config:node_type:add` | M | do later |
| Core's entity form stays the entity form door | drupal-architecture (1) | CKEditor, timezones, multi-value, contrib alters | `/surface-entity` pages go | M | do later, when the entity side resumes |
| Bundle-agnostic field item derivatives | performance (1) | halves the definitions at 100 bundles | changes [the id shape](../decisions.md#the-id-shape) | M | do not now |
| Always-written `locked: false` and `dependsOn: []` only when they say something | json-schema-tooling (1) | about 12% of a full schema's bytes | the React type requires both | S | do, in the contract version |
| Say `access: "runtime"` once at the top when it is the resource's own | ai-agents (1) | example 10 repeats it on every key | wire | S | do later |
| `ConfigObjectStorage` named on `#[Resource]` (`storage: new ConfigObject(...)`) | php-symfony (1) | the first resource loses a file | laravel, creative-generalist and `docs/decisions.md:1350-1358` keep the subclass | S | do not |
| Remove `ResourceInterface` (empty) | php-symfony, creative-generalist (2) | the attribute decides the kind | `StructureInterface` must stay; churn | S | do later, deprecated |
| `version:` cut until migrations exist | drupal-architecture (1) | its only legal value is 1 | the stamp and the contract diff read it | S | do not |
| An access class answers core's `update` and `view`; `AccessAnswer` internal | drupal-architecture (1) | no third result type to learn | [Access has three answers](../decisions.md#access-has-three-answers) | L | do not now |
| `#[Shape]` as a config schema type name | drupal-architecture (1) | core already names constrained scalars | shapes take alters, ports and `$defs`; a schema type does not | M | do not |

### 2c. Additions

"Budget" asks whether the item adds a row to the inventory in
`docs/concepts.md`, and whether it changes the first resource's four
(Resource, Operation, Storage, `defineInputs()` and `add()`).

| What | Who (n) | Strongest for | Strongest against | Budget | Cost | Rec |
| --- | --- | --- | --- | --- | --- | --- |
| The listing states each declared rule in prose ("When frequency is weekly: up to 5"), plus pattern, lock and does-not-apply sentences | ai-agents (1) | a model learns the ceiling only by being refused; prose validates nothing, so it cannot be stricter | listing strings in `ToolFeedbackTest` change | no row; four unchanged | M | do |
| An optional `description:` on `#[Operation]` (docblock summary as default), spent on the tool description | ai-agents (1; deterministic-callers on identical titles) | 38 of 65 tools share one 32-word sentence | none | no row; four unchanged | S | do |
| `addList($key, $item, $label, default:)` | laravel, creative-generalist (2) | the commonest settings key falls to core constructors, nine times | none | no row (a list is a key); four unchanged | S | do |
| `Rule::unless()`, and the emitter writing `c` for `not(not(c))` | laravel (1) | the recipe every toggle copies is a double negative | none | no row; four unchanged | S | do |
| `changes` on `DataSurfaceResult` and as a tool output (a dry run as a plan) | deterministic-callers (1) | CI asserts "no drift" as an empty plan | none | no row | S | do, with B12 |
| `ResourcesInterface::submit()` with decisive access, and `ResourceTestTrait` (`submitOperation`, `assertRefused`, `assertContractHolds`) | deterministic-callers (1) | eight test helpers assemble the call three ways, some skipping access | one public method | no row; a docs section | M | do |
| A config schema generator with a drift check (`data_surface:config-schema` or `:scaffold`) | laravel, creative-generalist (2) | the first page declares each key twice and two defaults three times | drupal-architecture: seed the other way (3.1) | no row; first page gains "generate, then commit", four unchanged | M | do |
| `mount()` for an alter's own mount | php-symfony, creative-generalist (2) | the module string is typed twice, and the build refuses any other value | none | no row (Mounted keys); four unchanged | S | do |
| `LabeledChoice` takes `enum:` (a class using core's `OptionsEnumTrait`) | laravel (1) | two arrays for an integer enum | none | no row (Options); four unchanged | S | do |
| `message` on a rule's `Range`, `Length`, `Count` | laravel (1) | a rule's sentence is core's plus the condition | none | no row | S | do |
| Instantiate declared constraints once at build to catch option typos | php-symfony (1) | `['maxx' => 5]` fails only at use | build time | no row | S | do |
| PHPStan rule against literal labels and descriptions | multilingual (1) | examples teach untranslatable strings | none | no row | S | do |
| Read core's `#[JsonSchema]` on data types | drupal-architecture (1) | `duration_iso8601` and contrib types emit `{}` | none | no row | S | do |
| `$id` and a root description ("the accepted document, not a request body") | json-schema-tooling (1) | an embedded schema's `$ref`s resolve against the wrong root | none | no row | S | do, in the contract version |
| `anyOf` of a slot's variants beside the conditionals | json-schema-tooling (1) | type generators write `{} | null` for a ticket | `ContractDiff` must learn it narrows nothing | no row | S | do, in the contract version |
| `title` on every def (its id) | json-schema-tooling (1) | generated type names stable and language-free | none | no row | S | do |
| A published `x-surface` metaschema; strict ajv over every baseline in Vitest | json-schema-tooling (1) | would have caught B20, B21, B22 | none | no row | S | do |
| Operation examples (Smithy `@examples`) run through both documents and the pipeline | ai-agents (1) | a worked call is the best first-call aid; never-stricter becomes tested | none | no row | M | do later |
| Contract diff `--format=json`; checks ECA config naming a tool against today's listing | deterministic-callers (1) | CI reads JSON; a renamed key fails before the model does | none | no row | S, M | do JSON now, ECA later |
| Fingerprint in the main module, from the read tool, optional on the write tool | deterministic-callers (1) | compare-and-set for two cron jobs, still opt-in | none | no row; Pipeline row grows | M | do later |
| Route from the operation (`#[Route]` beside `#[Operation]`, or `path:`) | drupal-architecture, creative-generalist (2) | tools are generated, routes typed by hand; only `node.page` has a route | two spellings of a route | no row; first page loses its routing file, four unchanged | M | do later, with core's `#[Route]` |
| Declared arrangement on the definition (group, `after:`, placeholder, prefix, suffix) under the renderer opt-in | form-api (1) | reaches blocks, formatters and React; placeholders stop being the first example | drupal-architecture: cut the layer instead (3.4) | adds a row ("display hints"); four unchanged | M | do later |
| `x-surface.translatable` from `label`/`text` schema types | multilingual (1) | config translation's signal stated | none | no row | S | do later |
| `x-surface.exact: false` at the root | json-schema-tooling (1) | a generic tool decides once whether "valid" is final | a fifth marker until 3.7 settles | no row | S | do later, with 3.7 |
| An OpenAPI 3.1 document per operation | json-schema-tooling (1) | names request body against accepted document | another endpoint | no row | M | do later |
| `#[Autowire]` parameters on runtime refiners, refused on static ones | php-symfony (1) | purity enforced by signature, not `assert()` | changes the runtime refiner row's text | no new row | M | do later |
| Deprecation metadata on a key | creative-generalist (1; `ROADMAP.md:333`) | the missing compatible step before removal | a new idea under Version | adds to the Version row | M | do later |
| Read tool output carries the full schema | ai-agents (1) | an MCP agent cannot reach either document | output size | no row | S | do later |
| Performance budget tests (builds per call, per derivation) | performance (1) | deterministic, would have caught the triple builds | none | no row | S | do |
| A readonly reader class generated per resource | creative-generalist (1) | `->get('max_recipients')` by string | codegen to maintain | adds a concept | M | do not now |

### 2d. Documentation

| What | Who (n) | Strongest for | Against | Cost | Rec |
| --- | --- | --- | --- | --- | --- |
| Say both documents describe the accepted document, and that an update sends a patch (top of "Two documents", and per door) | ai-agents, smithy, json-schema-tooling, deterministic-callers (4) | every reader of `required` takes it as "must send" | none | S | do |
| One optionality table: declared rows by door columns | smithy (1) | five settled entries, each found by tripping | none | S | do |
| Name JSON Merge Patch, with its three deviations | deterministic-callers (1) | scripts compute the minimal patch with a library | none | S | do |
| Glossary: add Variant, Owner, Child, Mount; fold Contributor; fix the strays of "shape" and "site"; an "engine words" list apart | naming, creative-generalist (2) | the glossary's promise of one sense each | none | S | do |
| "Shape" as the named scalar only, the umbrella sense to decisions | creative-generalist, smithy, naming (3) | code names only the third kind | [A shape is a named definition](../decisions.md#a-shape-is-a-named-definition) chose the umbrella | S | do |
| Mixin: "not flattened, unlike Smithy's"; Symfony `When`/`Compound` mapping | smithy, php-symfony (2) | teaches two concepts for free | none | S | do |
| "Coming from `#states`" in `docs/forms.md` | form-api (1) | blur timing, dropped unsaved input | none | S | do |
| "On a multilingual site" in `docs/storage.md`; "the default translation" in `docs/entities.md` | multilingual (1) | raw versus overridden reads were learned from code | none | S | do |
| Examples made translatable (`Venues`, the closed-room sentence, `langcode: en` in `ticket_types.yml`, the title as `label`) | multilingual (1) | the reason in `docs/decisions.md:2748-2750` is gone | none | S | do |
| "Testing your resource", naming the agreement traits | deterministic-callers (1) | adopters do not know they exist | none | S | do, with the trait |
| "Reading it from an agent" in `docs/served-contract.md` | ai-agents (1) | the page names agents as readers | none | S | do |
| `zend.assertions=-1` in `docs/installation.md` | performance (1) | PHP's built-in default runs the guards in production | none | S | do |
| `setRequired()` on a list means present; "at least one" is a `Count` | laravel, form-api (2) | site builders read Form API's non-empty | `docs/recipes.md:168-175` already warns | S | do, in the concept and widget pages |
| Namespaces table shows aliases beside core's `ResourceInterface` and `#[Condition]` | naming (1) | IDE imports pick the wrong one | none | S | do |
| Page titles aligned with nav; nav "Forms" split into "Forms" and "Contracts" | naming (1) | four pages carry two titles | none | S | do |
| Fold concept rows (does-not-apply, runtime refiner, context options) | laravel, creative-generalist (2) | 41 rows to 37; none of the four moves | each row answers a separate question a reader asks | S | do later, after class-level operations |
| The rollback and idempotence hazards of stored-value migrations (guard steps on shape; a `migrate(migrate(x))` helper) | deterministic-callers (1; drupal-architecture prefers post-update hooks first) | a lost stamp re-runs a step | migrations are designed, not built | S | do, in `docs/evolution.md` |

### 2e. Out of scope for the module

Read-only against `/Users/mlander/www/drupal_7_13/issue-*.md`; a draft is
named where one already covers the point.

| Issue | Project | Raised by | Existing draft |
| --- | --- | --- | --- |
| A refiner's definition has no setters on the interface | core | php-symfony, creative-generalist | "DataDefinitionInterface has no setter contract, so anything that narrows a definition must depend on the DataDefinition class" |
| Conditional rules cannot reach the Tool API listing as keywords | core | ai-agents | "Let a complex data definition declare conditions, so a property can be narrowed or required by a sibling's value and JSON Schema can emit it"; "Let a data definition declare which sibling values it is refined by, so JSON Schema can emit the dependency as a conditional" |
| Constraints to JSON Schema keywords; `#[JsonSchema]` on data types | core | drupal-architecture | "The JSON Schema normalizer should translate validation constraints into schema keywords" |
| `LabeledChoice` with an enum source | core | laravel | "Allow Choice constraints to carry option labels, so allowed values are declared once for validation, config schema, Form API and JSON Schema" |
| Defaults and examples on definitions (seeding, `examples:` beside `add()`) | core | drupal-architecture, smithy | "Allow data definitions to declare a default value"; "Allow data definitions to declare example values" |
| PCRE `u` and `D` in the Tool API's conversion | Tool API | json-schema-tooling | "convertPcreToEcma() refuses the `D` and `u` modifiers and silently drops `i`, `m` and `s`" |
| A `%key` permission declared to the Tool API | Tool API | security | "Let a declared tool permission carry a `%input` placeholder, so input-gated tools are not listed as \"Permission: None\"" |
| Idempotence per tool (a caller-named create) | Tool API | smithy | "Let a tool declare idempotence; today it is a property of the operation kind alone" |
| `t()` in static context for constraint messages | coding standards | multilingual | "Coding standards: the global t() should be the sanctioned spelling in static and declarative context, not a smell" |
| The normalizer ignores a lock below the top level | Tool API | ai-agents | none; file |
| `tool:run` answers pre-execution refusals as `error`/`error_type`, execution refusals as `message` | Tool API | deterministic-callers | none; file |
| The bridge wraps descriptions in `@text` (quotes escaped), casts them again, and normalizes from one instance at its discovery (one account, one language) | MCP bridge | ai-agents, multilingual | none; file (the *Open* note at `docs/decisions.md:2162-2164` names it) |
| Form API growth: `#required` as a hint, opting an element out of its type's validation, stable wrapper ids, an error that does not cancel a rebuild, `#limit_validation_errors` that keeps other values, error ids in `aria-describedby`, an `#empty_option` that means keep | core | form-api | none; file as one meta issue |
| Uninstalling strips `third_party_settings.<module>` from config entities, losing a migration stamp | core (design note) | deterministic-callers | none; a design note for migrations, not an issue |

## 3. Disagreements

**3.1 Config schema seeded into the blueprint, or generated from it.**
drupal-architecture: seed the blueprint from config schema through typed
config, `add()` may only narrow a seeded key, because core is moving
rules into schema and recipes, config actions and the schema checker
read schema when the pipeline is not in the loop. laravel and
creative-generalist: generate the schema from the class and check drift
("write once, then check", the protoc and buf model), because the class
is the declaration the README promises and generation changes no
behavior. php-symfony holds facts to "a place the compiler or phpstan
can see" but proposed neither. Recommendation: ship the generator and
drift check now (no behavior change, S to M), and keep seeding for core's
own config (`node.type`) as a later decision.

**3.2 A required key added with no default: note or breaking.** smithy:
breaking, as smithy-diff's `AddedRequiredMember`; an old client's next
write is refused, the same reason "optional made required" is breaking
(`docs/evolution.md:77`). The decision (`docs/decisions.md:3251-3265`):
compatible with a note, since nothing stored or sent before changes
meaning and the pipeline asks for the key in its own words. The
decision's reason is about stored values; smithy's is about requests,
which `docs/evolution.md:45-47` defines compatibility by. Recommendation:
smithy's rating; an allowance with a reason remains the escape.

**3.3 `with()` kept or renamed `mixIn()`.** smithy: keep, it is Smithy's
IDL spelling (`structure Journal with [Timestamps]`). laravel,
creative-generalist, naming: rename, because in PHP `with*` is the
immutable-copy prefix the author already meets on `OperationContext`,
and the engine and the React app already say `mixIn`. Recommendation:
rename; smithy's own advice to say "not Smithy's mixin" in the glossary
weakens the Smithy argument.

**3.4 The cosmetic layer cut, or arrangement declared.**
drupal-architecture: cut it into `hook_form_FORM_ID_alter()`, since the
form id is stable and the "nothing changes a value's meaning" promise is
kept by extraction and the pipeline anyway. form-api: the opposite
direction, declare grouping, order and placeholders on the definition
under the renderer opt-in (as `setSetting('widget', ...)` already is),
so blocks, formatters and React get them, and shrink the layer to the
message, the redirect and an escape hatch. Recommendation: do neither
now; form-api's direction is the one that reaches every host, but adds a
concept row.

**3.5 Radios, checkboxes and the empty option.** form-api: a stale
select should read "- Keep @value (no longer offered) -" (posting `''`,
not a sentinel), and a required list's marker should follow a `Count`,
never `setRequired()`. The decisions: [the empty option rule](../decisions.md#the-empty-option-rule)
rejected naming the stale value because the old placeholder posted a
sentinel; [a widget is chosen from the definition](../decisions.md#a-widget-is-chosen-from-the-definition)
says a list is never `#required` because the empty list is a value.
laravel agrees `setRequired()` on a list means present and keeps it.
smithy reports the adopters hit "Optional grows an empty choice".
Recommendation: form-api's wording only (it posts `''`, so the rule's
reason does not apply), and the marker rule as docs.

**3.6 `values` optional and nullable, or a required object.** ai-agents:
required, `NotNull`, `{}` allowed, because a top-level `null` does
nothing while a nested `null` clears, and a call with no `values` wrote
a default. The current reading is the Tool API's (`ResourceOperationTool.php:188`,
`$values[VALUES] ?? []`), and the update door requires nothing
([Required is the resource's](../decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
Recommendation: fix B12 first, which removes the write; then decide.

**3.7 One `narrowing` keyword or the four markers.** ai-agents: replace
`enumerated`, `declared`, `dynamic`, `runtime` with
`narrowing: ["stated" | "refine" | "live"]`, and `access: "runtime"`
with `"onCall"`, because each name misleads and a client needs one
answer. json-schema-tooling: keep `enumerated` and `declared` apart,
since the app evaluates them differently ([A rule declared as data](../decisions.md#a-rule-declared-as-data),
`docs/decisions.md:779-783`), merge only the two `runtime` spellings
(`runtime: ["refiner", "access"]`), and add one root `exact: false` for
generic tools. Recommendation: json-schema-tooling's, in the contract
version.

**3.8 `kind: update`, `patch`, or `put`.** deterministic-callers:
`patch`, because scripts read "update" as replace and the semantics are
a merge. smithy: keep `update`, and add `put` for a create whose
identifier the caller names (idempotent). ai-agents: keep `kind`, add
`"sends": "patch"` on an update. Recommendation: keep `update`, document
it as merge patch (2d), and declare `idempotent: TRUE` on caller-named
creates rather than adding a kind.

**3.9 Smaller ones.** `Omitted` or `AccessAnswer::omitted()` renamed
(naming against security; 2a). `#[Alters(of:)]` or `$owner` (php-symfony
against naming). Whether the read tool returns stored or accepted values
(deterministic-callers against ai-agents; 2b). Whether `prepared` is
removed (security) or kept inside one result shape (deterministic-callers
lists it): security's evidence decides it. Whether
`x-surface.access: "runtime"` is kept (security) or renamed (ai-agents).

## 4. Convergence

| Finding | Count | Reviewers |
| --- | --- | --- |
| An operation's id and facts belong on the attribute; a bare operation needs no method | 5 | laravel, php-symfony, creative-generalist, smithy, drupal-architecture |
| US spelling: `Licence` and friends | 5 | laravel, drupal-architecture, naming, deterministic-callers, multilingual |
| A refusal should be structured, field-named and not glued from fragments | 4 | deterministic-callers, ai-agents, multilingual, form-api |
| The documents describe the accepted document, not the request; say so | 4 | ai-agents, smithy, json-schema-tooling, deterministic-callers |
| The slot's authoring names read wrong (`attachBy`, `#[Variant(key:, value:)]`, "slot") | 4 | laravel, creative-generalist, naming, drupal-architecture |
| A list is declared through core constructors and `addDefinition()` returns the wrong thing | 4 | laravel, creative-generalist, naming, php-symfony |
| `surface` and `situation` stay deferred to a contract version | 4 | ai-agents, smithy, naming, json-schema-tooling |
| What a door returns (`prepared`, `notes`, `stale`) is undeclared or unprojected | 3 | security, ai-agents, deterministic-callers |
| A config settings key is declared twice (schema and class) | 3 | laravel, creative-generalist, drupal-architecture |
| `with()` should be `mixIn()` | 3 | laravel, creative-generalist, naming |

Also at three: "shape" in one sense (creative-generalist, smithy,
naming), `Catalogue` spelling (laravel, drupal-architecture, naming),
never-stricter tested by a real client engine or generated samples
(json-schema-tooling, deterministic-callers, ai-agents). At two: the
Access failure category, routes from operations, the `buildStatic()`
name, the refiner's parameter type, the empty kind interfaces, `mount()`,
the four markers, `readOnly` used twice, variant labels, `setRequired()`
on a list, folding concept rows, identical tool titles, a listing cached
for one account or one language.

## 5. The three things that would change the most verdicts

1. **One answer at every door, projected and coded** (three verdicts).
   security: "a single outbound projection applied to every document any
   door returns". deterministic-callers: "a stable `code` on every
   violation, carried in one declared result shape at every door".
   ai-agents: "the stated rules in the listing, together with the fix to
   the misleading license refusal". Items B1, B2, B11, B14 and the codes
   in 2b.
2. **A config key declared once** (three verdicts, direction disputed,
   3.1). laravel: "once the schema and the install file are generated
   from the class". creative-generalist: "config schema emitted and
   checked from the resource". drupal-architecture: seeding from schema,
   "from 'adopt with care' to 'propose for core'".
3. **An operation's facts on the attribute** (two verdicts, five
   reviewers ranking it). php-symfony: "an operation that states its
   facts on the attribute". smithy: "Declaring `creates` on
   `#[Operation]`", with the required-key rating of 3.2.

Single verdict-changers: form-api, arrangement declared once (3.4);
naming, its renames 1 to 4 before an outside adopter; performance,
per-derivative discovery entries and per-resource tool caching;
multilingual, the seven casts and the `PlainTextMarkup` decorator (B6,
B7); json-schema-tooling, B21 with a strict ajv compile in CI.

## 6. What not to do

| Proposal | Rule it would breach | Reason |
| --- | --- | --- |
| Rename `surface`, `situation`, `x-surface`, tool ids or route keys outside a contract version (including `_data_surface_cosmetics` and the `event.licence` def id) | product name | `docs/decisions.md:69-90`: a product or wire name breaks every site, client and tool that reads it; `:150-151` defers the two keys |
| Closures in rules (`requiredIf(fn ...)`) | static declarations; never stricter | laravel's own "not borrowed": a rule only PHP can read cannot be stated, so the documents drift |
| Cast stored values to enums | narrowing | laravel's own "not borrowed": an alter's `extendChoices()` value would be a fatal ("an enum is not a fence", `docs/options.md:118-125`) |
| Smithy's member-trait precedence (a key widening its shape) | narrowing | smithy's own "not": additions at a key narrow only (`docs/decisions.md:222-230`) |
| An OpenAPI `discriminator` on a slot | never stricter | `docs/decisions.md:2781`; json-schema-tooling's `anyOf` union is acceptable because it is wider than any `then` |
| A custom `$vocabulary` dialect for `x-surface` | product name; tooling | json-schema-tooling's own "not": ajv refuses the document until the dialect is added |
| Runtime derivation of config schema from the class | static declarations | creative-generalist: write once, then check, never derive at runtime |
| Seeding from schema that adds a constraint the pipeline does not enforce before every door answers | never stricter | drupal-architecture's change is safe only because `prepare()` checks the whole object on every write and dry run |
| A stale option that posts a sentinel | the empty option rule | form-api's wording fix must keep posting `''` |
| Fold the read tool into a dry run | concept budget; MCP hints | ai-agents' own "not cut": a dry run fills defaults and is not `readOnlyHint` |
| Drop `enumerated` and `declared` with nothing in their place | never stricter, in the app | the app evaluates them differently (`docs/decisions.md:779-783`) |
| A preview of `prepared` "projected" instead of removed | omitted is omitted both ways | security: storage's own layouts make a correct projection hard; drop it |
| A cache of builds across requests keyed by context | [A build is not cached](../decisions.md#a-build-is-not-cached) | performance's request-scoped memo is acceptable only keyed with cacheability and skipping max-age 0 |
| Display hints, `fixed:`, routes or a schema generator made part of the first page's concepts | concept budget | each may exist, but the first resource stays at four; display hints and `fixed:` add rows and must not reach that page |
| `AccessAnswer` made internal behind core's two questions | concept budget; three answers | changes an inventory row and [Access has three answers](../decisions.md#access-has-three-answers) |
| `version:` removed | evolution | the stamp and the contract diff read it; re-adding it later is a breaking signature change |
| Shared field item derivatives without amending the id shape | wire ids | [The id shape](../decisions.md#the-id-shape) |

## 7. Citations checked

I checked these against the code at be03a68 myself:

1. `ResourceOperationTool.php:208-210` serves `$storage->preview($result->prepared)` as `prepared` on a dry run: checks out.
2. `DataSurfacePipeline.php:653-660`, `acceptSecret()` returns `$fallback` when nothing is sent: checks out.
3. `InputDefinitions.php:215`, `t('Depends on @keys.')`, and `:216` casts the description: checks out.
4. `ResultReportingTrait.php:42`, `implode(' ', $lines)`: checks out.
5. `ResourcePlugins.php:92`, `$this->used = []` before the loop, `own` assigned only after it: checks out.
6. `DataSurfaceOptions.php:96-98`, `:117` (memo keyed by `spl_object_id()`, holding the definition) and the docblock at `:37`: checks out.
7. `ResourceOperationToolDeriver.php:166`, `(string) $operation->label`: checks out.
8. `Clause.php:51` strips `/\.\s*$/u`; `DataSurfacePipeline.php:874`, `:878`, `:922-929` glue `t('and')`, `'@message when @conditions.'` and bare `t('empty')`, `t('on')`: checks out.
9. `PatternTranslation.php:167` carries `d`, `w`, `s`, `b` unconditionally: checks out.
10. `docs/served-contract.md:303` says "never in the full schema" while `:81` puts `x-surface.runtime: true` in it: checks out.
11. `ContractDiff.php:579-583` rates a required key added with no default `NOTE`: checks out.
12. `OperationInputs.php:520-538` declares only `values`, `committed`, `prepared`: checks out.
13. `ConfigObjectStorage.php:153-163` always calls `save()`; `:130-133` throws without schema: checks out.
14. `ReferenceableOptions.php:107-109` adds only `user.permissions`: checks out.
15. `ContractEmitter.php:1349` and `:1933` write `x-surface` into a branch and a slot's `then`: checks out.
16. `DataSurfaceFormBuilder.php:1654-1659` passes `$violation->message` unchanged; `:2154-2163` writes the constant "Fixed for this operation.": checks out.
17. `ServedOperationAccessCheck.php:71-81` resolves the operation first and returns `$e->getMessage()` as the reason: checks out.
18. `ResourceCatalogue.php:203-213`, `Variant.php:29-33`, `DataSurfaceResult.php:80-82`, `ResourceReactPage.php:96-97`, `Operation.php:35` (`TARGET_METHOD` only), `DataSurface.php:639`: check out.

**Did not check out:** php-symfony's change 1 cites
`modules/data_surface_first/src/Resource/NewsletterResource.php:140-149`;
that file has 51 lines, and the quoted code is at `:18-27` (the range
140-149 matches `docs/first-resource.md`, where the same code is quoted).
The finding stands; the citation is to the wrong file. The operation
counts differ by scope across reviews (laravel and php-symfony 38,
creative-generalist 27, smithy 12 of 14) and were not reconciled.
