# Generated forms

A generated form is one more consumer of the pipeline, not a value path
of its own. The widgets collect a raw submitted tree and
`DataSurfacePipelineInterface::accept()` does the rest — coercion,
merging, locking, refusing undeclared keys — so the form produces exactly
what a JSON payload carrying the same strings produces.

The layer has two halves. `data_surface.form_builder`
(`Form\DataSurfaceFormBuilderInterface`) knows how to turn a surface into
a container of elements and read it back. The host traits know how to
satisfy one host family's protocol using it.

## Pick your host family

Every plugin family names its surface class the same way, with
`#[UsesSurface]` on the plugin; the surface class itself is in
[Declaring a surface](declaring-a-surface.md).

| Family | Use | What you write |
| --- | --- | --- |
| Blocks | `Plugin\Block\DataSurfaceBlockBase` | `#[UsesSurface]`, `build()`. |
| Conditions | `Plugin\Condition\DataSurfaceConditionBase` | `#[UsesSurface]`, `evaluate()` and `summary()`. |
| Actions | `Plugin\Action\DataSurfaceActionBase` | `#[UsesSurface]`, `execute()` and `access()`. |
| Field formatters | `Plugin\Field\FieldFormatter\DataSurfaceFormatterBase` | `#[UsesSurface]`, `formatValue()` or `viewElements()`. |
| Field types | `Form\DataSurfaceFieldTypeTrait` on the item class, implementing `Form\FieldSurfaceProviderInterface` | `#[UsesSurface]` and a one-line `defaultFieldSettings()`; `getDataSurfaceTarget()` only when the storage shape differs from the input shape. The surface is built in the `field_settings` context. |
| Any plugin resolving a form class per operation | `Form\DataSurfacePluginForm` | Nothing but `#[UsesSurface]`: list the class under a `forms` key. It is named per operation, so it settles the context's operation at construction. |
| A surface situation served at a route | `Form\DataSurfaceSituationForm` | Nothing but routing, and a cosmetic layer if the page needs one. |
| A standalone form of your own | Nothing | Build the container, call `submit()`. |

Under those sit three traits, and it is worth knowing which is which when
you adopt a family none of the base classes covers:

- **`DataSurfaceConfigurationTrait`** implements `ConfigurableInterface`
  from a surface: defaults come from the surface, and
  `setConfiguration()` runs values through the pipeline rather than
  trusting its caller. Keys the surface does not declare pass through
  untouched, which is what lets host-owned keys (a block's `id`, `label`,
  `provider`; a variant's `weight`; a context mapping) survive
  progressive adoption.
- **`DataSurfaceHostFormTrait`** holds the three form stages —
  `buildDataSurfaceForm()`, `validateDataSurfaceForm()`,
  `submitDataSurfaceForm()` — named for what they do rather than for one
  host's spelling of them.
- **`DataSurfacePluginFormTrait`** names those three for
  `PluginFormInterface`: `buildConfigurationForm`,
  `validateConfigurationForm`, `submitConfigurationForm`.

The split between the last two is not tidiness. A trait method *replaces*
an inherited one, so a block base class that pulled in the public triple
would silently replace the block form it is supposed to extend. A host
whose names match the triple adds `DataSurfacePluginFormTrait` and
nothing else; a host that renames them maps its own names onto the three
bodies. That is why `DataSurfaceConditionBase` and
`DataSurfaceActionBase` use the plugin form trait and
`DataSurfaceBlockBase` does not.

The counted evidence for the claim that one adapter serves a family: the
action base needs no translation methods at all, the condition base five
one-line ones, the block base four. The count tracks how much the host
itself insists on doing, not how much the surface layer costs.

### Two host mechanics worth knowing before you adopt

**Construction order.** Several host base classes call
`setConfiguration()` from inside their own constructor, which asks the
surface for its defaults before any subclass constructor body has run. A
plugin that promotes its collaborators in the constructor signature and
calls the parent constructor last is safe, because promoted properties
are assigned before the body runs. Assigning collaborators in `create()`
after construction is what fails. This is also why the host traits fetch
their services from the container at the point of use rather than
injecting them — a documented exception, not a habit.

**Trait over inheritance.** A host base class that composes core's
`ConfigurableTrait` into itself (actions, search pages, media sources,
CKEditor 5) is adopted by a subclass that simply uses
`DataSurfaceConfigurationTrait`. A trait used in the child overrides
methods the parent flattened from its own trait, so no `insteadof` is
needed — and `insteadof` naming a parent's trait is itself a fatal error.
The deep merge `ConfigurableTrait` would have applied is therefore never
reached, which is the point: the pipeline's merge rule is the only one
that decides what a surface key holds.

## Hosts and the non-drift rule

A generated form is gated twice by the time it writes: once by whatever
let the person reach the page, and once by the pipeline's access stage
when the submit handler runs. Those two must be the same answer, not two
spellings of one intention — a route requirement is checked when the page
is built and the submit arrives later, so a permission revoked in between
is caught only by the half that writes.

The rule: **the surface answers, and every gate reads that answer.**
`SurfacesInterface::access()` is the situation's permission and then
the surface's access class. A plugin host's `surfaceAccess()` asks it in
the host's context, and `submitDataSurfaceForm()` passes that into
`submit()`, so a host gates its own form without writing a line of
access code; a surface with no access class answers neutral, and the
host's own gates stand exactly as they stood. `data_surface_demo_node_type`
is the worked example: its routes' `_data_surface_situation_access`
requirement, the operation link in the content type listing, the
situation form on its way in and out, and the generated tool all ask
`NodeTypeSurface`'s access in the same situation, so nothing in that
module spells the permission twice.

An access refusal has no element to be flagged on — it belongs to the run
rather than to a value — so `flagSurfaceErrors()` reports it as a
form-level error under the reserved `@access` key. See
[the access stage](pipeline.md#access).

## The merge rule

`mergeSurfaceContainer()` puts a surface container inside a form fragment
a host handed over. One line governs it:

> The surface fills in what the host left unsaid and overwrites nothing
> the host said itself.

`#type`, `#tree`, `#attributes` and `#process` stay the host's where the
host declares them. Only the surface's own children and its container
marker are the surface's to place — and the surface's keys do win over a
host key of the same name, because those are the surface's business.

Taking those render keys over is how a fieldset became a container and a
host's own wrapper id disappeared, which is the bug the rule exists to
prevent.

## Wrapper ids and AJAX

The container carries a private marker, `#data_surface_wrapper`, holding
its DOM id. The id itself comes from `Html::getUniqueId()` over the
wrapper key the host passed, so two surfaces on one page cannot share a
wrapper and rebuild each other.

When the host already named its element, **the host's id wins**: the
container is pointed at that id instead, and the generated one is
discarded, including in the `#ajax` already attached to the children.

Every key another key refines against gets an `#ajax` with
`refreshSurface()` as the callback, at any depth: an attached child's or
a chosen slot variant's own dependency is wired too, as a trigger of the
child's own refiners. One exception, on purpose: an element whose
`#type` is a grouping type — `details`, `fieldset`, `container`, which
is what the map widget renders — is left unwired. A `details` is not an
input, emits no change event, and an `#ajax` on it never fires, which is
worse than nothing because the form looks wired and is not. Attaching to
each leaf inside it was considered and rejected: a refiner depends on the
whole value of a key, so a rebuild fired by one leaf would refine against
a half-filled map. A map dependency is declared and left unwired; the
surface still refines when the form is submitted, or when a scalar
dependency is touched.

### What a change replaces

**Only what the change moved, and never the element that was touched.**
Changing a key replaces the keys that refine against it, then the keys
that refine against those, until nothing new is reached: the same
closure the [discard cascade](#the-in-form-half-discarding-orphaned-input)
walks. In the registration example, the venue replaces the room and the
capacity; the room replaces the capacity; the pricing replaces the
ticket slot. In the demo block, the entity type replaces the bundle and
the field, and the bundle replaces the field. Replacing the whole
container, as the form once did, redrew the select the person had just
changed and left the real change — a maximum moving on another field —
looking like nothing happened.

The callback answers with an `AjaxResponse`:

| Command | For |
| --- | --- |
| `ReplaceCommand` | Each dependent, by its own wrapper. A slot whose deciding key moved is replaced whole, by the slot's wrapper. |
| `ReplaceCommand` | Each element placed with `placeRefreshed()` — the situation form's panel — on every rebuild. |
| `RemoveCommand`, then `AppendCommand` | The [stale marker](#stale-values-on-a-form): removed, and appended to the container again when the rebuild left one. It names stale selects across the whole container and may appear or disappear with the rebuild, so it is never replaced in place. |
| `RemoveCommand`, then `PrependCommand` | The messages the request produced — an error on the trigger itself, the one value a refinement request judges — printed inside the container where the render-array path printed them, after the previous request's are taken away. |

Each refinement target, each slot and each placed element gets a
wrapper of its own, a `div` in its `#prefix` and `#suffix`, with its id
on the element under `REFRESH_ID_KEY`. The ids are derived from the
container's id and the element's path below it
(`<container>--shelf--height`), so they are unique wherever the
container's is. They are placed by the container's `#process`, since
the container's id is only final once a host has merged it. A slot whose
deciding key chose nothing renders as its wrapper and nothing else, so
the rebuild that chooses a variant has a place to put it; extraction
reads nothing from it.

Each trigger carries, under `TRIGGER_KEY`, its own path below the
container and the dotted paths it replaces. The dependency edges are
fixed when a surface is sealed, so the closure the previous build wrote
is the closure of this one; each element is then taken from the rebuilt
container. When a dependent cannot be found there — a host or a
cosmetic layer took it out — the callback falls back to returning the
container, which the browser puts in place of the wrapper the trigger
names: coarser, never wrong.

**The ids have to survive the rebuild.** `Html::getUniqueId()` answers
an AJAX request with a random suffix, so a rebuild would name wrappers
the page has never seen, and the elements a partial rebuild leaves in
place keep their old ids. So each trigger posts its container's id with
the request (core's `#ajax['submit']`, under `WRAPPER_INPUT`), and
`buildSurfaceForm()` takes it back when it is one this wrapper key could
have generated. The container keeps the id it was first rendered with
for as long as the page lives.

One consequence of not replacing the trigger: it keeps the options it
was rendered with. A required select first offered on `- Select -`
keeps that option in the page after a choice, while the rebuilt form no
longer has it, so choosing it again is refused as a value the select
does not offer, and printed inside the container. The trigger's own
answer is the one value a refinement request judges, so this is the
error that request can produce, and nothing else is affected.

### `#limit_validation_errors`

Every `#ajax` the builder attached, at any depth, is limited to **that
element's own value path** and nothing wider. Touching one select says one thing about
one key: the host form around the surface is not being answered, and
neither is the rest of the surface.

It was once limited to the whole container, and that one line was a bug
with two halves. A dependent still holding a value the new choice had
just orphaned was inside the limit, so the rebuild flagged it — an error
for a question nobody had asked. And because Form API skips the rebuild
outright once anything has errored, the container came back refined
against the choice that had just been replaced: the dependent's list did
not follow its own parent either. Narrowing the limit closes both.

Nothing is lost by narrowing it. The surface's real validation runs on
submit, through the host's validate stage, where every key has been
answered on purpose.

One consequence to know about, because it is not obvious: Form API
answers a limit by **throwing away every value outside it** before the
rebuild runs. So by the time the container is rebuilt,
`$form_state->getValues()` holds the one key that was touched. That is
why the in-progress overlay is read from the raw input instead — see
[the SubformState lesson](#the-subformstate-lesson).

It is set in a `#process` callback on the container rather than written
at build time, for two reasons that are easy to get wrong:

1. A container does not know its own `#parents` until Form API assigns
   them, so there is no path to limit to at build time — and neither do
   its children, so the callback computes each child's path the way Form
   API is about to.
2. It has to be the container's callback rather than each child's,
   because by the time a child is processed Form API has already taken
   its copy of the triggering element.

## The SubformState lesson

Form API assigns `#parents` from the root of the complete form, so they
are **absolute**. A subform state reads values **relative** to the
fragment it wraps. A host that hands its plugin a subform state — a
block's settings, built with `SubformState::createForSubform()`, is the
common case — therefore has every absolute path looked up one level too
deep.

The failure is silent and expensive. The value comes back `NULL`, the
surface reports the key as "not configured", and that is a spurious
violation for a required key and a stored value replaced by nothing for
an optional one.

Two places resolve it, both by asking the complete form state for an
absolute path: `DataSurfaceWidgetBase::rawValue()` when extracting, and
`DataSurfaceHostTrait::surfaceRefinementInput()` when reading what an
in-progress AJAX rebuild has collected. The second locates the submitted
tree through the triggering element's own position rather than through a
fixed path, which is what makes it nesting-agnostic: the trigger's
absolute `#parents`, less the path below the container it carries under
`TRIGGER_KEY` — one key for a key of the surface, two for a key of an
attached child. If you write a host
adapter of your own, go through those rather than reading `$form_state`
directly.

`surfaceRefinementInput()` reads the **raw input**, not the validated
values, and that is not a detail: a refinement trigger limits validation
to itself, and Form API throws away every value outside a limit before
the rebuild runs. The values are the one touched key by then; the input
is still the whole form as the browser sent it. Reading the values
instead dropped every other in-progress edit on the way through, and
left a chain refining its second link against storage rather than
against the choice made one rebuild earlier.

Hosts do not call it directly. `surfaceFormValues($surface, $stored,
$form_state)` is the one overlay every host builds from: stored
underneath, in-progress edit on top, and the discard rule below applied
between them.

A full submit — the Save button, with or without JavaScript, or a
programmatic `submitForm()` — is the other case the overlay covers. The
build it is processed against runs before Form API knows the triggering
element, so no rebuild input is found; instead the host's
`surfaceSubmissionPath()` names where its container sits in the input,
and the submitted answers are overlaid on the stored values, **with
nothing discarded**: every value was sent on purpose and is judged. That
is what makes the elements the ones the answers ask for. Without it a
select offers the stored venue's rooms and Form API refuses the new
venue's room as a choice it was never offered before the surface is
asked, and a slot flipped in the same request is rendered as the stored
variant, with no element for the chosen variant's keys to arrive in. A
select left on the empty option it was given in place of a stored value
— named in the hidden input the container posts — is read as that
stored value, here and on an AJAX rebuild alike, so it is rebuilt
standing for it rather than as a key that holds nothing.
`DataSurfaceSituationForm` answers `surfaceSubmissionPath()`, since its
container is its own top level key; a plugin host nested inside another
form cannot know its position before Form API assigns it, answers NULL,
and is built from what is stored — so without JavaScript a plugin host
refuses a valid save that moves a parent and its dependent together
([decisions](decisions.md#a-plugin-host-cannot-overlay-a-full-submission)).

## Current values on extraction

`extractSurfaceValues()` takes a `$current` argument: what the surface's
keys already hold, in surface shape, read from wherever the host stores
them. **A host that leaves it out is telling the surface that storage is
empty.**

This is not an optimization. A form is always a partial statement about a
surface, because a key whose element was never rendered — access denied,
removed by an alter, or below a container the host did not build — has
said nothing about its value. Without the stored values to fall back on,
`accept()` would replace every one of them with its declared default.

The same reasoning is why the elements that carry a pipeline into a
static callback also carry `#data_surface_current`: the values the form
started from, as a plain array, so it survives the form cache. See [the
serialization rule](targets.md#the-serialization-rule) for what may and
may not ride on a form array.

`validateSurfaceForm()` takes the same `$current` and for a second
reason: only what a key already holds can be *stale*, so a host that
does not pass the stored values there gets a stale value refused as an
ordinary violation. The host traits pass one array to both calls.

## Two ways a value stops being allowed

A value can stop being allowed for two quite different reasons, and the
treatments are opposite. **The distinction is where the invalidation came
from**, not what the value is.

| | Out of the form, in storage | Inside the form, mid-edit |
|---|---|---|
| What happened | A bundle was deleted, a module uninstalled, a refiner narrowed under a saved value | The person changed a dependency, orphaning what a dependent was holding |
| What it is | A **stored** value that no longer validates | Input nobody submitted: an answer to a question no longer on the screen |
| Treatment | Kept behind the empty option, warning on save | **Discarded**, silently |
| Cleared when | A real submit, and at no other time | Never — nothing was stored to clear |
| Said out loud | A messenger warning, on every save | Nothing at all |

The two halves meet at the Save button. A stored value the rebuild
left behind the empty option because its dependency moved is not stale
on save: the same submission moves the dependency, so the pipeline
refuses it on its element and the person chooses again
([value semantics](semantics.md#stale-values-the-third-state)). Moving
the parent back shows it chosen again.

The two meet without conflicting. If a parent changes while a child was
already standing for a stale stored value, the child still stands for
it under the new parent's list — and the value is still stored, because
a stored value changes on a submit and at no other time.

### The in-form half: discarding orphaned input

On a refinement rebuild the surface is refined against the new values
first, and then every refinement target that the input holds a value for
is tested against its newly narrowed definition with
`OptionSet::allows()`. A value the narrowed definition no longer offers
is **dropped from the input**, and the key falls back, in this order:

1. its **stored** value, when the narrowed definition still offers that;
2. the **empty option standing for it**, when something is stored and
   is no longer offered — the other half of the table, reached from
   here;
3. **nothing chosen**: the empty option, with nothing behind it.

In the second case the key is an **orphan** of the edit: a key it
refines against moved away from what is stored, and the stored value is
not among what the moved key now offers. Its select stands for the
stored value, but nothing below it is refined against that value: the
overlay holds the key unanswered, the same as the empty select the
person is looking at, and records what it stands for under
`DataSurfaceFormBuilderInterface::STANDING_KEY` for the element alone.
So in the registration example, moving the venue away from a stored
room leaves the room standing for it and the capacity back at its
declared limit — not still capped, and described, by a room no longer
on the screen. A stored value the site narrowed away under a dependency
nobody moved is not an orphan: it is stale, kept, and what refines
against it still does, exactly as when the form was first built.

An empty input is never discarded: it is the person having said
"nothing" under a parent that has not moved.

No error and no warning, ever. Nothing was submitted, so there is nothing
to judge and nothing to report.

Three rules keep it honest:

- **Only input is ever dropped.** `discardedRefinementInput()` names
  keys — and, inside an attached child or a chosen slot variant, the
  dotted paths of the child's own keys its own refiners orphaned, asked
  of the child in its own frame; a stored value is never touched by it. A rule that could reach
  storage would be the stale model with the safety taken off.
- **The chain settles in one rebuild, to a fixed point.** Dropping a
  value moves what the next target refines against, and so does holding
  an orphan unanswered; either invalidates that target's input the same
  way. So the surface is refined again after every drop and every
  orphan, and the question asked again, until neither finds anything
  new. Each round adds a key to one of the two sets and none takes one
  away, so it ends within the depth of the longest chain. Changing the
  first of three links resets all three in the one rebuild the person is
  waiting on: the demo block's entity type moved with a bundle and field
  still posted leaves the bundle standing for the stored one and the
  field open again, refined against no bundle at all.
  `DataSurfaceFormBuilder::settleFrame()` is the loop, per frame, and a
  child's own frame is settled the same way.
- **A programmatic submission is exempt.** Its caller said every value on
  purpose, in one statement, so a value the surface refuses is refused
  rather than quietly dropped. Discarding is for the half-finished edit
  a browser is still in the middle of.

The drop reaches the raw input as well as the overlay, because Form API
resolves an element's `#value` from the input before it ever looks at
`#default_value`. An input left in place would put the orphaned value
straight back into the rebuilt select.

The settling lives in the form builder, behind two calls:
`discardedRefinementInput()` names what is dropped, and
`refinementOverlay()` returns the values to build from — stored
underneath, the input that stands on top, orphans unanswered, with
`STANDING_KEY` beside them. `surfaceFormValues()` uses both on every
host's rebuild; `buildSurfaceForm()` refines against the overlay and
renders each orphan standing for its stored value. A full submit
settles nothing: every value was sent on purpose, and the elements and
the pipeline both refine against what was submitted.

## Stale values on a form

A select whose stored value is no longer among the options it offers —
a deleted bundle, an uninstalled plugin, a refinement that narrowed —
comes up on its **empty option**, selected, and the stored value is not
an option:

```html
<select name="settings[bundle]">
  <option value="" selected>- None -</option>
  <option value="page">Page</option>
</select>
<input type="hidden" name="settings[@stale]" value="bundle">
```

The whole of the rule for when a single select shows its empty option
is [a decision](decisions.md#the-empty-option-rule): whenever no valid
choice is selected, and always on an optional select. A required select
with a stored value it still offers, or a declared default it offers,
has no empty option at all; on first entry it comes up on `- Select -`,
and a save leaving it there is refused with "@label is required.", set
as the element's `#required_error` so core's own check, which answers
first, uses the surface's words.

Four things are true of a stale select, and each closes a different way
of losing the value:

- **The stale value is not in the list.** Offering it back would let
  somebody re-save a reference to something that does not exist, and
  would make the list that is offered wider than the list that
  validates.
- **Nothing real is selected.** A browser handed a select whose value is
  missing from its options picks the first one, so the next unrelated
  save used to write that first option over the stored value without
  anybody choosing anything.
- **The element carries the value it stands for** on
  `#data_surface_stale` — a plain value, per [the serialization
  rule](targets.md#the-serialization-rule) — and extraction reads an
  empty submission from such an element as that value, so **leaving the
  select alone sends the stored value back**, and a save keeps it as
  long as nothing it depends on moved in the same submission. Reading it
  back is `DataSurfaceWidgetBase`'s job rather than the options
  widget's: extraction resolves widgets from the surface as advertised,
  having no values yet to refine with, so a key that is only a choice
  once a refiner has narrowed it is built by the options widget and read
  back by another.
- **The container says which selects those are**, in a hidden input,
  `DataSurfaceFormBuilderInterface::STALE_MARKER_KEY`, holding their
  dotted paths. The build a submission or a rebuild is processed against
  is made from the submitted input before any element exists, and an
  empty string there is the same whether it means "keep" or "nothing";
  the marker is what lets the overlay put the stored value back at those
  paths, so the rebuilt element stands for it again. It can only ever
  name a value that is already stored.

A required stale select is not `#required` to core, because empty there
means keep and core's check would refuse it before the surface is asked;
its label keeps the required marker.

Only an explicit new choice replaces the value. Choosing the empty
option of an optional select clears the key when the select could show
what it holds; on a stale select the empty option is what was selected
already, so it keeps.

On submit, `flagSurfaceErrors()` turns each stale reference into a
messenger **warning** and never a form error: nothing is wrong with the
submission and the save is going ahead. The nag repeats on every save,
which is the point — the value keeps working, and it will not start
working again on its own.

## Violations

`validateSurfaceForm()` flags violations on the exact elements they
belong to before it answers, so a host with nothing else to do may ignore
the return value. Stale references are not violations and are not
flagged as ones; see above.

A violation's message reaches `FormStateInterface::setError()` as the
object the constraint built, never as text. Form API takes a
`Stringable` and renders it when the error is printed, so a message with
placeholders is escaped once, by whoever prints it, rather than escaped
at build time and escaped again on the page.

## Finding the container again

Host protocols are inconsistent about what reaches a validate or submit
callback, so the container is located by its marker, at any nesting
depth, rather than by a fixed path.

`findSurfaceContainer()` throws when it finds nothing, and that is
deliberate. A fallback to the whole form reads as working: extraction
finds none of the surface's keys, `accept()` is handed nothing, and every
stored value quietly becomes its default. The one case where something is
genuinely wrong is the one case that must not be survivable.

## Hosts with no validate or submit hook

Some protocols ask for a settings form and then harvest the raw value
tree themselves. Field formatters, field widgets and field types are the
families. There is nowhere to run the pipeline, so an
`#element_validate` callback on the surface container **is** the
pipeline: it extracts, validates, flags violations on their own elements,
and writes the accepted values back into form state as the settings the
host will copy.

For a field type there is one sharper twist: what it writes back is the
**storage** shape, not the surface shape, because Field UI copies the
`settings` value straight onto the field config entity and saves it. So
the values have to have been through the target's `prepare()` by the time
validation ends — and the target's `commit()` is deliberately not called,
because the entity is the host's to save and calling both would save the
field twice. That split is covered in [Targets](targets.md).

Two more things these hosts force:

- **Static defaults.** `defaultSettings()` is static and cannot consult
  an instance surface. `DataSurfaceHostTrait::surfaceDeclaredDefaults()`
  answers it from the class's `#[UsesSurface]`, which is readable with
  no instance: `SurfacesInterface::defaults()` runs the surface's own
  `defineInputs()` alone, with no alter, context or refiner, and reads
  the defaults off it. A class that names no surface is refused with a
  clear message rather than answered with a quietly empty array.
- **Pruning.** `EntityDisplayBase::setComponent()` runs values through
  the formatter manager's `prepareConfiguration()`, which intersects them
  with `defaultSettings()`. Keys mounted onto the surface at build time
  must therefore appear in that static array too, which is why
  `surfaceDefaultSettings()` always declares `third_party_settings`.

## The situation form

`Form\DataSurfaceSituationForm` serves a surface in one of its
situations from the route alone. A situation says what is known, the
surface names its target and its access class, and that is everything a
form needs, so a surface served at a route does not write a form class:

```yaml
example.edit:
  path: '/admin/structure/examples/{example}/surface-edit'
  defaults:
    _form: 'Drupal\data_surface\Form\DataSurfaceSituationForm'
    _title: 'Edit example'
    _data_surface_surface: 'Drupal\example\Surface\ExampleSurface'
    _data_surface_situation: 'edit'
    _data_surface_cosmetics: 'example.surface_form_cosmetics'
  requirements:
    _data_surface_situation_access: 'TRUE'
  options:
    parameters:
      example:
        type: 'entity:example'
```

The defaults:

| Default | Holds |
| --- | --- |
| `_data_surface_surface` | The surface class, or its `#[Surface]` id. |
| `_data_surface_situation` | One of its situation ids. |
| `_data_surface_cosmetics` | Optional. A service id or class implementing `Form\DataSurfaceFormCosmeticsInterface`. |
| `_data_surface_panel` | Optional. A service id or class implementing `Form\DataSurfaceFormPanelInterface`: something shown inside the surface, rebuilt with it. |

The route's parameters are the situation method's, by name: an upcast
entity parameter arrives as the entity, a plain one as its value, so
`edit(NodeTypeInterface $type)` is served by a route with a `{type}`
parameter upcast to a node type. The defaults are underscore-prefixed
because Drupal's routing treats such defaults as its own: no parameter
converter tries to upcast them and no argument resolver tries to hand
them to `buildForm()`.

The form builds the context with the situation, builds the surface in
it, loads current values from the surface's composed target, and
submits through the pipeline to that target. A locked identity key
renders as a disabled element holding the value the situation knows,
and extraction keeps that value whatever is posted. The form id is
`data_surface_situation_form_<route name>`, so an alter hook can name
one route's form and two of them on one page cannot share a form state.
The content type demo's two routes are the worked example, and
[Surfaces as classes](surfaces.md#routes-from-situations) shows one.

The surface container is built under the `surface` key, which is
`DataSurfaceSituationForm::SURFACE_KEY` and is part of the contract with
anything that reads submitted values by path.

### A panel beside the elements

A route may name a panel in `_data_surface_panel`. The form hands it the
situation, the surface built in it, and the values the elements are
built with, and places what it returns inside the surface container,
under `DataSurfaceSituationForm::PANEL_KEY`, with the builder's
`placeRefreshed()`: every AJAX rebuild a refinement triggers replaces
it beside the dependents, whichever key moved, because a panel that
describes the surface as the answers stand changes when they do.
Extraction reads only the surface's own keys, so nothing in a panel is
ever taken for a value.

`data_surface_tool.contract_panel` (`SurfaceContractPanel`) is the one
this repository ships: every key with its type, label, requiredness,
default, what it allows in words, what it depends on and whether it is
narrowed right now, children and mounted keys included; and, collapsed,
the contract as JSON Schema from `data_surface.contract_emitter`
([The served contract](served-contract.md)), then what the derived tool
for the same situation can advertise through the Tool API. The
examples' routes name it.

### Access, twice

The route's requirement, `_data_surface_situation_access`, is the
situation's permission and then the surface's access class. The form
asks the same answer again on its way in, as the floor under the route
for a caller that reached the form another way, and hands it to the
pipeline on its way out. The second is the one that matters, for the
reason in [the non-drift rule](#hosts-and-the-non-drift-rule) above: a
route requirement is checked when the page is built and the submit
arrives later. The situation owns its operation, so an answer with no
opinion is a refusal on both halves, as it is on the route: the form
refuses anything but allowed on the way in and hands the pipeline a
decisive answer on the way out.

### The cosmetic seam

Three methods, `Form\DataSurfaceFormCosmeticsInterface`, and they are
the whole of what a form class is still for:

| Method | Decides |
| --- | --- |
| `alterSurfaceForm()` | How the built elements are arranged. Runs after every element exists, including the actions. |
| `surfaceFormMessage()` | What the person is told. NULL for the generic sentence. |
| `surfaceFormRedirect()` | Where they are sent. NULL to stay on the form. |

A route names one, in `_data_surface_cosmetics`. Each method is told the
situation id as the operation and the raw value of the situation's
first route parameter — the string in the path, before upcasting — or
`NULL` when the situation takes none.

Nothing in a cosmetic layer can change what a value means. Every element
keeps its name and its `#parents`; `#group` only relocates an element at
render time. If you find yourself wanting to change allowed values, a
default, or whether a key is required, that belongs on the surface —
in [its class](declaring-a-surface.md), or in an
[alter](surfaces.md#surface-alters) when it is somebody else's — not
here.

Swapping an element's `#type` for a better-looking one borrows what it
checks along with what it draws, so clear its `#element_validate` when
you do. An element validates before any form level handler, and a form
state keeps only the first error set on an element, so a check that came
with the element answers first and the surface's own violation — the one
that names the value that was refused — is dropped in silence. The demo
does exactly this where it borrows core's machine name element.

`data_surface_demo_node_type` is the worked example: two routes and a
cosmetics service holding core's vertical tabs, the machine name's
mirror-while-typing, the message and the redirect. There is no form
class in the module at all.

### When a hand-written form is still right

Three cases, and the demo module is the second one:

1. **The page is not one situation's page.** A form collecting a surface
   beside several unrelated things — a confirmation step, a batch, an
   entity form the surface rides inside — is a form, and it composes the
   surface container itself.
2. **The surface and the destination belong to different owners.**
   `data_surface_demo` renders the demo block's surface into a
   `StateTarget`, which is exactly the claim it exists to make: a surface
   is independent of where its values are stored. A situation route
   writes to the surface's own target, and the demo block's surface
   names none, because its host stores it; serving it at a route would
   mean giving it a destination that is not its own.
3. **The host protocol is not a route.** Field UI, the block layout
   form, the manage display form: those are the host trait families
   above, not this.

## A standalone form

A form that is not a plugin needs none of the above. Get the surface —
from a plugin's `getDataSurface()`, or from
`SurfacesInterface::build()` with a context — build the container, and
hand the submitted values to the pipeline with a target:

Compose `DataSurfaceHostTrait` for the three collaborators — the
pipeline, the form builder, and the in-progress AJAX input — and the
class is three delegations long:

```php
public function buildForm(array $form, FormStateInterface $form_state): array {
  $surface = $this->surface();
  // The pipeline's own merge rule, reused rather than restated: the
  // surface's defaults, then whatever the target holds — and then, laid
  // over the top by surfaceFormValues(), the in-progress choice an AJAX
  // rebuild is refining against, minus whatever that choice orphaned.
  $values = $this->surfaceFormValues(
    $surface,
    $this->surfacePipeline()->accept($surface, [], $this->target()->load($surface)),
    $form_state,
  );
  $form['surface'] = $this->surfaceFormBuilder()
    ->buildSurfaceForm($surface, $values, $form_state, 'my-form');
  return $form;
}

public function submitForm(array &$form, FormStateInterface $form_state): void {
  $surface = $this->surface();
  $builder = $this->surfaceFormBuilder();
  $target = $this->target();
  $values = $builder->extractSurfaceValues($surface, $form['surface'], $form_state, $target->load($surface));
  $result = $this->surfacePipeline()->submit($surface, $values, $target);
  if (!$result->isValid()) {
    $builder->flagSurfaceErrors($result->violations, $form['surface'], $form_state);
  }
}
```

A surface plus a target is a complete configurable thing.
`data_surface_demo` ships exactly this against a `StateTarget`, asking
the block manager for the demo block and reading its surface rather than
repeating its keys — which is what any other consumer would do.
