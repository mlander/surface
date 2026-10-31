# Built on typed data

Every key this module describes is a core typed data definition, and
every value is validated by core's typed data validation. This page
says what core gives, what a form, a tool and an HTTP API need that
core has no object for, which three parts the module adds to supply
it, and why each part is needed today. [How it fits](how-it-fits.md)
follows the build in full; [The resource pattern](pattern.md) explains
what an author writes; [Core gaps](core-gaps.md) says what core would
change.

## What core gives

Core 11's Typed Data API, in `core/lib/Drupal/Core/TypedData/`:

- **A definition per value.** `DataDefinition` holds a type, a label, a
  description, whether the value is required or read-only, settings and
  validation constraints (`DataDefinitionInterface`).
- **Groups of definitions.** `MapDataDefinition` holds named property
  definitions (`ComplexDataDefinitionInterface`); `ListDataDefinition`
  holds the definition of each item.
- **Typed data that validates.** `TypedDataManager::create()` makes a
  typed data object from a definition and a value; its `validate()`
  checks the value against the definition's constraints.
- **Typed config.** `TypedConfigManager` builds the same definitions
  from config schema YAML (`buildDataDefinition()`) and validates a
  config object with them (`createFromNameAndData()->validate()`).

## What the doors need that core has no object for

A form, a tool, an HTTP endpoint and its OpenAPI document each read the
definitions of one thing. To read the same ones, they need:

- **One group, written by several parties, then fixed.** The owner's
  class, other modules, the site's defaults, what the operation knows,
  and access for the account asking each write into the same group.
  Core has no object that holds the group while they write, puts them
  in order, and refuses writes after the last one.
- **Rules between keys.** One key's allowed values depend on another
  key's value, stated so that a schema can write the rule and a
  validator can check it.
- **Facts a definition cannot carry today.** A default value, examples,
  labels for allowed values, which sibling keys a definition depends on,
  and whether a key is fixed or locked
  ([Core gaps, step 1](core-gaps.md#step-1-facts-on-a-data-definition)
  and [the smaller gaps](core-gaps.md#smaller-gaps-outside-the-sequence)).
- **A JSON Schema of the whole group** that is never stricter than the
  validation that judges the values.

## What the module adds

Three parts, in the order a build uses them. The definitions inside are
core's throughout.

### The blueprint: where definitions are written

A resource or structure class writes its keys into a
`BlueprintInterface` in `defineInputs()`; another module's alter writes
into the add-only `BlueprintAdditionsInterface` in `alterInputs()`.
`add()` asks core's typed data manager for the definition
(`createDataDefinition()`, in `src/ResourceBuild/BlueprintAdapterBase.php`)
and returns it, so the rest is core's own setters:

```php
public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition;
```

What core's definition has no method for, `DefinitionMetadata` writes
onto the same object under an array key, and each of its methods calls
core's own method instead once core has one (`src/DefinitionMetadata.php`):

```php
public static function setDefaultValue(DataDefinitionInterface $definition, mixed $value): void {
  if (method_exists($definition, 'setDefaultValue') && static::supportsDefaultValue($definition)) {
    $definition->setDefaultValue($value);
    return;
  }
  static::arrayAccess($definition)->offsetSet(static::DEFAULT_VALUE_KEY, $value);
}
```

### The build: who writes, in which order

`ResourcesInterface::build()` turns a class and a context into a sealed
data surface:

```php
public function build(string $resource, OperationContext $context): SurfaceInterface;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`Resources::buildResource()` fills one `SurfaceBuilder`, the module's
internal mutable stage, in a fixed order: the owner's blueprint, each
alter's additions, the site's defaults, the context's defaults,
constraints and locks, the refiners and the declared rules between
keys (the owner's first), the children, each built the same way, and
the access answer for the account. A constraint the context adds and
every refiner's result are checked narrower than what the key had
(`src/Refinement/Narrowing.php`). A rule between keys is data on the
blueprint, as the introduction's badge alter declares it
(`tests/modules/surface_intro_test/src/OutsideDiscovery/TeaserBadgeAlter.php`):

```php
$inputs->narrow('limit')
  ->when(Condition::present('badge'))
  ->constrain('Range', ['max' => 20]);
```

### The seal: what every door reads

`SurfaceBuilderInterface::seal()` returns a `SurfaceInterface`: a
`DefinitionMap` of `Entry` objects, each holding a core definition with
who added it, whether it is locked and the sibling keys it depends on,
plus the refiners the build bound (`src/DefinitionMap.php`,
`src/Entry.php`). A door asks it for a key and gets core's definition:

```php
public function getDefinition(string $name): ?DataDefinitionInterface;
```

The map refuses writes, and the builder's mutators throw once it has
sealed:

```php
public function offsetSet(mixed $offset, mixed $value): void {
  throw new \LogicException('A definition map cannot be written to: the data surface has been sealed and advertised, and widening is a build-time act.');
}
```

`refine()` hands each refiner a deep clone of a definition, never the
sealed one. The form, the pipeline and the contract emitter
(`src/Contract/ContractEmitter.php`) read only the sealed result.

## Why each part is not core alone

| Part | What fails with core alone | Core gaps |
| --- | --- | --- |
| Blueprint and alters | `MapDataDefinition::setPropertyDefinition()` replaces or removes a property and records no writer; `hook_config_schema_info_alter()` lets a module change another's schema for the whole site and refuses only an added or removed type. Neither puts writers in order or holds a later one to tightening. | [1.7](core-gaps.md#17-what-narrowing-means), [step 3](core-gaps.md#step-3-third-party-settings-for-simple-config) |
| Facts on a definition | `DataDefinition` has no default value or example in core 11, `Choice` has no labels, and nothing states which sibling a definition depends on. | [1.4](core-gaps.md#14-default-values-and-example-values), [1.6](core-gaps.md#16-labels-on-choice), [1.9](core-gaps.md#19-which-siblings-a-definition-depends-on) |
| The build | A definition carries no operation and no access, and `TypedConfigManager::buildDataDefinition()` takes the schema and the stored data, with no account or operation. | [Step 2](core-gaps.md#step-2-operations-and-access-for-simple-config) |
| Rules between keys | A config schema dynamic type swaps a whole type and cannot add a constraint or make a key required; a `%parent` placeholder is resolved only when typed config validates, so nothing reading the definition sees it. | [1.8](core-gaps.md#18-conditions-that-only-narrow), [1.9](core-gaps.md#19-which-siblings-a-definition-depends-on) |
| The seal | `DataDefinition` keeps public setters, so a group handed to a form can change before the values are validated, and nothing promises that a description is never stricter than validation. | [Step 4](core-gaps.md#step-4-a-served-contract) |
| The JSON Schema | The serialization module's JSON Schema normalizers never read `getConstraints()`, and nothing describes what an operation accepts. | [1.2](core-gaps.md#12-constraints-as-json-schema-keywords), [step 4](core-gaps.md#step-4-a-served-contract) |

## What passes through untouched

- **The definitions.** `add()`, `addList()` and `attach()` return core's
  `DataDefinition`, `ListDataDefinition` and `MapDataDefinition`; a
  shape's `define()` returns a `DataDefinition`.
- **The constraints.** A key's rules are core validation constraint
  plugins (`Range`, `Length`, `Regex`). The module's own, such as
  `LabeledChoice` and `UniqueItems`, are constraint plugins of the same
  kind, in `src/Plugin/Validation/Constraint/`.
- **Validation.** The pipeline makes typed data with
  `TypedDataManager::create()` and calls `validate()`
  (`src/Pipeline/Validating.php`). It hands core a copy of the
  definition carrying a digest among its settings, so that core's
  prototype cache tells two definitions apart
  (`src/DefinitionTypedData.php`,
  [1.1](core-gaps.md#11-a-typed-data-prototype-keyed-by-its-definition)).
- **Storage.** A config object is validated whole against its config
  schema through typed config before it is saved
  (`ConfigObjectStorage::prepare()`, through
  `createFromNameAndData()->validate()`).

## What the aim is

Where core has a system that does the job, the module uses it; where
core has one that is close, the aim is to evolve that system rather
than keep a class here ([Namespaces](concepts.md#namespaces)). Each
step of [Core gaps](core-gaps.md) says what the module does today and
what core would change instead, so that piece of the module is no
longer needed: once a definition carries a default and examples (1.4),
`DefinitionMetadata`'s methods for them only call core's, and 1.7
proposes the narrowing check as a typed data service. The conversation
belongs to
[the core issue](https://www.drupal.org/project/drupal/issues/3622144).
