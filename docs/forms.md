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

| Family | Use | What you write |
| --- | --- | --- |
| Blocks | `Plugin\Block\DataSurfaceBlockBase` | The declaration, the refiner if you have one, `build()`. |
| Conditions | `Plugin\Condition\DataSurfaceConditionBase` | The declaration, the refiner, `evaluate()` and `summary()`. |
| Actions | `Plugin\Action\DataSurfaceActionBase` | The declaration, the refiner, `execute()` and `access()`. |
| Field formatters | `Plugin\Field\FieldFormatter\DataSurfaceFormatterBase` | The declaration, the refiner, `viewElements()`. |
| Field types | `Form\DataSurfaceFieldTypeTrait` on the item class | The declaration and `getFieldSurface()`, which takes the same operation and subject pair with `field_settings` as its verb; `getFieldSettingsTarget()` only when the storage shape differs from the input shape. |
| Any plugin resolving a form class per operation | `Form\DataSurfacePluginForm` | Nothing at all: list the class under a `forms` key. It is named per operation, so it settles the verb at construction and hands the subject to the plugin unread. |
| A standalone form | Nothing | Build the container, call `submit()`. |

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

The rule: **the provider answers, and every gate reads that answer.**
`submitDataSurfaceForm()` passes `$this->surfaceAccess()` into
`submit()`, so a host that has adopted the method gates its own form
without writing a line of access code, and a host that has not (neutral,
the default) behaves exactly as before. `data_surface_demo_node_type` is
the worked example: `NodeTypeSurfaceProvider::surfaceAccess()` states
what its two routes state in YAML, the operation link in the content type
listing asks it before offering itself, and the form hands it to the
pipeline when it writes. All three ask with the same coordinate — the
operation `add` with no subject, or `edit` with the content type's
machine name as its subject — which is the pair described in
[Declaring a surface](declaring-a-surface.md#the-operation-and-subject-pair).
Nothing in that module spells the permission twice, which is the property
the test asserts by comparing the provider's answer to the route's for
the same four accounts.

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
the DOM id the AJAX path replaces. The id itself comes from
`Html::getUniqueId()` over the wrapper key the host passed, so two
surfaces on one page cannot share a wrapper and rebuild each other.

When the host already named its element, **the host's id wins**: the
container is pointed at that id instead, and the generated one is
discarded, including in the `#ajax` already attached to the children.

Every key another key refines against gets an `#ajax` pointing at that
wrapper, with `refreshSurface()` as the callback. One exception, on
purpose: an element whose `#type` is a grouping type — `details`,
`fieldset`, `container`, which is what the map widget renders — is left
unwired. A `details` is not an input, emits no change event, and an
`#ajax` on it never fires, which is worse than nothing because the form
looks wired and is not. Attaching to each leaf inside it was considered
and rejected: a refiner depends on the whole value of a key, so a rebuild
fired by one leaf would refine against a half-filled map. A map
dependency is declared and left unwired; the surface still refines when
the form is submitted, or when a scalar dependency is touched.

### `#limit_validation_errors`

Every `#ajax` the builder attached is limited to the container's own
value path, so touching a dependency validates the surface and never the
host form around it, and leaves the surface's submitted values — and only
those — readable on the rebuild.

It is set in a `#process` callback on the container rather than written
at build time, for two reasons that are easy to get wrong:

1. A container does not know its own `#parents` until Form API assigns
   them, so there is no path to limit to at build time.
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
fixed path, which is what makes it nesting-agnostic. If you write a host
adapter of your own, go through those rather than reading `$form_state`
directly.

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

## Violations

`validateSurfaceForm()` flags violations on the exact elements they
belong to before it answers, so a host with nothing else to do may ignore
the return value.

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
  answers it from the class's `#[DataSurfaceAware]` attribute; a class
  whose surface is built at runtime overrides `defaultSettings()` itself,
  and the helper throws a clear message rather than returning a quietly
  empty array.
- **Pruning.** `EntityDisplayBase::setComponent()` runs values through
  the formatter manager's `prepareConfiguration()`, which intersects them
  with `defaultSettings()`. Keys mounted onto the surface at build time
  must therefore appear in that static array too, which is why
  `surfaceDefaultSettings()` always declares `third_party_settings`.

## A standalone form

A form that is not a plugin needs none of the above. Ask the provider for
its surface, build the container, and hand the submitted values to the
pipeline with a target:

Compose `DataSurfaceHostTrait` for the three collaborators — the
pipeline, the form builder, and the in-progress AJAX input — and the
class is three delegations long:

```php
public function buildForm(array $form, FormStateInterface $form_state): array {
  $surface = $this->surface();
  // The pipeline's own merge rule, reused rather than restated: the
  // surface's defaults, then whatever the target holds, then the
  // in-progress choice an AJAX rebuild is refining against.
  $values = array_replace(
    $this->surfacePipeline()->accept($surface, [], $this->target()->load($surface)),
    $this->surfaceRefinementInput($surface, $form_state),
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
repeating the declaration — which is what any other consumer would do.
