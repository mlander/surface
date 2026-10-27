# Options and resolvers

A list of allowed values is needed twice: once as a constraint (is this
value allowed?) and once as form `#options` (what do the values mean?).
Written twice, the two can drift. Core has the same split:
`OptionsProviderInterface` hangs labels off the instantiated data object
rather than the definition, so nothing working from definitions alone can
see them.

The principle here is one line:

> **The constraint is the single source of truth. Options are derived
> from constraints, never declared beside them.**

Two mechanisms carry it: a constraint that can hold labels, and a plugin
type that reads any constraint as a list.

## Core's question

Core asks "which values may this hold, and with what labels" through
one interface, `OptionsProviderInterface`. Field items implement it, and
core's options widgets read it. It has two halves, and each maps to one
of the two documents:

- **Possible** (`getPossibleValues()`, `getPossibleOptions()`): every
  value the key may hold, read from the declared definition. The full
  schema states this list.
- **Settable** (`getSettableValues()`, `getSettableOptions()`): what may
  be set now, read from the definition refined for the values in hand.
  The schema for these answers states this list. With no refined
  definition, it is the declared list.

One line gets a key's provider from the options service:

```php
$provider = \Drupal::service('data_surface.options')->provider($declared, $refined);
```

The labels are the `OptionSet`'s, and `$provider->cacheability()` holds
the lists' tags, contexts and max-age, which core's interface has no
place for. A key with no list answers an empty array. The account core's
methods take is not read: a definition is refined for an account when
it is built, so hand in the one built for that account. A resolver stays
the source of every list; the provider only reads what the resolvers
answer (why: [decision](decisions.md#our-options-answer-cores-question)).
The select widget reads its `#options` this way.

## LabeledChoice

`LabeledChoice` is a `Choice` subclass. Because it *is* a `Choice`, every
`Choice`-aware consumer already sees it (core's own `Choice` reading, a
schema emitter, a form builder), and the upstream ask becomes "labels on
`Choice`" rather than a second constraint. Its validator is
`ChoiceValidator`, named rather than subclassed, so what it accepts is
exactly what `Choice` accepts, down to the comparison. Labels and
descriptions play no part in validation.

Nothing in it knows about resources, structures, widgets or resolvers.
The resolver layer reads the constraint; the constraint never reads the
resolver.

### Three ways to write it

Which one is meant is decided by which of `choices` and `labels` are
given. All three allow the same values and offer the same labels.

**`labels` alone** is the one to reach for whenever the values carry
meaning: the keys of `labels` are the allowed values. It is the spelling
for integer values. An integer-keyed map in `choices` reads as a list of
values to `Choice` and to a person reading the declaration, so an integer
value's meaning goes in `labels`:

```php
$definition->addConstraint('LabeledChoice', [
  'labels' => [
    0 => t('Disabled'),
    1 => t('Optional'),
    2 => t('Required'),
  ],
  'descriptions' => [2 => t('Every request has to carry one.')],
]);
```

**`choices` as a map** is the same thing for string values, where no key
can be mistaken for a position: `choices` on its own is read as
`value => label`.

```php
$definition->addConstraint('LabeledChoice', [
  'choices' => ['star' => t('Star'), 'flame' => t('Flame')],
]);
```

**`choices` as a list with `labels` beside it** is `Choice`'s own form,
for a label set that covers only some of the values. It is also the
form the module writes back after narrowing or extending a list.

```php
$definition->addConstraint('LabeledChoice', [
  'choices' => ['star', 'flame', 'sash'],
  'labels' => ['star' => t('Star')],
]);
```

A bare list of values with no meaning attached is core's `Choice`, not
this constraint, so `choices` alone is never read as one. Both `labels`
and `descriptions` may be partial in the third form: a value with no
label of its own is named by its value.

The three spellings are one declaration to every consumer: the resolver
answers with the same `OptionSet`, the narrowing check gives the same
verdict, and a contribution merges into any of them identically:
`ChoiceSpellingParityTest` is where that is held to.

### One vocabulary, declared once

The map spelling already keeps a declaration from naming a value twice.
When the *code* names the values too (a `match` on a stored setting, a
lookup of what each value offers), a backed enum keeps the whole
vocabulary in one place. Core's `NodePreviewMode` is the precedent:
cases, a `label()` method, and `asOptions()` projecting the two into the
value-to-label map a form takes.

```php
enum DemoVariant: string {

  case Bold = 'bold';
  case Strong = 'strong';
  case Quiet = 'quiet';
  case Muted = 'muted';

  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Bold => t('Bold'),
      // ...
    };
  }

  /**
   * The value-to-label map the short spelling takes.
   */
  public static function choices(): array {
    $choices = [];
    foreach (self::cases() as $case) {
      $choices[$case->value] = $case->label();
    }
    return $choices;
  }

}
```

The structure consumes the projection, and no value is spelled out
twice anywhere:

```php
$inputs->add('variant', 'string', t('Variant'))
  ->addConstraint('LabeledChoice', ['choices' => DemoVariant::choices()]);
```

**An enum is not a fence around the key.** What the key allows is the
constraint, and an alter widens it at build time with
`extendChoices()`: `data_surface_demo_extras` contributes `ribbon`, which
is deliberately not a case in the demo's enum. A contributor answers for
its own value, and the owner's enum is not where that value lives: that
is what a contribution *is*. So code acting on a stored value takes the
string it was handed rather than `DemoVariant::from()`-ing it, and only
the owner's own half of the vocabulary is enumerated. An enum used as a
gate turns every contribution into a fatal.

**When the plain map is enough.** Most vocabularies are named once, in
the declaration, and read back only as options. Those want the map
spelling and nothing else; an enum whose only caller is the declaration
buys a class for no reader. Reach for the enum when the values are also
matched on in code. And when the values are integers, the enum's
`asOptions()` is the whole of the `labels` spelling:

```php
->addConstraint('LabeledChoice', ['labels' => NodePreviewMode::asOptions()]);
```

## The resolver plugin type

A resolver reads one kind of constraint as a list of allowed values.
Classes live in `Plugin/DataSurfaceOptionsResolver` and are declared with
the `#[DataSurfaceOptionsResolver]` attribute, which names the validation
constraint plugin the resolver reads.

```php
public function applies(Constraint $constraint): bool;
public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet;
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Nothing about resources or structures appears in either signature: a
resolver is handed a constraint and a data definition, both core types,
and answers with an `OptionSet`: allowed values as keys mapped to their
labels, optional per-value descriptions, and a `CacheableMetadata`
saying how long the answer may be reused.

`DataSurfaceOptionsResolverBase` answers `applies()` from the declared
constraint plugin id: the resolver serves that constraint's class and
anything extending it, so a constraint refining another is read by the
same resolver unless one of its own is declared. That is why the
`Choice` resolver would read a `LabeledChoice` if the `LabeledChoice`
resolver did not exist.

### The service

`data_surface.options` (`Options\DataSurfaceOptions`) is what consumers
call. `resolve(DataDefinitionInterface $definition): ?OptionSet` walks
the definition's constraints, asks each resolver, and answers with the
list, or `NULL` when no constraint on the definition names one.

When more than one constraint resolves, the answer is their
**intersection**, because every constraint on a definition has to hold at
once. Labels come from the first set, and the cacheability of both is
merged, since an answer is only reusable for as long as its
shorter-lived half.

Answers are memoized weakly, keyed by the definition object: an answer
lives as long as its definition, so a worker that outlives a request (an
MCP stdio server, a queue, a Drush batch) keeps none it no longer uses.
There is no invalidation because there is nothing to invalidate: a
refined definition is a different object, so narrowing can never be
served a stale list.

A constraint plugin that is not registered is logged as a warning rather
than thrown: the definition renders as free input and the constraint
still refuses invalid values when they are validated.

## The stock resolvers

| Plugin id | Constraint | Values from | Labels from | Cacheability |
| --- | --- | --- | --- | --- |
| `labeled_choice` | `LabeledChoice` | its `choices` | its `labels`, falling back to the value | permanent |
| `choice` | `Choice` | its `choices`, an empty list being a list of nothing (one resolved at validation names none) | the value itself | permanent |
| `plugin_exists` | `PluginExists` | the named manager's definitions, filtered by `interface` when the constraint sets one | array key, then public property, then `getLabel()`/`getAdminLabel()` | the manager's own, when it is a `CacheableDependencyInterface` |
| `entity_bundle_exists` | `EntityBundleExists` | bundle info for the constraint's `entityTypeId` | bundle labels | tag `entity_bundles` |
| `language_exists` | `LanguageExists` | the language manager; locked languages only when `allowLocked` is set | language names | tag `config:configurable_language_list` |
| `country` | `Country` (the address module's) | `address.country_repository`, narrowed to the constraint's `availableCountries` when it names any | localized country names | tag `countries`, context `languages:language_interface` |
| `data_surface_reference` | `DataSurfaceValidReference` | core's selection handler for its `entityTypeId` and `bundles`, for the account asking, while there are at most `data_surface.settings:reference_list_limit` ([below](#a-key-that-refers-to-an-entity)) | the entities' labels, sorted | the type's list tags, `References::CONTEXTS` for it, the settings' tag |
| `data_surface_referenceable_type` | `DataSurfaceReferenceableType` | the entity types of its `interface` and its `targets` of which the account may reference one, `exact` | entity type labels | tag `entity_types`, each type's list tags and contexts |

"Permanent" means the default `CacheableMetadata`: no tags, no contexts,
`PERMANENT` max-age. A list read from the constraint alone cannot go
stale, because the constraint is the list.

The three spellings of a label in the `plugin_exists` resolver are not
defensiveness: plugin definitions genuinely have three, and core's own
entity type definition keeps its label behind an accessor. Without the
third spelling an entity type select reads as machine names.

## Which constraint to write

A rule on a key is said by a constraint. Take the first of three steps
that can say it: each step down tells a client less before it calls
(why: [decision](decisions.md#a-rule-is-a-keyword-first-a-list-second-code-last)).

**1. A stock constraint with data options.** `Range`, `Length`,
`Count`, `UniqueItems`, `NotBlank`, `Regex`, `Email`, `Choice` and
`LabeledChoice` are the constraints `ContractEmitter` writes as JSON
Schema keywords (`minimum`, `maxLength`, `minItems`, `uniqueItems`,
`pattern`, `format`, `oneOf`). The
schema reader turns those keywords back into the same constraints, the
React page narrows on them with no request, and a tool caller reads them
in the listing before it calls. JSON Schema has a closed set of
keywords, and a generic validator ignores any other. A constraint the
emitter has no keyword for is in the same place: a client can read its
name, and nothing it can check. Keep
`LabeledChoice` for a vocabulary that is literal, such as
hidden/optional/required.

`UniqueItems` is a constraint this module adds because core has none.
Put it on a list whose items must differ; both documents state it as
`uniqueItems: true`, and a list that repeats an item is refused on the
list, naming the first item repeated. It is Symfony's `Unique`,
registered the way core registers `Count`, and compares items strictly.
`accept()` has already typed every scalar and put every map's keys in
declaration order, so on the accepted list that is the comparison
`uniqueItems` makes ([core gaps](core-gaps.md#15-two-constraints-core-is-missing)).

**2. A list the site decides: name the kind, and a resolver lists it.**
When the allowed values are "every one of a kind that this site has",
say which kind: `PluginExists`, `EntityBundleExists`, `LanguageExists`
or the address module's `Country`, each read by a [stock
resolver](#the-stock-resolvers), or the type string `entity:<type>` for
an entity's id ([below](#a-key-that-refers-to-an-entity)). There is no
code per resource: the constraint names the kind, the resolver lists
it for the site, and both documents state the list as a `oneOf` while it
is short. Past its budget a reference is looked up and a list of
variants or keys from the site is `deferred`
([below](#a-list-the-site-decides-past-its-budget)). A key a static
refiner narrows by such a key is still `enumerated`: one conditional per
value it lists. When no stock constraint names the kind, write one for
the kind, not for a resource, and [its resolver](#writing-a-resolver):
example 2 does for the rooms of the site's venues (`EventVenueRoom`),
and `data_surface_examples_keys` for the keys the site manages
(`ManagedKey`).

The address port shows why this beats a `LabeledChoice` whose choices
are assembled at build time. Its countries and languages started as
labeled choices filled from the country repository and the language
manager, and the cost was not the code: **a definition that cannot be
written down without services cannot sit in a resource or structure
class**, which holds no service. Said as the `Country` and
`LanguageExists` constraints instead, the definitions became literal
enough to sit in `AddressFieldSettingsResource`, readable without
instantiating anything, and the live lookup moved to the one place that
already answers for freshness.

`LanguageExists` is a constraint this module adds because core has none.
Core validates that a plugin, a bundle, an extension or a config object
exists, but nothing says "this is a language code", so it is the obvious
next existence constraint to propose upstream beside the ones that exist.
It is written to be module-agnostic, in core's own style (`allowLocked`
as the permission option, a placeholder message), for the same reason
`LabeledChoice` is.

**3. A constraint of your own, last.** Only for a rule no keyword and no
list can state: a comparison between two keys, uniqueness against what
is stored, a check of site state with a reason of its own. One such rule
needs none: a create's identity held once across what is stored is the
stock `DataSurfaceUnique`, which the build adds where the storage can
say whether a thing exists ("A venue named riverside already exists.";
[A title and its machine name](declaring-a-resource.md#a-title-and-its-machine-name)),
so no resource writes one. The cost: the
schema can only name it under `x-surface.checkedOnServer`, it does not
read back from the schema, the React page cannot narrow on it, and a
caller learns the rule when a call is refused. When you write a
constraint, at this step or for a kind at step 2, extend the stock
constraint closest to it, so its validator, its refusal and its result
code come with it. Example 2's room constraint extends `Choice`: a room
no venue has is refused in `Choice`'s words,
naming the rooms, and its resolver says its list is the whole of the
constraint ([below](#writing-a-resolver)), so both documents state the
rooms as a `oneOf` and name nothing under `checkedOnServer`.

**Example 2, before and after.** Its venue was a string with a
constraint of its own, a `Choice` subclass, a validator that read the
enabled venues and a resolver that listed them. It is now
`add('venue', 'entity:event_venue', t('Venue'))`, step 2 with no code:
its candidates are core's selection handler's, and both documents mark
it `x-surface.reference`. The room keeps what no stock constraint
says, a room of one of the site's venues: one constraint with its
validator and its resolver, and a narrower one its refiner adds for the
venue chosen
([example 2](../modules/data_surface_examples/README.md#example-2-answers-depend-on-answers)).

## A key that refers to an entity

A key that refers to an entity is declared by the data type string of
what it refers to, and holds the entity's id
(why: [decision](decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up)):

```php

$inputs->add('venue', 'entity:event_venue', t('Venue'));    // a venue's machine name

$inputs->add('contact', 'entity:user', t('Contact'));       // a user's id
$inputs->add('page', 'entity:node:article', t('Announcement'));

$inputs->add('thing', 'entity', t('Featured beside'))
  ->addConstraint('DataSurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
```

- **`entity:<type>` and `entity:<type>:<bundle>`** build one key: the
  id, typed as the entity type keeps it (an integer where a content
  entity type's id field is one, a string for a config entity), with
  `DataSurfaceValidReference` naming the entity type and the bundle.
  An entity type the site does not have is refused when the resource
  is built; a bundle it does not have yet is no refusal, and the key
  offers nothing until it has.
- **The bare `entity`** is two keys attached at the key, `entity_type`
  and `id`. `entity_type` lists the entity types the account may
  reference one of, by `DataSurfaceReferenceableType`, which the
  author writes on the key and the build moves onto it: `interface`
  (`ContentEntityInterface` or `ConfigEntityInterface`) and `targets`,
  each entity type allowed with its bundles (`['node' => ['article'],
  'user' => []]`). Internal entity types are never offered. `id` is a
  string, and a static refiner narrows it to the entity type chosen with
  the same `DataSurfaceValidReference`, so the three spellings are one
  mechanism, and the full schema states the id once per entity type, as
  a conditional. The id does not apply while no entity type is chosen,
  and is required once one is. Stored as `{entity_type, id}`.
- **In config, a content entity by its uuid.** A config storage
  (`ConfigObjectStorage`, `ConfigEntityStorage`) stores a reference to a
  content entity as its uuid, since a content entity's id differs
  between sites, and a reference to a config entity as its id, its
  machine name. Every door still sends and answers the id; only the
  stored config holds the uuid, typed `uuid` in its schema. A config
  entity also depends on what its keys refer to. A key keeps the id
  with `->setSetting(References::STORED_AS, References::BY_ID)`
  ([A reference is stored by uuid](storage.md#a-reference-is-stored-by-uuid)).

The candidates are core's entity reference selection handler's: the
`default` handler for the entity type, the bundles as its
`target_bundles`, the label as its `sort` (`References::handler()`),
for the account asking. Its query carries the entity type's access tag,
so what the selection plugin gives for nothing is the access filtering:
a node's leaves out unpublished nodes for an account without "bypass
node access" (with no node grants module), a user's leaves out blocked
users without "administer users", and node grants narrow both lists for
a site that has them. What this module adds: the key spellings, the
labels sorted, the limit, the lookup, the type list and its filters, and
one message for every refusal, "This entity (node: 7) cannot be
referenced.", whether the id names nothing, another bundle or an entity
the account may not reference, so a refusal never says whether an
entity the account cannot see exists (core's own `ValidReference` tells
the first apart).

**The limit.** While the handler counts at most
`data_surface.settings:reference_list_limit` candidates (50 as
shipped), the resolver lists them: the key's options, a select on the
form, a `oneOf` in both documents and an enum in the tool listing. Past
it, the resolver does not apply: the key takes an id, which the
constraint's validator holds to the handler, drawn by core's
`entity_autocomplete` element ([Widgets](widgets.md#the-stock-widgets)),
`x-surface.widget: autocomplete` in both documents, and looked up with
the resource's lookup ([The served contract](served-contract.md#a-lookup)).
The list's cacheability is what the handler read: the entity type's list
tags, the contexts `References::CONTEXTS` names for it
(`user.permissions`, and `user.node_grants:view` for a node), and the
settings' tag, since whether a key lists at all is the limit's. An
answer past the limit lists nothing and carries none of them: it is the
wider answer, so a cached one is never stricter than the site. The
validator, the options and the lookup all ask the same handler, so what
a key lists, what the lookup finds and what a write accepts are one
answer. `References::withLimit()` holds another limit for one call,
saving nothing, which example 16's page uses to show a key past it.

## A list the site decides, past its budget

A reference key's limit has a sibling for variants and keys the site
lists: `data_surface.settings:site_list_limit`, 20 as shipped. A key
whose variants come from the site (`attachVariants(from:)`, a component
per single-directory component) or whose keys do (`addEach()`,
`attachEach()`) is spelled out in the full schema and the tool listing
while the site lists at most that many; past it, both state the key
open and mark it `deferred`, and the deciding key keeps its list of ids
and labels (why: [decision](decisions.md#a-budget-for-what-the-site-decides)).
Where a reference key past its limit is looked up by label, such a key
is fetched per choice: choose the deciding key, then fetch the full
schema with that choice stated, `GET .../schema?choose=/props=<value>`,
the key's `x-surface.deferred.fetch` filled, once per value, and keep it until
the site's list changes
([the fetch per choice](served-contract.md#the-fetch-per-choice)). Or
ask with values, `POST .../schema` over HTTP or the narrowing tool,
`data_surface:<resource>:<operation>:schema`; both answer the chosen
variant's keys exactly
([asking again at the tool door](served-contract.md#asking-again-at-the-tool-door);
why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
`SiteListBudget::withLimit()` holds another limit for one call, saving
nothing, as `References::withLimit()` does.

## Writing a resolver

```php
#[DataSurfaceOptionsResolver(
  id: 'my_module_role_exists',
  label: new TranslatableMarkup('Role exists'),
  constraint: 'MyModuleRoleExists',
)]
final class RoleExistsOptions extends DataSurfaceOptionsResolverBase {

  public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet {
    $options = [];
    foreach ($this->roleStorage->loadMultiple() as $id => $role) {
      $options[$id] = $role->label();
    }
    // The list lives in site state, so the answer is reusable only until
    // that state changes, and what is built from it inherits the tag.
    $cacheability = (new CacheableMetadata())->addCacheTags(['config:user_role_list']);
    return new OptionSet($options, [], $cacheability);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Declare the cacheability honestly. It is what a generated form applies to
the element it builds, and it is the difference between a select that
updates when a role is added and one that does not. The distinction
between tags, which travel into a shared cache, and contexts, which mean
"this answer was for one request", is in
[Refinement](refinement.md#site-state-versus-per-request).

**A list that is the whole constraint.** Both documents state a
resolved list as `oneOf`, and name the constraint under
`x-surface.checkedOnServer` besides, since the schema cannot know the
constraint checks nothing more. A resolver whose list is every value its
constraint accepts and nothing else, as a `Choice`'s is, says so with
`exact: TRUE` on its attribute, and the constraint, and every subclass of
it the resolver reads, is then named under no `checkedOnServer`. Example
2's room does: its constraint is a `Choice` whose list is the rooms of
the venues the site has, read by `EventVenueRoomOptions`
(why: [decision](decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).
A memoized answer is dropped when a tag it was read under is
invalidated, so a definition kept for the rest of a request is answered
afresh, and a tool's definition carrying the list as its enum is cleared
with the tag.

To take a resolver out of circulation site-wide, or point it at a
different source:

```php
function my_module_data_surface_options_resolver_info_alter(array &$definitions): void {
  $definitions['country']['class'] = 'Drupal\my_module\ShippingZoneOptions';
}
```

Removing a definition there removes a way of reading that constraint from
the whole site, not just from forms.
