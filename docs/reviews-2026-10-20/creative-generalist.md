# DX review: the creative generalist

## 1. Standpoint

I review as an API designer who has shipped schema-first systems in TypeScript (zod, tRPC, react-hook-form), Rust (serde, typed builders), Swift (Codable, result builders), GraphQL and Protobuf, asked to look for delight rather than mere correctness. I hold the module to one test: a key, a tag or a rule is said once, in one vocabulary, and the language and the build catch the rest before a person does, without magic PHP cannot carry.

## 2. The five changes that would most improve the authoring experience

### 1. Say a key once: emit, then check, the config schema from the resource

**Problem.** The module's promise is "declared once", yet the first settings page declares every key twice and some defaults three times. `sender_name` is a `string` labeled "Sender name" in config schema (`modules/data_surface_first/config/schema/data_surface_first.schema.yml:5-7`) and again in `add('sender_name', 'string', t('Sender name'))` (`modules/data_surface_first/src/Resource/NewsletterResource.php:33`); `weekly` and `500` sit in `config/install/data_surface_first.newsletter.yml:3-4` and in `default:` (`NewsletterResource.php:38,46`). Drift is caught only at the first save ("a key it does not describe is refused", `docs/first-resource.md:84-85`), which is the latest moment a typed system should find it. The roadmap already names this "first among Drupalisms" and leaves it open (`ROADMAP.md:170-173`).

**Change.** A Drush command that writes the `mapping` of a `ConfigObjectStorage` resource from its sealed declaration, plus a drift check in the test the contract diff already runs: every declared key has a schema entry of a compatible type, and every schema key is declared or listed as storage-only. The schema stays a committed file and stays the storage's truth, so the reason in `docs/decisions.md:1360` ("The whole object is checked") is untouched: generation removes the hand-sync, not the schema.

Before, two files to keep in step by hand:

```yaml
# data_surface_first.schema.yml:5-10
    sender_name:
      type: string
      label: 'Sender name'
    sender_email:
      type: email
      label: 'Sender email'
```

After, one command and one assertion:

```
ddev drush data_surface:config-schema first.newsletter --write
```

```php
// In the module's own baseline test, beside ContractBaselineTest.
$this->assertConfigSchemaAgrees(NewsletterResource::class);
```

The committed newsletter schema is exactly what such a generator would emit, key for key and label for label, which is the evidence that the mapping is mechanical for the common case. What the generator cannot know (`label` versus `string` translatability, `nullable`, `requiredKey`, `FullyValidatable`, see `docs/storage.md:76-103`) is why I would ship it as "write once, then check", the `protoc` plus `buf breaking` model, not as runtime derivation.

**Cost.** A command and a test trait; docs only on the first page (step 2 becomes "generate, then commit"). No new concept, no behavior change. **Breaks** nothing: no contract moves, and adopters' hand-written schemas pass the check or are told precisely where they differ.

### 2. A bare operation is an attribute, and an operation with a path is a route

**Problem.** Twenty-seven operations spell the same five lines to say "a singleton is configured": `#[Operation(...)]` on a static method whose whole body is `return new OperationContext('configure');` (11 in shipped modules, 16 in test modules, by grep; `NewsletterResource.php:24-27` is the first a newcomer copies). The method form exists so "what the operation needs is the method's own signature" (`src/Resource/Attribute/Operation.php:9-11`), but a bare operation has no signature. Then the Form API door is the only door that needs YAML per operation (`modules/data_surface_first/data_surface_first.routing.yml:1-9`), while the React and API doors serve every operation at one generic route (`modules/data_surface_react/data_surface_react.routing.yml:10-16`) and the tool door needs nothing at all.

**Change.** `#[Operation]` becomes `TARGET_CLASS | TARGET_METHOD | IS_REPEATABLE` (today `TARGET_METHOD` only, `Operation.php:35`). On a class it means exactly `new OperationContext($id)`. Either placement takes an optional `path:`, from which a route callback builds `data_surface.operation.<resource>.<operation>` with the generic form and the operation access requirement. The method form stays for any operation that creates, knows identity or narrows, so the escalation reads like the one the module already teaches from a declared rule to a coded refiner.

Before (`NewsletterResource.php:18-27` and the routing stanza):

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }
```

After, which with change 1 is the whole "hello resource" on one screen:

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  permission: 'administer data_surface_first',
  path: '/admin/config/services/surface-newsletter',
)]
final class NewsletterResource implements ResourceInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sender_name', 'string', t('Sender name'))->setRequired(TRUE)->addConstraint('Length', ['max' => 64]);
    $inputs->add('sender_email', 'email', t('Sender email'))->setRequired(TRUE);
    // ...
  }

}
```

The page goes from seven files to five plus one generated, and loses `OperationContext` from its imports. I accept the storage subclass as it is: `docs/decisions.md:1350-1358` is right that a per-resource config name on `#[Resource]` would put storage on the resource.

**Cost.** An attribute target widened, a route callback, docs. It is the "two spellings" worry that led the module to reject attributes for rules (`docs/decisions.md:829-838`); here the two spellings are one attribute in two places, and the method form is needed exactly when there is something to write in it. The four-concept budget holds: still Resource, Operation, Storage, `add()`. **Breaks** nothing; hand-written routes keep working. New route names keep the `data_surface.` prefix, so the product rule is not crossed.

### 3. A refiner returns an effect, in the declared rule's vocabulary

**Problem.** A key that depends on another is narrowed in two vocabularies with opposite semantics. A declared rule's `constrain()` intersects: "the lower maximum and the higher minimum" (`src/Refinement/Rule.php:352-357`). A coded refiner calls core's `addConstraint()`, which replaces (`docs/decisions.md:229`), so authors restate the bound they keep (`RegistrationStep3Resource.php:81` restates `'min' => 1`), hand-roll the intersection (`modules/data_surface_demo_extras/src/Alter/DemoBlockAlter.php:89-91`), cast to reach `setDescription()`, which `DataDefinitionInterface` lacks (`RegistrationStep3Resource.php:80`, `modules/data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php:91`), and dig into core's raw constraint arrays (`RegistrationComplianceAlter.php:89`). The narrowing check refuses the mistakes afterwards, but a second newcomer still had to learn "restate the min" from two pages (`docs/fresh-eyes-2026-10-19.md:29`).

**Change.** Let a refiner return the effect half of a rule as a value: a `Narrow` with `constrain()`, `require()`, `doesNotApply()`, `describe()`, applied by `Rule::apply()`'s tightening, plus one new narrowing word, `except()`, for removing values from a list. The return type is the declaration, as `DataDefinitionInterface|DoesNotApply` already is (`docs/refinement.md:373-377`), and the definition parameter becomes optional when the refiner does not read it. `DoesNotApply::here()` is then just `Narrow::doesNotApply()`. Coded refiners that need a constraint outside the vocabulary (`EntityBundleExists`) keep returning a definition.

Before (`modules/data_surface_examples/src/Resource/RegistrationStep3Resource.php:77-88`):

```php
#[RefinesInput('capacity')]
public static function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
  $seats = Venues::seats($room);
  if ($seats !== NULL && $capacity instanceof DataDefinition) {
    $capacity->addConstraint('Range', ['min' => 1, 'max' => $seats])
      ->setDescription(t('Up to @seats for the @room.', [/* ... */]));
  }
  return $capacity;
}
```

After:

```php
#[RefinesInput('capacity')]
public static function capacityOfRoom(string $room): Narrow {
  $seats = Venues::seats($room);
  return $seats === NULL ? Narrow::nothing() : Narrow::constrain('Range', ['max' => $seats])
    ->describe(t('Up to @seats for the @room.', [/* ... */]));
}
```

This is the effect-system idea without the machinery: the refiner computes, the engine applies, and the effect can only narrow by construction. It also removes the deepest seam in the mental model. A rule is a condition and an effect; each half is either declared (a `Condition`, a `Rule` effect) or computed (watched parameters, a returned `Narrow`). Three concept rows become one idea.

**Cost.** One class (or `Rule` split into its condition and its effect), the refiner dispatcher reading one more return type, docs. **Breaks** nothing: every existing refiner keeps its signature. The examples' contracts are unchanged except that a computed description shows only where the effect tightened, which is what a declared rule already does (`Rule.php:362-369`).

### 4. A union's tags live on its variants

**Problem.** A slot is a tagged union, and its tags are written in three places that must agree: the deciding key's list (`RegistrationStep3Resource.php:54-56`, `'choices' => ['free', 'paid']`) and each variant's attribute (`modules/data_surface_examples/src/Structure/PaidTicketStructure.php:20`, `FreeTicketStructure.php:20`). The labels are in none of them: a plain `Choice` makes "each value its own label" (`src/Plugin/DataSurfaceOptionsResolver/ChoiceOptions.php:19,57`), so the flagship example's select and its pinned contract offer `free` and `paid` raw (`tests/fixtures/contracts/registration.step3.json:126-135`). The list is not just noise: the engine already derives the deciding key's values when no list is declared (`src/DataSurfaceBuilder.php:469-474`), and a declared list refuses any variant another module adds unless that module also extends the choices (`DataSurfaceBuilder.php:475-487`), which quietly closes a slot the glossary calls "always open" (`README.md:245-250`). Eight of the repository's ten slots declare their tags this way, every one a plain `Choice` with no labels (the other two are chosen by a field type).

**Change.** `#[Variant(..., label:)]`, and when the deciding key declares no list the slot writes a `LabeledChoice` from its variants' values and labels. The examples drop their redundant lists, so the union is declared where zod and Swift declare it: on its cases.

Before:

```php
$inputs->add('pricing', 'string', t('Pricing'), default: 'free')
  ->setRequired(TRUE)
  ->addConstraint('Choice', ['choices' => ['free', 'paid']]);
$inputs->attachBy('ticket', by: 'pricing')
  ->setLabel(t('Ticket'));
// PaidTicketStructure.php:20
#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'paid')]
```

After:

```php
$inputs->add('pricing', 'string', t('Pricing'), default: 'free')
  ->setRequired(TRUE);
$inputs->attachBy('ticket', by: 'pricing')
  ->setLabel(t('Ticket'));
#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'paid', label: new TranslatableMarkup('Paid'))]
```

`docs/decisions.md:2781` ("No OpenAPI discriminator on a slot") stands: the tag remains a sibling stated by `if`/`then`. This changes only where the tags are written.

**Cost.** One optional attribute argument and a branch in `attachBy()`. **Breaks**: the contracts' `title`s move from `free` to `Free`, a label change the evolution table calls compatible (`docs/evolution.md`, "A label ... changed"); baselines are re-pinned, no stored value moves.

### 5. A list is one blueprint call

**Problem.** After a scalar, the most common settings key is a multi-select, and it is where the blueprint hands the author core's raw constructors: `DataDefinition::create()`, then `new ListDataDefinition(['type' => 'list'], $day)`, then `addDefinition()` (`modules/data_surface_examples/src/Resource/DoorsResource.php:49-56`): nine times in the modules' and tests' PHP, and eight more in the docs. The reason is sound for the class (`ListDataDefinition::create()` asks a service, `docs/declaring-a-resource.md:419-423`) but it need not be the author's problem. And `addDefinition()` returns the blueprint (`src/Resource/BlueprintAdditionsInterface.php:78`) where `add()`, `attach()` and `attachList()` return the thing declared (`:62`, `:175`, `:220`), so the list must be labeled before it is handed over: the chain's shape changes under the reader's hand.

**Change.** `addList($key, $item, $label, default:)`, `$item` a type, a shape class or a definition, returning the `ListDataDefinition`, built by the engine, which may reach the typed data manager.

Before (`DoorsResource.php:49-56`):

```php
$day = DataDefinition::create('string')
  ->addConstraint('LabeledChoice', ['choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')]]);
$days = (new ListDataDefinition(['type' => 'list'], $day))
  ->setLabel(t('Days'))
  ->setRequired(TRUE);
$inputs->addDefinition('days', $days, default: ['fri', 'sat']);
```

After:

```php
$day = DataDefinition::create('string')
  ->addConstraint('LabeledChoice', ['choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')]]);
$inputs->addList('days', $day, t('Days'), default: ['fri', 'sat'])
  ->setRequired(TRUE);
```

**Cost.** One method, docs (the recipes page loses its one paragraph of core trivia). It is `add()`, so no concept. **Breaks** nothing; the contracts are identical.

## 3. Borrow

- **References as values (serde, typed builders).** `add()` already returns the definition object. Let `Condition::equals($frequency, 'monthly')` and `narrow($send_day)` accept that object, resolved to its key by identity inside the blueprint, with strings still accepted. The build catches a misspelled key today with a good message (`src/ResourceBuild/Resources.php:2632,2668`); what this adds is rename and find-usages in the IDE. No magic, no wrapper, nothing new on the wire. Attribute arguments (`#[RefinesInput('region')]`) stay strings, which PHP forces.
- **Generate, commit, diff (Protobuf and buf).** The contract diff (`docs/evolution.md:201-256`) is already this model and the best idea in the module for adopters. Change 1 extends it to config schema; the same step could emit a readonly reader class per resource, the PHP answer to `z.infer`, so code that reads the newsletter settings stops writing `->get('max_recipients')` by string. It is codegen, committed and checked, which is static declaration carried one step further.
- **Effect annotations, kept.** The static and runtime refiner split, enforced structurally, by a guarded container and by an agreement check (`docs/refinement.md:274-336`), is an effect system done honestly in PHP. I would borrow nothing here and point other ecosystems at it.
- **Deprecation as metadata (GraphQL `@deprecated`).** `ROADMAP.md:333` has it. It is the missing compatible step between "key added" and "key removed", which the evolution table can only call breaking; I would pull it forward.
- **Not Swift result builders.** PHP has no such feature, and the module's own rule, "a flat list, no `if`, no loop" (`docs/resources.md:166`), is the honest PHP equivalent. A DSL would cost the static readability that makes the class the contract.

## 4. Cut

- **The two empty kind interfaces.** `ResourceInterface` and `StructureInterface` declare nothing (`src/Resource/ResourceInterface.php:25-27`, `src/Resource/StructureInterface.php:21-23`); the attribute decides the kind (`docs/decisions.md:52`), and discovery uses the interface only to check that it matches the attribute (`src/ResourceBuild/ResourceRegistry.php:823`). Implement `DefinesInputsInterface` and let the attribute speak; keep the two as deprecated aliases.
- **Engine words out of the author's glossary.** The README promises "twenty-three words" (`README.md:131`). An author needs about twelve. Blueprint, layout, data surface, definition, resolver, context, access answer and host can move to an "engine words" list, and the Smithy umbrella sense of "shape" (`README.md:143-152`) belongs in the decisions page, since in code `#[Shape]` names only the scalar kind.
- **Three rows into one in `docs/concepts.md`.** With change 3, "Declared rule" (line 24), "Refiner" (line 25), "A key that does not apply" (line 26) and "Runtime refiner" (line 27, a flag on the refiner) are one row: a rule, a condition and an effect, each declared or computed. With change 2, "Operation context options" (line 29) is reached only when a method form is needed. Forty-one rows become thirty-seven, and none of the four marked **1** changes.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `attachBy($key, by:)` | `slot($key, by:)` | The glossary word is Slot (`README.md:245`); the method is the one concept a reader cannot find by its name. |
| `with(Structure::class)` | `mixIn(Structure::class)` | `with` is the most generic word in fluent PHP, and the concept row is "Mixin" (`docs/concepts.md:33`). |
| `attach('third_party_settings.<module>', ...)` in an alter | `mount(Structure::class, reads:)` | An alter's `add()` mounts by itself, yet its `attach()` spells its own mount path (`RegistrationComplianceAlter.php:61,63`); `ROADMAP.md:339` already names `mount()`. |
| `addDefinition()` for a list | `addList()` (change 5) | Returns the blueprint, unlike every other declaring call. |
| `DoesNotApply::here()` | `Narrow::doesNotApply()` (change 3) | One effect vocabulary for declared and computed rules. |
| `#[UsesStructure]` with no argument | also accept `#[UsesStructure(self::class)]` | "Uses structure" with nothing reads as missing an argument; `self::class` says "I am my own". |
| `#[Variant(of:, key:, value:)]` | add `label:` (change 4) | A tag without its label renders as a machine value. |
| README's opening "A *data surface* is the description of a group of values" (`README.md:3`) | "Data Surface describes a group of values..."; keep lowercase "data surface" for the sealed object (`README.md:153`) | The first sentence and the glossary give the word two senses. Keeps the word, so it does not cross `docs/decisions.md#the-product-keeps-its-name`. |
| Route names for change 2 | `data_surface.operation.<resource>.<operation>` | Keeps the product prefix, as the product rule asks for route names. |

None of these touches a wire identifier, a tool id or an `x-surface` key.

## 6. Verdict

Yes, I would adopt it for admin settings that an agent also calls: one class buys a form, a tool, two honest JSON Schemas and a React page that agree, and the never-stricter discipline is more rigorous than anything I have shipped with zod or GraphQL. The caveat is the authoring surface around that core: keys said twice with config schema, union tags said three times, and a coded refiner that speaks core's replacing `addConstraint()` while a declared rule speaks a tightening vocabulary. The one thing that would turn "yes, for settings" into "yes, by default" is change 1: a key declared once, with config schema emitted and checked from the resource.
