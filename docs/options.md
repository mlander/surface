# Options and resolvers

A list of allowed values is needed twice: once as a constraint (is this
value allowed?) and once as form `#options` (what do the values mean?).
Written twice, the two can drift. Core has the same split —
`OptionsProviderInterface` hangs labels off the instantiated data object
rather than the definition, so nothing working from definitions alone can
see them.

The principle here is one line:

> **The constraint is the single source of truth. Options are derived
> from constraints, never declared beside them.**

Two mechanisms carry it: a constraint that can hold labels, and a plugin
type that reads any constraint as a list.

## LabeledChoice

`LabeledChoice` is a `Choice` subclass. Because it *is* a `Choice`, every
`Choice`-aware consumer already sees it — core's own `Choice` reading, a
schema emitter, a form builder — and the upstream ask becomes "labels on
`Choice`" rather than a second constraint. Its validator is
`ChoiceValidator`, named rather than subclassed, so what it accepts is
exactly what `Choice` accepts, down to the comparison. Labels and
descriptions play no part in validation.

Nothing in it knows about surfaces, widgets or resolvers. The resolver
layer reads the constraint; the constraint never reads the resolver.

### Two spellings

Which one is meant is decided by whether `labels` is present.

**The canonical spelling** is `Choice`'s own list of values with the
meaning beside it. It is the only one that can express integer values,
because an integer-keyed map of labels cannot be told from a list of
values:

```php
$definition->addConstraint('LabeledChoice', [
  'choices' => [0, 1, 2],
  'labels' => [
    0 => new TranslatableMarkup('Disabled'),
    1 => new TranslatableMarkup('Optional'),
    2 => new TranslatableMarkup('Required'),
  ],
  'descriptions' => [2 => new TranslatableMarkup('Every request has to carry one.')],
]);
```

**The map convenience** is how a list with meaning is most often written
by hand: `choices` on its own is read as `value => label`.

```php
$definition->addConstraint('LabeledChoice', [
  'choices' => ['star' => new TranslatableMarkup('Star'), 'flame' => new TranslatableMarkup('Flame')],
]);
```

A bare list of values with no meaning attached is core's `Choice`, not
this constraint, so `choices` alone is never read as one. Both `labels`
and `descriptions` may be partial: a value with no label of its own is
named by its value.

## The resolver plugin type

A resolver reads one kind of constraint as a list of allowed values.
Classes live in `Plugin/DataSurfaceOptionsResolver` and are declared with
the `#[DataSurfaceOptionsResolver]` attribute, which names the validation
constraint plugin the resolver reads.

```php
public function applies(Constraint $constraint): bool;
public function resolve(Constraint $constraint, DataDefinitionInterface $definition): OptionSet;
```

Nothing about surfaces appears in either signature: a resolver is handed
a constraint and a data definition, both core types, and answers with an
`OptionSet` — allowed values as keys mapped to their labels, optional
per-value descriptions, and a `CacheableMetadata` saying how long the
answer may be reused.

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
list — or `NULL` when no constraint on the definition names one.

When more than one constraint resolves, the answer is their
**intersection**, because every constraint on a definition has to hold at
once. Labels come from the first set, and the cacheability of both is
merged, since an answer is only reusable for as long as its
shorter-lived half.

Answers are memoized per request, keyed by the definition object. There
is no invalidation because there is nothing to invalidate: the memo dies
with the request, and a refined definition is a different object, so
narrowing can never be served a stale list.

A constraint plugin that is not registered is logged as a warning rather
than thrown: the definition renders as free input and the constraint
still refuses invalid values when they are validated.

## The stock resolvers

| Plugin id | Constraint | Values from | Labels from | Cacheability |
| --- | --- | --- | --- | --- |
| `labeled_choice` | `LabeledChoice` | its `choices` | its `labels`, falling back to the value | permanent |
| `choice` | `Choice` | its `choices` | the value itself | permanent |
| `plugin_exists` | `PluginExists` | the named manager's definitions, filtered by `interface` when the constraint sets one | array key, then public property, then `getLabel()`/`getAdminLabel()` | the manager's own, when it is a `CacheableDependencyInterface` |
| `entity_bundle_exists` | `EntityBundleExists` | bundle info for the constraint's `entityTypeId` | bundle labels | tag `entity_bundles` |
| `language_exists` | `LanguageExists` | the language manager; locked languages only when `allowLocked` is set | language names | tag `config:configurable_language_list` |
| `country` | `Country` (the address module's) | `address.country_repository`, narrowed to the constraint's `availableCountries` when it names any | localized country names | tag `countries`, context `languages:language_interface` |

"Permanent" means the default `CacheableMetadata`: no tags, no contexts,
`PERMANENT` max-age. A list read from the constraint alone cannot go
stale, because the constraint is the list.

The three spellings of a label in the `plugin_exists` resolver are not
defensiveness: plugin definitions genuinely have three, and core's own
entity type definition keeps its label behind an accessor. Without the
third spelling an entity type select reads as machine names.

## Declare the constraint, resolve the list

Prefer an existence or validity constraint plus a resolver over a
`LabeledChoice` whose choices are assembled at build time.

The address port is the worked example. Its countries and languages
started as labeled choices filled from the country repository and the
language manager, and the cost was not the code: **a definition that
cannot be written down without services forces the whole surface to be
built at runtime**, in a service, away from the class it describes. Said
as the `Country` and `LanguageExists` constraints instead, the
definitions became literal enough to sit in a `#[DataSurfaceAware]`
attribute on the field item itself, harvestable without instantiation,
and the live lookup moved to the one place that already answers for
freshness.

So: when a list is "every one of a kind that this site has", say that and
write a resolver. Keep `LabeledChoice` for a vocabulary that really is
literal, such as hidden/optional/required.

`LanguageExists` is a constraint this module adds because core has none.
Core validates that a plugin, a bundle, an extension or a config object
exists, but nothing says "this is a language code", so it is the obvious
next existence constraint to propose upstream beside the ones that exist.
It is written to be module-agnostic, in core's own style — `allowLocked`
as the permission option, a placeholder message — for the same reason
`LabeledChoice` is.

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

Declare the cacheability honestly. It is what a generated form applies to
the element it builds, and it is the difference between a select that
updates when a role is added and one that does not. The distinction
between tags, which travel into a shared cache, and contexts, which mean
"this answer was for one request", is in
[Refinement](refinement.md#site-state-versus-per-request).

To take a resolver out of circulation site-wide, or point it at a
different source:

```php
function my_module_data_surface_options_resolver_info_alter(array &$definitions): void {
  $definitions['country']['class'] = 'Drupal\my_module\ShippingZoneOptions';
}
```

Removing a definition there removes a way of reading that constraint from
the whole site, not just from forms.
