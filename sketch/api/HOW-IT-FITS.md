# How the pieces fit

Three parties, in order:

| Party | Does | Knows |
| --- | --- | --- |
| **Caller** (a route controller, a tool, a test) | builds a context, asks for the surface, then uses it | where it is |
| **Framework** (one build step, not sketched as code) | runs the shape methods, applies alters, applies the context, runs refiners as values arrive | everything |
| **Surface** and its **alters** | declare | nothing about the caller |

The context is how the caller tells the framework where it is. The
framework does most of the work with it. A surface only reads it for the
rare fact that is not a value, such as "this field already has data".

## The build step

```
build(SurfaceInterface $surface, SurfaceContext $context):

  1. $surface->defineInputs($inputs)                <- no context: shape is the same everywhere
     $surface->defineOutputs($outputs)              <- if it has any; same tool, never refined
       keys, identity, dependencies, attachments

  2. foreach discovered alter whose #[AltersSurface] names this surface
     (and this situation, if it lists any):
       $alter->alterInputs($inputs)   (and alterOutputs(), if it has any)

  3. apply the context:                           <- the context's whole use
       lock each identity key (#[Surface(identity:)]) it knows
       add each constraint it carries (checked narrower)
       take its starting values as initial values, if it creates

  4. foreach attached child:
       build($child, $context->forChild($key))    <- a child may get its own context

  5. seal; cache by (surface id, context)

access($surface, $context, $account):
  the situation's permission, then the surface's access class if it has one

load / commit:
  the surface's target, by the identity in the context; a child with its
  own target handles its own keys, a child without one is stored by the
  parent under its key

refine($surface, $values):
  foreach #[RefinesInput($key)] method on the surface and its alters, when a
  sibling named in its signature changed (owner's first, then alters'):
       $definition = $refiner(clone $definition, ...named sibling values)
       check the result is narrower
```

Values never travel in the context. Identity facts in the context become
locks (step 3). Current values of everything else come from storage,
loaded by the target, and submitted values arrive at the refiners.

## One request: editing an existing field

Route: `/admin/structure/types/manage/article/fields/node.article.field_address`

```
Controller
  $context = FieldInstanceSurface::edit($field);
      operation  'edit'
      known      entity_type_id=node, bundle=article,
                 field_type=address, field_name=field_address
      children   storage => FieldStorageSurface::edit($storage)
                              constraints: cardinality => Range(min: 1)

  $surface = $surfaces->build(FieldInstanceSurface::class, $context);

Framework, step 1    FieldInstanceSurface::defineInputs()
                       entity_type_id, bundle, field_type, field_name, label, ...
                       identity is on the attribute: the first four
                       attach storage; attachBy settings on field_type
Framework, step 2    RequiredFieldsNeedHelpTextAlter adds help_link
Framework, step 3    all four identity keys are known -> locked
                       (on the add route only two are known -> two stay open)
Framework, step 4    storage child built with ITS context: operation edit,
                       and cardinality narrowed to Range(min: 1) right here
                     settings slot: field_type is locked to address, so the
                       child is AddressFieldSettingsSurface, built with the
                       parent's context; AddressCountryPolicyAlter applies to it

Controller
  $values = $target->load($field);          label, required, settings, ...
  render the form from $surface and $values, or hand both to a tool

As values change
  bundle:      locked, never refined
  default_country: AddressFieldSettingsSurface::defaultAmongAvailable() narrows to the
               chosen available_countries
```

## The same surface from a tool

```
$context = new SurfaceContext('add');            knows nothing
$surface = $surfaces->build(FieldInstanceSurface::class, $context);
```

Step 3 locks nothing. Every identity key is open and the agent supplies
all of them. The settings slot stays open until `field_type` arrives,
then the slot resolves. Same surface, same alters, same children.

## So, the context is

- **Built by** a static method on the surface, one per way it is asked
  for. The controller picks one. A tool builds an empty context.
- **Read by the framework** to lock identity, to hand children their own
  context, and as part of the cache key.
- **Never read by a surface method.** The shape methods take a shape,
  refiners take the values they name. State such as "this field has data" is
  narrowing the situation imposes, applied once at build.
- **Never** a bag of submitted values.
