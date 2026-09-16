# Refinement, contributions, and cacheability

A surface says what a key may hold. Refinement says what it may hold
*given what the other keys hold* — which variants exist for the chosen
casing, which bundles exist for the chosen entity type, which fields
exist on that bundle. This page says who is allowed to change a surface,
when, by how much, and how long the answer is good for.

It is the contribution-model chapter: read
[Declaring a surface](declaring-a-surface.md) first for how a surface of
your own is built, and [Options and resolvers](options.md) for how the
lists of allowed values this page narrows are declared.

One rule governs the whole page:

> **Widening is a build-time act. After a surface is advertised, nothing
> may make it accept a value it did not already accept.**

That is what makes an advertisement worth reading. A form, a validator,
a config action, a REST client and an agent all read the same surface,
and none of them can be surprised later by a value space they never saw.

## The three roles

Every module touching a surface is in exactly one of three roles, and
each role has exactly one permission.

| Role | When | May | May not |
| --- | --- | --- | --- |
| **Contributor** | Build time, on the build event | Add definitions under its own namespace, add values to an existing key, register refiners for what it added, declare cacheability | Add bare top-level keys, take anything away, contribute a value somebody already owns |
| **Contribution refiner** | Refinement, per request | Narrow the values its own contribution added, reading any key's value | Touch a value it did not contribute, hand back a value it was not given |
| **Policy filter** | Refinement, after the union | Remove values from any key | Add anything back |

The surface's **owner** — the provider whose definitions these are — is
simply the first contributor. Its own definitions, its refinement map
and its refiner are contribution number one, and it is held to the same
rules as everybody who arrives later. It is the only contributor that
never has to name itself: `addRefiner()` with no contributor means the
owner.

### Contributing

```php
public function onSurfaceBuild(DataSurfaceBuildEvent $event): void {
  if (!$event->appliesTo(DataSurfaceDemoFormatter::class)) {
    return;
  }
  $event->builder->extendChoices('variant', ['ribbon' => 'Ribbon'], 'my_module');
  $event->builder->addRefiner('variant', new MyVariantRefiner(), 'my_module');
}
```

The provider id is load-bearing, and it is the same id in both calls. It
is what the value is recorded under, what decides which values the
refiner is handed, and what a refusal names when two modules contribute
the same value. A subscriber that leaves it out is claiming to be the
owner, and its refiner is then handed the owner's values instead of its
own.

Two refusals fall out of this, both at build time:

- **One value has one owner.** Contributing a value the key already
  allows throws, naming who has it. Two modules contributing `ribbon`
  are two modules each believing they answer for how `ribbon` behaves,
  and only one of them can be right.
- **A key that allows anything cannot be contributed to.** If a key
  declares no list of allowed values, giving it one would *narrow* the
  contract its owner deliberately left open, which is not a
  contribution.

New keys go under the contributor's own namespace with
`setThirdPartyDefinition()`, which lands them at
`third_party_settings.<provider>.<key>` — advertised, validated and
machine-visible, the way core stores third-party settings. A formatter
or widget host has one extra rule to know about there: it prunes saved
settings against its static defaults array, so the mounted key has to
appear in that array too. See [Generated forms](forms.md).

### Refining

Refinement runs per request, whenever the values a key depends on are
known. In five lines:

1. Deep-clone the advertised definition, and divide its list of allowed
   values into the owner's — everything nobody contributed — and one
   slice per contributor.
2. Run the owner's refiners over the owner's slice.
3. Run each contributor's refiners over that contributor's slice.
4. The refined definition is the owner's, with its list of allowed
   values replaced by the **union** of every narrowed slice.
5. The policy filters then run over every key, each held to remove-only
   against the union it was handed.

The union is the whole point. A refiner narrowing "its" option can never
take a sibling module's option with it, and can never put back an option
another module narrowed away, because it never sees them. Where a key
has a single contribution — nearly every key — the algorithm reduces to
a plain chain of refiners over the whole definition, minus the ability
to widen.

Only the values are merged this way. Everything else about the refined
definition — its other constraints, its description, its default — is
the owner's, because the owner is the one who answers for the key.

A refiner is handed a deep clone, so it may mutate what it is given and
hand it back, or answer with a fresh definition. A fresh definition does
not have to carry the declared default or the examples forward: those
are copied across every link, so the rendered form and the accepted
value cannot disagree about what a key starts from.

### Filtering

```php
$event->builder->addFilter(new MySitePolicyFilter());
```

A filter speaks for the site rather than for a contribution — "this
installation does not allow that option, whoever added it" — so it sees
every key, including ones that declare no refinement dependencies at
all, and it may only take away. Use it for policy, never for
preference: a module that wants an option gone because it would rather
it were gone is a contributor arriving after the advertisement.

## The narrowing table

Every refiner link and every filter is checked, against what it was
handed, before its answer is used. The check is deliberately
conservative: it is applied to arbitrary constraints written by
arbitrary modules, and a wrong "this is narrower" is worse than a
refused refinement.

| Change | Verdict |
| --- | --- |
| Adding a constraint | narrower |
| Dropping values from a list of allowed values | narrower |
| Raising a `Length` or `Range` minimum, lowering its maximum | narrower |
| Adding a `Length` or `Range` bound where there was none | narrower |
| Sharpening a label, description, default or example | neither, and allowed |
| Changing the data type | **refused** |
| Refining `any` to a concrete type | narrower — the one escape hatch |
| Turning off the required flag | **refused** |
| Removing a constraint | **refused** |
| Dropping a choice constraint's list of values | **refused** |
| Adding values to a list of allowed values | **refused** |
| Replacing the options of any other constraint | **refused** |

The last row is where the conservatism lives: two `Regex` patterns
cannot be compared for containment, so replacing one is refused rather
than guessed at. A refiner that needs a different pattern advertises the
narrower one in the first place. For `Length` and `Range` only `min` and
`max` are compared, because the rest of their options are messages.

A refusal is a `\LogicException` naming the key, the contributor and
what widened:

> Refining "variant" for the data_surface_demo_extras contribution
> widened what it was given: the LabeledChoice constraint gained the
> values bold. Refinement may only narrow; widening is a build-time act,
> and the build is over.

Two more checks belong to the same contract. `seal()` refuses a
refinement map in which a key refines, directly or indirectly, against
itself: a cycle is not an infinite loop — each target is walked once —
which is exactly why it has to be refused, since the answer would
otherwise depend on the order the keys happened to be read in. And
nothing may change a builder after `seal()`: every mutator throws.

What `seal()` produces is a `DefinitionMap`: one `SurfaceEntry` per key,
in declaration order, carrying that key's definition, its contributor,
its locked state, its refinement edges, the values contributed to it and
the refiner chains registered against it. The map checks the rest of the
shape once, as it is built — a key declared twice, or a refinement edge
naming a key nobody declared, is refused there. Refinement then rebuilds
the map one entry at a time through `with()`, replacing only the
definition, so a narrowed surface cannot lose track of who owns what.

## Cacheability

A surface is computed from live site state. What it advertises can
depend on which entity types exist, which languages are installed, what
the current user may do — so anything that renders or stores a surface
has to be able to say for how long, and under what conditions, that
rendering holds. A surface is therefore a
`CacheableDependencyInterface`, and there are three places metadata
enters it:

- **The builder.** `addCacheableDependency()` at build time, for
  anything the definitions themselves were read from. Contributors
  declare the cacheability of their contribution the same way.
- **Refiners and filters.** A refiner whose answer depends on site state
  says so by also implementing core's `CacheableDependencyInterface`.
  Whatever it declares is merged into the refined surface when it runs
  — only when it runs, because a refinement that did not happen depends
  on nothing. There is no second interface for this: cacheability is
  core's question, asked in core's words.
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
formatter nor its form. On the build event it contributes a fifth value:

```php
$event->builder->extendChoices('variant', ['ribbon' => 'Ribbon'], 'data_surface_demo_extras');
$event->builder->addRefiner('variant', new DemoExtrasVariantRefiner(), 'data_surface_demo_extras');
```

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

Add a policy filter to the same surface and it runs last, over the
union, and can take `strong` away — from the advertisement and from
every narrowing of it — but putting `sash` back throws.

## Where this does not reach yet

- **Nested keys.** The narrowing check reads a definition's own
  constraints. A refiner that tightens a property inside a map is
  cloned safely and its work is kept, but the check does not descend
  into it. Dotted refinement paths (decision D6) are where that lands.
- **Contributed keys.** A contributor can add a value to an existing key
  and can mount a key under its own namespace, but it cannot yet
  register a refiner for the key it mounted, because addressing
  `third_party_settings.<provider>.<key>` needs the same dotted paths.
- **Storage.** For a config-backed surface, widening is a schema alter
  and the surface follows; storage gates the write either way.
