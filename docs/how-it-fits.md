# How it fits

Three parties, in order:

| Party | Does | Knows |
| --- | --- | --- |
| **Caller** (a route's form, a tool, a plugin host, a test) | builds a context, asks for the built resource or structure, then uses it | where it is |
| **Framework** (`Resources`, the service `data_surface.resources`) | runs the shape methods, applies alters, applies the context, binds refiners, builds children, seals | everything |
| **Resource** or **structure**, and its **alters** | declare | nothing about the caller |

The context is how the caller tells the framework where it is. The
framework does most of the work with it. A resource or structure only
reads it for the rare fact that is not a value, such as "this field
already has data", and only in an operation method, which is where
contexts are made.

## Discovery

Before anything is built, `ResourceBuild\ResourceCollectorPass`, a
compiler pass registered by `DataSurfaceServiceProvider`, lists every
class in an enabled module's `src/Resource/`, `src/Structure/` and
`src/Alter/` into a container parameter, and registers each alter, and
each storage and access class a resource or structure names, as an
autowired service. `ResourceRegistry` (`data_surface.resource_registry`)
reads their attributes into the discovery cache: each resource's or
structure's id and access class (and a resource's identity and storage)
and its `#[RefinesInput]` methods; each operation with its parameters;
each alter with the operations it applies in; each variant with the
slot and value it fills. Plugin definitions get `#[UsesStructure]`
copied in by `ResourcePluginHooks`, and that is where the registry
finds the other kind of structure: a plugin that is its own structure,
`#[UsesStructure]` with no argument, whose definition records its own
class (`ResourcePlugins::ownStructures()`). It is read the same way,
under the id `<host type>:<plugin id>` unless it carries
`#[Structure]`, and the cache id hashes both lists. Discovery refuses a
class whose `defineInputs()` or `defineOutputs()` is not static.

A class carrying `#[DerivesResources]` is a deriver, registered as an
autowired service; the registry asks it for its resources and
structures here, and reads each into a definition with no class,
`<base>:<derivative>`, whose shape and operations are methods the
deriver names. The cache entry carries the tags each derivative was
read from, so a saved field or form display clears it ([Derived
resources](resources.md#derived-resources)).

## The build step

`ResourcesInterface::build(string $resource, OperationContext $context):
DataSurfaceInterface`, in `Resources::buildResource()`:

```
build(FieldInstanceResource::class, $context):

  1. the owner's shape, asked of the class: no instance is ever made
       $class::defineInputs(new ResourceShape($builder))          <- no context: the
       $class::defineOutputs(new ResourceShape($builder, TRUE))      same everywhere
     ResourceShape writes straight into a fresh DataSurfaceBuilder,
     the engine's internal builder. $class is the #[Resource] or
     #[Structure] class, or the plugin class of a plugin that is its
     own structure

  2. the alters #[Alters] names for this class, skipping one
     whose operations leave this context's id out:
       $alter->alterInputs(new ResourceShapeAdditions($builder, $module))
       $alter->alterOutputs(...)                    <- if it has outputs
       $alter->storageShape()                       <- if HasStorageShapeInterface
     ResourceShapeAdditions mounts what it adds at
     third_party_settings.<module>

  3. the checks on the shape: every identity key is a declared input,
     no operation id is provided twice, no class is attached inside
     itself, every slot is chosen by a plain input

  4. the context (applyContext):
       starting values become the keys' defaults, if it creates
       each constraint is added to its key, checked narrower
       each identity key it knows becomes that key's default, locked

  5. the refiners (bindRefiners), owner's first, then each alter's:
       each #[RefinesInput] method checked against the shape and the wall,
         the owner's checked static, and an alter's static unless it
         declares runtime
       the keys it watches become refinement edges; a key its alter
         mounted, refined or watched, by its dotted path in the mount
       its class becomes one RefinesInputRefiner link on each key it refines:
         the owner's link holds its class name and calls static methods,
         an alter's holds the alter service, whose runtime refiners
         may be instance methods
       a method watching nothing runs now, checked narrower

  6. the children, each through this same build step in its own frame,
     invoked the way a resource asked on its own is (invokeChild): the
     child operation the parent's context names, else the one with the
     parent's context id, else the child's default, else the parent's
     context id and identity (childContext); an operation's permission and
     the child's access class then make it open, locked (the parent's
     key locked) or omitted (the key left out):
       attach('storage', FieldStorageResource::class)
         -> build(FieldStorageResource::class, <its add operation, by id>)
       attachBy('settings', by: 'field_type')
         -> one child per #[Variant] for (FieldInstanceResource, settings)
         -> one derived child per value a DerivedVariantsInterface service
            fills and no variant class does

  7. seal: a DataSurfaceInterface, its children sealed into the parent's
     entries as Attachment / Slot
```

Nothing is cached across builds: a build describes live site state,
and what that state is, a shape says with `addCacheableDependency()`.

The rest of the framework, beside the build:

```
access($resource, $context, $account)          Resources::access()
  the operation's permission, %keys filled from the known identity,
  then the resource's access class if it has one,
  then each slot variant a locked deciding key chose, through its own
  access class, which may refuse and never allow (every child's access
  was answered at build, however it was invoked)

storage($resource, $context, $built)           Resources::storage()
  the resource's ResourceStorageInterface, wrapped in a StorageAdapter
  bound to the context, with a route per child whose class names
  storage, each in the context it is invoked in; a child without it
  is stored by the parent under its key, and one its access omitted
  keeps what is stored under its key

refine($values)                                  DataSurface::refine()
  for each key whose watched siblings all hold values (a key an alter
  mounted, which only that alter watches, is read at its path and not
  waited for): its chain of
  RefinesInputRefiner links (owner's, then alters'), each handed a deep
  clone and checked narrower; each child refined in its own frame
```

Values never travel in the context. Identity facts in the context
become locks (step 4). Current values come from storage, loaded by the
storage class, and submitted values arrive at the refiners and the
pipeline.

## One request: editing an existing field

The derived tool `data_surface:field.instance:edit`, given
`field: node.article.field_address`:

```
ResourceOperationTool
  $context = $resources->operation(FieldInstanceResource::class, 'edit',
                                  ['field' => 'node.article.field_address']);
      OperationArguments loads the FieldConfig: FieldConfigInterface is
      satisfied by one entity type's class
      FieldInstanceResource::edit($field) returns
        id         edit, creates FALSE
        known      entity_type_id=node, bundle=article,
                   field_name=field_address, field_type=address
        children   storage  => FieldStorageResource::edit($storage)
                                 known entity_type_id, field_name, field_type
                                 cardinality => Range(min: current), if it has data
                   settings => edit, knowing entity_type_id, bundle, field_name

  $access = $resources->access(FieldInstanceResource::class, $context);
      'administer node fields', then FieldInstanceAccess (a locked
      storage is refused), then the settings' access class, if any

  $built = $resources->build(FieldInstanceResource::class, $context);

Step 1   FieldInstanceResource::defineInputs(), static
           entity_type_id, bundle, field_type, field_name, label, ...
           attach storage; attachBy settings on field_type
Step 2   no alter in this repository names the field resource
Step 4   all four identity keys are known -> locked
           (on the add tool only two are known -> two stay open)
Step 5   bundleOfEntityType() watches entity_type_id -> an edge
Step 6   storage built with ITS context: field_type locked to address,
           cardinality narrowed right there if the field has data; its
           settings slot resolved to the variant derived from
           field.storage_settings.address
         settings slot: field_type is locked to address, so the child
           is AddressFieldSettingsResource, built in the settings context;
           every other field type's settings, derived from
           field.field_settings.<type>, sit in the slot's table too

  $storage = $resources->storage(FieldInstanceResource::class, $context, $built);
      FieldInstanceStorage, routing storage to FieldStorageStorage and the
      address settings to AddressFieldSettingsStorage

  $pipeline->submit($built, $values, $storage)
      load     each level loads its own slice and the adapter assembles it
      accept   the payload merged over what was loaded, level by level
      validate each child in its own frame, violations filed under its key
      prepare  each storage class rehearses its write: the storage, the field,
               the settings, each held to its config schema
      commit   the storage first, then the field, then the settings
```

## The same resource from the add tool

```
$context = FieldInstanceResource::add('node', 'article');   knows two keys
$built = $resources->build(FieldInstanceResource::class, $context);
```

Step 4 locks the entity type and the bundle; the field type and the
machine name stay open, and the caller supplies them. The settings slot
stays an `any` placeholder until `field_type` has a value, then resolves
to that type's variant. The storage child sees the field's context id,
`creates` and known identity, so its own `field_type` is open too: a
caller describing the storage's settings names the type there, and
`FieldStorageStorage` refuses one that differs from the field's. When the
values are committed, the storage learns the field's accepted name and
type as identity, is created first, and the field is created on it.
Same resource, same alters, same children.

## A structure from a plugin host

A plugin's structure is built by its host, in
`DataSurfaceHostTrait::hostedStructure()`, with `new
OperationContext('configure')`: the host's own verb, which is no declared
operation, and nothing known. The host supplies the storage (the
plugin's configuration, or the field config Field UI is editing),
because only it holds the instance, and asks access of the structure's
access class alone. A plugin protocol that asks a class for its
defaults statically gets `ResourcesInterface::defaults()`: the owner's
shape alone, with no alter and no context.

## Tools, derived

`data_surface_tool` derives one tool per operation that can be asked
on its own (`ResourceCatalogue::standalone()`: the resource names
storage, no plugin uses it, and every `%key` in its permission can be
supplied by a parameter). `ResourceOperationToolDeriver` writes each
definition from the static layer: `OperationInputs` turns the
operation's parameters into inputs and builds the resource in a context
read off the signature for `values`; `ResourceOperationTool` refines
`values` to the real context once the parameters arrive, asks
`Resources::access()` decisively, and submits through the pipeline to
the composed storage, stopping after prepare for a dry run. Beside each
that changes a thing that exists (`ResourceCatalogue::reads()`) the same
deriver writes a read tool, `ResourceOperationReadTool`, which takes the
parameters, asks the same access, and answers with what its storage
loads, less every secret. Nothing in any of these classes names a
resource or a key.

## So, the context is

- **Built by** a static `#[Operation]` method on the resource or
  structure, one per way it is asked for, or a bare
  `new OperationContext($id)` from a plugin host or a test.
- **Read by the framework** to lock identity, to narrow keys, to start
  a new thing's values, to hand children their own context, to name an
  operation's permission, and by storage and access classes to know
  which thing.

- **Never read by a shape or a refiner.** The shape methods take a
  shape, refiners take the values they name. State such as "this field
  has data" is narrowing the operation imposes, applied once at build.
- **Never** a bag of submitted values.
