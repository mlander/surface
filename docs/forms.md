# Generated forms

This page covers the form door: how the module draws a form from a
resource or a plugin's structure, reads the submitted values back, and
redraws it as answers change. Use it when you put a generated form into
a plugin, an entity form or a route of your own, or arrange its keys.
[Doors](introduction/12-doors.md) and
[Structures](introduction/03-structures.md) introduce the ideas.

<!-- cspell:ignore deparment -->

A generated form is one more consumer of the pipeline, not a value path
of its own. The widgets collect a raw submitted tree and
`SurfacePipelineInterface::accept()` does the rest (coercion,
merging, locking, refusing undeclared keys), so the form produces exactly
what a JSON payload carrying the same strings produces.

The layer has two halves. `surface.form_builder`
(`Form\SurfaceFormBuilderInterface`) knows how to turn a data surface
into a container of elements and read it back. The host traits know how to
satisfy one host family's protocol using it. The service draws through
`Form\Drawing`, reads back through `Form\Extracting` and refines over
AJAX through `Form\Refreshing`, three internal classes
(why: [decision](decisions.md#the-form-builder-draws-extracts-and-refreshes)).

## Pick your host family

Every plugin family names its structure class the same way, with
`#[UsesStructure]` on the plugin; the structure class itself is in
[Declaring a resource](declaring-a-resource.md).

| Family | Use | What you write |
| --- | --- | --- |
| Blocks | `Plugin\Block\TypedBlockBase` | `#[UsesStructure]`, `build()`. |
| Conditions | `Plugin\Condition\TypedConditionPluginBase` | `#[UsesStructure]`, `evaluate()` and `summary()`. |
| Actions | `Plugin\Action\TypedConfigurableActionBase` | `#[UsesStructure]`, `execute()` and `access()`. |
| Field formatters | `Plugin\Field\FieldFormatter\TypedFormatterBase` | `#[UsesStructure]`, `formatValue()` or `viewElements()`. |
| Field types | `Form\SurfaceFieldTypeTrait` on the item class, implementing `Form\FieldSurfaceProviderInterface` | `#[UsesStructure]` and a one-line `defaultFieldSettings()`; `getSurfaceStorage()` only when the storage layout differs from the input layout. The structure is built in the `field_settings` context. |
| Any plugin resolving a form class per operation | `Form\SurfacePluginForm` | Nothing but `#[UsesStructure]`: list the class under a `forms` key. It is named per operation, so it settles the context's id at construction. |
| A resource's operation served at a route | `Form\SurfaceOperationForm` | Nothing but routing, and a cosmetic layer if the page needs one. |
| A standalone form of your own | Nothing | Build the container, call `submit()`. |

Under those sit three traits, and it is worth knowing which is which when
you adopt a family none of the base classes covers:

- **`SurfaceConfigurationTrait`** implements `ConfigurableInterface`
  from a structure: defaults come from the structure, and
  `setConfiguration()` runs values through the pipeline rather than
  trusting its caller. Keys the structure does not declare pass through
  untouched, which is what lets host-owned keys (a block's `id`, `label`,
  `provider`; a variant's `weight`; a context mapping) survive
  progressive adoption.
- **`SurfaceHostFormTrait`** holds the three form stages,
  `buildSurfaceForm()`, `validateSurfaceForm()` and
  `submitSurfaceForm()`, named for what they do rather than for one
  host's spelling of them.
- **`SurfacePluginFormTrait`** names those three for
  `PluginFormInterface`: `buildConfigurationForm`,
  `validateConfigurationForm`, `submitConfigurationForm`.

The split between the last two is not tidiness. A trait method *replaces*
an inherited one, so a block base class that pulled in the public triple
would silently replace the block form it is supposed to extend. A host
whose names match the triple adds `SurfacePluginFormTrait` and
nothing else; a host that renames them maps its own names onto the three
bodies. That is why `TypedConditionPluginBase` and
`TypedConfigurableActionBase` use the plugin form trait and
`TypedBlockBase` does not.

The counted evidence for the claim that one adapter serves a family: the
action base needs no translation methods at all, the condition base five
one-line ones, the block base four. The count tracks how much the host
itself insists on doing, not how much the data surface layer costs.

### Two host mechanics worth knowing before you adopt

**Construction order.** Several host base classes call
`setConfiguration()` from inside their own constructor, which asks the
structure for its defaults before any subclass constructor body has run. A
plugin that promotes its collaborators in the constructor signature and
calls the parent constructor last is safe, because promoted properties
are assigned before the body runs. Assigning collaborators in `create()`
after construction is what fails. This is also why the host traits fetch
their services from the container at the point of use rather than
injecting them: a documented exception, not a habit.

**Trait over inheritance.** A host base class that composes core's
`ConfigurableTrait` into itself (actions, search pages, media sources,
CKEditor 5) is adopted by a subclass that simply uses
`SurfaceConfigurationTrait`. A trait used in the child overrides
methods the parent flattened from its own trait, so no `insteadof` is
needed, and `insteadof` naming a parent's trait is itself a fatal error.
The deep merge `ConfigurableTrait` would have applied is therefore never
reached, which is the point: the pipeline's merge rule is the only one
that decides what a declared key holds.

## Hosts and the non-drift rule

A generated form is gated twice by the time it writes: once by whatever
let the person reach the page, and once by the pipeline's access stage
when the submit handler runs. Those two must be the same answer, not two
spellings of one intention: a route requirement is checked when the page
is built and the submit arrives later, so a permission revoked in between
is caught only by the half that writes.

The rule: **the resource or structure answers, and every gate reads
that answer.** `ResourcesInterface::access()` is the operation's
permission and then the access class the resource or structure names. A
plugin host's `surfaceAccess()` asks it in the host's context, and
`submitSurfaceForm()` passes that into `submit()`, so a host gates
its own form without writing a line of access code; a structure with no
access class answers neutral, and the
host's own gates stand exactly as they stood. `surface_examples_node_type`
is the worked example: its routes' `_surface_operation_access`
requirement (which every route naming a resource and an operation has
without saying it), the operation link in the content type listing, the
operation form on its way in and out, and the generated tool all ask
`NodeTypeResource`'s access in the same operation, so nothing in that
module spells the permission twice.

An access refusal has no element to be flagged on (it belongs to the run
rather than to a value), so `flagSurfaceErrors()` reports it as a
form-level error: its key and path are empty, and its code says what
refused. See
[the access stage](pipeline.md#access).

## The merge rule

`mergeSurfaceContainer()` puts a data surface's container inside a form
fragment a host handed over. One line governs it:

> The data surface fills in what the host left unsaid and overwrites
> nothing the host said itself.

`#type`, `#tree`, `#attributes` and `#process` stay the host's where the
host declares them. Only the data surface's own children and its
container marker are its to place, and its keys do win over a host key
of the same name, because those are its business.

Taking those render keys over is how a fieldset became a container and a
host's own wrapper id disappeared, which is the bug the rule exists to
prevent.

## Arranging the keys

How a form is laid out is declared once, on the keys, and every door
that draws them reads it: the operation form, each plugin host (a
block, a condition, a formatter, a field type), the demo's standalone
form, and the React app through the served contract
(why: [decision](decisions.md#arrangement-is-declared-once)).
`$inputs->describe()`, called after the key is declared, says the group
it is drawn in, its weight, a placeholder, a prefix and a suffix beside
its input, and a help text:

```php
public static function defineInputs(BlueprintInterface $inputs): void {
  $workflow = ArrangementGroup::details('workflow', t('Publishing options'), collapsed: TRUE);

  $inputs->add('status', 'boolean', t('Published'), default: TRUE);
  $inputs->describe('status', group: $workflow);

  $inputs->add('rate', 'integer', t('Hourly rate'));
  $inputs->describe('rate',
    placeholder: t('For example, 40'),
    prefix: '$',
    suffix: t('per hour'),
    help: t('Whole dollars.'),
    weight: -5,
  );
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **A group** is `ArrangementGroup::fieldset($id, $label)` or
  `ArrangementGroup::details($id, $label, collapsed:)`, with an optional
  `weight:` and an optional `description:`, drawn under its legend or
  summary (what the group's keys are for, as core's performance form
  says of its bandwidth settings). The keys of one frame (the top, an attached part, a
  module's mount) that name one id are drawn in one group, so they say
  it alike; the build refuses two that differ. It is drawn where its
  first key is declared: the form builder puts its element in the frame
  directly before that key, under `@group.<id>`
  (`SurfaceFormBuilderInterface::GROUP_PREFIX`), and the container's
  pre-render moves each key into it when the form is drawn, as a placed
  fieldset is moved ([below](#a-placed-fieldset)). Until then every key
  stays where its value is posted: its `#parents`, its `#name`, its
  refresh wrapper and everything that walks the container by path are
  as they were, and the AJAX callback replaces a key inside a group by
  the same id. A map alone in its group (an attached structure, a map of
  [keys from the site](resources.md#keys-that-come-from-the-site)) is
  drawn as the group's one details, with the group's label and collapse
  state, rather than its own details inside the group's.
- **A weight** orders a key among its frame's keys, or, inside a group,
  among the group's; keys of equal weight keep declaration order.
- **A placeholder** is drawn on an input a value is typed into (a text
  field, a text area, a number, an email or a URL input), never on a
  password. It is the only placeholder there is: a declared example is
  stated by both documents, and no longer drawn as one, since an empty
  field showing a value reads as filled.
- **A prefix and a suffix** are drawn beside the input (`#field_prefix`,
  `#field_suffix`); **the help** after the key's description, on the
  form only: the description is what the key means, which the tool
  listing says, and the help is for a person filling the form in.

An alter arranges an owner's key with the same `describe()`, laid over
what the owner said: it may place the key (a group, a weight)
and reword its texts, and it may not take any of them away, so an empty
text where the owner gave one is refused. Nothing in an arrangement is a
rule: no default, no requiredness and no path moves, and both schemas
are unchanged by it, less the hint below. The served contract states it
as `x-surface.arrangement` only when a renderer asks for the widget
hints ([The served contract](served-contract.md#x-surface)).

What stays for a [cosmetic layer](#the-cosmetic-seam) is what is truly
one form's: a borrowed element (the content type demo puts the three
groups its resource declares into core's vertical tabs, reaching each by
`@group.<id>`), markup, the message and the redirect.

## Other modules' keys

The keys alters add are mounted under `third_party_settings`, one map
per module ([Alters](resources.md#alters)). On a form
each module's map is one `details`, titled with the module's name, or
with what its alter calls it
(`describe('third_party_settings.<module>', label: ...)`; the examples'
compliance module says "Compliance"). The `third_party_settings` map
around them only groups, and is marked so
(`DefinitionMetadata::isGrouping()`): the fieldset widget renders it as a
plain `container`, with no title and no description, so a person sees
one fieldset per contributing module and nothing above them. The served
contract says the same, with no `title` and `x-surface.group`
([The served contract](served-contract.md#x-surface)). The paths are
unchanged: the elements are still named
`surface[third_party_settings][<module>][<key>]`, and the contract panel
lists every key by its dotted path.

### A placed fieldset

An alter that places its mount
(`describe('third_party_settings.<module>', after: 'capacity')`, see
[Alters](resources.md#alters)) has its fieldset drawn
directly after that key. The fieldset widget builds the `details` where its
values live, inside the grouping container, and marks it with the key it
follows (`PLACED_AFTER_KEY`). The container's `#pre_render`
(`preRenderSurfaceContainer()`, after its type's own) moves it when the
container is rendered: out of the grouping container, to directly after
the named element of the same frame, at any depth, under a key no
declared key can take, and with that element's `#weight` when it has
one, so a frame Form API sorts keeps it there. When the named key sits
in an [arrangement group](#arranging-the-keys), the fieldset is drawn in
that group, right after the key, as the React app draws it
(`ArrangementFormTest` and the app's `widgets.test.tsx` hold both doors
to it). A grouping container left with nothing in it is not drawn.

The move happens at render time and only then, because it is a matter
of drawing. By then Form API has processed, validated and submitted the
form, so everything that walks the container by path finds the
fieldset where the fieldset widget built it: extraction, `flagSurfaceErrors()`,
the trigger's inline error, the stale marker, `Extracting::settle()`, and the AJAX
callback's walk to each dependent. What is kept:

- **The value path.** Its elements keep their `#parents` and `#name`
  (`surface[third_party_settings][<module>][<key>]`), so they post, and
  save, where they always did.
- **Dependents-only refresh.** Each key inside it keeps its refresh
  wrapper and id. Changing the license replaces the capacity and the
  stewards it moves, by their wrappers; changing the capacity replaces
  the stewards. The fieldset itself is never replaced, so it stays
  where it was drawn. An element the callback replaces is rendered
  without the container, so a placed fieldset inside it, in an attached
  part, is placed by the callback the same way.
- **Its title and its fields**, both inside it.

## Wrapper ids and AJAX

The container carries a private marker, `#surface_wrapper`, holding
its DOM id. The id itself comes from `Html::getUniqueId()` over the
wrapper key the host passed, so two data surfaces on one page cannot
share a wrapper and rebuild each other.

When the host already named its element, **the host's id wins**: the
container is pointed at that id instead, and the generated one is
discarded, including in the `#ajax` already attached to the children.

Every key another key refines against gets an `#ajax` with
`refreshSurface()` as the callback, at any depth: an attached child's or
a chosen variant's own dependency is wired too, as a trigger of the
child's own refiners. One exception, on purpose: an element whose
`#type` is a grouping type (`details`, `fieldset`, `container`, which
is what the fieldset widget renders) is left unwired. A `details` is not an
input, emits no change event, and an `#ajax` on it never fires, which is
worse than nothing because the form looks wired and is not. Attaching to
each leaf inside it was considered and rejected: a refiner depends on the
whole value of a key, so a rebuild fired by one leaf would refine against
a half-filled map. A map dependency is declared and left unwired; the
data surface still refines when the form is submitted, or when a scalar
dependency is touched.

A trigger typed into, a number or a text input, changes when it loses
focus, and its `#ajax` carries `disable-refocus`. Core puts focus back
on the trigger after every response unless another element with a
selector holds it; a person who left the field by clicking blank space
leaves only the body focused, so focus snapped back to the field they
had left, and a refused value, redrawn with its error, held them there.
Focus now stays where the person put it, and core's own `refocus-blur`,
which it sets on these types, still carries it to the field they tabbed
to. A select or a checkbox changes while focused and keeps core's
refocus: it is disabled during the request and would otherwise lose
focus to the body.

### What a change replaces

**Only what the change moved, and the element that was touched only
when what it shows is wrong.** Changing a key replaces the keys that
refine against it, then the keys
that refine against those, until nothing new is reached: the same
closure the [discard cascade](#the-in-form-half-discarding-orphaned-input)
walks. In the registration example, the venue replaces the room and the
capacity; the room replaces the capacity; the pricing replaces the
ticket variants. In the demo block, the entity type replaces the bundle and
the field, and the bundle replaces the field. Replacing the whole
container, as the form once did, redrew the select the person had just
changed and left the real change (a maximum moving on another field)
looking like nothing happened.

The callback answers with an `AjaxResponse`:

| Command | For |
| --- | --- |
| `ReplaceCommand` | Each dependent, by its own wrapper. Variants whose deciding key moved are replaced whole, by the wrapper of the key they sit at. |
| `ReplaceCommand` | Each element placed with `placeRefreshed()` (the operation form's panel), on every rebuild. |
| `RemoveCommand`, then `AppendCommand` | The [stale marker](#stale-values-on-a-form): removed, and appended to the container again when the rebuild left one. It names stale selects across the whole container and may appear or disappear with the rebuild, so it is never replaced in place. |
| `ReplaceCommand` | The trigger itself, by its own wrapper, in three cases only: its own value was refused (the error is printed under it), its previous request printed an error under it (so a fixed error goes away), or the rebuild changed the options it offers (a required select loses `- Select -` once something is chosen). |
| `RemoveCommand`, then `PrependCommand` | Whatever else the request put in the messenger, a stale value kept, printed inside the container where the render-array path printed it, after the previous request's are taken away. An error on the trigger is never among them: it is printed under the trigger. |
| `AnnounceCommand` | At most one, polite: what the rebuild changed, in whole sentences, for whoever cannot see it. The trigger's refusal comes first, named for its field, then each dependent the change moved, compared with what the page showed: "Send day no longer applies." or "Send day now applies.", "Items per issue now allows 1 to 20.", "<Label> is now required.", "The choices for Room have changed.", and, for a group whose fields changed (a variant), "Ticket was updated." Nothing is announced when nothing moved. |

**Coming from `#states`**: [below](#coming-from-states), idiom by idiom.

Each refinement target, each trigger, each key with variants and each
placed element gets a wrapper of its own, a `div` in its `#prefix` and
`#suffix`, with its id on the element under `REFRESH_ID_KEY`. The ids
are derived from the
container's id and the element's path below it
(`<container>--shelf--height`), so they are unique wherever the
container's is. They are placed by the container's `#process`, since
the container's id is only final once a host has merged it. Variants
nothing has chosen yet render as their wrapper and nothing else, so
the rebuild that chooses a variant has a place to put it; extraction
reads nothing from it. A key that does not apply to the values the form
is drawn for is the same: no element, not disabled and not hidden, only
its wrapper, so the rebuild that makes it apply puts it there
([Refinement](refinement.md#when-a-key-does-not-apply)).

Each trigger carries, under `TRIGGER_KEY`, its own path below the
container and the dotted paths it replaces. The dependency edges are
fixed when a data surface is sealed, so the closure the previous build wrote
is the closure of this one; each element is then taken from the rebuilt
container. When a dependent cannot be found there (a host or a
cosmetic layer took it out), the callback falls back to returning the
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

**An error on the trigger is printed under it, once.** The trigger's
own answer is the one value a refinement request judges, so it is the
one error that request can produce. A violation reported on it is not
made a Form API error, because Form API skips the rebuild
once anything has errored and every dependent would come back refined
against the answer before this one: the builder holds it for the
request, the rebuild goes ahead (refining as though the refused value
were not there, since a refiner never sees an invalid sibling; why:
[decision](decisions.md#a-refiner-never-sees-an-invalid-sibling)), and
the callback replaces the trigger with the error under it, in the form
element template's own error slot, with `aria-invalid` and the `error`
class. The error carries an id, the input's own followed by `--error`,
which the input's `aria-describedby` names after its description, so
the error is read with the field; and the rebuild's announcement says
it, named for the field. Type `EV-2048` as example 3's license: the license is redrawn
with "A license number is four digits, such as 2048." under
it, and the capacity with the no-license ceiling of 100. The message is
said there and nowhere else: not at the top of the container, and not
held over to the next page. An error Form API's own element validation
sets on the trigger, a maximum length, still stops the rebuild, and is
printed the same way and taken off the top. The trigger's next request
replaces it again, so an error the person fixed goes away with it
(why: [decision](decisions.md#an-error-on-the-trigger-renders-inline)).

The same exception covers the case that used to be this section's
trade-off. A trigger keeps the options it was rendered with, and a
required select first offered on `- Select -` lost that option in the
rebuilt form after a choice, so choosing it again was refused as a
value the select did not offer. The callback now compares the options
the trigger was rendered with (read off the form the request arrived
with) to the rebuilt ones, and replaces a trigger whose options moved:
the select is redrawn without `- Select -` the moment something is
chosen, and the page and the form agree again. It is redrawn only
then; a trigger whose options did not move is never touched.

### Coming from `#states`

A Form API form says how one element follows another with `#states`,
which the browser applies and the server never sees. Here the rule is
the resource's, said once and checked by the pipeline at every door, and
the form follows it by a round trip to the server: a select or a
checkbox triggers it when it changes, a text or a number input when it
loses focus. Each idiom, and what differs:

| `#states` | Here | What behaves differently |
| --- | --- | --- |
| `visible` | The key applies: a refiner, or a declared rule, answers it does not apply otherwise (`DoesNotApply::here()`, or `doesNotApply()` on a declared rule), and no element is drawn until it does ([Refinement](refinement.md#when-a-key-does-not-apply)). | Without JavaScript, a key that does not apply has no element, so a post leaves it out and it keeps what it holds, where a hidden `#states` element would post its value. With JavaScript, a rebuild draws it afresh from what is stored, so what was typed in it before it went away is gone. |
| `invisible` | The same rule, the other way round. | The same. |
| `required` | A declared rule, `$inputs->narrow($key)->when(...)->require()`, or a refiner that requires. | The pipeline checks it on every save, in the rule's own words, with or without JavaScript and at the tool and the React page alike; `#states` only drew the marker, and the browser checked nothing a person could not switch off. The marker follows on the rebuild. |
| `disabled` | Locked: the operation knows the value (an identity key), or an access answer locks the key or its child. | A locked key posts nothing that counts: extraction keeps what is stored, whatever arrives, where `#states` disabled only the browser's element. It depends on what is stored or who asks, never on another element's live value. |
| `enabled` | Not locked. | As for `disabled`. |
| `checked`, `unchecked` | A refiner or a rule that narrows the key to one value, or the stored value, drawn as it is. | Nothing is ticked for the person: a value is what is stored or sent, and the pipeline refuses one the rule does not allow, rather than the browser ticking a box nobody touched. |
| `access` (by `#access`) | Access: an access answer omits the key (or locks it), asked of the account and, at runtime, of the thing ([Access](resources.md#access)). | Decided on the server for the account, before anything is drawn; the full schema says which answers wait for the thing, `x-surface.live`. |

Two habits do not carry over. A rule is not a matter of drawing, so a
rule `#states` would only have shown is checked here: a value a hidden
element still held is not saved behind a person's back. And nothing
happens between round trips: a text trigger's dependents move when it
loses focus, and the rebuild announces what changed for whoever cannot
see it ([What a change replaces](#what-a-change-replaces)).

### A collection on a form

A collection ([Collections](resources.md#collections)) is drawn as its own
`details`, titled with the list's label, holding one `details` per item
and an "Add another" button:

- **Each item** is the child's map, refined against that item's values
  alone, drawn the way an attached child is and titled with the list's
  label and the item's number ("Spice racks 2"). Its elements are named
  by delta, `surface[racks][1][spices]`. Each carries a "Remove"
  button, whose accessible name adds the item's title, "Remove Spice
  racks 2", so no two buttons in a list read the same.
- **Add and remove** are submit buttons, named for their place
  (`surface_racks_add`, `surface_racks_1_remove`), with
  `#limit_validation_errors` empty: nothing is judged, every value is
  still mid-edit. Their submit handler (`addListItem()`,
  `removeListItem()`) rewrites the posted items, one empty item more at
  the end, or one fewer with the rest moved up, and rebuilds the form.
  Their AJAX callback is `refreshSurface()`, and each names the list as
  the one element it replaces, so the list is replaced by its own
  wrapper and nothing else on the page is: what was typed elsewhere,
  and in the other items, survives. Without JavaScript they are plain
  submits that rebuild the page.
- **Bounds.** The list is drawn with at least its Count's minimum, an
  empty item for each missing one; "Remove" is disabled while it holds
  no more than its minimum, and "Add another" once it holds its maximum.
- **Locked** by its access, the list is drawn disabled, every item and
  both buttons with it, and says so in its description: the access
  answer's own reason when it gave one.
- **Inside an item**, refinement is wired in the item's own frame: a
  key another key of the same item refines against is a trigger whose
  path carries the delta (`shelves.1.unit`), and replaces only what it
  moves in that item (`shelves.1.height`), the dependents-only rule
  unchanged. The discard cascade settles each item against what is
  stored at the same delta.
- **Extraction** reads each drawn item through the child's map, in the
  order drawn, drops an item that holds nothing at all, and remembers
  the delta each kept item was drawn at for the request, so a violation
  at `racks.2.spices` after a dropped blank row is flagged on the row it
  came from.

- **A collection inside an item** is drawn as one, in its place among
  the item's elements, by the same rules: its elements and buttons are
  named by both deltas (`surface[aisles][1][shelves][0][height]`,
  `surface_aisles_1_shelves_add`), its buttons replace only that
  item's list, it is read back into the item it belongs to, and a
  violation inside it (`aisles.1.shelves.0.height`) is flagged where it
  was drawn, each delta remembered at each depth (decided 2026-10-19;
  until then such a list had no widget and the form failed).
  `NestedCollectionFormTest` is the worked example.

### A list of free values on a form

A list of single values with no list of allowed values (keywords, a
list of free strings) is drawn by the stock list widget the same way,
in a `fieldset` of rows rather than a `details` of items
(why: [decision](decisions.md#a-widget-is-chosen-from-the-definition)):

- **Each row** is the element the item's own widget draws, its title
  the list's label and the row's number, read by screen readers and not
  shown, named by delta (`surface[keywords][1]`), with a "Remove" button
  beside it, its accessible name "Remove Keywords 2", and "Add another"
  under all of them.
- **The widget cannot know where it sits**, so it marks the list and its
  buttons, and the container's process callback wires them at any depth
  when the form is processed: the list gets its refresh wrapper, each
  button a name for its place (`surface_keywords_add`,
  `surface_keywords_remove-1`), the container's AJAX callback and
  the path it sits at. Their submit handlers (`ListWidget::addRow()`,
  `removeRow()`) rewrite the posted rows and rebuild; nothing is judged.
- **Bounds and extraction** are a collection's: at least the Count's
  minimum drawn, the buttons disabled at the bounds, an empty row
  dropped on extraction, and a refusal of an item flagged on the row it
  was drawn as.

### `#limit_validation_errors`

Every `#ajax` the builder attached, at any depth, is limited to **that
element's own value path** and nothing wider. Touching one select says one thing about
one key: the host form around the data surface is not being answered,
and neither is the rest of the data surface.

It was once limited to the whole container, and that one line was a bug
with two halves. A dependent still holding a value the new choice had
just orphaned was inside the limit, so the rebuild flagged it: an error
for a question nobody had asked. And because Form API skips the rebuild
outright once anything has errored, the container came back refined
against the choice that had just been replaced: the dependent's list did
not follow its own parent either. Narrowing the limit closes both.

Nothing is lost by narrowing it. The data surface's real validation runs on
submit, through the host's validate stage, where every key has been
answered on purpose.

One consequence to know about, because it is not obvious: Form API
answers a limit by **throwing away every value outside it** before the
rebuild runs. So by the time the container is rebuilt,
`$form_state->getValues()` holds the one key that was touched. That is
why the in-progress overlay is read from the raw input instead: see
[the SubformState lesson](#the-subformstate-lesson).

It is set in a `#process` callback on the container rather than written
at build time, for two reasons that are easy to get wrong:

1. A container does not know its own `#parents` until Form API assigns
   them, so there is no path to limit to at build time, and neither do
   its children, so the callback computes each child's path the way Form
   API is about to.
2. It has to be the container's callback rather than each child's,
   because by the time a child is processed Form API has already taken
   its copy of the triggering element.

## The SubformState lesson

Form API assigns `#parents` from the root of the complete form, so they
are **absolute**. A subform state reads values **relative** to the
fragment it wraps. A host that hands its plugin a subform state (a
block's settings, built with `SubformState::createForSubform()`, is the
common case) therefore has every absolute path looked up one level too
deep.

The failure is silent and expensive. The value comes back `NULL`, the
data surface reports the key as "not configured", and that is a spurious
violation for a required key and a stored value replaced by nothing for
an optional one.

Two places resolve it, both by asking the complete form state for an
absolute path: `SurfaceWidgetBase::rawValue()` when extracting, and
`SurfaceHostTrait::surfaceRefinementInput()` when reading what an
in-progress AJAX rebuild has collected. The second locates the submitted
tree through the triggering element's own position rather than through a
fixed path, which is what makes it nesting-agnostic: the trigger's
absolute `#parents`, less the path below the container it carries under
`TRIGGER_KEY` (one key for a key of the data surface, two for a key of
an attached child). If you write a host
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

A full submit (the Save button, with or without JavaScript, or a
programmatic `submitForm()`) is the other case the overlay covers. The
build it is processed against runs before Form API knows the triggering
element, so no rebuild input is found; instead the host's
`surfaceSubmissionPath()` names where its container sits in the input,
and the submitted answers are overlaid on the stored values, **with
nothing discarded**: every value was sent on purpose and is judged. That
is what makes the elements the ones the answers ask for. Without it a
select offers the stored venue's rooms and Form API refuses the new
venue's room as a choice it was never offered before the data surface
is asked, and variants whose deciding key flipped in the same request
are rendered as the stored variant, with no element for the chosen
variant's keys to arrive in. A
select left on the empty option it was given in place of a stored value
(named in the hidden input the container posts) is read as that
stored value, here and on an AJAX rebuild alike, so it is rebuilt
standing for it rather than as a key that holds nothing.
`SurfaceOperationForm` answers `surfaceSubmissionPath()`, since its
container is its own top level key; a plugin host nested inside another
form cannot know its position before Form API assigns it, answers NULL,
and is built from what is stored, so without JavaScript a plugin host
refuses a valid save that moves a parent and its dependent together.
With JavaScript the parent's AJAX rebuild offers the dependent's new
choices before the save, and the save is accepted
(why: [decision](decisions.md#a-plugin-host-cannot-overlay-a-full-submission)).

## Current values on extraction

`extractSurfaceValues()` takes a `$current` argument: what the data
surface's keys already hold, in input layout, read from wherever the host
stores them. **A host that leaves it out is telling the data surface that
storage is empty.**

This is not an optimization. A form is always a partial statement about a
data surface, because a key whose element was never rendered (access
denied, removed by an alter, or below a container the host did not
build) has said nothing about its value. Without the stored values to
fall back on, `accept()` would replace every one of them with its declared default.

The same reasoning is why the elements that carry a pipeline into a
static callback also carry `#surface_current`: the values the form
started from, as a plain array, so it survives the form cache. See [the serialization rule](storage.md#the-serialization-rule) for what may and
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
| Cleared when | A real submit, and at no other time | Never: nothing was stored to clear |
| Said out loud | A messenger warning, on every save | Nothing at all |

The two halves meet at the Save button. A stored value the rebuild
left behind the empty option because its dependency moved is not stale
on save: the same submission moves the dependency, so the pipeline
refuses it on its element and the person chooses again
([value semantics](semantics.md#stale-values-the-third-state)). Moving
the parent back shows it chosen again.

The two meet without conflicting. If a parent changes while a child was
already standing for a stale stored value, the child still stands for
it under the new parent's list, and the value is still stored, because
a stored value changes on a submit and at no other time.

### The in-form half: discarding orphaned input

On a refinement rebuild the data surface is refined against the new values
first, and then every refinement target that the input holds a value for
is tested against its newly narrowed definition with
`OptionSet::allows()`. A value the narrowed definition no longer offers
is **dropped from the input**, and the key falls back, in this order:

1. its **stored** value, when the narrowed definition still offers that;
2. the **empty option standing for it**, when something is stored and
   is no longer offered (the other half of the table, reached from
   here);
3. **nothing chosen**: the empty option, with nothing behind it.

In the second case the key is an **orphan** of the edit: a key it
refines against moved away from what is stored, and the stored value is
not among what the moved key now offers. Its select stands for the
stored value, but nothing below it is refined against that value: the
overlay holds the key unanswered, the same as the empty select the
person is looking at, and records what it stands for under
`SurfaceFormBuilderInterface::STANDING_KEY` for the element alone.
So in the registration example, moving the venue away from a stored
room leaves the room standing for it and the capacity back at its
declared limit, not still capped, and described, by a room no longer
on the screen. A stored value the site narrowed away under a dependency
nobody moved is not an orphan: it is stale, kept, and what refines
against it still does, exactly as when the form was first built.

An empty input is never discarded: it is the person having said
"nothing" under a parent that has not moved.

No error and no warning, ever. Nothing was submitted, so there is nothing
to judge and nothing to report.

Three rules keep it honest:

- **Only input is ever dropped.** `discardedRefinementInput()` names
  keys (and, inside an attached child or a chosen variant, the
  dotted paths of the child's own keys its own refiners orphaned, asked
  of the child in its own frame); a stored value is never touched by it. A rule that could reach
  storage would be the stale model with the safety taken off.
- **The chain settles in one rebuild, to a fixed point.** Dropping a
  value moves what the next target refines against, and so does holding
  an orphan unanswered; either invalidates that target's input the same
  way. So the data surface is refined again after every drop and every
  orphan, and the question asked again, until neither finds anything
  new. Each round adds a key to one of the two sets and none takes one
  away, so it ends within the depth of the longest chain. Changing the
  first of three links resets all three in the one rebuild the person is
  waiting on: the demo block's entity type moved with a bundle and field
  still posted leaves the bundle standing for the stored one and the
  field open again, refined against no bundle at all.
  `Extracting::settleFrame()` is the loop, per frame, and a
  child's own frame is settled the same way.
- **A programmatic submission is exempt.** Its caller said every value on
  purpose, in one statement, so a value the pipeline refuses is refused
  rather than quietly dropped. Discarding is for the half-finished edit
  a browser is still in the middle of.

The drop reaches the raw input as well as the overlay, because Form API
resolves an element's `#value` from the input before it ever looks at
`#default_value`. An input left in place would put the orphaned value
straight back into the rebuilt select.

The settling lives in the form builder (`Form\Extracting`), behind two
calls:
`discardedRefinementInput()` names what is dropped, and
`refinementOverlay()` returns the values to build from: stored
underneath, the input that stands on top, orphans unanswered, with
`STANDING_KEY` beside them. `surfaceFormValues()` uses both on every
host's rebuild; `buildSurfaceForm()` refines against the overlay and
renders each orphan standing for its stored value. A full submit
settles nothing: every value was sent on purpose, and the elements and
the pipeline both refine against what was submitted.

## Stale values on a form

A select whose stored value is no longer among the options it offers
(a deleted bundle, an uninstalled plugin, a refinement that narrowed)
comes up on its **empty option**, selected, and the stored value is not
an option:

```html
<select name="settings[bundle]">
  <option value="" selected>- Keep article (no longer offered) -</option>
  <option value="page">Page</option>
</select>
<input type="hidden" name="settings[@stale]" value="bundle">
```

The empty option says what it stands for, "- Keep @value (no longer
offered) -", and still posts the empty string, never a value the key
does not take (why: [decision](decisions.md#the-empty-option-rule)). An
orphan of an edit, which a save refuses rather than keeps, says "- Select
-" or "- None -" as before, and so does a select on a declared default
the list does not offer, since nothing is stored to keep.

A single select shows its empty option whenever no valid choice is
selected, and always on a select that may be cleared: an optional key
its storage does not always hold. A key its storage always holds is one
a fully validatable config schema does not mark `nullable: true`
(why: [decision](decisions.md#the-empty-option-rule),
[decision](decisions.md#a-key-its-storage-always-holds)). A required select,
or one its storage always holds,
with a stored value it still offers, or a declared default it offers,
has no empty option at all; on first entry it comes up on `- Select -`,
and a save leaving it there is refused with the data surface's own
words, "@label is required." (or "... is required when ..." where a rule
made it so): the form builder keeps core's own required check off every
element it draws, which still draws the marker and the browser's
`required` attribute (why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)).

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
  `#surface_stale` (a plain value, per [the serialization rule](storage.md#the-serialization-rule)), and extraction reads an
  empty submission from such an element as that value, so **leaving the
  select alone sends the stored value back**, and a save keeps it as
  long as nothing it depends on moved in the same submission. Reading it
  back is `SurfaceWidgetBase`'s job rather than the options
  widget's: extraction resolves widgets from the data surface as advertised,
  having no values yet to refine with, so a key that is only a choice
  once a refiner has narrowed it is built by the select widget and read
  back by another.
- **The container says which selects those are**, in a hidden input,
  `SurfaceFormBuilderInterface::STALE_MARKER_KEY`, holding their
  dotted paths. The build a submission or a rebuild is processed against
  is made from the submitted input before any element exists, and an
  empty string there is the same whether it means "keep" or "nothing";
  the marker is what lets the overlay put the stored value back at those
  paths, so the rebuilt element stands for it again. It can only ever
  name a value that is already stored.

A required stale select is not `#required` to core, because empty there
means keep and core's check would refuse it before the data surface is
asked, and it has no `required` attribute, which would have the browser
refuse the option that keeps the value. The rest of the required hint is
drawn as on every required element: the label keeps the marker, and the
select says `aria-required="true"`, so a screen reader and the eye agree
(why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)).

Only an explicit new choice replaces the value. Choosing the empty
option of an optional select clears the key when the select could show
what it holds; on a stale select the empty option is what was selected
already, so it keeps.

On submit, `flagSurfaceErrors()` turns each stale reference into a
messenger **warning** and never a form error: nothing is wrong with the
submission and the save is going ahead. The nag repeats on every save,
which is the point: the value keeps working, and it will not start
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

A constraint's own words name no field, so where the message is printed
away from its element, in the messages at the top of the page with
Inline Form Errors off, the element's title is put before it, as one
translatable sentence with the message a placeholder in it: posting
5000 as example 1's capacity says "Capacity: This value should be
between 1 and 1000." A message that already begins with the title (a
required key's "Event title is required.") and an element with no title
are left as they are, and with Inline Form Errors on nothing is added:
it prints the message beside the element and names the field in its own
summary (why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)).

## Finding the container again

Host protocols are inconsistent about what reaches a validate or submit
callback, so the container is located by its marker, at any nesting
depth, rather than by a fixed path.

`findSurfaceContainer()` throws when it finds nothing, and that is
deliberate. A fallback to the whole form reads as working: extraction
finds none of the data surface's keys, `accept()` is handed nothing, and every
stored value quietly becomes its default. The one case where something is
genuinely wrong is the one case that must not be survivable.

## Hosts with no validate or submit hook

Some protocols ask for a settings form and then harvest the raw value
tree themselves. Field formatters, field widgets and field types are the
families. There is nowhere to run the pipeline, so an
`#element_validate` callback on the data surface's container **is** the
pipeline: it extracts, validates, flags violations on their own elements,
and writes the accepted values back into form state as the settings the
host will copy.

For a field type there is one sharper twist: what it writes back is the
**storage** layout, not the input layout, because Field UI copies the
`settings` value straight onto the field config entity and saves it. So
the values have to have been through the storage's `prepare()` by the
time validation ends, and the storage's `commit()` is deliberately not
called, because the entity is the host's to save and calling both would
save the field twice. That split is covered in [Storage](storage.md).

Two more things these hosts force:

- **Static defaults.** `defaultSettings()` is static and cannot consult
  an instance's built structure.
  `SurfaceHostTrait::surfaceDeclaredDefaults()` answers it from the
  class's `#[UsesStructure]`, which is readable with no instance:
  `ResourcesInterface::defaults()` runs the structure's own
  `defineInputs()` alone, with no alter, context or refiner, and reads
  the defaults off it. A class that names no structure is refused with a
  clear message rather than answered with a quietly empty array.
- **Pruning.** `EntityDisplayBase::setComponent()` runs values through
  the formatter manager's `prepareConfiguration()`, which intersects them
  with `defaultSettings()`. Keys mounted onto the data surface at build time
  must therefore appear in that static array too, which is why
  `surfaceDefaultSettings()` always declares `third_party_settings`.

## The operation form

`Form\SurfaceOperationForm` serves a resource in one of its
operations from the route alone. An operation says what is known, the
resource names its storage and its access class, and that is everything
a form needs, so a resource served at a route needs no form class:

```yaml
example.edit:
  path: '/admin/structure/examples/{example}/surface-edit'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Edit example'
    _surface_resource: 'Drupal\example\Resource\ExampleResource'
    _surface_operation: 'update'
    _surface_cosmetics: 'example.surface_form_cosmetics'
  options:
    parameters:
      example:
        type: 'entity:example'
```

The defaults:

| Default | Holds |
| --- | --- |
| `_surface_resource` | The resource class, or its `#[Resource]` id. A derived resource's id may hold a variable of the route's path, `entity.content:node.{node_type}`, so one route serves every bundle ([below](#one-route-for-every-bundle)). |
| `_surface_operation` | One of its operation ids. |
| `_surface_cosmetics` | Optional. A service id or class implementing `Form\SurfaceFormCosmeticsInterface`: a borrowed element, markup, the message and the redirect. Grouping and order are the keys' [arrangement](#arranging-the-keys). |
| `_surface_panel` | Optional. A service id or class implementing `Form\SurfaceFormPanelInterface`: something shown inside the data surface's container, rebuilt with it. |
| `_surface_fixed` | Optional. A map of key paths to values, each key fixed to its value on this page; a value written `'{name}'` is read from the route's path ([below](#a-route-that-fixes-or-fills-keys)). |
| `_surface_defaults` | Optional. A map of key paths to values, each the value its key starts from on this page, for an operation that creates; a value written `'{name}'` is read from the route's path ([below](#a-route-that-fixes-or-fills-keys)). |
| `_surface_query` | Optional. A map of key paths to query parameter names, each key a link may start from the parameter it names, `?subject=Pricing`, on any operation that draws values ([below](#keys-a-link-or-the-code-starts)). |

The route's parameters are the operation method's, by name: an upcast
entity parameter arrives as the entity, a plain one as its value, so
`update(NodeTypeInterface $type)` is served by a route with a `{type}`
parameter upcast to a node type. The module's own defaults start with an
underscore, which keeps each of them out of the route match's
parameters and out of what `_title` substitutes. No converter runs for
one, because none is declared for it under `options.parameters`, and
`buildForm()` declares no parameter of its name, so none is handed to
it.

**A plain default is a route parameter.** Core counts a default whose
name has no leading underscore as a parameter of the route, so
`to: 'friends'` pins the operation's `$to` on that page as `{to}` in the
path would: the by-name mapping reads it
(`OperationArguments::fromRoute()`), and the operation judges it as it
judges any parameter. A plain default
that nothing the route serves reads is refused when the route is matched, so
a misspelled one is never ignored: its readers are the operation's
parameters and the parameters of the route's `_controller`, its
`_form`'s `buildForm()`, its `_title_callback` and its `_custom_access`,
since core hands route parameters to each by name (core's
`EntityController::addBundleTitle()` reads `entity_type_id` and
`bundle_parameter` that way); where reflection cannot read one of them,
nothing is refused. One the operation refuses, or whose value names
nothing, is the route written wrong, a 403 with the operation's reason
on every URL. A plain default is never what the page is about: the
subject a cosmetic layer is told is the first of the operation's
parameters that is a variable of the path
(why: [decision](decisions.md#a-plain-route-default-pins-an-operation-parameter)).

The form builds the context with the operation, builds the resource in
it, loads current values from the resource's composed storage, and
submits through the pipeline to that storage. Its validation runs the
whole run dry, access and storage's own `prepare()` included, so every
refusal is said on its element while Form API still takes errors, and
the submit runs it again and writes; a refusal that arrives only then,
what is stored having moved in between, is said as a message
(why: [decision](decisions.md#nothing-an-operation-runs-reads-the-request)). A locked identity key
renders as a disabled element holding the value the operation knows,
and extraction keeps that value whatever is posted. A resource its
access locks at the top renders every element disabled, says why above
them, once, and has no Save; nothing on it is marked required, since
nothing is asked (why: [decision](decisions.md#locked-at-the-top)).
A key locked on its own (a child its access locked, the fees of
example 10 once the program is published) says why in its description:
the access answer's reason, "The fees are fixed once the program is
published.", or "Fixed for this operation." when the answer gave none,
or the lock is an operation's (an identity key). An operation that
changes nothing is offered under its own label, "Check a program", not
"Save", and says, on submit, that the values were accepted and nothing
was written. The form id is
`surface_operation_form_<route name>`, so an alter hook can name
one route's form and two of them on one page cannot share a form state.
The content type demo's two routes are the worked example, and
[Routes from operations](resources.md#routes-from-operations) shows one.

The data surface's container is built under the `surface` key, which is
`SurfaceOperationForm::SURFACE_KEY` and is part of the contract with
anything that reads submitted values by path.

### A panel beside the elements

A route may name a panel in `_surface_panel`. The form hands it the
operation, the resource built in it, and the values the elements are
built with, and places what it returns inside the data surface's
container,
under `SurfaceOperationForm::PANEL_KEY`, with the builder's
`placeRefreshed()`: every AJAX rebuild a refinement triggers replaces
it beside the dependents, whichever key moved, because a panel that
describes the built resource as the answers stand changes when they
do. Extraction reads only the data surface's own keys, so nothing in a panel is
ever taken for a value.

`surface.contract_panel` (`ContractPanel`) is the one
the module ships: every key with its type, label, requiredness,
default, what it allows in words, what it depends on and whether it is
narrowed right now, children and mounted keys included; and, collapsed,
the contract as JSON Schema from `surface.contract_emitter`
([The served contract](served-contract.md)), the full schema for any
answers, then, where the tool module is installed, what the derived
tool for the same operation can advertise through the Tool API
([The Tool API door](tools.md#the-contract-panel)).

A route names one panel. Two are one panel that composes them: the
examples' routes name `surface_examples.panels` (`ExamplePanels`),
which returns the contract panel and, below it, what config schema alone
can say about the same settings
(`modules/surface_examples/README.md`).
Both sit under the one key, so one rebuild replaces both.

### A route that fixes or fills keys

A route may serve an existing operation with some of its keys fixed,
filled or started, on that page alone, with no new operation. Who
chooses a value decides how it reaches the operation, and one rule says
which:

> If the operation must know it, it is an operation parameter; if one
> page states it for every visitor, it is a route seed, a literal or the
> route's own path segment bounded by a converter or a requirement,
> fixed when nobody may change it there and a default when they may; if
> a link offers it and the visitor may change it, it is a query prefill
> the route lists; if only code at request time can compute it, a
> controller hands it to the form as a prefill. A bounded path segment
> enters the context through a reference and nothing else from the
> request does: a query value, a posted value and the clock never do.

Three words name what a route says of the keys. A **seed** is a value a
route puts into the operation's context: a fixed value or a default. A
**prefill** is a value the form's elements start from that never enters
the context: a value a link sends, or one the code building the form
hands it. A **reference** is a seed whose whole value is a name in
braces, `'{name}'` (it matches `^\{[A-Za-z_][A-Za-z0-9_]*\}$`), and
whose value is the raw value of the route's path variable of that name.
Any other value in braces is a literal, so a map's value written as
JSON, `'{"format": "html"}'`, stays one. An operation parameter is
none of the three: [the operation form](#the-operation-form) maps a
variable of the path, or a plain default, onto it by name.

The first case is an add page: a basic page whose title is set, or whose
body starts with text, served by the generic `create`:

```yaml
example.add_welcome:
  path: '/admin/content/add-welcome'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Add a welcome page'
    _surface_resource: 'entity.content:node.page'
    _surface_operation: 'create'
    _surface_fixed: { 'title.value': 'Welcome' }
    _surface_defaults: { 'body.value': 'Start here.' }
```

A basic page's title and body are each a field's structure, attached at
the field's name, so the paths name the `value` key inside each
([Content entities](entities.md)). `OperationRoute` reads both defaults
after the operation has built its context, and applies them to it:

- **`_surface_fixed`** narrows each key to its value:
  `withConstraint($path, 'Choice', ['choices' => [$value],
  'surface_fixed' => TRUE])`, the last option saying the Choice
  fixes the key, which the build reads and takes off. A fixed key holds
  its value: it starts from the value whatever default it declares, is
  made required where it was optional, and is drawn as that value, an
  item that posts it; both documents state it as `const`, its label
  under `x-surface.fixed`, and list it as required. Any other value is
  refused by the key's name, and a submission cannot leave the key
  empty.
- **`_surface_defaults`** gives each key the value it starts from in
  this build: `withDefaults([$path => $value])`. The form's element
  starts from it, both documents state it as `default`, and a create that
  leaves the key stores it. Only an operation that creates takes
  defaults: on an update the stored value stands, so a route that names
  defaults on one is refused.

**Values.** YAML types a value (`3` an integer, `true` a boolean, `'3'`
a string). Either way it is read through [the coercion table](semantics.md#the-coercion-table), so `copies: '3'` and `copies: 3`
are the same integer, and held to the key's definition with typed data,
its constraints included, before the build uses it. A key that holds one
value takes one: a list or a map given to it is refused before any
constraint reads it, as a context's own default or fixed value is, and
the message says "it holds one string, not a list or a map."

**Reach.** A path reaches what [a site default](declaring-a-resource.md#a-sites-own-defaults) reaches: a key
the resource declares, a key inside a map it declares
(`greeting.format`), a key of a structure attached at a fixed key
(`title.value`), which the build hands to that child's own context, and
a key an alter mounts (`third_party_settings.<module>.<key>`). It does
not reach a key inside variants, a collection's item or a map of keys
from the site, a whole attached structure, a secret or a computed key,
and a fixed value is one value, never a list or a map. Nor does it reach
an identity key the context knows: which thing the page is about is the
operation's to say, from its parameters, so a route and a parent context
that fix or fill one are refused alike, before the context's values are
written. Each is refused saying why.

**Order.** The declared default, then the site's
(`surface.defaults`), then the operation's own `withDefaults()`,
then the route's `_surface_defaults`, each over the last; a fixed
value is what its key starts from over all of them. A prefill is laid
over these as the element's starting input, the code's first and a
link's over it, and what the visitor posts stands over both.

**Not a gate.** The operation's permission and its access class gate the
route as before. A fixed value is a convenience for that page: the
operation's other doors (its tool, HTTP, another route) still accept any
value the operation does. A value that must hold at every door is the
operation's own constraint, or its access class's answer
(why: [decision](decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for)).

#### A value from the route's path

A seed may be a reference to a variable of the route's own path, so the
URL says which value a key is fixed to or starts from. The inquiry's
`department` is a key that refers to a term of the departments
vocabulary (`'entity:taxonomy_term:departments'`):

```yaml
example.inquiry_department:
  path: '/contact/{department}'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title_callback: '\Drupal\surface\Form\SurfaceOperationForm::title'
    _surface_resource: 'example.inquiry'
    _surface_operation: 'send'
    _surface_fixed: { department: '{department}' }
  requirements:
    department: '\d+'
  options:
    parameters:
      department:
        type: 'entity:taxonomy_term'
        bundle: [departments]
```

On `/contact/12` the department is drawn fixed to term 12 and stated
`const: 12`, and a posted value other than 12 is refused. On
`/contact/999` core's converter answers 404 before access is asked,
since no term of the vocabulary has that id.

- **The raw value.** A reference reads the variable as the URL carries
  it, never the object a converter loads from it: the term arrives as
  its id, read through the coercion table and held to the key as a
  literal is.
- **Bounded by the route.** The variable needs a requirement
  (`requirements.<name>`) or a type a converter loads
  (`options.parameters.<name>.type`), for which core writes the
  converter it found under `options.parameters.<name>.converter`; a type
  no converter loads leaves the raw text unbounded, and the route is
  refused. The bound only has to exist, so `'.+'` passes: what holds the
  value is the key, its type and its constraints, and a value the key
  refuses names nothing on that page
  ([below](#refusal-by-who-chose-the-value)).
- **Named by the path.** A reference names a variable of the route's own
  path. A name the path does not have is refused, so a misspelled
  `'{deparment}'` is never a seed that sets nothing.
- **A whole value.** `'Team {department}'` is text, not a reference: a
  key reads one value of its type, and a fragment inside text could not
  be held to it.
- **An optional variable left out seeds nothing.** With `department: ~`
  under defaults the route matches both `/contact` and `/contact/12`, and
  the reference fixes the department only when the path names one. One
  route then serves both URLs, so it takes the place of a route at
  `/contact`, which would match the same path; its title must not read
  the optional variable, which `_title` prints as written when the URL
  leaves it out.

A reference puts the visitor's value in the context, within the bound
the route sets, on that page alone, and is no parameter: the subject a
cosmetic layer is told is still the first of the operation's parameters
the path carries. The PHP spelling of the same pin, for a department
every door must see fixed, is a parameter,
`send(?string $department = NULL)`, whose method fixes the key with
`withConstraint()` when it is given: then the API, the tool and the
React page carry the department too, and `const` is true at each. A
route may not fix the key again over that method
([below](#refusal-by-who-chose-the-value)).

#### One route for every bundle

A derived resource's id may hold a variable of the route's path, filled
from its raw value before the resource is looked up, so one route serves
every resource its path names:

```yaml
example.node_add:
  path: '/example/node/{node_type}/add'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title_callback: '\Drupal\surface\Form\SurfaceOperationForm::title'
    _surface_resource: 'entity.content:node.{node_type}'
    _surface_operation: 'create'
  options:
    parameters:
      node_type:
        type: 'entity:node_type'
```

`/example/node/article/add` serves the create of
`entity.content:node.article`, the id every other door already addresses
that resource by. The variable is bounded as a reference's is, here by
the `entity:node_type` converter, which loads its type where node is
installed; a variable nothing bounds, or a `{name}` the path does not
have, is refused. The converter answers 404 for a node type that does
not exist. A node type the deriver lists no resource for, one with no
stored default form display, fills an id no resource has: a page not
found to any account, since there is no operation to ask a permission
of. The context is the deriver's create, which knows the entity type and
the bundle from its binding, so nothing in it is read from the request
([below](#which-build-holds-a-routes-values)).

`SurfaceOperationForm::title` titles the page with the operation's
label, which a derived create already words for its bundle, "Add Basic
page", and answers "Page not found" for an id the path fills that no
resource has. It serves any route whose `_title` cannot say what it
serves, such as one with an upcast placeholder, which `_title` would
print as its raw id; for node, core's `NodeController::addPageTitle`
serves too. The catalog lists the route under every derived resource
whose id it fills, its path filled for each
([The site's catalog](catalog-command.md#the-doors)).

An edit route for every bundle cannot be written so: `/node/{node}/edit`
names a node whose bundle is known only once it is loaded, and nothing
answers which derived resource an entity belongs to. The bundle goes in
the path, `/example/node/{node_type}/{id}/edit`, and the update refuses
a node of another bundle as one that names nothing here
([Content entities](entities.md#the-doors)).

#### Keys a link or the code starts

A route may list the keys a link may start, each from a query
parameter:

```yaml
example.inquiry:
  path: '/contact'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Contact us'
    _surface_resource: 'example.inquiry'
    _surface_operation: 'send'
    _surface_query: { subject: subject }
```

`/contact?subject=Pricing` draws the form with the subject starting as
Pricing, and the visitor may change it. Each entry is a key path and the
query parameter that starts it, a plain name of letters, digits, `_` and
`-`, so a nested path takes a name of its own, `'body.value': body`: PHP
renames `body.value` in a query string to `body_value` and reads `a[b]`
as an array before the form reads either. A query parameter the route
does not list is the page's own, and the form does not read it.

- **The first draw only.** A prefill is laid over what is stored and
  declared when the form is first drawn, before anything is submitted.
  From then on input stands for itself: a rebuild, a validation round and
  the submission read what was posted, and an element the post leaves
  out takes what is stored or declared, never the prefill again, so a
  select the visitor cleared stays cleared.
- **Held to its key, dropped when refused.** Each value is read through
  the coercion table and held to its key's definition, its constraints
  included, as a seed is. A value the key refuses is left out, saying
  nothing, and the element starts from what is stored or declared: a
  crafted link cannot make the form show a value its key refuses, nor
  turn the page into a 403 or a 404. A list or a map given to a key that
  holds one value, `?subject[]=Pricing`, is such a value. An empty value,
  `?subject=`, starts nothing, and a key the build fixes or locks keeps
  what the build says.
- **Never the context.** A prefill enters no build and no document. The
  contract panel reads it as an answer in progress on the first draw, so
  a key it narrows is shown narrowed, while the `default` the panel
  states stays the declared or the route's; the tool, HTTP and the
  OpenAPI document never see it. The page varies by each query parameter
  the route lists, `url.query_args:<name>`, and by no other.
- **Reach.** A seed's, with one more refusal: a map with keys of its own
  is named key by key, never whole, since a value laid over it whole
  would replace each key inside it the value leaves out, a stored one
  among them; an identity key the context knows is refused as it is for
  a seed. `third_party_settings.<module>.<key>` is reached; the
  mount and a module's map in it are not.
- **On any operation that draws values.** A link may start a key of an
  update as well as of a create: what every visitor starts from is a
  default, which only a create takes, while a prefill is one visitor's
  input, judged on Save as any input is. A delete draws no values, so a
  route serving one that lists query names is refused.

A path no prefill reaches is the route written wrong: the form's first
draw refuses it with a `\LogicException` naming the route and the key,
whatever the link sends ("The example.inquiry route's
"_surface_query": "code" on example.inquiry is a secret, which no
form draws, so no prefill starts it."), and the catalog lists it before
a visitor meets it. A query name no link can use fails the router
rebuild: an empty one, one in braces (what a link sends is input, never
a reference), one holding anything but letters, digits, `_` and `-`
(PHP renames or splits it), one Drupal or this module reads for itself
on a form page (`destination`, `token`, `_format`, `_wrapper_format`,
`ajax_form`, `page`, `q`, `dryRun`), and one that is a variable or a
default of the route, which a link built with `Url::fromRoute()` leaves
out of its query.

The code that builds the form may hand it values the same way, for a
value only the request can give, such as today's date. The route keeps
the two naming defaults, a `_controller` in place of `_form`, so the
operation's access check still gates the page. A value of the URL that
names nothing is then a 403 with no reason, not a 404: the access check
lets such a URL through only to a controller it knows answers it with a
page not found, the operation form as `_form` and a React page, and it
cannot know what another controller does with the URL:

```yaml
example.report_add:
  path: '/reports/add'
  defaults:
    _controller: '\Drupal\example\Controller\ReportController::add'
    _title: 'Add a report'
    _surface_resource: 'entity.content:node.report'
    _surface_operation: 'create'
```

```php
use Drupal\surface\Form\SurfaceOperationForm;

public function add(): array {
  return $this->formBuilder()->getForm(SurfaceOperationForm::class, [
    'field_report_date' => ['value' => date('Y-m-d', $this->time->getRequestTime())],
  ]);
}
```

The values are in the resource's own shape, nested as a door sends them,
and each is named by its key's path. They are held as a link's are and
laid first, a link's over them, and a key no prefill reaches is the same
`\LogicException`. The controller hands values, never a context: the
route's access check and the form read the context through one reader,
`OperationRoute`, so the two cannot disagree, and a date or an account
in the context would key the build per day or per visitor and ride in
the serialized form.

#### Which build holds a route's values

When a route that seeds is matched, its access check holds the seeds to
the resource at once, by building it for no account, so a seed the
resource refuses is refused before the page is drawn. Whether the build
cache, `cache.surface_build`, keeps that build for the next request
depends on what the context holds:

- **Nothing read from the request.** Literal seeds and plain defaults,
  on an operation handed no value of the path: the build is kept, tagged
  with everything it read, one entry per seeded context and language, so
  two routes seeding the same values on one operation share it. A route
  serving every bundle is kept once per bundle: the path chooses the
  resource, and the deriver's create knows its bundle from its binding.
- **A value of the path.** An operation handed a value of the path, or a
  reference read: the same build is held for this request alone
  (`ResourcesInterface::holdDeclared()`), never read from the bin or
  written to it, so the bin holds no entry per value a URL carries.

A route that seeds nothing holds nothing when it is matched, and a
prefill enters no build. Within a request the memo keeps the 32 builds
asked for most recently, of either kind.

#### Refusal, by who chose the value

The route's access check asks in this order, and the first refusal is
the answer:

1. what the route alone shows to be written wrong, below, which reads
   nothing of the URL;
2. which resource and operation the route names, a `{name}` in the
   resource's id filled from the path;
3. the operation's permission, from those two ids alone;
4. the context the route's parameters and seeds build;
5. the access class, for that context.

What it answers depends on who chose what is wrong:

- **The route's author.** A route written wrong is refused with the
  reason, and is never served. What the route alone shows is refused
  when the router is rebuilt (`OperationRouteSubscriber`), with the
  `\InvalidArgumentException` core throws for a reserved variable name
  in a path: a `{name}` in the resource's id, or a reference, that names
  no variable of the path or one nothing bounds; one path under both
  `_surface_fixed` and `_surface_defaults`, since a fixed
  value is also what its key starts from; a `_surface_query` that
  is no map of key paths to query names, or names one no link can use;
  and `_surface_fixed` on a React page
  ([below](#what-the-page-states)). Such a refusal stops the rebuild for
  the whole site, as core's own does: no route of any module is written
  until the routing file is corrected, and the router serves the routes
  of the last rebuild that finished. Each of these is asked again when
  the route is matched, with the same words, for a route no rebuild saw.
  The rest needs the operation or the resource built, and is refused
  when the route is matched, a 403 that gives the reason: the shape of
  `_surface_fixed` and `_surface_defaults`; a path that names
  no key or that no seed reaches; a literal value its key refuses, "The
  example.add_welcome route: The "create" operation sets "body.value" on
  entity.content:node.page to ..., which the key does not accept: ...";
  defaults on an operation that does not create; an identity key the
  context knows, fixed or filled; a path the operation's own context
  already narrows with a Choice, since the route's Choice would take its
  place; a seed or a query entry on an operation that deletes; and a
  plain default that nothing reads, that the operation refuses, or whose
  value names nothing. What the route alone shows is refused before the
  permission is asked, to any account, with the same words; past the
  resource's id the permission is asked first, so an account it refuses
  hears the permission's reason, whatever else the route gets wrong.
- **The visitor.** A value of the URL that names nothing is a page not
  found for an account the operation lets in, and tells no other account
  anything. A converter that loads nothing answers core's 404 before
  access is asked, to any account, as core's own routes do. A value no
  converter saw reaches the access check, which asks the operation's
  permission first: an account it refuses hears the permission's reason,
  the same for every value. For an account it lets in (a `%key` in it
  filled from the URL's parameters), a value names nothing when it is an
  id no entity has, an entity the operation does not take as its own (a
  node of another bundle), or a reference whose value its key refuses.
  An operation method says so itself by throwing `NamesNothingException`
  naming the parameter, as the content entity update does for a node of
  another bundle; any other `\InvalidArgumentException` it throws is a
  403 with its reason. In the request's own dispatch the access check
  lets the request through, for this request alone, and the form answers
  with a page not found, its exception carrying the reason, at a route
  served by the operation form (`_form`, or a form extending it) or by a
  React page (`ResourceReactPage::served()`); at a route any other
  controller serves, it is refused with no reason, a 403, since the
  check cannot know the controller reads what the value names; a thing
  removed between the draw and the Save is a page not found on the Save
  too. An id the path fills that no resource has is a page not found to
  any account, since there is nothing to ask a permission of. Asked
  about a link (`Url::access()`, a menu link, a local task, a
  breadcrumb), each of these is refused with no reason, so no link to a
  page not found is drawn. Only the value is the visitor's: a reference
  written at a path where no value can go (a path that names no key, a
  key out of reach, a secret, a computed key, a whole map, a known
  identity key, a default on an update) is the route written wrong,
  refused with the reason whatever the URL carries.
- **The visitor, where the operation declares no permission.** Nothing
  lets an account in before the value is read, so a value that names
  nothing is refused to every account, for this request alone and with
  no reason: that a value names nothing is told only to an account a
  permission let in. A derived content entity operation is such an
  operation, since entity access answers for it once the thing is loaded
  ([Content entities](entities.md#the-doors)).
- **Whoever wrote a link, or the code building the form.** A prefill
  its key refuses is dropped, saying nothing
  ([above](#keys-a-link-or-the-code-starts)), never a 403 or a 404. A
  path no prefill reaches is the route or the code written wrong, the
  first draw's `\LogicException`.

#### What the page states

On the form page, the contract panel and anything else built from the
form's context state the route's seeds, since both come through the
context the build read: a fixed key as `const`, its label under
`x-surface.fixed`, and a filled key with `default`. A prefill is no
seed, so no document states it. The tool, the OpenAPI document and HTTP
describe operations, not routes, and do not change.

A React page served at a module's own route takes a route's defaults and
query prefills as starting values, and fixes nothing:

```yaml
example.card_app:
  path: '/example/cards/app/{copies}'
  defaults:
    _controller: '\Drupal\surface_react\Controller\ResourceReactPage::served'
    _title_callback: '\Drupal\surface_react\Controller\ResourceReactPage::servedTitle'
    _surface_resource: 'example.card'
    _surface_operation: 'create'
    _surface_defaults: { title: 'Dear you', copies: '{copies}' }
    _surface_query: { occasion: occasion }
  requirements:
    copies: '\d+'
```

The route names the resource and the operation as a form route does, and
the same access check gates it. Beside what the generic page hands the
app, this one hands it `seeds.defaults`, each key the route's defaults
start, by its key's path and valued as the build holds it (`'{copies}'`
at `/example/cards/app/4` starts the copies from the number 4), and
`prefill`, each value a link sends, held to its key as the form holds it
and dropped when refused; a key the build fixes or locks is left out of
both. The app lays the stored values, then the defaults, then the
prefill, each over the last, once, as it loads, and narrows for a
started value something depends on as it narrows for a change. Nothing
of the route reaches a document: the app's documents are the
operation's, fetched from the API, which knows no route, so they state
the operation's `default` and none of the route's. Its write goes to the
operation's own API path, which holds a value to the operation's
constraints alone, so a key the page stated fixed would take any value
there, and `_surface_fixed` on such a route fails the router
rebuild, naming the route. The page varies by each query parameter the
route lists, as the form does, and `servedTitle()` titles it with the
operation's label. The generic page,
`/surface-react/{resource}/{operation}`, carries no seeds, and answers a
URL that names nothing as the form's route does
([React](https://github.com/mlander/surface/blob/main/modules/surface_react/README.md#a-page-at-a-modules-route)).

### Access, twice

The route's requirement, `_surface_operation_access`, is the
operation's permission and then the resource's access class, the
permission asked from the two ids before anything the URL carries is
read ([Refusal, by who chose the value](#refusal-by-who-chose-the-value)).
A route whose defaults name a resource and an operation has it without
saying it (`OperationRouteSubscriber` adds it); a routing file may still
say `_surface_operation_access: 'TRUE'`. The form
asks the same answer again on its way in, as the floor under the route
for a caller that reached the form another way, and hands it to the
pipeline on its way out. The second is the one that matters, for the
reason in [the non-drift rule](#hosts-and-the-non-drift-rule) above: a
route requirement is checked when the page is built and the submit
arrives later. The operation is the resource's own, not a host's, so
an answer with no opinion is a refusal on both halves, as it is on the
route: the form refuses anything but allowed on the way in and hands the
pipeline a decisive answer on the way out.

### The cosmetic seam

Three methods, `Form\SurfaceFormCosmeticsInterface`, and they are
the whole of what a form class is still for:

| Method | Decides |
| --- | --- |
| `alterSurfaceForm()` | What this one form borrows or adds around the built elements: an element of core's (vertical tabs), markup. Runs after every element exists, including the actions and the groups the keys' arrangement declares. |
| `surfaceFormMessage()` | What the person is told. NULL for the generic sentence. |
| `surfaceFormRedirect()` | Where they are sent. NULL to stay on the form. |

A route names one, in `_surface_cosmetics`. Each method is told the
operation id, as `$operation`, and, as `$subject`, the raw value of the
first of the operation's parameters that is a variable of the route's
path (the string in the path, before upcasting), or `NULL` when the path
carries none. A parameter a plain default pins is never the subject: it
is the same on every URL of the route, so it says nothing of which thing
the page is about.

Grouping, order, placeholders and help are not a cosmetic layer's: they
are declared on the keys ([Arranging the keys](#arranging-the-keys)),
where every door reads them, and a layer is read by one form alone. What
a layer keeps is what is truly one form's.

Nothing in a cosmetic layer can change what a value means. Every element
keeps its name and its `#parents`; `#group` only relocates an element at
render time. If you find yourself wanting to change allowed values, a
default, or whether a key is required, that belongs on the resource or
structure: in [its class](declaring-a-resource.md), or in an
[alter](resources.md#alters) when it is somebody else's. Not
here.

Swapping an element's `#type` for a better-looking one borrows what it
checks along with what it draws, so clear its `#element_validate` when
you do. An element validates before any form level handler, and a form
state keeps only the first error set on an element, so a check that came
with the element answers first and the data surface's own violation
(the one that names the value that was refused) is dropped in silence. The form
builder does exactly this where it draws core's machine name element for
a key of type `machine_name` computed from a title
([A title and its machine name](declaring-a-resource.md#a-title-and-its-machine-name)),
and the stock number widget does the same for core's number element,
whose own `#min` and `#max` check would otherwise answer before a
`Range` and its message
(why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)).

A swap must also keep the shape the element posts. The widget that drew
the element reads the value back from what it posts, in the
definition's shape, and the cosmetic layer runs after the widget has
chosen the element, never instead of it. Radios and checkboxes need no
swap at all now: they are stock widgets, asked for on the definition
(`setSetting('widget', 'radios')`), and a list of a few choices is
checkboxes unasked ([Widgets](widgets.md#which-element-a-list-of-choices-is)).
Asked for there, the element is the same at every door: the served
contract says `radios` or `checkboxes`, and the React app draws them,
where a swap changes the form alone. A swap that changes what is posted
is a widget of its own ([Writing a widget](widgets.md#writing-a-widget)).

`surface_examples_node_type` is the worked example: two routes, a
resource that declares its three groups, and a cosmetics service that
puts those groups into core's vertical tabs (placed before the first
group, so the tabs render before the groups they gather), the machine
name's mirror-while-typing, the message and the redirect. There is no
form class in the module at all.

### When a hand-written form is still right

Three cases, and the demo module is the second one:

1. **The page is not one operation's page.** A form collecting a data
   surface beside several unrelated things (a confirmation step, a
   batch, an entity form it rides inside) is a form, and it composes the
   data surface's container itself.
2. **The structure and the destination belong to different owners.**
   `surface_examples_plugins` renders the demo block's structure into a
   `StateStorage`, which is exactly the claim it exists to make: a
   structure is independent of where its values are stored. An operation
   route writes to the resource's own storage, and the demo block's
   structure names none, because its host stores it; serving it at a route would
   mean giving it a destination that is not its own.
3. **The host protocol is not a route.** Field UI, the block layout
   form, the manage display form: those are the host trait families
   above, not this.

## A standalone form

A form that is not a plugin needs none of the above. Get the data
surface (from a plugin's `getSurface()`, or from
`ResourcesInterface::build()` with a context), build the container, and
hand the submitted values to the pipeline with storage:

Compose `SurfaceHostTrait` for the three collaborators (the
pipeline, the form builder, and the in-progress AJAX input), and the
class is three delegations long:

```php
public function buildForm(array $form, FormStateInterface $form_state): array {
  $surface = $this->surface();
  // The pipeline's own merge rule, reused rather than restated: the
  // declared defaults, then whatever the storage holds, and then, laid
  // over the top by surfaceFormValues(), the in-progress choice an AJAX
  // rebuild is refining against, minus whatever that choice orphaned.
  $values = $this->surfaceFormValues(
    $surface,
    $this->surfacePipeline()->accept($surface, [], $this->storage()->load($surface)),
    $form_state,
  );
  $form['surface'] = $this->surfaceFormBuilder()
    ->buildSurfaceForm($surface, $values, $form_state, 'my-form');
  return $form;
}

public function submitForm(array &$form, FormStateInterface $form_state): void {
  $surface = $this->surface();
  $builder = $this->surfaceFormBuilder();
  $storage = $this->storage();
  $values = $builder->extractSurfaceValues($surface, $form['surface'], $form_state, $storage->load($surface));
  $result = $this->surfacePipeline()->submit($surface, $values, $storage);
  if (!$result->isValid()) {
    $builder->flagSurfaceErrors($result->violations, $form['surface'], $form_state);
  }
}
```

A data surface plus storage is a complete configurable thing.
`surface_examples_plugins` ships exactly this against a `StateStorage`, asking
the block manager for the demo block and reading its data surface rather
than repeating its keys, which is what any other consumer would do.
