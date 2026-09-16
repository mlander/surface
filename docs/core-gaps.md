# Core gaps

The rule this module holds itself to is to use core's own data
definitions to their fullest before extending anything. Every place
core's vocabulary falls short is recorded here with the interim spelling
chosen for it and the upstream issue it maps to.

This is the issue-facing artifact. If you are reading the module as part
of [the core issue](https://www.drupal.org/project/drupal/issues/3622144),
this is the page that says what would have to change in core for the
interim spellings to go away.

Every interim spelling is contained on purpose: each is written and read
in exactly one place, so the day core lands its version, that place
becomes a pass-through and no consumer changes.

## The table

| Gap | Interim spelling here | Upstream |
| --- | --- | --- |
| A definition cannot declare a default value | The definition array key `default_value`, written and read through `DefinitionMetadata` | Issue drafted: defaults on data definitions |
| A definition cannot declare examples | The definition array key `examples`, same pass-through | Issue drafted: example values on data definitions |
| `Choice` holds bare values, so allowed values cannot carry meaning | `LabeledChoice`, a `Choice` subclass carrying `labels` and `descriptions` | Issue drafted: labels on `Choice` |
| Core has existence constraints for plugins, bundles, extensions and config, but none for languages | The `LanguageExists` constraint, written in core's own style | Propose beside the ones that exist |
| Nothing says a value is fixed in this context | Surface-level `lock()` / `isLocked()`, not a definition flag | Possibly nothing needed; revisit |
| No `text` / multiline string type | The definition **setting** `multiline` on a `string`, which the string widget renders as a textarea | Candidate: a `text` primitive, parity with config schema's `type: text` |
| No `patternProperties` / `additionalProperties` on maps | Not needed by current consumers; maps are closed here | [#3556242] |
| `required` conflates non-empty, nullable and present | `NULL` and `''` are "not configured"; everything else is a value | The union/nullability architecture discussion |
| `required` defaults differ between the definition world and the input world | Core's `DataDefinition` is optional by default. Providers here say which they mean rather than relying on it | None; a convention to document |

## Defaults and examples on definitions

`DataDefinitionInterface` can describe a value's type, label, description,
settings, constraints and whether it is required — but not what value it
starts from, and not what a valid one looks like. Every layer built on
top has grown its own spelling: Field API has
`getDefaultValue()`/`getDefaultValueLiteral()`, plugin contexts have
`ContextDefinition::getDefaultValue()`, config keeps defaults outside the
schema entirely in `config/install` YAML, and every contributed API that
describes inputs with data definitions re-adds a `default_value` property
to its own wrapper.

The practical cost is at the edges. Core can emit JSON Schema from
normalizers, and JSON Schema has standard `default` and `examples`
keywords, but a schema derived from typed data can never populate either,
because the source metadata has nowhere to hold them. A form generator
has nothing to feed `#placeholder`, so a definition whose constraint is a
regex renders as an empty box and the pattern is discovered only on
validation failure.

Here both live on the definition, under the definition array keys the
core drafts propose, written and read through `DefinitionMetadata`. Each
accessor delegates to the definition's own core method when one exists,
so the class becomes a pass-through the day core lands.

Note that curated examples and `FieldItemInterface::generateSampleValue()`
are complements, not substitutes. `generateSampleValue()` answers "give
me something valid" and needs the field layer and instantiation; an
example answers "show me what this usually looks like" and is plain
metadata.

## LabeledChoice, and the ask being "labels on Choice"

A `Choice` constraint knows which values are allowed and nothing about
what they mean. The labels live somewhere else — almost always a form's
`#options` array — and the two are kept in agreement by hand.

Concrete cases in core today: `node.type.*.preview_mode` is constrained
in config schema to `0`, `1` and `2`, while the words Disabled, Optional
and Required exist only in `NodeTypeForm`. Every `#config_target` form
rendering a select declares `#options` in the form class while the config
schema declares the `Choice` under the same property, and nothing checks
that the two match. `OptionsProviderInterface` does carry labels, but it
hangs off the instantiated data object, so nothing working from
definitions alone can reach them.

The result is validation without meaning and meaning without validation,
held by different layers.

`LabeledChoice` is a `Choice` **subclass**, and that choice is the whole
point of decision D4. Every `Choice`-aware consumer already sees it —
core's own `Choice` reading, core's `AllowedValues`, a schema emitter —
so the upstream ask becomes "add labels to `Choice`" rather than "accept
a new constraint". Had it been a parallel constraint, every consumer
would have needed teaching about it, one at a time.

It is written to be module-agnostic for the same reason. The constraint,
its validator and `LanguageExists` carry nothing specific to this module:
no surface types in their signatures, options named as core would name
them (`choices` keyed by value, `labels`, `descriptions`), messages
matching core's `Choice`, and no dependency on the options resolver. The
resolver layer consumes them; they never know it exists.

The same rule applies to the resolver plugin type itself. It should read
as "options from constraints", a core-shaped service, not a surface
feature — which is why nothing about surfaces appears in a resolver's
signature. See [Options and resolvers](options.md).

## LanguageExists

Core validates that a plugin exists (`PluginExists`), that a bundle
exists (`EntityBundleExists`), that an extension exists
(`ExtensionExists`) and that a config object exists (`ConfigExists`).
Nothing says "this is a language code", so a language key is either left
unchecked or checked by a hand-written constraint in every module that
needs one.

`LanguageExists` here follows the existing ones in style: the list is
resolved rather than declared, a permission option (`allowLocked`, as
`PluginExists` has `allowFallback`) rather than an inline list, and a
message naming the offending value in a placeholder. It is the obvious
next existence constraint to propose upstream.

## The typed data prototype cache

`TypedDataManager::getPropertyInstance()` caches field item prototypes by
root data type and property path, **not** by field config object. So
asking two different unsaved `FieldConfig` objects for their first item
hands back two clones of the first one's item — and a target built from
the second writes to the first one's entity.

This is not theoretical. A tool hits it immediately, because it describes
an unsaved field while refining its inputs and creates another while
executing, in one request.

The workaround here is to build items from
`FieldConfigBase::getItemDefinition()`, which a field config caches on
itself and binds to itself. `data_surface_tool`'s `FieldSurfaceLocator`
and `DataSurfaceFieldTypeTrait` both do that. It is a candidate core
issue in its own right; nothing about it is specific to surfaces.

## Tool API: constraints matched by name, not by `instanceof`

`ContextDefinitionNormalizer::applyConstraintSchema()` reads exactly two
constraints when emitting a schema, `Choice` and `AllowedValues`, and it
matches them by **plugin id**. So a value list declared as anything else
— `LabeledChoice`, or an existence constraint such as `PluginExists`,
`Country` or `LanguageExists` — does not reach the advertised schema at
all, even though `LabeledChoice` *is* a `Choice`.

This is the one place decision D4 does not pay off yet, and it is the
reason the bridge carries a workaround: it asks `data_surface.options`
for the list, which is the one place that reads a constraint as one, and
adds a plain `Choice` carrying those values beside the constraint that
named them. The duplicate goes away when the Tool API matches by
`instanceof` rather than by plugin id. That is recorded as a Tool
follow-up in the bridge's own docblock rather than worked around by
changing the Tool API.

Four more gaps the bridge found, all listed in
`modules/data_surface_tool/README.md`:

- A context definition has no settings and no examples, so type settings
  and examples are dropped in conversion.
- Defaults do not reach the schema for maps, lists, or falsy values: the
  normalizer emits `default` only on the scalar branch and only when the
  value passes a truthy test.
- Labels have nowhere to go even when the values do. JSON Schema's `enum`
  is a bare list and the normalizer has no convention for per-value
  labels.
- `setLocked()` has no effect below the top level.

## Core facts worth keeping straight

Verified during the survey; several contradict common assumptions.

- `ConfigEntityBase` has no `validate()`. Config entities are never
  schema-validated on save.
- `Config::save()` casts through schema but never validates.
  `Config::validate()` does not exist; only `ConfigImporter::validate()`.
- `FullyValidatable` is a marker whose validator is a no-op. Only the
  config action manager checks it, and only *after* saving.
- `ConfigFormBase::copyFormValuesToConfig()` is private static, despite
  the class docblock inviting overrides.
- Formatter and widget settings are pruned against static
  `defaultSettings()` on display save.
- `BlockPluginTrait::submitConfigurationForm()` skips `blockSubmit()`
  entirely when the form carries any error.
