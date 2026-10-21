# DX review: a Laravel developer (2026-10-20)

Reviewed on branch `next` at fad37cc. Paths are relative to the module
root unless they start with `core/`.

## 1. Standpoint

I review as a senior Laravel developer who declares settings and models
as Eloquent models, form requests (`rules()`, `messages()`,
`authorize()`), API resources and Filament or Nova panels. I hold the
module to expressiveness per line: how many lines and concepts it takes
to say "this key, this type, these values, required unless that one is
off", and whether a dependent rule reads as one sentence the way
`exclude_unless:after_hours,true` does.

## 2. The five changes that would most improve authoring

### 1. A settings page is declared three times: generate two of them

**Problem.** The newsletter's four keys are written in three files. The
config schema names each key's type and label
(`modules/data_surface_first/config/schema/data_surface_first.schema.yml:1-16`),
the install file gives each a value, two of which repeat the class's
`default:` (`modules/data_surface_first/config/install/data_surface_first.newsletter.yml:3-4`
against `modules/data_surface_first/src/Resource/NewsletterResource.php:38`
and `:46`), and the class says type, label, default and constraints
again. The schema is not optional: `ConfigObjectStorage::prepare()`
throws when none describes the object
(`src/Storage/ConfigObjectStorage.php:130-133`). A fully validatable
object adds a `FullyValidatable` root and a `nullable: true` per optional
key (`modules/data_surface_examples/config/schema/data_surface_examples.schema.yml:220-241`),
which is the class's required flags restated. Laravel duplicates too
(migration, `$casts`, rules), but nobody types the derived file:
`make:*` and `schema:dump` write it. The roadmap already names this gap
as "the parallel declaration every critique named first among
Drupalisms" (`ROADMAP.md:170-174`), with runtime schema discovery as
the cure.

**Change.** Before discovery-time emission, ship the cheap half: a Drush
generator, `drush data_surface:scaffold first.newsletter`, that seals the
resource and prints (or writes with `--write`) its config schema mapping
and its `config/install` file. Type from the data type, label from the
label, `sequence` for a list, `mapping` for an attached child, the
`third_party_settings` sequence for an owner, `nullable: true` for each
optional key when asked for `--fully-validatable`, and install values
from declared defaults. A required key with no default is reported, not
invented.

```yaml
# Before: written by hand, data_surface_first.schema.yml:1-16
data_surface_first.newsletter:
  type: config_object
  label: 'Newsletter settings'
  mapping:
    sender_name:
      type: string
      label: 'Sender name'
    # ... three more keys, each restating the class
```

```bash
# After: generated from NewsletterResource, committed as today
drush data_surface:scaffold first.newsletter --write
```

**Cost.** One Drush command and a step on the first page, no new
concept; the settings page stays at four. **Breaks.** Nothing: the YAML
is still committed, storage still validates against it, and neither
document changes. The schema stops being a second hand-written check,
which it never was in intent; it remains the config system's contract
for import and translation.

### 2. A bare operation is a method of one line: let the attribute stand alone

**Problem.** 38 of the 68 `#[Operation]` methods in the module, and all
12 in the examples and `data_surface_first`, return
`new OperationContext('<their own id>')` and nothing else (grep of
`#[Operation(` followed by that return). The id is written twice and
must agree, since a context whose id differs is refused
(`docs/decisions.md:386-392`). Laravel's `Route::resource()` and
`Route::view()` give the conventional case without a method; you write
one only when there is code.

**Change.** `#[Operation]` also targets the class
(`src/Resource/Attribute/Operation.php:35` is `TARGET_METHOD` today),
repeatable, and on the class it means exactly the bare context: no
parameters, `new OperationContext($id)`. Discovery reads class
attributes beside the method loop (`src/ResourceBuild/ResourceRegistry.php:527-559`);
derived operations already reach an `OperationDefinition` without an
attributed method (`src/ResourceBuild/ResourceRegistry.php:760-778`).

```php
// Before: NewsletterResource.php:18-27
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  /**
   * Configures the newsletter, whose settings always exist.
   */
  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }
```

```php
// After
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
final class NewsletterResource implements ResourceInterface {
```

**Cost.** A second place an operation may be written. The rule decision
rejected an attribute spelling because "two spellings of one rule would
be documented twice" (`docs/decisions.md:838`); the difference here is
that the class form carries no code at all, so there is nothing to keep
in step, and anything that knows, creates or narrows is still a method.
**Breaks.** Nothing: tool ids, routes and both documents are the same;
`of:` operations on alters stay methods.

### 3. `unless()`: say "applies only when" without a double negative

**Problem.** The recipe every toggle copies
(`docs/recipes.md:149-151`, from
`tests/modules/data_surface_resource_test/src/Resource/Library/OpeningHoursResource.php:82-84`):

```php
$inputs->narrow('door_code')
  ->when(Condition::not(Condition::equals('after_hours', TRUE)))
  ->doesNotApply();
```

Read aloud: when not after-hours equals true, it does not apply. Two
negations for "the door code applies only with after-hours access". The
same shape is at `OpeningHoursResource.php:94-96` and
`tests/modules/data_surface_resource_test/src/Resource/Parcel/ParcelResource.php:126-128`,
and the third newcomer used it for weekdays. The schema then carries a
double negation as well: the "required wherever it applies"
conditional wraps the ruling condition in `not`
(`src/Contract/ContractEmitter.php:1336`), giving
`{"if": {"not": {"not": ...}}}` (`tests/src/Kernel/DeclaredNarrowingTest.php:526`),
which the third fresh-eyes log called "correct but awkward to read"
(`docs/fresh-eyes-2026-10-19b.md:51`). Laravel has a word for the
inverted condition (`exclude_unless`, `required_unless`) so the rule
states the positive.

**Change.** `Rule::unless(Condition $condition)`, which sets
`Condition::not($condition)` under the same one-condition check
`when()` makes (`src/Refinement/Rule.php:118-124`), and the emitter
writing `c` where it would write `not(not(c))`.

```php
// After
$inputs->narrow('door_code')
  ->unless(Condition::equals('after_hours', TRUE))
  ->doesNotApply();
```

**Cost.** One method on the rule. The condition vocabulary stays closed
(`docs/decisions.md:786-799`): `unless` is a word of the rule, not a new
condition, and it states the same `if`. **Breaks.** `unless()` breaks
nothing. Collapsing the double negation changes the text of a
conditional where a rule already uses `not`, which is the test module's
parcel and opening hours only; the shipped examples' baselines in
`tests/fixtures/contracts/` hold no such rule. Semantically the
documents are identical.

### 4. `addList()`: a list of values is a key like any other

**Problem.** `add()` takes key, type, label and default
(`src/Resource/BlueprintAdditionsInterface.php:62`). A list of scalars
falls off it into core construction with a literal array, the label
moves to a setter and the default to a second method:

```php
// Before: modules/data_surface_examples/src/Resource/DoorsResource.php:49-56 and 67-69
$day = DataDefinition::create('string')
  ->addConstraint('LabeledChoice', [
    'choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')],
  ]);
$days = (new ListDataDefinition(['type' => 'list'], $day))
  ->setLabel(t('Days'))
  ->setRequired(TRUE);
$inputs->addDefinition('days', $days, default: ['fri', 'sat']);

$greeters = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('string')))
  ->setLabel(t('Greeters'));
$inputs->addDefinition('greeters', $greeters, default: []);
```

That spelling appears nine times in five files. The stated reason
(`docs/declaring-a-resource.md:419-423`) is that
`ListDataDefinition::create()` asks the typed data manager and a class
reaches for no service: a reason not to call `create()`, not a reason
the blueprint cannot build the list. The blueprint already has a plural
verb for every other case, `addEach()` for a map from the site and
`attachList()` for a list of structures
(`src/Resource/BlueprintInterface.php:91`,
`src/Resource/BlueprintAdditionsInterface.php:220`); the one missing is
the one a settings page needs most. Laravel writes it as
`'days' => ['required', 'array'], 'days.*' => [Rule::in([...])]`.

**Change.** `addList(string $key, string|DataDefinitionInterface $item,
string|\Stringable $label, mixed $default = NULL): ListDataDefinition`,
where `$item` is a type name, a shape's class or an item definition.

```php
// After
$day = DataDefinition::create('string')
  ->addConstraint('LabeledChoice', [
    'choices' => ['fri' => t('Friday'), 'sat' => t('Saturday'), 'sun' => t('Sunday')],
  ]);
$inputs->addList('days', $day, t('Days'), default: ['fri', 'sat'])
  ->setRequired(TRUE);

$inputs->addList('greeters', 'string', t('Greeters'), default: []);
```

**Cost.** One method, no concept: a list is already a key. **Breaks.**
Nothing; `addDefinition()` stays for maps.

### 5. A vocabulary from an enum, by core's own convention

**Problem.** `docs/options.md:74-110` recommends a backed enum when the
code also matches on the values, and the demo writes one with its own
projection, `DemoVariant::choices()`
(`modules/data_surface_demo/src/DemoVariant.php:81-83`). Core already
ships that convention: `OptionsEnumTrait`, with `label()` and
`asOptions()` (`core/lib/Drupal/Core/Utility/OptionsEnumTrait.php:10-29`),
which `NodePreviewMode` uses (`core/modules/node/src/NodePreviewMode.php:13-15`).
For an integer enum the declaration then needs two arrays
(`docs/options.md:132-141`). Laravel says `Rule::enum(Status::class)`.

**Change.** `LabeledChoice` takes `enum:`, a class using
`OptionsEnumTrait`, resolved in its constructor
(`src/Plugin/Validation/Constraint/LabeledChoiceConstraint.php:95-111`)
into the canonical spelling: case values as `choices`, `asOptions()` as
`labels`.

```php
// Before: docs/options.md:138-141
->addConstraint('LabeledChoice', [
  'choices' => array_column(NodePreviewMode::cases(), 'value'),
  'labels' => NodePreviewMode::asOptions(),
]);
```

```php
// After
->addConstraint('LabeledChoice', ['enum' => NodePreviewMode::class]);
```

Everything after the constructor (the resolver, the narrowing table,
`extendChoices()`, the emitter) sees the plain arrays it sees today, so
a cached form serializes nothing new. This respects "an enum is not a
fence" (`docs/options.md:118-125`): the enum declares the owner's half
only, an alter still offers values outside it, and stored values stay
scalars; nothing is cast. **Cost.** One constraint option and a
paragraph. **Breaks.** Nothing; `DemoVariant` could `use
OptionsEnumTrait` and drop `choices()`.

## 3. Borrow

- **A message on every rule effect.** A Laravel rule's sentence is
  always overridable (`messages()`). A declared rule takes a message
  only for `Regex` (`src/Refinement/Rule.php:61-68`), so "at least one
  day while after-hours access is on" written as a rule gets core's
  sentence with the condition after it (`docs/recipes.md:196-200`).
  Allow `message` (and Symfony's min and max message names) on a rule's
  `Range`, `Length` and `Count`. It transfers cleanly: a message is in
  neither document, and the narrowing check compares bounds only and
  already ignores the rest of the options (`docs/refinement.md:444-446`),
  so nothing can become stricter than the runtime.
- **Generators.** `make:request` and friends are why Laravel's
  duplication does not hurt; change 1 is that idea.
- **Already here, and better.** `sometimes` and PATCH semantics are the
  module's absent-keeps, null-clears rule (`docs/decisions.md:2958-2972`),
  stated in both schemas, which Laravel leaves to each controller. The
  refusal sentence that names the condition ("Door code does not apply
  when After-hours access is off.", `docs/recipes.md:160-161`) is what
  Laravel's `:attribute` placeholders never manage for dependent rules.
- **Not borrowed: closures.** `Rule::requiredIf(fn () => ...)` and
  `$validator->sometimes(..., fn)` are rules only PHP can read, which is
  why a Laravel API's OpenAPI document drifts. The closed vocabulary
  (`docs/decisions.md:786-791`) is right, and the module already has the
  honest home for a closure: a refiner, marked runtime when it reads
  state.
- **Not borrowed: casting to enums.** Eloquent's `$casts` would turn a
  value an alter contributed into a fatal, for the reason
  `docs/options.md:118-125` gives.

## 4. Cut

Held to the inventory in `docs/concepts.md:17-59`:

- **Fold "A key that does not apply" (`docs/concepts.md:26`) into the
  Declared rule and Refiner rows.** It is one effect of a rule
  (`->doesNotApply()`) and one answer of a refiner. A Laravel reader
  meets `exclude_if` as one rule among rules, not a concept. What it
  must not be confused with (a key narrowed to nothing,
  `docs/refinement.md:398-403`) fits in a clause of the Declared rule
  row.
- **Fold "Runtime refiner" (`docs/concepts.md:27`) into the Refiner
  row.** It is a flag on the same attribute, and its question ("does a
  rule read site state?") is the Refiner row's skip column.
- **Fold "Operation context options" (`docs/concepts.md:29`) under
  Operation once change 2 lands.** The class form is the bare context,
  and a method is where the options live; the row's own skip column
  already says "It is one `new OperationContext('configure')`".
- **Not cut: the `ConfigObjectStorage` subclass.** The decision
  (`docs/decisions.md:1350-1358`) gives two reasons. "It would put a
  storage detail on the resource" is weak, since `storage:` already is
  one; "a storage class is an autowired service, one per class" is real.
  At three lines it is not worth a second spelling, and the generator in
  change 1 can write it.
- **Not cut: mixins and ports.** A mixin is a Laravel trait with a
  contract; a port is the price of the wall, and the wall is what makes
  a def reusable across owners.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `$inputs->with(Structure::class)` (`src/Resource/BlueprintAdditionsInterface.php:268`) | `$inputs->mixIn(Structure::class)` | `OperationContext::with*()` return a copy (`src/Resource/OperationContext.php:73-165`); the blueprint's `with()` mutates, and its concept is called Mixin (`README.md:255-259`). PHP API only, so it does not cross the product rule; nine call sites, one in the examples (`modules/data_surface_examples/src/Resource/PressResource.php:41`). |
| `attachBy($key, by: $sibling)` (`src/Resource/BlueprintInterface.php:49`) | `slot($key, by: $sibling)` | The method says "by" twice and not the concept; the glossary's word is Slot (`README.md:245-250`). PHP only. |
| `Rule::describe($text)` (`src/Refinement/Rule.php:214`) | `Rule::because($text)` | The same verb on the blueprint rewords a key, with a different signature (`src/Resource/BlueprintAdditionsInterface.php:124`); the rule's says why the key tightened, which `->because(t('Up to 5 in a weekly issue.'))` reads as. PHP only. |
| `setRequired(TRUE)` on a list | keep | It means present and not null; the empty list satisfies it (`docs/decisions.md:2931-2945`), which is Laravel's `present`, not `required`. Core's flag, so no rename; `docs/recipes.md:168-175` already warns. |
| `ResourceCatalogue`, `docs/catalogue.md` | `ResourceCatalog`, `docs/catalog.md` | American spelling. A class name and a docs path; neither is a product name or on the wire. |
| `licence` (`#[Shape('event.licence')]`, `modules/data_surface_examples_compliance/src/Shape/Licence.php:18`; the stored key) | `license`, in prose only | American spelling. The shape id is a def id in both documents and the key is stored in config, so renaming them crosses the wire rule and needs a version (`docs/evolution.md`); not worth it for an example. |
| `narrow($key)`, `DoesNotApply::here()` | keep | Both say exactly what they do; `narrow` keeps the only-tightens rule in the author's mouth. |

## 6. Verdict

I would adopt it for settings pages and for admin configuration an agent
must call: one class gives me what a form request, an API resource, an
OpenAPI document and an MCP tool would take four hand-synced files to
give me in Laravel, and absent-keeps, null-clears and a schema never
stricter than the runtime are better than what I ship. The caveat is
the tax around the class: the config schema and install file written by
hand, a method per bare operation, and lists and inverted conditions
that read worse than everything else in the blueprint. The one thing
that would make it an unreserved yes is change 1: once the schema and
the install file are generated from the class, the class is the single
declaration the README promises.
