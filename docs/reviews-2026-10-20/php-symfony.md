# Review: a modern PHP and Symfony architect

## 1. Standpoint

I review as someone who writes PHP 8.3 and 8.4 on Symfony every day: readonly classes, enums, attributes as the only configuration, the Validator's `When`, `Compound` and `Sequentially`, autowired services, and phpstan at a high level. I hold the module to one test: every fact that is static should be stated once, in a place the compiler or phpstan can see, and every runtime refusal that a signature could have made impossible is a cost the author pays.

The short version first. The authoring layer is better typed than most Drupal APIs. I ran phpstan at level 9 (the module ships at level 6, `phpstan.neon`) over `data_surface_first`, the three example modules, `src/Resource` and `src/Refinement`, and no resource, structure, shape or refiner class reported an error. Every finding came from Drupal container plumbing, config arrays (`Keys/TicketTypes.php:51-52`) or the engine's own `Condition.php` and `Rule.php`. Each of the five changes below takes one runtime check, or one fact written twice, and turns it into something the language checks.

## 2. The five changes, ranked

### 1. Put an operation's static facts on the attribute, and stop writing its id twice

**Problem.** An operation's id is written in `#[Operation('configure', ...)]` and again in `new OperationContext('configure')`. The build only checks that the two agree when the operation is asked (`src/ResourceBuild/Resources.php:1963-1971`, decision `docs/decisions.md:386-392`). Whether the operation creates is not on the attribute. It is on the context the method returns (`src/Resource/OperationContext.php:54`), so it can only be learned by running the method. As a result the catalogue cannot say whether an operation that takes a parameter creates: `ResourceCatalogue::creates()` returns NULL for it (`src/ResourceBuild/ResourceCatalogue.php:203-212`), and `docs/catalogue.md:15-17` calls "Creates" "the one fact that is not static". The tree has 38 operation methods whose whole body is `return new OperationContext('<same id>');`, such as `modules/data_surface_examples/src/Resource/BulletinResource.php:31-34` and `TicketSalesResource.php:32`. In Symfony, `#[AsCommand(name:, description:)]` carries the static facts and the method carries behavior. Here the method is used to state a fact.

**Change.** Add `creates:` to `#[Operation]`. Let the engine stamp the id, so the context's `$id` becomes optional and `Resources::operation()` calls `withId()` when it is NULL. Make the attribute repeatable and allowed on the class (`TARGET_CLASS | TARGET_METHOD | IS_REPEATABLE`) for an operation that needs no arguments, which then needs no method at all.

Before, `modules/data_surface_first/src/Resource/NewsletterResource.php:140-149`:

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }
```

After:

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
final class NewsletterResource implements ResourceInterface {
```

An operation with arguments keeps its method, which now says only what varies (before: `tests/modules/data_surface_resource_test/src/Resource/Pantry/PantryResource.php:31-42`):

```php
#[Operation('add', label: 'Add a pantry', creates: TRUE)]
#[Operation('edit', label: 'Edit a pantry')]
// ...
public static function edit(string $pantry): OperationContext {
  return new OperationContext(known: ['pantry' => $pantry]);
}
```

(The class-level `add` and the method-level `edit` would sit in different places. They are shown together here only for brevity.)

**Cost.** One argument on the attribute, one optional constructor argument, and a new attribute target. No new concept: the first resource still uses four. `FirstResourceDocsTest` and the first-resource page lose four lines. Decision `#whether-an-operation-creates` (`docs/decisions.md:2402-2407`) is reversed: `creates` becomes static, and `kind` (`OperationDefinition::kind()`, `src/ResourceBuild/OperationDefinition.php:137-143`) becomes static with it.

**What it breaks.** Nothing on the wire: `x-surface.operation.creates` is the same value. The generated catalogue changes, because operations that take parameters now show a "Creates" answer where they showed none. Existing method-only spellings keep working. `creates:` on the context can be deprecated, and a disagreement between it and the attribute refused, as the id disagreement is today.

### 2. Make the sentinels and the closed vocabularies enums

**Problem.** `DoesNotApply` (`src/Refinement/DoesNotApply.php:51-79`) and `Omitted` (`src/Pipeline/Omitted.php:47-73`) are each written as a final class with a private constructor and a static `??=` singleton. That is what a single-case enum gives you from the engine, with clone and unserialize already refused. The two are spelled differently, `here()` and `value()`. `Omitted`'s docblock says "identity is the whole point" (`Omitted.php:40-42`), but `is()` tests `instanceof` (`Omitted.php:84-86`), so the singleton machinery does nothing. `DoesNotApply` also carries five engine helpers marked `@internal` (`marked()`, `marks()`, `inside()`, `leftOut()`, `isDeclaredBy()`, `DoesNotApply.php:93-220`), so an author's IDE offers them on the one type an author returns.

The same pattern of string constants inside closed sets appears elsewhere:

- Condition kinds are protected string constants (`src/Refinement/Condition.php:52-62`), and two `match` expressions end in `default => throw ... 'No condition is called %s.'` (`Condition.php:351`, `:381`). A backed enum would make those matches exhaustive, and phpstan would prove it.
- `AccessAnswer::answer()` returns `'open'|'locked'|'omitted'` as a `string` (`src/Resource/AccessAnswer.php:54-64`, `:282`).
- `AccessAnswer::kindOf()` returns `self::STATIC` or `self::RUNTIME` as a `string` (`AccessAnswer.php:74-79`, `:195`).
- `OperationDefinition::kind()` returns `'create'|'update'|'read'` (`OperationDefinition.php:20-30`).

**Change.**

```php
enum DoesNotApply {
  case Here;
  /** @deprecated Use DoesNotApply::Here. */
  public static function here(): self { return self::Here; }
}
```

Before, `src/Refinement/DoesNotApply.php:23-24` (and `tests/modules/data_surface_resource_test/src/Structure/Notice/NoticeBoardStructure.php:43-44`):

```php
public static function pinnedWhileOpen(DataDefinitionInterface $pinned): DataDefinitionInterface|DoesNotApply {
  return \Drupal::state()->get(self::CLOSED) ? DoesNotApply::here() : $pinned;
```

After:

```php
  return \Drupal::state()->get(self::CLOSED) ? DoesNotApply::Here : $pinned;
```

The return type is unchanged. `DoesNotApply::isDeclaredBy()` uses `is_a($name, self::class, TRUE)` and `RefinesInputRefiner` uses `instanceof DoesNotApply` (`src/ResourceBuild/RefinesInputRefiner.php:225`), and both hold for an enum. Move the five helpers to an engine class such as `Refinement\RuledOut`. Do the same with `Omitted::Value`. Back the vocabularies with their wire strings: `enum Access: string { case Open = 'open'; ... }` with a `narrower()` method in place of the `ORDER` map, `enum AccessKind: string`, `enum OperationKind: string`, and `enum ConditionKind: string`.

**Cost.** Mostly renames. `here()` and `value()` can stay as deprecated static methods, since enums can have static methods.

**What it breaks.** Nothing on the wire, because each enum's `->value` is the string emitted today. PHP code that compares `answer()` or `kind()` to a string literal breaks unless those methods keep returning `->value` for a deprecation cycle.

### 3. Type a refiner's definition as what the engine actually hands it

**Problem.** A refiner's first parameter is documented as `DataDefinitionInterface` (`src/Resource/Attribute/RefinesInput.php:17`, `docs/second-resource.md:133`). That interface has `addConstraint()` (core `DataDefinitionInterface.php:226`) but no `setDescription()`, `setRequired()` or `setSetting()`. So any refiner that rewords a key needs an `instanceof DataDefinition` guard, and the shipped examples have them:

- `modules/data_surface_examples/src/Resource/RegistrationStep2Resource.php:73`
- `RegistrationStep3Resource.php:80`
- `modules/data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php:91`
- `modules/data_surface_demo/src/Structure/DemoBlockStructure.php:93`, `:112`

The guard is worse than noise. When it fails, the refiner silently returns the key un-narrowed, which is the one outcome the narrowing check can never catch. It never fails in practice: `add()` returns `DataDefinition`, and `attach()` and `attachList()` return `MapDataDefinition` and `ListDataDefinition`, which both extend it. The engine does not check the parameter's type either (`src/ResourceBuild/ResourceRegistry.php:902-916` reads only parameter names). Only `addDefinition(string $key, DataDefinitionInterface $definition)` (`src/Resource/BlueprintAdditionsInterface.php:78`) admits a definition that is not a `DataDefinition`.

**Change.** Tighten `addDefinition()` to `DataDefinition`, guarantee that every refiner is handed one, and document the concrete type.

Before (`RegistrationStep2Resource.php:70-81`):

```php
#[RefinesInput('capacity')]
public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
  $seats = Venues::seats($room);
  if ($seats !== NULL && $capacity instanceof DataDefinition) {
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
      ->setDescription(t('Up to @seats for the @room.', [...]));
  }
  return $capacity;
}
```

After:

```php
#[RefinesInput('capacity')]
public static function capacityOfRoom(DataDefinition $capacity, string $room): DataDefinition {
  $seats = Venues::seats($room);
  if ($seats !== NULL) {
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
      ->setDescription(t('Up to @seats for the @room.', [...]));
  }
  return $capacity;
}
```

**Cost.** One parameter type on `addDefinition()`, plus docs. Existing refiners typed to the interface still run, because PHP allows a parameter type to be wider than what is passed.

**What it breaks.** An adopter who passes a `FieldConfig` straight to `addDefinition()`. `data_surface_entity` already asserts `DataDefinition` (`modules/data_surface_entity/src/FieldItemDerivation.php:269`), so the engine assumes it too.

### 4. Make a rule's condition an argument, and give "does not apply" its own verb

**Problem.** `narrow($key)` returns a mutable `Rule`, and the condition arrives through a later `when()`. That leaves three ways to write a rule wrong, and the type system catches none of them:

- A rule with no `when()` is refused only when the resource is built (`docs/recipes.md:70-73`).
- A second `when()` throws (`src/Refinement/Rule.php:118-124`).
- `doesNotApply()` beside any other effect throws, in either order (`Rule.php:198-204`, `:385-389`).

Symfony's `When` constraint takes the condition and the constraints in one constructor. A fluent builder whose first required step is optional is the shape a Symfony developer has learned to distrust.

**Change.** `narrow(string $key, Condition $when): Rule`, with the condition required by the signature, and a separate terminal verb, `ruleOut(string $key, Condition $when): void`, for the effect that cannot be combined with anything.

Before (`modules/data_surface_examples/src/Resource/BulletinResource.php:53-59`):

```php
$inputs->narrow('send_day')
  ->when(Condition::equals('frequency', 'monthly'))
  ->doesNotApply();
$inputs->narrow('items')
  ->when(Condition::equals('frequency', 'weekly'))
  ->constrain('Range', ['max' => 5])
  ->describe(t('Up to 5 in a weekly issue.'));
```

After:

```php
$inputs->ruleOut('send_day', when: Condition::equals('frequency', 'monthly'));
$inputs->narrow('items', when: Condition::equals('frequency', 'weekly'))
  ->constrain('Range', ['max' => 5])
  ->describe(t('Up to 5 in a weekly issue.'));
```

**Cost.** A signature change and one method name. No new concept: concepts.md already has "a key that does not apply" as a row. The vocabulary, the JSON Schema each condition states and the narrowing check are untouched.

**What it breaks.** Every declared rule changes mechanically: example 7, the compliance alter (`RegistrationComplianceAlter.php:68-71`), the recipes and the test fixtures. The old chain can stay for a release, with `when()` deprecated.

### 5. Give an alter its own mount without spelling its module name

**Problem.** In an alter, `add('licence', ...)` takes a bare key and mounts it under `third_party_settings.<module>` automatically. `attach()` and `describe()`, however, need the full path as a string. The compliance alter writes `'third_party_settings.data_surface_examples_compliance'` twice (`modules/data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php:61`, `:63`). That string can only take one value: the build refuses any mount but the calling module's (`src/Resource/BlueprintAdditionsInterface.php:169-171`), and the engine already knows the module because discovery found the class there (`src/ResourceBuild/ResourceCollectorPass.php:137-156`). A string the author must type, and the engine already knows and refuses if it is wrong, is a parameter that should not exist.

**Change.** Add one method on the additions blueprint that means "my mount".

Before (`RegistrationComplianceAlter.php:61-63`):

```php
$inputs->attach('third_party_settings.data_surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
$inputs->describe('title', label: $this->t('Public event title'));
$inputs->describe('third_party_settings.data_surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
```

After:

```php
$inputs->mount(ComplianceStructure::class, reads: ['capacity' => 'capacity'], after: 'capacity')
  ->setLabel($this->t('Compliance'));
$inputs->describe('title', label: $this->t('Public event title'));
```

**Cost.** One method. The concept is the existing "Mounted keys" row (`docs/concepts.md:38`). Paths, storage and the contract are unchanged.

**What it breaks.** Nothing. The path spelling can remain valid.

## 3. Borrow

- **Argument resolution for runtime refiners.** A refiner's signature already works like a Symfony controller's: parameters after the first are resolved by name, from watched siblings. The missing half is resolution by type. A runtime refiner on the owner's class cannot be injected, because the class is never constructed, so the fixtures reach for the service locator: `\Drupal::currentUser()` (`tests/modules/data_surface_resource_test/src/Resource/Exhibit/ExhibitResource.php:79`) and `\Drupal::state()` (`NoticeBoardStructure.php:44`). Borrow `#[Autowire]` on a parameter: `public static function roomsForAccount(DataDefinition $room, #[Autowire(service: 'current_user')] AccountInterface $account)`. Reflection sees the parameter at build, as it sees watches today. A refiner without `runtime: TRUE` that declares a service parameter is refused, so purity is enforced by the signature rather than by `StaticRefinerContainer` under `assert()` and a phpstan rule about `\Drupal::` (`docs/decisions.md:541-578`). This fits the module's constraints: declarations stay static, and the two documents still never run a runtime refiner.
- **`When` and `Compound`, by name, in the docs.** `narrow()->when()` is Symfony's `When`, with ExpressionLanguage replaced by a closed vocabulary. That substitution is correct, because only a closed vocabulary has "one exact JSON Schema spelling" (`Condition.php` docblock). `#[Shape]` is a `Compound` with a label and examples, written once as a def. One sentence each in `docs/concepts.md` would teach a Symfony reader two concepts for free and spend no concept budget.
- **Readonly classes.** `OperationContext` (`src/Resource/OperationContext.php:29-60`) and `Condition` are already immutable property by property. `final readonly class` (PHP 8.2) says so in one word and stops a subclass from adding mutable state. Property hooks and asymmetric visibility would be the natural next step, for example an abstract hooked property in place of `ConfigObjectStorage::CONFIG`, but the module supports PHP 8.3 (`README.md:43`). Until the minimum is 8.4, use a typed class constant, `public const string CONFIG` (PHP 8.3).
- **Named-argument constraints.** Core's constraint factory already honors `#[HasNamedArguments]` (core `ConstraintFactory.php:67-70`), and `LabeledChoiceConstraint` uses it (`src/Plugin/Validation/Constraint/LabeledChoiceConstraint.php:94`). The option arrays in `addConstraint('Range', [...])` are still unchecked by phpstan. A misspelled option fails only when the constraint is instantiated: I ran `create('Range', ['maxx' => 5])` through core's constraint manager with `ddev drush php:eval` and got `Error: Unknown named parameter $maxx`. `Rule::constrain()` validates its options at the call (`Rule.php:142-158`), which is the right model. The build could instantiate each declared constraint once, through the factory, so a typo is refused at build with the key's name in the message. That costs nothing at runtime, because the build is not on the request path for a cached form.

## 4. Cut

- **The config object's storage subclass.** `NewsletterStorage` (`modules/data_surface_first/src/Storage/NewsletterStorage.php`) is a file whose only content is a constant. Decision `docs/decisions.md:1350-1358` says a storage service "cannot be handed a name per resource". In Symfony's container that is not true. The compiler pass that already instantiates `#[Resource]` and registers its storage (`ResourceCollectorPass.php:142-149`) could register one `ConfigObjectStorage` service per resource, with the config name bound as an argument, from `#[Resource('first.newsletter', storage: new ConfigObject('data_surface_first.newsletter'))]`. The decision's other reason, that this "would put a storage detail on the resource", already holds for `storage: NewsletterStorage::class`. The first resource would drop from two PHP files to one and keep its four concepts. A forgotten `CONFIG` would also stop being a runtime `LogicException` (`src/Storage/ConfigObjectStorage.php:211-212`) behind an empty default (`:71`).
- **`ResourceInterface`.** It is empty (`src/Resource/ResourceInterface.php:25-27`), and the attribute already decides the kind (`docs/decisions.md:52-58`). `StructureInterface` must stay, because a plugin that is its own structure carries no attribute (`src/Resource/StructureInterface.php:9-10`). A resource could implement `DefinesInputsInterface` alone. This is minor, and only worth doing alongside change 1.
- **The engine helpers on author-facing types.** This is the `DoesNotApply` move from change 2, plus `AccessAnswer::kindOf()` and `ACCOUNT_CONTEXTS` (`AccessAnswer.php:86`, `:195`), which an access class author never calls.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `DoesNotApply::here()` (`DoesNotApply.php:77`) | `DoesNotApply::Here` | An enum case is a guaranteed singleton (change 2). |
| `Omitted::value()` (`Omitted.php:71`) | `Omitted::Value` | One spelling for both sentinels. |
| `Rule::require()` (`Rule.php:180`) | `Rule::setRequired()` | `require` is a language construct. The author writes `->setRequired(TRUE)` on the same key one line up in `defineInputs()`. |
| `Rule::requires()`, `Rule::rulesOut()` (`Rule.php:257`, `:247`) | `isRequired()`, `isRuledOut()` | The getters read as verbs next to the setter verbs. Core's getter is `isRequired()`. |
| `narrow($key)->when($c)` | `narrow($key, when: $c)` | Change 4. |
| `narrow($key)->when($c)->doesNotApply()` | `ruleOut($key, when: $c)` | Change 4. |
| `#[Alters(resource: ...)]` (`src/Resource/Attribute/Alters.php:34`) | `#[Alters(of: ...)]` | It names a structure as often as a resource, and `#[Variant(of:)]` and `#[Operation(of:)]` already say `of`. It is almost always passed positionally, so the rename is close to free. |
| `addDefinition(): static` (`BlueprintAdditionsInterface.php:78`) | returns the definition | `add()`, `attach()` and `attachList()` return what they declared. This one alone returns the blueprint. |
| `AccessAnswer::answer(): string`, `kindOf(): string` | `Access` and `AccessKind` enums | Change 2. The wire values `open`, `locked`, `omitted`, `static` and `runtime` are unchanged. |
| `'third_party_settings.<module>'` as an alter's key | `mount()` | Change 5. The path stays the stored path. |
| `#[Resource]` (`src/Resource/Attribute/Resource.php:23`) | keep | PHP's manual lists `resource` as a soft-reserved word. Symfony would write `#[AsResource]`, but Drupal's attributes (`#[Block]`, `#[Hook]`) carry no `As`, and consistency with core is worth more. Recorded so it is a choice, not an accident. |

None of these proposals renames anything that keeps the word surface under `docs/decisions.md#the-product-keeps-its-name`. The enums keep their wire strings, and `x-surface.*` is untouched.

## 6. Verdict

I would adopt it for settings pages and plugin configuration today. The static declaration, attribute discovery and the narrowing check are what I would have designed, and the authoring classes pass phpstan level 9 untouched. The caveat is the set of places where a fact is written twice or checked only at build: the operation id and `creates`, the sentinels, the refiner's interface type and the rule's optional `when()`. What would remove my hesitation is change 1, because an operation that states its facts on the attribute is what every door, including the catalogue, can then read without running anything.
