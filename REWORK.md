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
2. **Subsurfaces.** `attach()`, `attachBy()`, open slots filled by
   `#[SurfaceVariant]`, narrowing inside maps, targets composing along
   the tree. Consumers: the field tools' settings as a child resolved
   from the subject; the demo block's presentation slot.
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
