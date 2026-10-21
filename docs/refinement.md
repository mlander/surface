# Refinement, contributions, and cacheability

A resource or structure says what a key may hold. Refinement says what
it may hold *given what the other keys hold*: which variants exist for
the chosen casing, which bundles exist for the chosen entity type, which
fields exist on that bundle. This page says who is allowed to change a
resource or structure, when, by how much, and how long the answer is good for.

It is the contribution-model chapter: read
[Declaring a resource](declaring-a-resource.md) first for how a resource
or structure of your own is built, [Alters](resources.md#alters)
for what an alter is, and [Options and resolvers](options.md) for how the
lists of allowed values this page narrows are declared.

One rule governs the whole page:

> **Widening is a build-time act. After a data surface is advertised,
> nothing may make it accept a value it did not already accept.**

That is what makes an advertisement worth reading. A form, a validator,
a config action, a REST client and an agent all read the same data
surface, and none of them can be surprised later by a value space they
never saw.

## The two roles

Every module touching a resource or structure is in one of two roles, and
each role has exactly one permission.

| Role | When | May | May not |
| --- | --- | --- | --- |
| **Contributor** | Build time: an alter's `alterInputs()` | Add keys, mounted under its own module's name; offer more values on a fixed list with `extendChoices()`; reword a label or description with `describe()` | Add bare top-level keys, take anything away, change a key's type, contribute a value somebody already owns |
| **Refiner** | Refinement, per request: a rule declared with `narrow()`, or a `#[RefinesInput]` method | Narrow one key, reading the siblings it watches | Touch a value its class did not contribute, hand back a value it was not given |

The **owner** of a resource or structure, the `#[Resource]` or
`#[Structure]` class that declares it, is simply the first contributor.
Its keys and its `#[RefinesInput]` methods are contribution number one, and they are held to the same rules
as everybody who arrives later.

Nothing removes. A site that must hide an owner's key or value by
policy is a later concept, not a role here.

### Contributing

An alter contributes in `alterInputs()`:

```php
#[Alters(DataSurfaceDemoFormatter::class)]
final class DemoFormatterAlter implements AltersInputsInterface {

  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->extendChoices('variant', [self::RIBBON => $this->t('Ribbon')]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

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

A key an alter adds lands at `third_party_settings.<module>.<key>`:
advertised, validated and machine-visible, the way core stores
third-party settings. A formatter or widget host has one extra rule to
know about there: it prunes saved settings against its static defaults
array, so the mounted key has to appear in that array too. See
[Generated forms](forms.md).

### Declaring a rule

Most rules between keys are a condition and an effect: without a license
the capacity stops at a hundred; an insured parcel is signed for; a note
is for freight alone. Such a rule is declared, in the blueprint, beside
the keys it is about:

```php
use Drupal\data_surface\Refinement\Condition;

$inputs->narrow('capacity')
  ->when(Condition::absent('license'))
  ->constrain('Range', ['max' => 100])
  ->because($this->t('Up to 100 without an event license.'));
```

That is example 4's alter (`RegistrationComplianceAlter`), on example
3's capacity, reading the license it mounted itself. Declare the rule
when its condition and effect fit the vocabulary below; write a refiner
when the effect must be computed (the stewards for a capacity,
`ceil($capacity / 50)`, stays coded) or the condition is runtime.

| Condition | Holds when the key | Stated as the `if` |
| --- | --- | --- |
| `Condition::present($key)` | holds a value | `{"properties": {k: {"not": {"type": "null"}}}, "required": [k]}` |
| `Condition::absent($key)` | holds nothing, or does not apply | `{"properties": {k: {"type": "null"}}}` |
| `Condition::equals($key, $value)` | holds the value | `{"properties": {k: {"const": v}}, "required": [k]}` |
| `Condition::in($key, $values)` | holds one of them | `{"properties": {k: {"enum": [...]}}, "required": [k]}` |
| `Condition::atLeast($key, $n)`, `atMost()` | holds a number of at least, at most, n | `{"properties": {k: {"type": "number", "minimum": n}}, "required": [k]}`, `maximum` |
| `Condition::above($key, $n)`, `below()` | holds a number above, below, n | the same with `exclusiveMinimum`, `exclusiveMaximum` |
| `Condition::not($c)` | `$c` does not hold | `{"not": c}` |
| `Condition::allOf(...$cs)`, `anyOf()` | every one, at least one, holds | `{"allOf": [...]}`, `{"anyOf": [...]}` |

A rule says its condition with `->when($c)`, or with `->unless($c)` for
one whose effects hold where `$c` does not: the same rule as
`->when(Condition::not($c))`, read without the double negative, and one
condition either way. "The door code does not apply unless after-hours
access is on":

```php
$inputs->narrow('door_code')
  ->unless(Condition::equals('after_hours', TRUE))
  ->doesNotApply();
```

The conditional requiring a key wherever no rule rules it out tests
`after_hours` itself here, not `not(not(...))`. Each class's `use` line:
[Namespaces](concepts.md#namespaces).

| Effect | Does, while the condition holds | Stated as the `then` |
| --- | --- | --- |
| `->constrain('Range', ['min' => a, 'max' => b, 'message' => ...])` | raises the minimum, lowers the maximum; refuses a value outside the rule's bound with the message, if one is given | `minimum`, `maximum` |
| `->constrain('Length', [...])`, `('Count', [...])` | the same, for a text's length, a list's items | `minLength`, `maxLength`; `minItems`, `maxItems` |
| `->constrain('Choice', ['choices' => [...]])` | keeps only these of the key's own values (`LabeledChoice` reads the same) | `oneOf` of what stays |
| `->constrain('Regex', ['pattern' => ..., 'message' => ...])` | a pattern, where the key has none | `pattern` |
| `->require()` | the key must hold a value | `required`, and its type without `null` |
| `->doesNotApply()` | the key [does not apply](#when-a-key-does-not-apply) | the key `false` |
| `->because($text)` | says why, under the field, where the rule's other effects tightened the key for these values (always for `require()`, or for a description alone) | `description`, kept in the `then` whatever the values |

A bound's `message` is one sentence for the rule, as a `Regex`'s is:
written under Symfony's own option for each bound the rule sets
(`minMessage`, `maxMessage`; `notInRangeMessage` for a range holding
both bounds, and `exactMessage` too for a length or count, for both
bounds at one value), and only where the rule's bound is the one that
stands, so a bound the key already held keeps the key's message.
Symfony reports a range holding both bounds in one message, whichever
side refuses, so word a rule's message on a range for the range ("From
1 to 100 without a license."), and write its numbers into the sentence:
which option reports it decides which placeholders it has. The form and
the tool refuse with it, the condition after it:

```php
$inputs->narrow('late_days')
  ->when(Condition::equals('after_hours', TRUE))
  ->constrain('Count', ['min' => 1, 'message' => t('Choose at least one day for after-hours access.')]);
```

"Choose at least one day for after-hours access. This applies when
After-hours access is on." The documents state the bound, `minItems`,
as they state a key's own, and carry no bound's message.

A condition names keys of the frame the narrowed key sits in, by their
names: the owner's keys, and an alter's own mounted keys by the names it
added them under (the mount around it is written into the `if`). A value
it compares with is of the key's type. The condition is a test of values
as the pipeline accepts them: a key holding nothing is `null` there, and
a key that does not apply is left out, which `absent` reads alike. The
test module's parcel (`resource_test.parcel`) declares every word of
both tables.

A declared rule is a refiner, static by construction: one link per
contributor in the key's chain, after that contributor's coded refiners,
the owner's before an alter's; held to the narrowing check, watched for a
form's AJAX rebuild, and never handed a value its own key refuses. Two
things set it apart:

- **It waits for nothing.** It runs whatever its keys hold, since
  `absent` is about a key holding nothing, and a key only a rule reads
  holds no coded refiner back.
- **It only tightens.** A bound is the lower maximum and the higher
  minimum of the rule's and what the key allows by then; a list keeps
  what both allow. A rule that would widen what the key declares, a
  maximum above its own or a value its list does not offer, is refused
  when the resource is built, and so are a condition comparing a key
  with a value of another type, a rule with no condition (a rule that
  holds whatever the other keys hold is a constraint in
  `defineInputs()`: [recipe](recipes.md#an-unconditional-rule-is-a-constraint)),
  and a rule requiring a key a refiner may rule out.

The full schema states each rule as written, one conditional marked
`x-surface.declared`, without running anything, and the key carries
`x-surface.declared: true`
([served contract](served-contract.md#x-surface)). The schema for one
set of values evaluates it, as the pipeline does. The decision, with
what was weighed: [A rule declared as data](decisions.md#a-rule-declared-as-data).

### Refining

Refinement runs per request, whenever the values a key depends on are
known. In four lines:

1. Deep-clone the advertised definition, and divide its list of allowed
   values into the owner's (everything nobody contributed) and one
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
has a single contribution (nearly every key), the algorithm reduces to
a plain chain of refiners over the whole definition, minus the ability
to widen.

Only the values are merged this way. Everything else about the refined
definition (its other constraints, its description, its default) is
the owner's, because the owner is the one who answers for the key.

#### One method, one rule

A refiner is a method, so a class that refines several keys has several
methods, each named for its rule and each attributed with the one key it
refines: `DemoBlockStructure::bundleOfEntityType()` and
`::fieldOfBundle()`. Nothing dispatches on a key's name. The method
takes the key's definition first and one parameter per sibling it
watches, and runs once every watched sibling has a value. A watched
value its own key's refined definition refuses counts as no value: a
refiner never sees an invalid sibling, so a license in the wrong format
is no license ([decision](decisions.md#a-refiner-never-sees-an-invalid-sibling)).

A refiner is handed a deep clone, so it may mutate what it is given and
hand it back, or answer with a fresh definition. A fresh definition does
not have to carry the declared default or the examples forward: those
are copied across every link, so the rendered form and the accepted
value cannot disagree about what a key starts from.

### Static and runtime refiners

Refinement is one rule, narrow and never widen, but there are two kinds
of rule, and a refiner says which it is:

```php
#[RefinesInput('room')]
public static function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface

#[RefinesInput('room', runtime: TRUE)]
public function openRooms(DataDefinitionInterface $room, string $venue): DataDefinitionInterface
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **Static, the default.** A pure function of the siblings it watches:
  the same values in, the same definition out, whatever is stored,
  whoever asks and whenever. That is a promise the module relies on:
  the served contract runs a static refiner ahead of time, for every
  value its watched siblings could take, and writes each answer down as
  a JSON Schema conditional (`enumerated`), or, where the values cannot
  be listed, says the key is `dynamic` and can be asked about with
  values. Which rooms a venue has is static.
- **Runtime, `runtime: TRUE`.** A rule whose answer depends on something
  no schema can state: a lookup in the database or a remote service,
  uniqueness (a machine name nobody has taken), the current account,
  the time, any service. It promises the schema nothing. The served
  contract never runs it ahead of time, in either document, and marks
  the key `runtime`; only the pipeline, a form and the schema for one
  set of values run it, against those values. Which rooms are closed
  today is runtime: example 4 reads it from State.

Everything else is the same for both. A runtime refiner names the keys
it watches as its parameters, so a form and the React page refine again
when one changes, it is gated the same way, and it is held to the same
narrowing check. Two differences follow from the kind:

- **One that watches nothing runs on every refinement.** A static
  refiner that watches nothing runs once, at build, and its answer is
  what the data surface advertises
  ([decision](decisions.md#a-refiner-that-watches-nothing)). A runtime
  one cannot be fixed at build, so it runs on every refinement,
  validation and submission instead, whatever the key's other refiners
  are waiting for, and the data surface advertises the key as declared.
- **Its answer holds for the moment it was given.** A data surface
  refined through a runtime refiner is not cacheable (max-age 0), and nor
  is a form or contract drawn from it; the unrefined data surface, and
  the full schema, which never run it, keep their own cacheability.

On one key, a class's static methods run before its runtime ones; the
owner's run before an alter's, as before. A key with both kinds is
`enumerated` or `dynamic` for its static refiners, over the siblings
those watch, and `runtime` besides.

#### Enforcing the kind

A static refiner that reads site state is a schema that lies: the full
schema would state, for any values, an answer that was only true when
it was emitted. Take a refiner that caps a capacity at a threshold read
from configuration. It looks static, the same values in and the same
cap out, until somebody changes the threshold: the served contract
emitted before still says the old cap, for every value, and a client
holding it refuses or accepts what the pipeline now does not. That is a
runtime rule, and it says `runtime: TRUE`. So the kind is not taken on
trust; three layers hold a static refiner to it
([decision](decisions.md#static-by-default-runtime-by-declaration)):

1. **Structural, at build: a static refiner is a static method.** The
   one thing a static method cannot have is injected services, so the
   build refuses a `#[RefinesInput]` instance method that does not
   declare `runtime: TRUE`, naming the method and both fixes. A static
   method may still declare runtime, since it can reach the global
   container. So an instance method is always runtime, and a static
   method is static unless it says otherwise. An alter's static refiner
   that only needs translation calls `t()`, or constructs
   `PluralTranslatableMarkup` for a plural, as the owner's does.
2. **Observational, in development and tests: the container is
   guarded.** With assertions on (`zend.assertions = 1`, as in ddev
   and the test suite, never in production, where `assert()` is not
   compiled), every call to a static refiner, from the build, the
   engine's refinement, the pipeline or the emitter's enumeration, runs
   with the global container replaced by
   `Refinement\StaticRefinerContainer`, which throws when asked for a
   service or a parameter:

   > The refiner `Class::method()` is declared static but asked for the
   > service state; a refiner that needs a service must declare
   > runtime: TRUE.

   That catches every `\Drupal` shortcut, since each goes through the
   container: `\Drupal::service()`, `::state()`, `::config()`,
   `::currentUser()`, `::time()`, `::entityTypeManager()`. Two things
   pass. `t()` builds a lazy `TranslatableMarkup` and resolves the
   translation service only when it is rendered, after the refiner has
   returned. The typed data manager is let through by name: core's own
   `DataDefinition::getConstraints()` asks the container for it to add
   a type's default constraints, which is reading a definition, answered
   from code. The real container is put back when the refiner returns or
   throws.
3. **The agreement check.** With assertions on, the emitter asks a
   static refiner a second time for the first combination of every key
   it enumerates, and refuses a different answer ("the refiner ... is
   declared static but answered differently when run again"): a counter
   or a clock reads no service and still is not a function of what it
   watches. And `Tests\data_surface\Traits\StaticRefinerAgreementTrait`
   holds a whole document to it: for every conditional the emitter
   wrote, a fresh `refine()` against the conditional's values, static
   refiners alone, answers the declared key with the conditional's
   `then` laid over it, keyword for keyword, attached structures
   included. The examples and the fixtures with enumerated keys run
   under it, and it holds each declared rule's conditional the other
   way: for sampled values, refining with the rules alone answers what
   the conditionals a standard validator finds met say.

Before any of them runs, static analysis says it: the module's PHPStan
configuration carries a rule (`tests/src/PHPStan/StaticRefinerRule.php`,
registered by `phpstan-rules.php`, run by `scripts/check.sh`) that flags
a `#[RefinesInput]` static method not declaring `runtime: TRUE` whose
body calls `\Drupal::`, naming the method, the call and both fixes. A
module of your own runs it by including that file in its PHPStan
configuration.

None of the three proves purity, and a refiner can still lie in ways
none sees: a static method reading a static property another class
fills from storage, say. What they make is the honest spelling the easy
one: a refiner that needs anything but its arguments is a runtime
refiner, said on the method.

Access has the same split, read from an answer's cacheability rather
than declared, and the same three layers: the build for the account
alone applies only static answers, the guard above asks a static answer
again with the container guarded (refusing services that read site
state, since an access class holds injected ones), and
`StaticAccessAgreementTrait` holds the full schema's access marks to the
build ([Static and runtime access](resources.md#static-and-runtime-access)).

## When a key does not apply

Narrowing says which values a key allows. Sometimes the answer is that
the key has no business being there at all: a send day for a bulletin
that goes out monthly. A refiner says so by answering
`DoesNotApply::here()`, from a method whose return type names it:

```php
use Drupal\data_surface\Refinement\DoesNotApply;

#[RefinesInput('send_day')]
public static function sendDayOnlyWhenWeekly(DataDefinitionInterface $send_day, string $frequency): DataDefinitionInterface|DoesNotApply {
  return $frequency === 'weekly' ? $send_day : DoesNotApply::here();
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

That is the bulletin of the test module, `resource_test.bulletin`. Its
`defineInputs()` declares `send_day` as everything it may ever be, a
required choice of three days, and the refiner says where it applies.

- **It is the narrowest answer**, and nothing else changes: the method is
  gated on what it watches, static unless it declares `runtime: TRUE`,
  held to the same enforcement, and enumerated by the served contract
  like any static refiner. Once a key does not apply, nothing after it
  runs, and nothing may make it apply again.
- **The return type is the declaration.** `DataDefinitionInterface|DoesNotApply`
  tells the build, the full schema and the tool before anything runs that
  the key may be ruled out, which is how they keep it out of `required`
  where they cannot say where it applies. A method that answers it
  without declaring it is refused.
- **Any key**: a key of the class's own, a key an alter mounted (the
  alter's refiner on its own key), and the key an attached child or
  variants are attached at. A parent's refiner of a key holding a child may only
  answer that it does not apply; anything else it hands back must be
  what it was handed.

What each door does with it, for a monthly bulletin with `friday`
stored:

| Door | Does |
| --- | --- |
| The form | Draws no element for it, not even a disabled one; changing the frequency to weekly brings it back over AJAX, showing `friday`, required. |
| The pipeline | Reads it as left out. Left out, `null`, `''` or `friday` is accepted; any other day is refused: "Send day does not apply when Frequency is Monthly." Nothing about it is required. |
| Storage | Keeps `friday`, so the bulletin sent weekly again sends on Friday. |
| The read tool | Leaves it out of `values`. |
| The tool | Lists it as optional, since a listing cannot say where it applies; the pipeline asks for it where it does. |
| The schema for these answers | Leaves it out of `properties`, `required` and `values`, and names it under the frame's `x-surface.doesNotApply` with what it depends on. |
| The full schema | `false` under the monthly conditional, and `required` under the weekly one: `{"if": {...weekly...}, "then": {"required": ["send_day"]}}`. |
| React | Drops the field when the frequency moves to monthly, and brings it back, required, with no request. |

A key that does not apply is not a key narrowed to no values. A list
narrowed to nothing (the extras' ribbon slice below, were it the whole
key) is a select offering nothing, and refuses every value; it applies,
and nothing it could hold is allowed. Nor is it an empty variant, which
applies and has nothing to say. The decision, and why each door does
what it does: [A key that does not apply](decisions.md#a-key-that-does-not-apply).

## The narrowing table

Every refiner link, and every constraint an operation adds, is checked
against what it was handed before its answer is used. The check is
deliberately conservative: it is applied to arbitrary constraints
written by arbitrary modules, and a wrong "this is narrower" is worse
than a refused refinement.

| Change | Verdict |
| --- | --- |
| Adding a constraint | narrower |
| Dropping values from a list of allowed values | narrower |
| Raising a `Length`, `Range` or `Count` minimum, lowering its maximum | narrower |
| Adding a `Length`, `Range` or `Count` bound where there was none | narrower |
| Changing or dropping a `Length` or `Count` `exactly` | **refused** |
| Sharpening a label, description, default or example | neither, and allowed |
| Refining `any` to a concrete type, a map included | narrower: the declared escape hatch |
| Changing the data type to one of its derivatives (`entity` to `entity:node` to `entity:node:article`) | narrower |
| Changing the data type any other way | **refused** |
| Turning off the required flag | **refused** |
| Removing a constraint | **refused** |
| Dropping a choice constraint's list of values | **refused** |
| Adding values to a list of allowed values | **refused** |
| Replacing the options of any other constraint | **refused** |
| Adding or removing a map property | **refused** |
| Answering that the key does not apply (`DoesNotApply::here()`) | narrowest: nothing is compared after it |
| Making a key that did not apply apply again | **refused** |
| Any of the above, inside a map property or a list item | as above |

A derivative is narrower because every value of `entity:node` is a value
of `entity`; the derivative's own properties are its type's to compute,
so only the base's own required flag and constraints are compared, as
they are from `any`. `string_long` is not a derivative of `string`: the
test is the base type followed by a colon.

The conservatism lives in the replaced-options row: two `Regex` patterns
cannot be compared for containment, so replacing one is refused rather
than guessed at. A refiner that needs a different pattern advertises the
narrower one in the first place. For `Length`, `Range` and a list's
`Count` only the bounds are compared (`min`, `max`, and Symfony's
`exactly`, which only narrows by staying what it was), because the rest
of their options are messages. `Count` joined the comparison with
collections, whose Count the parent narrows
([decision](decisions.md#count-is-the-parents)); before, a changed
`Count` was compared whole and refused.

A collection's parent may narrow the list and not its items: a refiner
of the list key that narrows inside the item it is handed is refused,
naming the key ([Collections](resources.md#collections)).

Inside a map the table recurses, which is what holds an attached
structure to it: the child narrows its own keys, and the map its parent
advertises narrows with them. Refining `any` to a map is how the
variants' placeholder resolves to the variant its deciding key chose (see
[Attached structures](resources.md#children)), and how a
`#[RefinesInput]` method on an `any` key describes a child that cannot
be listed statically.

A refusal is a `\LogicException` naming the key, the contributor and
what widened:

> Refining "variant" for the data_surface_demo_extras contribution
> widened what it was given: the LabeledChoice constraint gained the
> values bold. Refinement may only narrow; widening is a build-time act,
> and the build is over.

Two more checks belong to the same contract. Building refuses a
resource or structure in which a key refines, directly or indirectly,
against itself: a cycle is not an infinite loop (each key is walked
once), which is exactly why it has to be refused, since the answer would
otherwise depend on the order the keys happened to be read in. And a
sealed data surface is only read: refinement returns a new data surface
rather than changing the one it was asked of.

What the build step seals is a `DefinitionMap`: one `Entry` per
key, in declaration order, carrying that key's definition, its
contributor, its locked state, its refinement edges, the values
contributed to it and the refiner chains bound to it. The map checks
the rest once, as it is built: a key declared twice, or a
refinement edge naming a key nobody declared, is refused there.
Refinement then rebuilds the map one entry at a time through `with()`,
replacing only the definition, so a narrowed data surface cannot lose
track of who owns what.

## Cacheability

A data surface is computed from live site state. What it advertises
can depend on which entity types exist, which languages are installed,
what the current user may do, so anything that renders or stores a data
surface has to be able to say for how long, and under what conditions,
that rendering holds. A data surface is therefore a
`CacheableDependencyInterface`, and there are three places metadata
enters it:

- **The blueprint.** `BlueprintInterface::addCacheableDependency()` in
  `defineInputs()`, for anything the definitions themselves were read
  from: a key offered only while a module is installed, a label read
  from configuration.
- **Refiners.** A static `#[RefinesInput]` method calls no service and
  is a pure function of the siblings it watches, so it adds nothing of
  its own. The site state it points at is fetched by an options
  resolver, which says how long its answer holds; see the next item.
  (The engine's internal refiner interface merges the metadata of a
  link that is itself a cacheable dependency, but the link the build
  step binds for static methods, `RefinesInputRefiner`, is not one, for
  that reason.) A runtime refiner reads what the data surface does not
  describe, so the link it is bound in, `Refinement\RuntimeRefiner`,
  says max-age 0: a data surface refined through it holds for the request
  that refined it.
- **Option resolvers.** Each resolved option list carries its own
  metadata on its `OptionSet`, which the options widget applies to the
  element it builds.

The form builder applies the refined data surface's metadata to its
container, so a generated form carries both halves: the blueprint's, on the
container, and each list's, on its element. See
[Generated forms](forms.md).

### Site state versus per-request

The split matters, and it is the one thing to get right when caching a
data surface:

- **Cache tags and max-age travel.** They describe how long an answer
  stays true for *everybody*, so they can be stored in a shared cache
  and invalidated for everybody at once. A refined data surface carrying the
  `config:system.site` tag can be cached and will be dropped when that
  config is saved.
- **Cache contexts mean per-request.** A context says the answer would
  have been different for a different request: a different user,
  language, or URL. A refined data surface carrying contexts is only valid for
  the request that produced it. It may be stored in a shared cache only
  by a cache that varies on those contexts; anything that stores it
  without them is storing one visitor's data surface and serving it to the
  next.

The practical rule for anything holding a data surface: **never persist a
refined data surface that carries cache contexts** unless the thing persisting
it varies by those contexts. A data surface with no contexts, a
`PERMANENT` max-age and no tags is static, and may be reused freely.
Anything else is only as reusable as its shortest-lived half.

## Worked example: the extras demo

`data_surface_demo` ships a field formatter whose `variant` key
advertises four values (`bold`, `strong`, `quiet`, `muted`) and one
refiner that narrows them to the ones the chosen casing offers. It knows
nothing about any other module.

`data_surface_demo_extras` is a separate module that owns neither the
formatter nor its form. Its alter of the formatter's structure contributes
a fifth value, and a refiner of its own for it:

```php
#[Alters(DataSurfaceDemoFormatter::class)]
final class DemoFormatterAlter implements AltersInputsInterface {

  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->extendChoices('variant', [self::RIBBON => $this->t('Ribbon')]);
  }

  #[RefinesInput('variant')]
  public static function ribbonInUpperCase(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface {
    return $casing === 'uppercase' ? $variant : $variant->addConstraint('LabeledChoice', ['choices' => []]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The method refines a key the alter offered more values on, so it is
bound as the `data_surface_demo_extras` contribution's refiner, not in
the owner's chain. It is a static method, as every static refiner is,
though its class is a service: only a runtime refiner may be an
instance method.

The advertised key now allows five values, and the data surface records
that one of them belongs to the extras module. At refinement time:

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
- **An alter watches only the keys it mounted.** An alter's
  `#[RefinesInput]` method may refine and watch the keys it mounted, by
  their own names, beside the owner's
  ([Decisions](decisions.md#an-alter-watches-its-own-mounted-key));
  the compliance alter of the examples caps the capacity at a hundred
  until the license is given, by a declared rule reading the license of
  the compliance structure it attached at its mount, whose own refiner
  raises the stewards count with the capacity it reads through a
  [port](resources.md#ports). A watched mounted key is never waited for: it is handed as
  it stands, NULL while empty. Another module's mounted key, and any
  key inside a map that is not a mount, still needs the dotted paths.
- **Storage.** For config-backed values, widening is a schema alter and
  the resource or structure follows; storage gates the write either way.
