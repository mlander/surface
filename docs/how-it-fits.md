# How it fits

Three parties, in order:

| Party | Does | Knows |
| --- | --- | --- |
| **Caller** (a route's form, a tool, a plugin host, a test) | builds a context, asks for the surface, then uses it | where it is |
| **Framework** (`Surfaces`, the service `data_surface.surfaces`) | runs the shape methods, applies alters, applies the context, binds refiners, builds children, seals | everything |
| **Surface** and its **alters** | declare | nothing about the caller |

The context is how the caller tells the framework where it is. The
framework does most of the work with it. A surface only reads it for the
rare fact that is not a value, such as "this field already has data",
and only in a situation method, which is where contexts are made.

## Discovery

Before anything is built, `SurfaceBuild\SurfaceCollectorPass`, a
compiler pass registered by `DataSurfaceServiceProvider`, lists every
class in an enabled module's `src/Surface/` and `src/SurfaceAlter/`
into a container parameter, and registers each alter, and each target
and access class a surface names, as an autowired service.
`SurfaceRegistry` (`data_surface.surface_registry`) reads their
attributes into the discovery cache: each surface's id, identity,
target, access class and `#[RefinesInput]` methods; each situation with
its parameters; each alter with the situations it applies in; each
variant with the slot and value it fills. Plugin definitions get
`#[UsesSurface]` copied in by `SurfacePluginHooks`, and that is where
the registry finds the other kind of surface: a plugin that is its own
surface, `#[UsesSurface]` with no argument, whose definition records
its own class (`SurfacePlugins::ownSurfaces()`). It is read the same
way, under the id `<host type>:<plugin id>` unless it carries
`#[Surface]`, and the cache id hashes both lists. Discovery refuses a
surface whose `defineInputs()` or `defineOutputs()` is not static.

## The build step

`SurfacesInterface::build(string $surface, SurfaceContext $context):
DataSurfaceInterface`, in `Surfaces::buildSurface()`:

```
build(FieldInstanceSurface::class, $context):

  1. the owner's shape, asked of the class: no instance is ever made
       $class::defineInputs(new SurfaceShape($builder))          <- no context: the
       $class::defineOutputs(new SurfaceShape($builder, TRUE))      same everywhere
     SurfaceShape writes straight into a fresh DataSurfaceBuilder,
     the engine's internal builder. $class is the #[Surface] class,
     or the plugin class of a plugin that is its own surface

  2. the alters #[AltersSurface] names for this surface, skipping one
     whose situations leave this context's operation out:
       $alter->alterInputs(new SurfaceShapeAdditions($builder, $module))
       $alter->alterOutputs(...)                    <- if it has outputs
       $alter->storageShape()                       <- if HasStorageShapeInterface
     SurfaceShapeAdditions mounts what it adds at
     third_party_settings.<module>

  3. the checks on the shape: every identity key is a declared input,
     no situation id is provided twice, no surface is attached inside
     itself, every slot is chosen by a plain input

  4. the context (applyContext):
       starting values become the keys' defaults, if it creates
       each constraint is added to its key, checked narrower
       each identity key it knows becomes that key's default, locked

  5. the refiners (bindRefiners), owner's first, then each alter's:
       each #[RefinesInput] method checked against the shape and the wall,
         and a surface's checked static
       the keys it watches become refinement edges
       its class becomes one RefinesInputRefiner link on each key it refines:
         the surface's link holds its class name and calls static methods,
         an alter's holds the alter service and calls instance methods
       a method watching nothing runs now, checked narrower

  6. the children, each through this same build step in its own frame:
       attach('storage', FieldStorageSurface::class)
         -> build(FieldStorageSurface::class, childContext($context, 'storage'))
       attachBy('settings', by: 'field_type')
         -> one child per #[SurfaceVariant] for (FieldInstanceSurface, settings)
         -> one derived child per value a DerivedVariantsInterface service
            fills and no variant class does

  7. seal: a DataSurfaceInterface, its children sealed into the parent's
     entries as SurfaceAttachment / SurfaceSlot
```

Nothing is cached across builds: a surface describes live site state,
and what that state is, a shape says with `addCacheableDependency()`.

The rest of the framework, beside the build:

```
access($surface, $context, $account)           Surfaces::access()
  the situation's permission, %keys filled from the known identity,
  then the surface's access class if it has one,
  then each child the context resolves, through its own access class,
  which may refuse and never allow

target($surface, $context, $built)              Surfaces::target()
  the surface's SurfaceTargetInterface, wrapped in a SurfaceTargetAdapter
  bound to the context, with a route per child whose class names a
  target, each in the context its parent hands it; a child without one
  is stored by the parent under its key

refine($values)                                  DataSurface::refine()
  for each key whose watched siblings all hold values: its chain of
  RefinesInputRefiner links (owner's, then alters'), each handed a deep
  clone and checked narrower; each child refined in its own frame
```

Values never travel in the context. Identity facts in the context
become locks (step 4). Current values come from storage, loaded by the
target, and submitted values arrive at the refiners and the pipeline.

## One request: editing an existing field

The derived tool `data_surface:field.instance:edit`, given
`field: node.article.field_address`:

```
SurfaceSituationTool
  $context = $surfaces->situation(FieldInstanceSurface::class, 'edit',
                                  ['field' => 'node.article.field_address']);
      SituationArguments loads the FieldConfig: FieldConfigInterface is
      satisfied by one entity type's class
      FieldInstanceSurface::edit($field) returns
        operation  edit, creates FALSE
        known      entity_type_id=node, bundle=article,
                   field_name=field_address, field_type=address
        children   storage  => FieldStorageSurface::edit($storage)
                                 known entity_type_id, field_name, field_type
                                 cardinality => Range(min: current), if it has data
                   settings => edit, knowing entity_type_id, bundle, field_name

  $access = $surfaces->access(FieldInstanceSurface::class, $context);
      'administer node fields', then FieldInstanceAccess (a locked
      storage is refused), then the settings' access class, if any

  $surface = $surfaces->build(FieldInstanceSurface::class, $context);

Step 1   FieldInstanceSurface::defineInputs(), static
           entity_type_id, bundle, field_type, field_name, label, ...
           attach storage; attachBy settings on field_type
Step 2   no alter in this repository names the field surface
Step 4   all four identity keys are known -> locked
           (on the add tool only two are known -> two stay open)
Step 5   bundleOfEntityType() watches entity_type_id -> an edge
Step 6   storage built with ITS context: field_type locked to address,
           cardinality narrowed right there if the field has data; its
           settings slot resolved to the variant derived from
           field.storage_settings.address
         settings slot: field_type is locked to address, so the child
           is AddressFieldSettingsSurface, built in the settings context;
           every other field type's settings, derived from
           field.field_settings.<type>, sit in the slot's table too

  $target = $surfaces->target(FieldInstanceSurface::class, $context, $surface);
      FieldInstanceTarget, routing storage to FieldStorageTarget and the
      address settings to AddressFieldSettingsTarget

  $pipeline->submit($surface, $values, $target)
      load     each level loads its own slice and the adapter assembles it
      accept   the payload merged over what was loaded, level by level
      validate each child in its own frame, violations filed under its key
      prepare  each target rehearses its write: the storage, the field,
               the settings, each held to its config schema
      commit   the storage first, then the field, then the settings
```

## The same surface from the add tool

```
$context = FieldInstanceSurface::add('node', 'article');   knows two keys
$surface = $surfaces->build(FieldInstanceSurface::class, $context);
```

Step 4 locks the entity type and the bundle; the field type and the
machine name stay open, and the caller supplies them. The settings slot
stays an `any` placeholder until `field_type` has a value, then resolves
to that type's variant. The storage child sees the field's operation,
`creates` and known identity, so its own `field_type` is open too: a
caller describing the storage's settings names the type there, and the
storage target refuses one that differs from the field's. When the
values are committed, the storage learns the field's accepted name and
type as identity, is created first, and the field is created on it.
Same surface, same alters, same children.

## The same surface from a plugin host

A plugin's surface is built by its host, in
`DataSurfaceHostTrait::hostedSurface()`, with `new
SurfaceContext('configure')`: the host's own verb, which is no declared
situation, and nothing known. The host supplies the target (the
plugin's configuration, or the field config Field UI is editing),
because only it holds the instance, and asks access of the surface's
access class alone. A plugin protocol that asks a class for its
defaults statically gets `SurfacesInterface::defaults()`: the owner's
shape alone, with no alter and no context.

## Tools, derived

`data_surface_tool` derives one tool per situation that can be asked
on its own (`SurfaceCatalogue::standalone()`: the surface names a
target, no plugin uses it, and every `%key` in its permission can be
supplied by a parameter). `SurfaceSituationToolDeriver` writes each
definition from the static layer: `SituationInputs` turns the
situation's parameters into inputs and builds the surface in a context
read off the signature for `values`; `SurfaceSituationTool` refines
`values` to the real context once the parameters arrive, asks
`Surfaces::access()` decisively, and submits through the pipeline to
the composed target, stopping after prepare for a dry run. Nothing in
either class names a surface or a key.

## So, the context is

- **Built by** a static `#[Situation]` method on the surface, one per
  way it is asked for, or a bare `new SurfaceContext($operation)` from a
  plugin host or a test.
- **Read by the framework** to lock identity, to narrow keys, to start
  a new thing's values, to hand children their own context, to name a
  situation's permission, and by targets and access classes to know
  which thing.
- **Never read by a shape or a refiner.** The shape methods take a
  shape, refiners take the values they name. State such as "this field
  has data" is narrowing the situation imposes, applied once at build.
- **Never** a bag of submitted values.
