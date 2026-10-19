# data_surface roadmap: after the hardening pass

This consolidates three sources into one ordered plan: the open threads
HARDENING.md left behind, the clean-room design review (four blind
designs from the headless CMS, declarative operations, schema-first
API, and frontend component-model traditions, each critiqued against
this module), and the Laravel review (framework and ecosystem, mapped
to borrow / already-have / reject). PLAN.md, ADOPTION.md, and
HARDENING.md are the history; this is what comes next.

The single strongest finding of both reviews: the input half of this
module is already the pattern every tradition converges on. Constraints
as introspectable data carrying labeled options, a declared dependency
graph, one write pipeline for every caller, and checked narrow-only
extension were each independently reinvented by all four clean rooms,
and Laravel's documented failure mode (narrowing leaking into closures
no emitter can see) is the standing argument for how we did it. What
the reviews add is the other half of the contract: output, access,
serving, and evolution.

## Phase A: the contract completes

0. **The definitions map** — DONE 2026-09-15. (groundwork; done first, while the interfaces
   are still ours to reshape). Replace the surface's parallel arrays
   (definitions, refinements, refiner chains, locked, contributions)
   with one typed, ordered, immutable `DefinitionMap` of `Entry`
   value objects, each entry carrying its definition plus contributor,
   locked state, and dependency edges. Validated once at construction;
   iterator and array-access compatible so call sites keep working;
   built from the attribute's plain arrays at the factory boundary, so
   authoring syntax is unchanged. Later phases hang methods on it:
   dotted-path lookup (D6), narrowing intersect, additive diff (item
   14), by-contributor filtering (item 20). Sibling in the same change:
   a typed violation set replacing the name-to-list violations array,
   modeled on core's entity constraint violation list. Boundary rule,
   stated once: contracts are objects, payloads are arrays; the values
   flowing through accept, validate, prepare, and commit stay plain
   arrays forever. The output surface (item 2) is then born on the same
   map type instead of converted later.

1. **Access on the contract** — DONE 2026-09-15. The method landed as
   `surfaceAccess()` because core action and block interfaces already
   own `access()` with incompatible signatures; the pipeline takes a
   resolved `AccessResultInterface` on `submit()`; refusals land under
   the reserved `@access` violation key; `DataSurfaceAccess::decisive()`
   is the idiom for a provider that owns an operation, since core
   permission and entity checks answer neutral on denial. (The headline
   net-new borrow, from Form
   Request `authorize()` plus policies). `DataSurfaceProviderInterface`
   gains an access answer per operation and account, returning core's
   `AccessResult` (which carries reason and cacheability, exceeding the
   Laravel original). `DataSurfacePipeline::submit()` consults it
   before `accept()`; the future endpoint consults the same answer.
   Hosts that spell access today (the action base, the tools) delegate
   to it. Form, Drush, config action, agent: one resolution.
2. **The output surface** — DONE 2026-09-15. Landed as a second
   definitions map on the surface with `refineOutputs()` against input
   values, the `Omitted` sentinel, strict-typed `conformOutput()`, the
   formatter split (`formatValue()` with byte-identical rendering), and
   the tool converter. The tool bridge consumer landed as the converter
   plus a recorded limitation: the Tool API has no output-definition
   refiners, a candidate tool issue alongside the instanceof one.
   (Decided earlier; every clean-room critique
   ranked its absence the largest gap). A second definitions group
   typing what a host's execution emits: provided-context in core
   terms. Shares the definition vocabulary (types, labels,
   descriptions, examples); differs where outputs differ: no defaults,
   no locking, refinement runs against the *input* values (a dry run
   emits a prepared artifact and no committed flag). Includes the
   **Omitted sentinel** (from JSON resources' MissingValue): a gated or
   irrelevant output field is absent, never NULL, and the presence
   condition is declared data so the emitted schema states it.
   First consumers: the tool bridge (converted to Tool API output
   definitions) and a `formatValue()` split on the formatter base so
   the data step is testable and reusable before any render array.
3. **Secrets at the codec** — DONE 2026-09-15, with riders. Landed:
   the `secret` flag, the sodium codec service (HKDF from the site
   hash salt; swappable, and the salt dependence is a deployment
   caveat: salt rotation makes stored secrets unreadable, and
   settings.php readers can decrypt), the encrypting shape decorator,
   password rendering that never echoes, keep-on-empty semantics with
   the `CLEAR_SECRET` marker (a documented exception to D3), and the
   write-only note in the tool conversion. Riders left open: a
   storage-level decorator for storage classes that use no settings shape
   (state, config, plugin configuration currently store a secret in
   the clear, documented honestly in docs/storage.md); builder
   `secret()` sugar; a guard against secret plus Choice rendering as a
   visible select. Original spec: a `secret` flag on `DefinitionMetadata`
   plus an encrypting `SettingsShapeInterface` decorator, so plaintext
   never reaches storage from any caller; emission redacts or omits
   flagged keys (`writeOnly` in JSON Schema terms), and locked keys
   emit `readOnly`.

## Phase B: the served contract

The clean rooms' second structural finding: our contract is rebuilt
in-process per request and readable only from booted PHP; theirs is a
served document. Drupal-shaped version:

Prerequisite, before the endpoint's coordinates freeze: **split
operation from subject on the provider contract.** `operation` becomes
a closed verb from the host type's vocabulary and never carries
identity; `subject` is an optional opaque string id the provider
resolves itself, NULL when the provider is its own subject. Both
`getDataSurface()` and `surfaceAccess()` take the pair; typed entry
points such as the node type provider's entity-taking method remain
the in-process convenience; the edit-prefixed id encoding in the node
type provider is deleted. This is the wire shape the discovery route
and the dry-run endpoint address surfaces by: host type, host id,
operation, subject.

Prerequisite, second half — DONE 2026-09-15. The triple is complete
(`getDataSurfaceStorage()` on both provider contracts, the field-side
accessor renamed to match), `DataSurfaceProviderForm` serves provider
forms from route coordinates with a three-method cosmetics seam, and
the node type demo's form class is gone, not shrunk: routing plus a
small cosmetics class. The standalone State demo form deliberately
stays hand-written as the documented case of a surface paired with a
target nobody owns. Original spec: **complete the per-operation triple on the
provider contract.** Target acquisition currently has three spellings:
plugin hosts wrap themselves implicitly, the field contract has an
accessor, the node type provider invented a bespoke method. The
provider contract gains a target accessor for a given operation and
subject, so surface, access, and target form the complete triple the
endpoint resolves from coordinates; without it the endpoint could
serve plugins but not standalone providers. Payoff: a generic provider
form controller, route names the provider service and operation with
the subject upcast from the route, so standalone providers stop
hand-writing form classes and the node type form shrinks to routing
plus its cosmetic layer, which is the part that should stay bespoke.

Prerequisite, third half — DONE 2026-09-15. The attribute is deleted;
`declareDataSurface(builder)` is the one home, static so the formatter
defaults protocol reads the same declaration the instance path builds
from, with no container and no plugin constructed out of season; the
awareness service slimmed to interface detection for the future
catalogue; nine classes converted with semantic parity proven by the
suite and the byte-identical comparison document. Original spec: **the
method becomes the one home; the attribute stops declaring.** The static-harvest claim the attribute was
built on has shrunk to detection, which the interface already provides,
and the pre-contribution sketch it holds is a lie of omission next to
what the factory builds; meanwhile its costs are real: the
array-constructor-only spelling, no map property definitions (the
address before-seal callback exists only for this), no translatable
constants, the interim metadata keys, and the one-home rule itself.
`definitions`, `outputs`, `refinements`, and `locked` leave the
attribute; every surface is declared in `getDataSurface()` through the
builder, with a protected builder helper on the host bases keeping
simple plugins compact; the attribute is deleted (the interface is the
marker) unless a future index needs it for category-style metadata.
Plugins and standalone providers then author identically, and the
subject-capturing refiner pattern is the same everywhere. Tooling that
wanted containerless indexing uses the discovery document instead,
which serves the real contract.

4. **Emission as a plugin type.** — DELIVERED 2026-10-08 as the main
   module's emitter, `data_surface.contract_emitter`: JSON Schema
   2020-12, labeled options as `oneOf` const/title, each slot as `if`/
   `then` on its deciding sibling, dependency edges and locking under
   `x-surface`, widget hints only when a renderer asks
   (`docs/served-contract.md`). Still open: the constraints it knows
   are a list in the emitter, not yet a plugin type. Original spec:
   per-constraint schema normalizers, ordered, matched by constraint
   (by `instanceof`, not plugin id, which also resolves the Tool API
   normalizer thread), so third
   parties teach the emitter about their constraints. Output: JSON
   Schema 2020-12; labeled options as `oneOf` const/title; refinement
   edges as `dependentSchemas`/`if-then` where declared, honestly
   marked runtime-narrowed where an object refiner is in play.
5. **Generate the config schema from the shape.** The hand-written
   `block.settings.*` and formatter-settings YAML becomes an emitted
   artifact via config schema discovery, deleting the parallel
   declaration every critique named first among Drupalisms.
   Backbone, added 2026-09-17 after the owner named the gap: **the
   coordinate space is declared, not free.** A provider declares its
   operations, and per operation its subject space as a data
   definition carrying an existence constraint, so the option-resolver
   machinery enumerates every valid coordinate with labels and
   cacheability for free; a subject space with no resolvable
   constraint is marked honestly non-enumerable. Host bases default to
   one configure operation with no subject. Access has two tiers here, following core's own repeated pattern of
   subject-less twins (create access beside entity access, field
   access with null items, permissions beside grants): calling
   `surfaceAccess($operation, NULL)` on a subject-taking operation
   answers the GENERAL question, may this account use this operation
   for any subject, and is never a missing-subject refusal; the
   concrete subject answer is the runtime tier the endpoint re-asks.
   Staticness is not a second result type: it is the answer's own
   cacheability, a permissions-context result is the static tier the
   catalogue may cache per permissions hash, a subject-tagged or
   max-age-zero result is runtime. The node type provider's current
   missing-subject refusal on edit changes to the general answer when
   this lands. The catalogue enumerates
   coordinates first; the endpoint validates coordinates against the
   declaration before dispatching. The rule underneath, stated once:
   nothing may vary a surface except a declared coordinate or a
   surface value; no third argument, ever; anything else that would
   vary the definitions is a new operation, a subject, or a key inside
   the surface.

6. **The discovery document.** One route serving every surface-aware
   plugin's contract (definitions, defaults, options, refinement
   graph, access verbs, output schema), cheap because the attribute is
   harvestable and the factory is the one assembly point. Regenerated
   only from commit-side cache invalidation, never from a rehearsal.
   Rider on discovery: **the demo block declares its outputs.** Its
   output is a headline and a list of labeled lines, no fetching
   involved, so it splits a pure resolve step from `build()` the way
   the formatter split `formatValue()`, and `build()` assembles the
   render array from it. This proves the output vocabulary on a
   composite shape and makes the block's discovery entry meaningful to
   a headless consumer. The general block output story stays gated on
   declared data and context dependencies, which is the one clean-room
   convergence not yet built; interactive blocks stay out of scope.

   Discoverability, folded into discovery after the human-search
   review: standalone providers get a **service tag** carrying host
   type and id, and a **catalogue service** unions tagged providers
   with surface-aware plugin definitions to answer "all surfaces on
   this site" with provider class, operations, and file. The discovery
   document serves from the catalogue, so standalone providers stop
   being invisible to enumeration. On top of it, a **list tool**
   through the bridge (drush is broken on this site) giving a human
   the thirty-second answer: every surface, where it lives, who
   provides it, which kind. And a **form debug annotation**: when a
   debug setting is on, generated forms carry an HTML comment naming
   the host id and provider class, the Twig-debug move, bridging a
   misbehaving page to the right file with no convention knowledge.
   Naming rules (SurfaceProvider suffix, swap adopters named and
   cross-referenced from their hook docblock) land in the docs and the
   repository CLAUDE.md.

7. **The dry-run and validate endpoint, Precognition-shaped.** The
   *same* pipeline entry with a stage-selection flag, never a parallel
   route: full submit, dry run (stop after prepare), validate-only,
   and validate-only scoped to named keys for as-you-type checks.
   The response exceeds Precognition: alongside field-scoped
   violations it returns the re-refined definitions and narrowed
   option sets for every key whose dependencies the payload
   configured, which is exactly what the form's AJAX rebuild already
   computes. Partial payloads are already solved by the merge rule.
   The endpoint calls `submit()` with the provider's `surfaceAccess()`
   answer and merges the result's access cacheability into the
   response.

## Phase C: declarative narrowing

8. **Option domains by reference** (decided; refined by all reviews).
   A constraint spelling that names a registered options resolver with
   parameter bindings to sibling keys, so bundle-of-entity-type and
   field-of-bundle stop costing an imperative refiner per plugin. The
   object refiner remains the checked escape hatch, and the emitter
   reports it as runtime-narrowed.
   - **Options alters** (later concept, from the sketch): a class in
     `src/OptionsAlter/` changing one list of allowed values everywhere
     it is used, not per resource or structure.
   - **Remove-only policy** (later concept, beside options alters): a
     site hiding an owner's key, and an options alter removing an entry
     stored values use; both need the stale rule (item 11).
9. **A closed vocabulary of dependent effects** (the Laravel negative
   result made policy). Landed 2026-10-18 as declared rules (Next,
   item 3), all but locked-when. Declared edge effects on the refinement map:
   narrow-choices-to, required-when, excluded-when, locked-when, as
   equality-on-dependency data (`required_if` shaped). Serializable,
   emitted, and evaluated by the pipeline, so required-or-not and
   presence conditions reach schema consumers, not just option lists.
10. **Relevance shapes the payload, not just the form.** A false
    relevance predicate means the key contributes nothing to prepare
    and commit and stored values stand (never pruned: the no-silent-
    loss rule holds against both Laravel's `exclude_unless` and the
    clean rooms' `when`-pruning, which we reject). Landed 2026-10-18
    as a refiner's answer, "This key does not apply" (Next, item 2).
11. **Stale values: stash, warn, never reset** (decided 2026-09-16
    after the owner hit it live; supersedes the earlier
    reset-versus-keep policy menu, deleting the per-edge knob).
    Display never errors: a select whose stored value fell outside the
    refined set renders unselected with a placeholder naming the
    unavailable value; required selects included, and no forced
    defaults ever. Untouched means keep: the widget marks the element
    stale and extraction maps the untouched placeholder back to the
    stored value, so an unrelated save can never clear it (the trap:
    empty submissions otherwise mean clear). Stale is its own
    non-blocking violation class: a failing value that equals the
    stored one on a refined key warns and saves; a new out-of-set
    value stays a hard violation. Required splits: never-set is the
    ordinary hard violation, stale-required stashes and nags. Dry-run
    responses expose stale references as their own field so agents see
    re-choose distinctly from invalid. Semantics doc gains stale as a
    third state.
12. **Wildcard segments in the D6 path grammar.** D6's dotted paths
    gain a `*` list-item segment, expanded against the actual payload
    at refinement time, so errors and refiners stay index-addressed.

## Phase D: evolution

13. **Shape versioning with pure stored-value migrations** (decided;
    refined). A version on the attribute; pure shape-to-shape
    transforms shipped with the definition; the pipeline's accept
    stage converts stored values forward on read. Borrowed discipline: only
    up-transforms are required (down is a restore), and a
    per-destination ledger stamps the applied version at commit so
    environments converge in recorded order.
14. **Additive-diff lint.** Emit each resource's and structure's
    schema against a committed snapshot in CI; fail on removed keys,
    tightened constraints, or type changes without a version bump. The
    registry's publish-time diff, as a test any module can run.
15. **Deprecation as definition metadata.** `deprecated` and sunset
    keys through `DefinitionMetadata`; widgets flag dying options; a
    report enumerates stored usages before removal.

## Phase E: structural, carried forward

16. **D6: dotted refinement paths and `mount()`**, so a contributor
    can refine the key it mounted and nested surfaces compose. The
    settled syntax plus the wildcard segment from item 12. Amended
    2026-09-16 with the union story: mounts come in two kinds. Plain
    mounts, the third-party namespace case. And
    **discriminator-selected variant mounts**: a sibling key chooses
    which child surface occupies a branch, because refinement narrows
    values and structurally cannot swap shape; when a drill level
    changes which keys exist rather than which values are allowed, it
    is a union, spelled as a discriminator plus one self-contained
    child surface per variant, violations path-prefixed, the composite
    target routing the branch, emitted as JSON Schema oneOf. First
    target: plugin-selects-plugin, where the mounted child is the
    chosen plugin's own surface reused, never restated. Guardrails
    that keep deep drill-downs out of imperative code, restated here
    because mounts will tempt violations: chains of selects are
    declared option domains (item 8), presence is relevance (item 10)
    and never a refiner's job, and the imperative refiner stays the
    marked escape hatch for computed narrowing only. Refiner dispatch contract, pinned 2026-09-16 so nesting cannot
    break it: a refiner is always dispatched on the DECLARED edge
    spelling, never a concrete expanded path; wildcard expansion
    delivers the index through a separate refinement context argument;
    mounted child surfaces run their refiners re-keyed in their own
    local frame, so no parent ever matches into child paths. This
    keeps every refiner's name space flat and closed, which is what
    makes the match-dispatch house style sound at any depth.
    Standalone versus
    embedded declarations are a spectrum since the attribute retired:
    promote to a standalone surface class on reuse, captured subject,
    variant branches, third-party ownership, or a declaration
    outgrowing one screen.
17. **Presets**: named whole-configuration value sets on the
    attribute, validated through the pipeline at build, surfaced as
    placement starting points and reused as test fixtures.
18. **Derived host cache metadata**: the base classes merge the
    data surface's cacheability union into the host's contexts and tags
    automatically, closing the last hand-authored cache seam.
19. **Storage settings for field types** with the has-data lock (safe
    now that locked keys keep stored values), and the storage-settings
    tool bridge.
20. **Contributor dependencies**: `PluginConfigurationStorage` reads
    `getDefinitions()->contributions()` into `calculateDependencies()` so
    uninstalling a contributor cleans up its keys.
21. **Retire `config_surface`** once the functional suite runs in CI.

## After the rework

The concepts the pattern deferred, in the order they come
next. `docs/pattern.md` (Deferred) records the position each is held
to.

1. **Collections**: ~~`attachList()`, an ordered list whose items are a
   child structure, delta as position~~ DONE 2026-10-16 (`docs/resources.md`,
   Collections). Still open: `attachListBy()`, each item choosing its own
   child; an item routed to its child's own storage, its stable identity
   an identity key on the child and weights the storage's business; a
   collection inside an item on the form; content entity fields of more
   than one value, with the entity side.
2. **Input shapes**: another way to enter the same value, converted to
   one canonical value; the widget alter layer on the `variants` branch.
3. **Options**: options sources, the `OptionsList` constraint naming
   one, and options alters changing a list everywhere it is used;
   naming undecided.
4. **Remove-only policy**: a site hiding an owner's key, and an options
   alter removing an entry; already recorded under item 8.
5. **Per-method refinement gating**, if a case needs it: today a key's
   refiners wait for the union of what they watch.
6. **Partial updates of a collection item**: changing one item without
   resending the list, probably an operation on the child.
7. **Plugin hosts overlay a full submission**: a host supplies its
   subform's parents to `surfaceSubmissionPath()`, so a save without
   JavaScript that moves a parent and its dependent together is not
   refused by Form API (`docs/decisions.md`).
8. **Served contract, first shape** — DONE 2026-10-08: `data_surface_react`
   serves one operation as JSON Schema 2020-12 with `x-surface`, refine
   and validate (dry run) endpoints, and a React form rendered from it
   (`docs/served-contract.md`). Writes since: `/submit`, with `created`
   for an operation that creates and an opt-in stored-values fingerprint.

## Next, ranked (2026-10-18)

What makes the pattern better from here, in the order it pays off. The
developer-facing side on `next` (reusable subschemas, static and runtime
refinement with its enforcement, child operations with three-way access,
collections, the vocabulary) is complete as scoped; the entity side is
tabled until the user resumes it.

Priority, in milestones (2026-10-18). A before anything: a known bug
and an unverified invariant make everything above them suspect. B before
C: B changes what a schema can state, and C reads that schema. The
renames sit in A because they are cheapest before new code exists.

- **A, merge-ready:** the refinement leak; the never-stricter audit; the
  blueprint and storage layout renames (item 6); the docs reading pass
  and the leftover-words decision; then merge `next` to `main`, the React
  local resolution included.
- **A, from the first fresh-eyes run (2026-10-18, `docs/fresh-eyes-2026-10-18.md`),
  before the merge** — DONE 2026-10-18: `ConfigObjectStorage`, the pinned
  pages `docs/first-resource.md` and `docs/second-resource.md` on
  `data_surface_first`, `docs/concepts.md`, the guesses documented where
  the log asked, the two contradictions, the three bugs, and defaults
  out of the tool listing (item 9). As planned: ship a storage class for a simple config object
  (the most common case has none; the docs only say it was removed);
  a settings-page recipe at the top of the README, which becomes the
  pinned "Your first resource" page; document the permissions file and
  the config schema convention for keys another module mounts; fix the
  two contradictions (a page saying nothing emits a schema; two
  spellings of the operation label); two bugs: a `{{ max }}` placeholder
  printed literally in a refusal, and core's number element check
  overriding the rule's own message; and a slot variant with no keys
  must not crash the form (HTTP 500, "No data surface widget serves the
  map data type").
- **B, what the contract can say:** inapplicable keys (2, done); declarative
  narrowing (3, done); ports (4); mixins (5); named scalar shapes (7).
- **C, access and the doors:** static and runtime access (8); operation
  behavior facts and the tool door's refusal feedback (9).
- **D, edges as they bite:** item 10.
- **E, the entity side** when resumed: item 11.
- **F, confidence in the direction** (gates, not features; several run
  alongside B and C): item 13 below.
- **In parallel from now:** file the drafted core issues (item 12),
  now including one for conditional data definitions (a complex
  definition declares `when`/`then`/`required` conditions that only
  narrow; typed data validates them, the JSON Schema normalizer emits
  `if`/`then` and `dependentRequired`; the companion to the dependency
  metadata issue); the core conversation is slow and depends on nothing
  above.

1. **Make `next` trustworthy, then merge it to `main`.**
   - The refinement leak, DONE 2026-10-18 (bc0e1b1, core's typed data
     prototype cache keyed without the definition): one call to the registration tool changes the
     verdict of the next call in the same process (a capacity of 50 is
     refused after an 80, with the stewards rule from the 80). Separate
     requests are fine. Suspect the definition of an alter's mounted key
     being shared between builds. The one known bug on the branch.
   - The audit's fixes, DONE 2026-10-18 (e1abf5b, with one change to the
     rule below: on an optional key absent keeps, null or '' clears, a
     value replaces, and the schema keeps null in such a key's type; see
     "Absent keeps, null clears" in the decisions). Decided with the Tool API as the
     precedent (it coerces first by a documented table, validates after,
     and its matrix tests check the advertised schema against what the
     tool accepts post-coercion): the invariant's scope is the accepted
     document; `required` means present and not null and says nothing
     about size; on an optional key null, absent and '' all mean "the
     stored value or default stands", and the schema advertises such a
     key as optional without null in its type (so a stale key allowing
     null is the same rule, not a special case); a secret is optional,
     absent or null meaning keep what is stored, its type not nullable;
     the casting table is replaced by the Tool API's coercions, called
     where the module can and mirrored on the form door, which makes
     the "boolean stored as integer" finding an alignment rather than a
     bug; shipped regexes gain `D` and translation carries only a safe
     subset of pattern syntax, else omits `pattern`; a locked list's
     items carry no `const`; blank collection items have their keys
     checked before they are dropped; `format` stays, as an annotation
     per the spec, with the test validator configured so; the audit's
     one-off pins become matrix tests in the Tool API's shape (advertised
     schema acceptance, input coercion).
   - The "never stricter than the runtime" audit, DONE 2026-10-18 (3d43d8f,
     `docs/audit-never-stricter.md`), deferred for the demo:
     the form posts `"50"` as text where the schema says integer; the
     schema refuses unknown keys, and whether the pipeline does is
     unchecked; an untouched stale stored value is accepted while the
     full schema's list refuses it; a Regex translated from PCRE to ECMA
     may refuse what the original accepts. Each is a schema to widen or
     a runtime to tighten, decided case by case.
   - A reading pass over the docs and decisions after the rename, then
     the leftover old words in one decision, DONE 2026-10-18
     (`docs/decisions.md#the-product-keeps-its-name`): module name and
     namespaces, the `data_surface.` service prefix, route names and
     paths, the `x-surface` keyword, the `surface[...]` form key, the
     React app's internal names, the `DataSurface*` engine prefix keep
     the word; concept names in code took the new one. With it, three
     fresh-eyes entries: a derived tool declares its operation's
     permission (12), a refusal a refiner caused names the keys it
     depends on (15), and the README's "never contradicted" says what
     only the runtime settles (17).
2. **"This key does not apply"**: DONE 2026-10-18
   (`docs/decisions.md#a-key-that-does-not-apply`). Found by the
   fresh-eyes run: a send day that only applies when the frequency is
   weekly had no good spelling. A refiner now answers
   `DoesNotApply::here()`, declared in its return type, for a key, a
   mounted key, an attached child's key or a slot's key: the form draws
   no element, the pipeline reads it as left out and refuses any other
   value sent for it, naming what it depends on, storage keeps what it
   holds, the read tool and the schema for these answers leave it out,
   and the full schema states it `false` under the conditional that
   rules it out and its `required` conditionally (a `then` may carry
   `required` now, fresh-eyes entry 19); the React page narrows it from
   the full schema. A list narrowed to nothing is a select offering
   nothing, never a text field. Phase B item 10 (relevance shapes the
   payload) is the older note of the same idea, and its no-silent-loss
   rule is the one kept.
3. **Declarative narrowing** (Phase C): DONE 2026-10-18
   (`docs/decisions.md#a-rule-declared-as-data`). An author states a
   condition instead of coding it, for example "a licence is absent",
   and the emitter writes it as a conditional without executing
   anything. A blueprint's `narrow($key)->when(Condition::...)` takes a
   closed vocabulary of conditions (present, absent, equals, in, the four
   bounds, not, allOf, anyOf) and of effects (a Range, Length or Count
   bound, a subset of the key's list, a pattern, required, does not
   apply, a description), each with one exact JSON Schema spelling; the
   rule is a static refiner in the key's chain, after its contributor's
   coded ones, waiting for nothing, and only tightening. The full schema
   states each as written (`x-surface.declared`); the compliance capacity
   was the first case and is exact now, its stewards still `dynamic`.
   Left out: a conditional lock, and an alter's rule on values it
   offered with `extendChoices()`.
4. **Ports: a structure declares what it reads from its container.**
   The wall says a child never names a parent's key, and structures
   obey it; an alter may watch the owner's keys by name, and the
   compliance example does (its stewards rule watches `capacity`),
   which binds the reusable piece to one owner's key names. Instead a
   structure declares a port under a name of its own
   (`$inputs->reads('capacity', 'integer')`) and its refiners watch the
   port; the container wires each port to one of its keys when it
   attaches the structure (`attach('compliance', Compliance::class,
   reads: ['capacity' => 'seats'])`), whether the container is a
   resource or an alter. Consequences: an alter's blueprint must be able to
   `attach()` and `attachList()` under its mount, so an alter becomes
   an adapter that attaches a reusable structure and wires it; a
   port-dependent conditional is emitted at the site on the parent's
   `allOf`, never in the structure's def, which stays reusable; the
   child's `dependsOn` is translated to the parent's path at the site;
   identity handed through `withChild(known:)` is documented as a port
   of a fixed kind, a port being a live sibling value. Rewording an
   owner's label or tightening an owner's key directly stays an
   alter's own business, as glue for one owner.
   Reuse from another module follows: the reusing module attaches the
   structure and wires its ports; the structure brings its refiners,
   access class and operations and keeps its def id in the contract;
   storage is the container's (its config schema describes the keys, and
   it depends on the structure's module); alters on the structure apply
   wherever it is used, which a container may want to opt out of (to
   decide); a change to the structure changes every container's stored
   layout, which is Phase D with an explicit contract to diff.
5. **Mixins: a structure merged flat.** Today composition only nests
   (attach, with its frame and wall; an alter's mount under its module's
   name). A mixin, Smithy's word, merges a structure's members into the
   owner's own keys: declared by the owner only (`with: [Timestamps::class]`),
   never by an alter, since an alter's keys must live under its own name;
   stored flat and described by the owner's config schema; emitted as
   `allOf` of the mixin's `$ref` and the owner's properties with
   `unevaluatedProperties`, so the def stays reusable; its refiners run
   in the owner's frame but watch only the mixin's own members and
   ports; its access locks or omits its members. Nest for a thing of its
   own inside this one (a contact, a ticket); mix in for shared members
   that are part of this thing (timestamps, ownership, publishing
   flags), which is how an entity's base-field traits already compose
   and what the bundle resource will want. With ports, in B.
6. **Rename the builders to blueprints.** DONE 2026-10-18 (`BlueprintInterface`,
   `BlueprintAdditionsInterface`, `SettingsLayoutInterface`,
   `HasStorageLayoutInterface::storageLayout()`). Before named scalar shapes
   exist, `ShapeInterface` and `ShapeAdditionsInterface`, the builders
   passed to `defineInputs()`, `defineOutputs()` and `alterInputs()`,
   become `BlueprintInterface` and `BlueprintAdditionsInterface` (decided
   2026-10-18 over "members" and "builder": a blueprint is the thing you
   declare members into, as in a migration's table blueprint), so that a
   `#[Shape]` class and the thing you declare into never share a name.
   The members a blueprint holds keep Smithy's word in prose. Also
   `HasStorageShapeInterface::storageShape()` and `SettingsShapeInterface`,
   which describe how values are laid out in storage, take a name that
   says so (layout). Mechanical, with the snapshot.
7. **Named scalar shapes.** The one Smithy level the pattern lacks: a
   named simple shape with its traits, reused anywhere (`string Licence
   @pattern`). Today a scalar's constraints, message and description are
   written inline at each key, and core's answer, a data type plugin, is
   too heavy for a four-digit string with a label. A `#[Shape('event.licence')]`
   class whose static `define()` returns the data definition, used as
   `$inputs->add('licence', Licence::class)`: the contract emits it once
   as a def and references it; an alter can target the shape and so
   tighten every use on the site; a port can declare it as its type;
   and "shape" takes its Smithy place, resources, structures and named
   scalars all being shapes. Small, additive, after ports.
8. **Static and runtime access**, the same split as refinement. Static
   access is decidable from the account alone (an operation's
   permission, a role) and is stated in the full schema and the tool
   listing for the account asking. Runtime access depends on the
   subject or on values ("may edit this node", field permissions' "edit
   own") and is applied only once the subject is known. The kind is
   read from the answer's cacheability, which Drupal already requires
   to be honest: permission or role contexts only, no entity tags, no
   per-user context, means static. The full schema marks a key under
   runtime access (`x-surface.access: "runtime"`) as one that may be
   locked or omitted later. Access answers per key or structure;
   narrowing allowed values by account is a runtime refiner, not
   access.
9. **The tool door's feedback.** Keep the listing small, as intended.
   Name each dependency in the input's description. Make a refusal on a
   dependent key say what is allowed for the values sent ("room: not
   offered for Riverside Hall; choose Main hall or East room"), which
   means narrowing the `values` map from its own partial value in the
   tool bridge. One retry per dependent key is the target. Applies to
   ECA and MCP alike.
   Defaults in the listing (decided 2026-10-18, DONE 2026-10-18,
   `docs/decisions.md#required-is-the-resources-and-a-listing-carries-no-stored-value`): a tool listing is
   cached by clients and is a discovery channel, so it stops carrying
   current stored values as `default`. A create operation lists the
   declared defaults; an edit operation lists none and says in its
   description that a key left out keeps its current value and that the
   read tool returns current values. The schema for these answers keeps
   current values in `values` (per request, never cached) and the full
   schema keeps declared defaults. Updates stay a patch.
   Also the operation's behavior facts, which MCP reads as its tool
   annotations and the bridge already derives from the Tool API:
   `#[Operation]` gains `destructive` and `readOnly` (both false by
   default); idempotent is derived from `creates` unless declared; the
   tool deriver maps read-only to the `Read` kind and sets destructive;
   both schema documents carry the facts at the top beside the
   operation's id and label, so a client reading the contract directly
   has them; open-world is a later flag on the storage. Tool API gap to
   file: idempotence is a property of the operation kind, not the tool,
   so an edit cannot say it is idempotent while an add says it is not.
10. **Pattern edges**, each small: a read-only operation form for the
   top-level locked answer (today read as denied); a view-only slot
   variant (today not offered); `childrenMayAnswer()` skipping the
   build when no access class exists; a collection inside an item on
   the form; a secret inside a collection item hiding the whole list
   from the read tool; `attachListBy()`; an item routed to its child's
   own storage; the phpstan rule for static refiners; the "Right now"
   wording for a key that is both narrowed and under a runtime rule.
11. **The entity side, when resumed.** Fields as two kinds: a `field`
   resource (identity: entity and field name; a collection of items
   with Count from cardinality; field access; a `set` operation whose
   tool shares Tool Belt's `field_set_value` contract) and a
   `field.item` structure (no identity; entity, field name and delta
   from its container; keys derived at build from the known field).
   Unit 4's per-field-per-bundle derivatives collapse into these. Then
   multi-value fields through collections, the form display as the
   cosmetic layer (a single-value field drawn as one element, not a
   fieldset; timestamps and references drawn as such), address as the
   first override (country narrows subdivisions), path alias as the
   first alter with a storage of its own, and a placed block as a
   config entity resource whose settings are a slot keyed by plugin id
   with each plugin's structure as a variant.
12. **The core conversation**, bottom-up: the definition-level facts
   first (constraints to JSON Schema keywords, `refined_by` on
   definitions, a setter contract, labels on Choice; drafted), then
   operations and access for config objects framed as what entity types
   already have, then third-party settings for simple config, and Form
   API last. `docs/core-gaps.md` and the proposal document are to be
   rewritten in the new vocabulary to carry it.

13. **Confidence in the direction.** The two risks the design carries
    are weight and the untested half, so these are gates every later
    item is held to, with the evidence each produces.
    - **A concept budget, with a page that enforces it.** (The page and
      the inventory exist: `docs/first-resource.md`, pinned to
      `data_surface_first` by `FirstResourceDocsTest`, and
      `docs/concepts.md`.) A doc page,
      "Your first resource", that writes a working resource using only
      resource, operation, storage and `add()`, and nothing else, with a
      drift test that pins its code. Every item in B and C must leave
      that page unchanged; a concept that cannot stay off it has to
      justify itself. Beside it, a one-page concept inventory: each
      concept, the question that makes you need it, and the sentence to
      skip it. Smithy's recurring complaint is weight; this is the
      defense.
    - **Fresh eyes, twice.** First a reader with no context (an agent
      given only the docs and a task, none of this history) writes a
      resource with a structure, an alter and a runtime rule from the
      docs alone, and logs every place it guessed, searched or got it
      wrong; the log becomes doc fixes. Then the same exercise by a
      person. Repeat the agent run after every milestone; it is cheap.
    - **Two real adopters.** Port two existing contrib settings forms
      that we did not write (candidates: pathauto's settings, metatag's
      defaults, or another module's `ConfigFormBase` with options and a
      dependency between fields) as resources in a sandbox module, and
      record what the pattern could not express or expressed badly.
      Generality is only shown on forms designed without us in mind.
    - **The entity proof, with a definition of done.** Item 11 counts as
      done when one real bundle with a multi-value field, an entity
      reference, an image and a path alias is created and edited through
      the form, the React page, the tool and MCP, with field permissions
      honoured, and the "today" comparison beside it. Ports, mixins and
      collections all have to hold at once there; nothing else exercises
      them together.
    - **The audit stays green.** `NeverStricterAuditTest` pins the
      invariant; a breach decided and fixed changes a test on purpose,
      never silently. New doors and new kinds add cases to it.
    - **An evolution design before anyone depends on a resource.**
      Phase D, written after reading Smithy's defaults-and-model-evolution
      design: which changes to a resource are compatible, what a stored
      value migration looks like, how a contract diff is reported.
    - **The core story told once, by an outsider's standard.** Rewrite
      `docs/core-gaps.md` and the proposal in the new vocabulary, bottom
      up (definition facts, then operations and access for config, then
      third-party settings for simple config, Form API last), and have
      the fresh-eyes reader read that too.

## Rejected, on the record

So they are not re-proposed: imperative input-mutation hooks before
validation (breaks form/API parity and makes emission lie); open
middleware insertion between pipeline stages (a pipe between validate
and commit destroys every-caller parity; our extension doors are
deliberately narrower and typed); unchecked runtime rule attachment
(the emitter-invisible leak both reviews document as Laravel's central
failure); client-held signed contract state (re-derivation beats
tamper-proofing state that should not be client-held); a lenient
unknown-key mode per caller class; pruning stored values for hidden or
narrowed-away keys; and encoding widget or markup vocabulary anywhere
in the contract.

## Ordering and the core conversation

Item 0 before everything, since it reshapes the interfaces every
later item builds on. A before B (the endpoint consults access;
discovery serves the output schema). C can start alongside B. D needs B's emission for the diff
artifact. Phase A items 1 and 2 plus the endpoint in B are the pieces
that answer the issue's reviewers directly: they complete the claim
that one contract serves forms, APIs, and agents, in both directions,
with access resolved once. The essentials the clean rooms missed and
we keep are the defense against "why not just adopt a manifest
registry": translation, exportable staged config with dependency
cleanup, the concrete write gate, additive third-party settings,
site-builder context wiring, interactive blocks, and brownfield
coexistence.
