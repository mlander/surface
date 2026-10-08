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

      // SKETCH GAP: <what the sketch did not say, and what was decided>

  Sparingly, for real gaps only. Each step's report lists the ones it
  wrote.

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
   `SKETCH GAP:` comment in the code. The extras module had no demo
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
4. **Plugins and remaining hosts.** Every demo plugin, the address field
   type, and the formatter, condition and action hosts move to
   `#[UsesSurface]` with their surface in `src/Surface`.
5. **Delete the old spelling.** `DataSurfaceDeclarationInterface`,
   `DataSurfaceProviderInterface`, the host trait's declaration paths,
   the build event and its subscribers, the attribute directory, and
   every builder method nothing reaches. The builder becomes internal.
   Tests written against the old spelling are rewritten here.
6. **Docs.** The sketch's README and build walkthrough become the front
   of `docs/`; `sketch/` is deleted once the docs say everything it did.

Each step is one unit, run by an agent, with the suite and gates as the
exit check and a one-line commit by the orchestrator.
