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
   with one typed, ordered, immutable `DefinitionMap` of `SurfaceEntry`
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
   target-level decorator for targets that use no settings shape
   (state, config, plugin configuration currently store a secret in
   the clear, documented honestly in docs/targets.md); builder
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

Prerequisite, second half: **complete the per-operation triple on the
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

Prerequisite, third half: **the method becomes the one home; the
attribute stops declaring.** The static-harvest claim the attribute was
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

4. **Emission as a plugin type.** Per-constraint schema normalizers,
   ordered, matched by constraint (by `instanceof`, not plugin id,
   which also resolves the Tool API normalizer thread), so third
   parties teach the emitter about their constraints. Output: JSON
   Schema 2020-12; labeled options as `oneOf` const/title; refinement
   edges as `dependentSchemas`/`if-then` where declared, honestly
   marked runtime-narrowed where an object refiner is in play.
5. **Generate the config schema from the surface.** The hand-written
   `block.settings.*` and formatter-settings YAML becomes an emitted
   artifact via config schema discovery, deleting the parallel
   declaration every critique named first among Drupalisms.
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
9. **A closed vocabulary of dependent effects** (the Laravel negative
   result made policy). Declared edge effects on the refinement map:
   narrow-choices-to, required-when, excluded-when, locked-when, as
   equality-on-dependency data (`required_if` shaped). Serializable,
   emitted, and evaluated by the pipeline, so required-or-not and
   presence conditions reach schema consumers, not just option lists.
10. **Relevance shapes the payload, not just the form.** A false
    relevance predicate means the key contributes nothing to prepare
    and commit and stored values stand (never pruned: the no-silent-
    loss rule holds against both Laravel's `exclude_unless` and the
    clean rooms' `when`-pruning, which we reject).
11. **Stale-value policy per refinement edge** (Filament's child reset
    made data): reset-to-default versus keep-and-violate, applied by
    the pipeline between accept and validate, so the form rebuild and
    an agent round trip resolve a stale dependent identically. Also:
    stale references become a distinct violation class so hosts can
    degrade instead of refusing opaquely.
12. **Wildcard segments in the D6 path grammar.** D6's dotted paths
    gain a `*` list-item segment, expanded against the actual payload
    at refinement time, so errors and refiners stay index-addressed.

## Phase D: evolution

13. **Surface versioning with pure stored-value migrations** (decided;
    refined). A version on the attribute; pure shape-to-shape
    transforms shipped with the definition; the pipeline's accept
    stage converts stored values forward on read. Borrowed discipline: only
    up-transforms are required (down is a restore), and a
    per-destination ledger stamps the applied version at commit so
    environments converge in recorded order.
14. **Additive-diff lint.** Emit each surface's schema against a
    committed snapshot in CI; fail on removed keys, tightened
    constraints, or type changes without a version bump. The
    registry's publish-time diff, as a test any module can run.
15. **Deprecation as definition metadata.** `deprecated` and sunset
    keys through `DefinitionMetadata`; widgets flag dying options; a
    report enumerates stored usages before removal.

## Phase E: structural, carried forward

16. **D6: dotted refinement paths and `mount()`**, so a contributor
    can refine the key it mounted and nested surfaces compose. The
    settled syntax plus the wildcard segment from item 12.
17. **Presets**: named whole-configuration value sets on the
    attribute, validated through the pipeline at build, surfaced as
    placement starting points and reused as test fixtures.
18. **Derived host cache metadata**: the base classes merge the
    surface's cacheability union into the host's contexts and tags
    automatically, closing the last hand-authored cache seam.
19. **Storage settings for field types** with the has-data lock (safe
    now that locked keys keep stored values), and the storage-settings
    tool bridge.
20. **Contributor dependencies**: `PluginConfigurationTarget` reads
    `getDefinitions()->contributions()` into `calculateDependencies()` so
    uninstalling a contributor cleans up its keys.
21. **Retire `config_surface`** once the functional suite runs in CI.

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
