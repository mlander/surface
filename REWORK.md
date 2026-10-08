# Rework: applying the surface pattern to this module

Branch `surface-pattern`, off `main`. The target is the pattern in
`sketch/` (read `sketch/README.md` and `sketch/api/HOW-IT-FITS.md`
first). The sketch is the spelling authors see. The engine underneath
(pipeline, targets, narrowing, forms, widgets, tool bridge) stays and is
what the spelling gets wired to.

Rules for the whole rework:

- The `variants` branch is NOT merged. Subsurfaces are rebuilt here in
  the sketch's spelling. Input shapes (the widget layer) are a later
  concept and are not brought over.
- Options are a separate problem. The existing constraint option
  resolvers keep working; `OptionsList`, option sources and option
  alters from the sketch are not built in this rework.
- Intermediate steps need not leave everything working. Each step moves
  toward the sketch; the suite may carry known failures between steps,
  listed in CLAUDE.md, as long as nothing errors silently.
- Two spellings may coexist while consumers migrate. The old one is
  deleted in step 5, not before.
- Where a decision has to be made that the sketch does not address, it
  is flagged in the code at the point of decision, so it can be grepped
  and the sketch amended or the decision confirmed:

      // Sketch gap: <what the sketch did not say, and what was decided>

  Sparingly, for real gaps only. Each step's report lists the ones it
  wrote. (Step 6 settled all of them into `docs/decisions.md` and
  removed the comments; the marker is spelled here in lower case so a
  grep for it over the module comes back empty.)

## Sequence

1. **The facade.** The sketch's API in `Drupal\data_surface\Surface`:
   `SurfaceInterface`, `ShapeInterface`, `ShapeAdditionsInterface`,
   `HasOutputsInterface`, `SurfaceAlterInterface`,
   `AltersOutputsInterface`, `SurfaceContext`, `SurfaceTargetInterface`,
   `SurfaceAccessInterface`, and the attributes `Surface`, `Situation`,
   `RefinesInput`, `AltersSurface`, `SurfaceVariant`, `UsesSurface`.
   Discovery of `src/Surface` and `src/SurfaceAlter` as a compiler pass
   like `HookCollectorPass`. A build step that runs `defineInputs()`
   over an adapter on the existing builder, applies discovered alters,
   applies the context (identity locks, constraints, starting values,
   child contexts), binds `#[RefinesInput]` methods as the refiner, and
   seals through the existing factory. First consumer: the demo block
   through `#[UsesSurface]`. `attach()`/`attachBy()` are declared and
   throw "not yet" until step 2.

   **Done.** The API is in `src/Surface/`; the bridge in
   `src/SurfaceBuild/`: `SurfaceCollectorPass` (registered by
   `DataSurfaceServiceProvider`) lists the classes into a container
   parameter and registers alters, targets and access classes as autowired services;
   `SurfaceRegistry` (`data_surface.surface_registry`) reads their
   attributes into the discovery cache; `Surfaces`
   (`data_surface.surfaces`) is the build step. Seams: the owner's shape
   writes straight into `DataSurfaceBuilder`; an alter's keys are
   mounted under `third_party_settings.<module>` (and
   `third_party_outputs.<module>`), not beside the owner's; each class's
   `#[RefinesInput]` methods become one `RefinesInputRefiner` link in
   the owner's chain of each key, with `addRefinement()` edges, so the
   engine gates per key on the union of what its methods watch; a
   refiner watching nothing runs once at build; known identity becomes
   default plus `lock()`, starting values become defaults and are
   refused on a context that does not create; the build event still
   fires last, with the host class and id the caller passes, which is
   how old-spelling subscribers reach new-spelling surfaces. Deviations
   from the sketch: `build()` takes an optional host class and host id;
   `#[UsesSurface]` reaches the plugin definition through
   `SurfacePluginHooks` (block only); the demo needed a constraint and
   resolver of its own for a bundle's fields. Every undecided point is a
   sketch-gap comment in the code. The extras module had no demo
   block branch to convert, so `DemoBlockAlter` is new.
2. **Subsurfaces.** `attach()`, `attachBy()`, open slots filled by
   `#[SurfaceVariant]`, narrowing inside maps, targets composing along
   the tree. Consumers: the field tools' settings as a child resolved
   from the subject; the demo block's presentation slot.

   **Done.** The engine carries subsurfaces on the entry:
   `SurfaceAttachment` (a sealed child and the class it came from) and
   `SurfaceSlot` (the deciding key, a shell, a variant table of
   attachments), set by the builder's `attach()` / `attachBy()`.
   Seams: the shapes reserve a subsurface key as an empty map in
   declaration order and record the class; `Surfaces` builds each child
   after the parent's context and refiners, through the same build step
   (own shape, alters, refiners, build event) in
   `Surfaces::childContext()` — the context `withChild()` handed it, or
   the parent's operation, `creates` and known identity — and an open
   slot's children come from `SurfaceRegistry::getVariants()`.
   `attachBy()` adds a Choice over the variant ids to the deciding key,
   held to `Narrowing`, and a refinement edge from the slot to it, which
   is what the form's AJAX rebuild and discard cascade ride; a locked
   deciding key resolves the slot at seal. `DataSurface::refine()`
   refines each child in its own frame over its own defaults; the
   pipeline's `accept()` / `validate()` recurse level by level, refuse
   another variant's keys (`VariantMismatchException`, a path-aware
   violation), reset a stored value that does not fit a newly chosen
   variant to its defaults, and re-file child violations under the
   parent key; `Narrowing` reads inside maps and list items.
   `SurfaceTargetAdapter` composes targets along the tree (a routed
   child is committed after its parent, in its context plus the
   parent's accepted identity); `SurfaceInputDefinitions::fromSlot()`
   emits an unresolved slot as the widest honest map. The wall is
   enforced in `Surfaces::assertWalled()`. `describe()` (label and
   description only) landed on both shapes at the owner's request.
   Consumers: `DemoBlockSurface` gained `presentation` and a two-child
   slot (and the classic block the same keys); `FieldInstanceSurface`
   in `data_surface_tool` with `add` / `edit`, `FieldInstanceTarget`
   and `FieldInstanceAccess`; `AddressFieldSettingsSurface` (with
   `AddressFieldSettingsTarget`) fills its open slot, `SurfaceAddressItem`
   builds it for Field UI; the field tools build the field instance
   surface from their subject for a field type with a variant, and keep
   the old path (`FieldSurfaceLocator`) for the test module's
   `SurfaceGatedItem`, the one old-spelling field type left. Deviations:
   `SurfacesInterface::target()` takes the built surface optionally; an
   alter's `attach()` and output subsurfaces are refused for now; a
   routed child target is wired inside `SurfaceTargetAdapter` rather than
   through `CompositeTarget`, because a slot's route needs its sibling's
   value and a composite hands each child only its own keys; the
   registry skips an undiscovered class that carries `#[Surface]`
   (a test autoloader knows disabled modules). `DemoBlockAlter` now caps
   a grid instead of a list with summaries, since `show_summary` moved
   into the list child and an alter of the parent cannot see it.
3. **Situations end to end.** The node type provider becomes a surface
   with situations, a target and an access class. The generic provider
   form and the tool bridge are driven by situations: one route per
   situation, one tool per situation, inputs being the situation's
   parameters plus the surface's open keys.

   **Done.** `NodeTypeSurface` (`node.type`, identity `type`) in
   `data_surface_demo_node_type` with `add()` (creates; adds the
   uniqueness constraint with `withConstraint()`) and
   `edit(NodeTypeInterface $type)`, `NodeTypeTarget` (the node type
   entity, then base field overrides that moved) and `NodeTypeAccess`
   (the entity's create or update answer, after the situation's
   permission). The extras module's node type branch is
   `NodeTypeAlter`. Seams: discovery reads each situation's parameters
   (`SituationParameter`); `SituationArguments` maps a route's
   parameters or a tool's inputs onto them by name and loads an entity
   parameter given as an id, and `Surfaces::situation()` goes through
   it; `SituationRoute` reads `_data_surface_surface` /
   `_data_surface_situation` off a route for both
   `DataSurfaceProviderForm` (a second spelling beside the provider
   one) and the `_data_surface_situation_access` access check; an alter
   implementing `HasStorageShapeInterface` hands a storage shape for its
   own mount, which `SurfaceTargetAdapter` applies in load and prepare;
   the adapter commits an attached child before its parent and a slot
   variant after; `SurfaceTargetAdapter::outputs()` reads a surface's
   outputs back from its target. `data_surface_tool` derives
   `data_surface:<surface>:<situation>` for every situation of every
   surface with a target (`SurfaceSituationToolDeriver`,
   `SurfaceSituationTool`, `SituationInputs`): parameters as inputs, an
   entity one by id; `values` less the known identity keys, exact for a
   situation that needs nothing and refined to the real context through
   `input_definition_refiners` otherwise; `dry_run`. `FieldStorageSurface`
   with `FieldStorageTarget` is attached at `storage`; `reuse()` hands it
   `FieldStorageSurface::edit()`, which adds the has-data constraint; the
   field tools' add path uses `reuse`. `SurfaceCatalogue`
   (`data_surface.surface_catalogue`) and `docs/catalogue.md` via
   `scripts/generate-catalogue.php`. Deviations: `data_surface:node_type_add`
   is renamed, not aliased, and `NodeTypeAdd` and
   `SurfaceProviderToolBase` are deleted; `NodeTypeSurfaceProvider` and
   `NodeTypeAddTarget` stay, deprecated and unrouted, because the old
   spelling's docs and the form's provider spelling still use them; the
   edit route's parameter is `{type}` (was `{node_type}`), to match the
   situation's `$type`; a new-spelling target has no prepare step, so
   the node type config schema is no longer checked before a write (a
   dry run no longer previews the entity); a situation's form and tool
   read a neutral access answer as a refusal
   (`DataSurfaceAccess::decisive()`), since the situation owns its
   operation; the catalogue says whether a situation creates only when
   the situation needs nothing. Every undecided point is a
   sketch-gap comment.
4. **Plugins and remaining hosts.** Every demo plugin, the address field
   type, and the formatter, condition and action hosts move to
   `#[UsesSurface]` with their surface in `src/Surface`.

   **Done.** Targets prepare: `SurfaceTargetInterface` gained
   `prepare(SurfaceContext, array $values): array` (rehearse the write
   with every check storage makes, no side effects, return what would be
   stored) and `commit()` now takes what prepare returned.
   `SurfaceTargetAdapter::prepare()` runs the target's prepare over the
   storage-shaped values, then each routed child's in its context plus
   the parent's accepted identity, files a child's refusals under its key
   and throws them all at once as `TargetViolationsException`;
   `preview()` turns a prepared result into plain arrays (`own`,
   `children`), which a derived tool's dry run answers with as
   `prepared`. `NodeTypeTarget` builds the unsaved node type and each
   moving base field override and holds them to their config schema
   (`node.type.*` is fully validatable; for a config entity that is its
   entity validation too), storing an empty description or help as NULL
   as core's form does; `FieldInstanceTarget`, `FieldStorageTarget` and
   `AddressFieldSettingsTarget` likewise. Every plugin host reads
   `#[UsesSurface]`: `SurfacePluginHooks` alters block, formatter,
   condition, action and field type definitions (last, so a swapped class
   is read), `DataSurfaceHostTrait::hostedSurface()` builds the named
   surface for each base class and the field type trait,
   `SurfacesInterface::defaults()` answers the static defaults
   protocols, the field type host asks the surface's access class, and
   `DataSurfacePluginForm` serves a plugin that only names a surface.
   Migrated: the demo formatter (`DemoFormatterSurface`, with the
   extras module's `DemoFormatterAlter`), `SurfaceAddressItem`, and the
   test module's `SurfaceGatedItem` (`GatedFieldSettingsSurface`, a
   settings variant with an access class); the demo modules have no
   condition or action, so the new hosts are pinned by
   `ThresholdCondition`, `ThresholdAction`, `PinnedNoteBlock` and
   `PlainThresholdPlugin` in `data_surface_surface_test`. Deleted:
   `DemoExtrasSurfaceSubscriber` and its services file,
   `DemoExtrasVariantRefiner`, `FieldAdd`, `FieldUpdate`,
   `SurfaceFieldSettingsTrait`, `FieldSurfaceLocator` and its service.
   The field tools are the derived `data_surface:field.instance:add`,
   `:reuse` and `:edit`; `COMPARISON.md` compares `:reuse` with Tool
   Belt's `field_add`. Two derivation rules, in
   `SurfaceCatalogue::standalone()` and the deriver: a situation whose
   permission has a `%key` no parameter can supply is no tool
   (`field.storage:add`), and a surface any plugin uses is no tool. The
   catalogue lists each surface's plugins (`SurfacePlugins`,
   `data_surface.surface_plugins`) and whether each situation stands on
   its own. Seams: an alter's `extendChoices()` (new on
   `ShapeAdditionsInterface`) is the engine's contribution, and the
   alter's `#[RefinesInput]` method on that key is bound as the
   contribution's refiner; `Surfaces::access()` builds the surface and
   lets each child the context resolves (an attachment, a slot whose
   deciding key is locked) refuse through its own access class; a scalar
   situation parameter named for a surface key is described as that key
   in a tool. Deviations: the demo formatter's `classes` output is no
   longer refined by the variant, because the sketch never refines
   outputs (its test now asserts the open list); the derived field tools
   do not offer a field type whose settings are no surface (the old
   tools fell back to the config schema, which is Tool Belt's job), and
   `field_name` gained the storage's own Regex and Length so a bad name
   is a violation rather than a `FieldException`; `FieldInstanceTarget`
   places a new field on the default form and view displays, as the old
   add tool did; `FieldInstanceTarget` loads and writes the field's own
   settings, not `getSettings()`, which mixes in the storage's; the
   `PROVIDER` constant moved to `NodeTypeReviewSettings::MODULE`. Every
   undecided point is a sketch-gap comment.
5. **Delete the old spelling.** `DataSurfaceDeclarationInterface`,
   `DataSurfaceProviderInterface`, the host trait's declaration paths,
   the build event and its subscribers, the attribute directory, and
   every builder method nothing reaches. The builder becomes internal.
   Tests written against the old spelling are rewritten here.

   **Done.** One spelling. Deleted from `src/`: the declaration and
   provider interfaces, the build event, the factory (it only
   dispatched the event and sealed), policy filters, output refiners
   and `refineOutputs()`, and the awareness service (superseded by
   `SurfacePlugins`). `DataSurfaceBuilder` and its interface are
   `@internal`, constructed only by the build step; they lost
   `setPropertyDefinitions()`, `setPropertyDefinition()`,
   `addOutputRefinement()`, `addOutputRefiner()`, `addFilter()`,
   `isSealed()` and the constructor's host refiner (public methods 24
   to 18), and `DataSurface` lost its host refiner, filters and output
   refiner. `Surfaces::build()` takes a surface and a context only and
   seals directly. The four plugin bases, the host, configuration, form
   and formatter traits and `DataSurfacePluginForm` host only the surface
   `#[UsesSurface]` names: `getDataSurface()`, `getDataSurfaceTarget()`
   and `surfaceAccess(?account, operation)` lost the operation and
   subject coordinate, and access asks the surface's access class.
   `FieldSurfaceProviderInterface` stays, trimmed, because Field UI's
   static validate callback rebuilds the item and needs its surface and
   its target. `DataSurfaceProviderForm` lost its provider half and is
   `DataSurfaceSituationForm`; `NodeTypeSurfaceProvider` and
   `NodeTypeAddTarget` are deleted. `ShapeInterface` gained
   `addCacheableDependency()`; `attach()` and `attachBy()` return the
   map at the key for the owner to label, and `attachBy()` lost its
   children: a slot is always open, and the demo's presentation
   surfaces carry `#[SurfaceVariant]`. A slot is also filled, for the
   values no variant class fills, by a `DerivedVariantsInterface`
   service (`data_surface.derived_variants`); `data_surface_tool`'s
   `FieldSettingsSchemaVariants` derives a field type's settings from
   `field.field_settings.<type>`, so the derived field tools offer
   every UI field type again, and the catalogue says which variants are
   declared and which derived. `Narrowing` also accepts a data type
   becoming one of its derivatives (`entity` to `entity:node`), the Tool
   API's rule; its acceptance of a list item turning `any` is not
   adopted. Test fixtures migrated to surface classes: the test block,
   chain block, condition, action, formatter and the secret item's
   settings (secret key included); the build-event subscriber, the
   policy filter, both output refiners, the ribbon refiner and the
   legacy demo declaration are deleted, as are `SurfaceAlterTest` and
   `NodeTypeSurfaceProviderTest`; `DataSurfaceProviderFormTest` is
   `DataSurfaceSituationFormTest`. Counts: `src/` 126 files and 20564
   lines before, 119 and 18826 after; test classes 65 before, 64 after; the suite
   675 tests and 4344 assertions before, 653 and 4018 after, with only
   the two webdriver failures. Deviations: the
   schema fallback covers instance settings only, so a string's
   `max_length`, a storage setting, is not yet reachable through the
   derived tools (the storage surface has no settings slot, and
   `field_type` is not one of its keys); a derived variant has no class,
   so no alter, refiner, target or access class applies to it.
6. **Docs.** The sketch's README and build walkthrough become the front
   of `docs/`; `sketch/` is deleted once the docs say everything it did.

   **Done.** One code item first: `FieldStorageSurface` gained identity
   `field_type` and a `settings` slot chosen by it, filled by a
   `#[SurfaceVariant(of: FieldStorageSurface::class, key: 'settings')]`
   class or, for every UI field type, by `FieldStorageSettingsSchemaVariants`
   (the schema deriver, now a class with three constants, pointed at
   `field.storage_settings.<type>` and `defaultStorageSettings()`).
   `FieldStorageTarget` loads, prepares and commits the type and the
   settings, and refuses at prepare a type other than the field's and,
   once the field has data, a settings change that alters its columns.
   A string's `max_length` is set through `field.instance:add`
   (`storage.settings.max_length`, the type named on the storage too:
   the add situation knows no type, and the wall keeps the field's from
   the child until commit) and changed through `field.storage:edit`.
   The docs: `docs/index.md` opens with the pitch and the pattern
   table; `docs/pattern.md` and `docs/how-it-fits.md` carry the
   sketch's README and walkthrough in the module's real names;
   `docs/decisions.md` settles every sketch-gap comment (39, plus two
   decisions the docs stated without a comment) by topic, and the code
   keeps eleven one-line pointers to it; every other page was checked
   against the code. `sketch/` is deleted, with its phpstan exclusion.
   Dead engine code: `NodeTypeTarget` reimplemented
   `BaseFieldOverrideTarget`'s translation, so it now delegates through
   the latter's new `values()`, `plan()` and `write()` (its plan is
   exported arrays plus the fields whose override goes, so a dry run
   previews it); `ConfigObjectTarget` had no production caller and is
   deleted with its six tests (the composite tests now use two state
   targets). `ConfigEntityTarget` and `CompositeTarget` are also reached
   only by tests and are left for the owner. `ROADMAP.md` gained "After
   the rework". `docs/catalogue.md` changed (storage identity and its
   derived slot); both `COMPARISON.md` files regenerated unchanged, as
   they compare instance settings only.

## Closing: what the rework changed

| | `main` (b6db309) | after step 6 |
| --- | --- | --- |
| `src/` PHP files | 86 (given as 96) | 118 |
| `src/` lines | 14306 (given as 17463) | 18641 |
| `DataSurfaceBuilder` public methods | 21 | 18, and `@internal` |
| Tests (suite run) | 573 (as given) | 650, 4033 assertions |
| Test methods (static count) / test classes | 422 / 55 | 469 / 64 |

The `main` file and line counts are measured the way step 5 measured
its own (every `*.php` under `src/` at that commit); the figures this
step was handed for `main` do not match that measure, and both are
shown. `src/` grew because the engine stayed and the authoring layer
(`src/Surface/`, `src/SurfaceBuild/`) was added over it, while the old
spelling's declaration, provider, event, filter and output-refiner code
went.

For an author, the rework replaced several ways of saying one thing
with one. A surface used to be a static `declareDataSurface()` on a
host or a provider service, its rules a `refineDataDefinition()` with a
`match` and dependencies declared apart, its add and edit forms a
provider calling `lock()` per operation, another module's changes a
build event subscriber holding the whole builder, and its storage and
access a provider triple. Now it is one `#[Surface]` class in
`src/Surface/`: a flat `defineInputs()`, one named `#[RefinesInput]`
method per rule whose signature is its dependency list, `#[Situation]`
static methods that say how much is known, and a target and an access
class named on the attribute. Another module writes an
`#[AltersSurface]` class that can only add, reword and offer more on a
fixed list. A plugin names its surface with `#[UsesSurface]` and keeps
only its rendering. Children are `attach()` and always-open
`attachBy()` slots that variants, declared or derived, fill without the
parent naming them. And from that one class the form, the route, the
access check, the catalogue entry and the tool are all derived, so a
person, a route and an agent get the same answer.

Each step is one unit, run by an agent, with the suite and gates as the
exit check and a one-line commit by the orchestrator.
