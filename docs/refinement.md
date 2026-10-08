# Refinement, contributions, and cacheability

A surface says what a key may hold. Refinement says what it may hold
*given what the other keys hold* — which variants exist for the chosen
casing, which bundles exist for the chosen entity type, which fields
exist on that bundle. This page says who is allowed to change a surface,
when, by how much, and how long the answer is good for.

It is the contribution-model chapter: read
[Declaring a surface](declaring-a-surface.md) first for how a surface of
your own is built, [Surfaces as classes](surfaces.md#surface-alters) for
what an alter is, and [Options and resolvers](options.md) for how the
lists of allowed values this page narrows are declared.

One rule governs the whole page:

> **Widening is a build-time act. After a surface is advertised, nothing
> may make it accept a value it did not already accept.**

That is what makes an advertisement worth reading. A form, a validator,
a config action, a REST client and an agent all read the same surface,
and none of them can be surprised later by a value space they never saw.

## The two roles

Every module touching a surface is in one of two roles, and each role
has exactly one permission.

| Role | When | May | May not |
| --- | --- | --- | --- |
| **Contributor** | Build time: an alter's `alterInputs()` | Add keys, mounted under its own module's name; offer more values on a fixed list with `extendChoices()`; reword a label or description with `describe()` | Add bare top-level keys, take anything away, change a key's type, contribute a value somebody already owns |
| **Refiner** | Refinement, per request: a `#[RefinesInput]` method | Narrow one key, reading the siblings it watches | Touch a value its class did not contribute, hand back a value it was not given |

The surface's **owner** — the `#[Surface]` class whose shape this is —
is simply the first contributor. Its keys and its `#[RefinesInput]`
methods are contribution number one, and they are held to the same rules
as everybody who arrives later.

Nothing removes. A site that must hide an owner's key or value by
policy is a later concept, not a role here.

### Contributing

An alter contributes in `alterInputs()`:

```php
#[AltersSurface(DemoFormatterSurface::class)]
final class DemoFormatterAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->extendChoices('variant', [self::RIBBON => $this->t('Ribbon')]);
  }

}
```

The alter's module is load-bearing, and nothing has to name it: the
build step records what the alter adds under the module the class
belongs to. That is what decides which values the alter's own refiners
are handed, and what a refusal names when two modules contribute the
same value.

Two refusals fall out of this, both at build time:

- **One value has one owner.** Contributing a value the key already
  allows throws, naming who has it. Two modules contributing `ribbon`
  are two modules each believing they answer for how `ribbon` behaves,
  and only one of them can be right.
- **A key that allows anything cannot be contributed to.** If a key
  declares no list of allowed values, giving it one would *narrow* the
  contract its owner deliberately left open, which is not a
  contribution.

A key an alter adds lands at `third_party_settings.<module>.<key>` —
advertised, validated and machine-visible, the way core stores
third-party settings. A formatter or widget host has one extra rule to
know about there: it prunes saved settings against its static defaults
array, so the mounted key has to appear in that array too. See
[Generated forms](forms.md).

### Refining

Refinement runs per request, whenever the values a key depends on are
known. In four lines:

1. Deep-clone the advertised definition, and divide its list of allowed
   values into the owner's — everything nobody contributed — and one
   slice per contributor.
2. Run the owner's refiners over the owner's slice.
3. Run each contributor's refiners over that contributor's slice.
4. The refined definition is the owner's, with its list of allowed
   values replaced by the **union** of every narrowed slice.

A `#[RefinesInput]` method of an alter on a key the alter offered more
values on is that contribution's refiner: handed the alter's values
only. A method of an alter on any other key of the owner's runs in the
owner's chain, after the owner's own methods.

The union is the whole point. A refiner narrowing "its" option can never
take a sibling module's option with it, and can never put back an option
another module narrowed away, because it never sees them. Where a key
has a single contribution — nearly every key — the algorithm reduces to
a plain chain of refiners over the whole definition, minus the ability
to widen.

Only the values are merged this way. Everything else about the refined
definition — its other constraints, its description, its default — is
the owner's, because the owner is the one who answers for the key.

#### One method, one rule

A refiner is a method, so a class that refines several keys has several
methods, each named for its rule and each attributed with the one key it
refines: `DemoBlockSurface::bundleOfEntityType()` and
`::fieldOfBundle()`. Nothing dispatches on a key's name. The method
takes the key's definition first and one parameter per sibling it
watches, and runs once every watched sibling has a value.

A refiner is handed a deep clone, so it may mutate what it is given and
hand it back, or answer with a fresh definition. A fresh definition does
not have to carry the declared default or the examples forward: those
are copied across every link, so the rendered form and the accepted
value cannot disagree about what a key starts from.

## The narrowing table

Every refiner link, and every constraint a situation adds, is checked
against what it was handed before its answer is used. The check is
deliberately conservative: it is applied to arbitrary constraints
written by arbitrary modules, and a wrong "this is narrower" is worse
than a refused refinement.

| Change | Verdict |
| --- | --- |
| Adding a constraint | narrower |
| Dropping values from a list of allowed values | narrower |
| Raising a `Length` or `Range` minimum, lowering its maximum | narrower |
| Adding a `Length` or `Range` bound where there was none | narrower |
| Sharpening a label, description, default or example | neither, and allowed |
| Refining `any` to a concrete type, a map included | narrower — the declared escape hatch |
| Changing the data type to one of its derivatives (`entity` to `entity:node` to `entity:node:article`) | narrower |
| Changing the data type any other way | **refused** |
| Turning off the required flag | **refused** |
| Removing a constraint | **refused** |
| Dropping a choice constraint's list of values | **refused** |
| Adding values to a list of allowed values | **refused** |
| Replacing the options of any other constraint | **refused** |
| Adding or removing a map property | **refused** |
| Any of the above, inside a map property or a list item | as above |

A derivative is narrower because every value of `entity:node` is a value
of `entity`; the derivative's own properties are its type's to compute,
so only the base's own required flag and constraints are compared, as
they are from `any`. `string_long` is not a derivative of `string`: the
test is the base type followed by a colon.

The conservatism lives in the replaced-options row: two `Regex` patterns
cannot be compared for containment, so replacing one is refused rather
than guessed at. A refiner that needs a different pattern advertises the
narrower one in the first place. For `Length` and `Range` only `min` and
`max` are compared, because the rest of their options are messages.

Inside a map the table recurses, which is what holds a subsurface to
it: the child narrows its own keys, and the map its parent advertises
narrows with them. Refining `any` to a map is how a slot's placeholder
resolves to the variant its deciding key chose (see
[Surfaces as classes](surfaces.md#subsurfaces)), and how a
`#[RefinesInput]` method on an `any` key describes a child that cannot
be listed statically.

A refusal is a `\LogicException` naming the key, the contributor and
what widened:

> Refining "variant" for the data_surface_demo_extras contribution
> widened what it was given: the LabeledChoice constraint gained the
> values bold. Refinement may only narrow; widening is a build-time act,
> and the build is over.

Two more checks belong to the same contract. Building refuses a surface
in which a key refines, directly or indirectly, against itself: a cycle
is not an infinite loop — each target is walked once — which is exactly
why it has to be refused, since the answer would otherwise depend on the
order the keys happened to be read in. And a sealed surface is only
read: refinement returns a new surface rather than changing the one it
was asked of.

What the build step seals is a `DefinitionMap`: one `SurfaceEntry` per
key, in declaration order, carrying that key's definition, its
contributor, its locked state, its refinement edges, the values
contributed to it and the refiner chains bound to it. The map checks
the rest of the shape once, as it is built — a key declared twice, or a
refinement edge naming a key nobody declared, is refused there.
Refinement then rebuilds the map one entry at a time through `with()`,
replacing only the definition, so a narrowed surface cannot lose track
of who owns what.

## Cacheability

A surface is computed from live site state. What it advertises can
depend on which entity types exist, which languages are installed, what
the current user may do — so anything that renders or stores a surface
has to be able to say for how long, and under what conditions, that
rendering holds. A surface is therefore a
`CacheableDependencyInterface`, and there are three places metadata
enters it:

- **The shape.** `ShapeInterface::addCacheableDependency()` in
  `defineInputs()`, for anything the definitions themselves were read
  from: a key offered only while a module is installed, a label read
  from configuration.
- **Refiners.** A `#[RefinesInput]` method calls no service and is a
  pure function of the siblings it watches, so it adds nothing of its
  own. The site state it points at is fetched by an options resolver,
  which says how long its answer holds; see the next item. (The
  engine's internal refiner interface would merge the metadata of a
  link that is itself a cacheable dependency, but the one link the
  build step binds, `RefinesInputRefiner`, is not one, for that
  reason.)
- **Option resolvers.** Each resolved option list carries its own
  metadata on its `OptionSet`, which the options widget applies to the
  element it builds.

The form builder applies the refined surface's metadata to the surface
container, so a generated form carries both halves: the shape's, on the
container, and each list's, on its element. See
[Generated forms](forms.md).

### Site state versus per-request

The split matters, and it is the one thing to get right when caching a
surface:

- **Cache tags and max-age travel.** They describe how long an answer
  stays true for *everybody*, so they can be stored in a shared cache
  and invalidated for everybody at once. A refined shape carrying the
  `config:system.site` tag can be cached and will be dropped when that
  config is saved.
- **Cache contexts mean per-request.** A context says the answer would
  have been different for a different request — a different user,
  language, or URL. A refined shape carrying contexts is only valid for
  the request that produced it. It may be stored in a shared cache only
  by a cache that varies on those contexts; anything that stores it
  without them is storing one visitor's shape and serving it to the
  next.

The practical rule for anything holding a surface: **never persist a
refined shape that carries cache contexts** unless the thing persisting
it varies by those contexts. A surface with no contexts, a `PERMANENT`
max-age and no tags is static, and may be reused freely. Anything else
is only as reusable as its shortest-lived half.

## Worked example: the extras demo

`data_surface_demo` ships a field formatter whose `variant` key
advertises four values — `bold`, `strong`, `quiet`, `muted` — and one
refiner that narrows them to the ones the chosen casing offers. It knows
nothing about any other module.

`data_surface_demo_extras` is a separate module that owns neither the
formatter nor its form. Its alter of the formatter's surface contributes
a fifth value, and a refiner of its own for it:

```php
#[AltersSurface(DemoFormatterSurface::class)]
final class DemoFormatterAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->extendChoices('variant', [self::RIBBON => $this->t('Ribbon')]);
  }

  #[RefinesInput('variant')]
  public function ribbonInUpperCase(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    return $casing === 'uppercase' ? $variant : $variant->addConstraint('LabeledChoice', ['choices' => []]);
  }

}
```

The method refines a key the alter offered more values on, so it is
bound as the `data_surface_demo_extras` contribution's refiner, not in
the owner's chain.

The advertised key now allows five values, and the surface records that
one of them belongs to the extras module. At refinement time:

| Casing | Owner's slice → narrowed to | Extras' slice → narrowed to | Union |
| --- | --- | --- | --- |
| `none` | bold, strong, quiet, muted → all four | ribbon → none | bold, strong, quiet, muted |
| `uppercase` | bold, strong, quiet, muted → bold, strong | ribbon → ribbon | bold, strong, **ribbon** |
| `lowercase` | bold, strong, quiet, muted → quiet, muted | ribbon → none | quiet, muted |

Read the two refiners and notice what neither of them had to do. The
formatter's refiner never mentions `ribbon`, and could not have kept it
even if it tried: it is not in the list it is handed. The extras
refiner never mentions `bold`: it is handed one value, and all it
decides is whether that value survives. Neither module has to know the
other exists, and the select, the validator and any machine reading the
contract all see the same five values, narrowed the same way.


## Where this does not reach yet

- **Nested keys.** A refiner refines a whole key. There is no dotted
  path to refine one property inside a map on its own; the method
  refining the map narrows the property inside what it returns, and
  the check descends into it. Dotted refinement paths (decision D6) are
  where that lands.
- **An alter cannot watch a mounted key.** An alter's
  `#[RefinesInput]` method may refine the key it mounted, by that key's
  own name, watching the owner's keys
  ([Decisions](decisions.md#an-alter-refines-its-own-mounted-key));
  the compliance alter of the examples makes its privacy notice required
  above a hundred people that way. No method can watch a mounted key:
  its value lives under `third_party_settings.<module>`, and handing it
  over needs the same dotted paths.
- **Storage.** For a config-backed surface, widening is a schema alter
  and the surface follows; storage gates the write either way.
