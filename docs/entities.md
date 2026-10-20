# Content entities

A content entity's create and update form, said as a resource. It is
the example meant to find where the pattern is thin: field types
already describe their values as typed data, which is less than a
resource or structure says, so the resource and each field's structure are derived
from the typed data first and layered on top of it (labels, defaults,
what narrows, access, operations), with a class written by hand where
the derivation is not enough.

`data_surface_entity` does it, through two derivers
([Derived resources](resources.md#derived-resources)):

| Deriver | Derives | One per |
| --- | --- | --- |
| `Resource\ContentEntityResources` | `entity.content:<entity type>.<bundle>`, `entity.content:node.page` | bundle of a content entity type with a bundle key and a stored default form display |
| `Resource\FieldItemStructures` | `field.item:<entity type>.<bundle>.<field>`, `field.item:node.page.body` | field that bundle's resource carries |

Both are read at discovery and cached with the rest of it, under the
tags of what they were read from: `entity_field_info`, `entity_bundles`,
the form display list and the bundle's form display. Saving a field or
a display clears the entry, the registry this request already read with
it, and the derived tools. They stay derivers, not
[keys from the site](resources.md#keys-that-come-from-the-site): a bundle
is a resource of its own, with operations, storage and identity, and a
field a structure every bundle carrying it shares; keys from the site
are for one resource asking a value or a group per thing.

The module serves `node.page` at `/surface-entity/node/page/add` and
`/surface-entity/node/{id}/edit`, through the generic operation form
with the contract panel beside it; `/surface-entity` lists every
content entity resource the site has, what each carries and what it
leaves out and why, read from the registry.

## What is derived from what

| What | From |
| --- | --- |
| The content entity resource's keys | the identity (`entity_type`, `bundle`, `id`), then one child per component of the bundle's default form display, in its weight order |
| Its operations | `add`, knowing the entity type, the bundle and that there is no id yet (`id` known as NULL, so locked empty); `edit`, taking the entity as `id`, typed `entity:<entity type>` |
| Its access | the entity's: create access for the bundle on add, update access for the entity on edit |
| Its storage | `ContentEntityStorage`, below |
| Its outputs | `id`, the entity's id once saved |
| A field item structure's keys | the item's property definitions the field type stores and a caller sets, typed as the coercion table carries them (the table below) |
| Each key's label | the field's, when the item has one key; the property's, when it has several |
| Each key's description | the field's, when the item has one key |
| Required | on the main property, when the field is required |
| Default | the field's literal default (`getDefaultValueLiteral()`), cast to the key's type |
| Constraints | `Length`, `Range`, `Regex` and `Choice` from the property definitions and from the field type's own `getConstraints()` (its `ComplexData` per property); the rest are checked when its storage validates the entity |
| What settings narrow | a list field's allowed values (`LabeledChoice`), a text field's formats (`LabeledChoice`, then a runtime refiner), a reference's targets (a constraint and its options resolver), a date's form (`Regex`) |
| Its operations | `add` and `edit`, which the parent invokes to match its own; each knows the entity type, the bundle and the field, and `edit` the entity's id |
| Its access | field access, as the child's access: open, locked or omitted |

The parent labels each child's key with the field's label and
description, so the form draws a fieldset per field and the contract
names it as the field.

## Covered field types

| Field type | Keys | How each maps |
| --- | --- | --- |
| `string`, `string_long`, `telephone` | `value` | `string`, with the field's `max_length` |
| `email` | `value` | `email` |
| `text`, `text_long` | `value`, `format` | `string`; `format` a choice of the formats the field allows |
| `text_with_summary` | `value`, `summary`, `format` | as above |
| `boolean` | `value` | `boolean` |
| `integer` | `value` | `integer`, with the field's `min` and `max` |
| `decimal`, `float` | `value` | `float`, with `min` and `max` |
| `list_string`, `list_integer` | `value` | `string`, `integer`, a labelled choice of the allowed values |
| `list_float` | `value` | `string`, a labelled choice of the allowed values as written |
| `datetime` | `value` | `string` in the storage form: `YYYY-MM-DD` for a date, `YYYY-MM-DDTHH:MM:SS` in UTC for a date and time, held to it by a `Regex` |
| `timestamp`, `created`, `changed` | `value` | `integer`, seconds since the epoch |
| `link` | `uri`, `title` | `uri`, `string`; no `title` when the field switches titles off |
| `entity_reference` | `target_id` | `integer` or `string`, as the target entity type's ids are |

A date travels as the string core stores, and a timestamp as the
integer it stores, because those are the two the [coercion
table](semantics.md#the-coercion-table) carries without guessing: a
string is a number written out, an integer is a whole number in any
notation. An ISO date with a zone, or a timestamp given as a date,
would be a conversion the pipeline does not make; the `Regex` says the
form in its message, and the React app's text input and the form's
textfield take it as written.

Anything else is left out, with a note: a field type not in the table
(`language`, `file`, `image`, `uri`, `path`, `uuid` and every
contributed type), a field that is computed, read-only or internal,
one whose cardinality is not 1, and a form display component that is no
field at all (an extra field another module adds, such as Scheduler's).
The resource's `notes` say which and why, and its description counts
them; `/surface-entity` lists them. On this site's basic page that is
`langcode` (language), `field_tags` (any number of values),
`moderation_state` and `path` (computed), and `publish_status` and
`scheduler_settings` (no field).

## Overrides

Where the derivation is thin, a module writes a field type's item
structure by hand: a class in its `src/Structure/` carrying
`#[FieldItemStructure(type: 'telephone')]` and implementing
`FieldItemStructureInterface`, whose static `defineItem(BlueprintInterface
$inputs, FieldDefinitionInterface $field)` fills the blueprint, and whose
own static `#[RefinesInput]` methods refine it. It replaces the
derivation for every field of that type; the field's label, operations
and access stay derived. Its keys are the item's property names, which
is how the storage writes them.

Discovery finds these classes for the deriver: `#[DerivesResources(base:
'field.item', overrides: FieldItemStructure::class)]` names the attribute,
and the registry hands the deriver every class in an enabled module's
`src/Resource` or `src/Structure` carrying it. The test module's
`TelephoneItemStructure` is the one written so far
(`ContentEntityAccessTest`); an address field's, which needs it most,
is the next.

## Field access is child access

Each field is a child invoked through its own operation, so its access
is the child's: `FieldItemAccess` asks the entity's access control
handler `fieldAccess('edit')` with the field's items, and, when that is
not allowed, `fieldAccess('view')`:

| Edit | View | The child |
| --- | --- | --- |
| allowed | | open |
| not allowed | allowed | locked: drawn disabled, `readOnly` in both contracts, locked in the tool, a posted value ignored |
| not allowed | not allowed | omitted: in no form, contract or tool input, a posted value an unknown key, and what is stored left as it was |

The items are the entity's own on edit, and a throwaway entity of the
bundle's on add, as core's own form makes one. What both answers read
is carried on the data surface, so a contract and a form vary by it.
Core's node field access lets only an account with `administer nodes`
change the author, the creation date and the promotion flags (the
published flag also with `administer node published status`), and the
revision log only when the bundle makes new revisions; for anyone else
those children are locked. The test module locks one field and hides
another (`ContentEntityAccessTest`).

Each answer is static or runtime by what core's answers read
([Static and runtime access](resources.md#static-and-runtime-access)):
`administer nodes` is static, so the full schema and the tool listing
state those locks for the account that asks; an answer that read the
entity is runtime, marked there and settled once the entity is known;
one core gave with nothing at all, no hook restricting the field, is the
same for every account and is said with `user.permissions`. An edit
tool's static definition names no entity, so field access has nothing
to ask, and every field is listed marked.

The parent's own access is the entity's create or update access, so
the resource never lets anyone write what core's form would not.

## The storage

`ContentEntityStorage`, in `data_surface_entity` rather than beside the
engine's storage classes, because what it reads and writes is the field
item derivation's keys, which the engine does not own
([decision](decisions.md#the-content-entity-storage)).

- **Load** reads the entity the context names: its identity, and each
  carried field's first item, property by property, cast to the key's
  type. An empty field is absent, so it starts from its default.
- **Prepare** sets the accepted values on an entity: a new one of the
  bundle on add, a fresh copy of the stored one on edit
  (`loadUnchanged()`), never the object a route or a form holds. Each
  field goes through the entity's own `set()`, its first item merged
  over what the item holds, so a property the resource does not carry (a
  link's options) keeps its value. On add, a field nobody answered is
  not set at all, so the entity's own default applies: a default value
  callback, the field type's own. Then the entity validates itself, and
  every violation is filed under the key it is about, by its property
  path: `field_description.0.value` is the `field_description` child's
  `value`, a list-level one is the child itself, and one about the
  entity as a whole is filed under `@entity`. As core's form does, a
  violation about a field the account may not edit, or one the resource
  does not carry, is dropped.
- **Commit** applies the prepared values again and saves. It then
  describes what it saved: a load that knows no id reads the entity it
  just created, so the `id` output answers with the new id, the derived
  tool returns it and the React app can open the page it made.
- **Revisions**: on edit, a resource that carries the revision log field
  makes a new revision, by the current account, at the request time,
  with the log the caller gave; one that does not leaves revisions to
  the entity type's default (none, for a node saved outside a form).
- **Secrets**: none; a node has no secret field.

A dry run stops after prepare; a PHP caller reads the prepared values
on the result (the identity, whether a revision would be made, and each
field's item), and a door answers the accepted values as the read tool
would, a field whose view access is denied left out
([decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## The doors

- **The form**: `/surface-entity/node/page/add` and
  `/surface-entity/node/{id}/edit`, the generic operation form with the
  contract panel. The edit route upcasts `{id}` to the node; a node of
  another bundle is refused by the page's edit operation, so the route
  answers 403.
- **The tools**: `data_surface:entity.content:node.page:add`, `:edit`
  and `:edit:read`, derived like every other operation's. The field item
  structures have no storage, so they are no tools. Every bundle with a form
  display has its three.
- **The React app**: `/surface-react/entity.content:node.page/add` and
  `/surface-react/entity.content:node.page/edit?id=<nid>`. The derived id
  holds a colon, which a route parameter takes as it is.
- **The landing page**: `/surface-entity`.

## What it does not do

- Collections. `attachList()` exists ([Resources](resources.md#collections)),
  but a field with more than one value is still left out: deriving it
  as a collection waits for the entity side to resume.
- Keys another module adds. An alter may name every content entity
  resource by the deriver's base id (`#[Alters('entity.content')]`),
  and its keys are built, refined, validated and offered, but a content
  entity has nowhere to keep `third_party_settings`, so its storage does
  not keep them.
- Translations. The resource edits the entity in the language it loads
  in; `langcode` is left out.
- A form's extras: Scheduler's, path aliases, menu settings, the
  moderation state, the vertical tabs.

## Holes

Every place where core's APIs or this pattern fell short, and what was
done about it.

1. **A default value callback needs an entity.**
   `FieldDefinitionInterface::getDefaultValue()` takes the entity being
   made, because a callback may read it; `getDefaultValueLiteral()` has
   only the literal. A field whose default is a callback (a node's
   author) states no default here; the storage leaves the field unset on
   add, and the entity applies the callback when it is created. The
   item's notes say so. Drafted as a core issue: a subject-less tier of
   the default value API.
2. **Field access takes items.** `fieldAccess()` takes a field's items,
   and a field has none without an entity, so on add the question is
   asked of a throwaway entity of the bundle, as `EntityForm` does.
   Node's own handler shows why it must: its answer for the revision
   log reads `$items->getEntity()`, so asking with no items fails.
   Drafted as the same core issue's second half: `$items === NULL` as
   the general answer.
3. **A reference's default is stored by uuid**, and turned into an id
   by `processDefaultValue()`, which takes an entity. The derivation
   loads the target by uuid itself.
4. **A relative date default** (`default_date_type`) is worked out by
   `processDefaultValue()` when an entity is created, so it is not
   stated; the item's notes name it.
5. **A field type's own constraints are an instance method's.**
   `FieldItemInterface::getConstraints()` is asked of an item, so the
   derivation makes one from the item definition, with no entity, which
   is all the ones a structure can state need. A field type whose
   `getConstraints()` reads its entity is caught and carries none.
6. **Two bounds, one Range.** A numeric field type declares its minimum
   and its maximum as two `Range` constraints, each with its own
   message; carried onto one key they merge into one `Range`, which
   Symfony allows only with its own wording, so the field type's
   messages are dropped.
7. **Constraints a structure cannot state** (`LinkType`,
   `LinkAccess`, `ValidReference`, `ReferenceAccess`, a `PathAlias`,
   Scheduler's) are not carried; the entity checks them at prepare and
   the violation lands on the key. Neither contract can say them; the
   item's notes name them, which is all a reader learns of them before
   submitting.
8. **An option cannot be a float.** An option set is keyed by value,
   and a PHP array key is never a float, so a `list_float`'s value
   travels as its allowed value's spelling, `'1.5'`.
9. **A decimal is a float here.** Typed data has no decimal type; a
   value with more digits than a float holds loses them before the
   entity's precision check sees it.
10. **Which targets a reference may hold depends on the account.** The
    selection handler checks access, so the list is live: a constraint,
    `DataSurfaceReferenceable`, points at it, and its options resolver
    lists the targets for the account asking while there are at most
    64 (the served contract's cap), carrying what the selection read:
    `user.permissions`, and `user.node_grants:view` for a node or a
    comment, `user` for a target type core does not list
    ([decided](decisions.md#every-door-projects-what-the-read-tool-projects));
    past that the key is free and the validator asks the handler.
11. **Which text formats an account may use** is a fact about the
    account, so it is a runtime refiner, never run ahead of time; the
    full schema lists the field's formats and marks the key `runtime`.
    Core's text widget also preselects the account's first format; the
    structure leaves the format unanswered, and a body saved without one
    renders through the fallback format.
12. **A static tool definition is no account's.**
    [Decided](decisions.md#a-static-tool-definition-is-no-accounts):
    the `add` tool's cached `values`, which no parameter refines, is
    built for no account, every field there and the ones field access
    may lock or leave out marked, and derived as the anonymous account.
    A tool instance lists `values` again for the account that asks, its
    static field access stated and its runtime field access marked.
13. **Tool definitions are cached apart.** The registry clears itself
    when a field or a display changes, and the tool plugin manager's
    cache did not, so a new field reached the `add` tool's static
    definition only at the next cache rebuild. Settled 2026-10-19:
    `data_surface_tool` clears the tool definitions when a tag they were
    derived from is invalidated
    ([decision](decisions.md#tool-definitions-after-a-field-changes)).
14. **The entity's id is storage's.** The add operation knows `id` as
    nothing, so it is locked empty, and the new id is an output the
    storage reads back after the commit. React's `created` used to hold
    every identity key the write accepted against where the thing now
    lives; a key the write left empty now names nothing and is not
    compared.
15. **Content moderation decides publishing.** `moderation_state` is
    computed and left out, so on a bundle under a workflow the entity
    takes the workflow's default state, which sets `status` whatever the
    `status` child said: a page added published through the tool on this
    site was stored unpublished.
16. **A form display is not only fields.** A component may be an extra
    field another module draws (Scheduler's `publish_status`); it is
    left out by name.
17. **What the resource does not carry is not this write's business.**
    Entity validation validates every field; a violation about a field
    the resource leaves out, or one the account may not edit, is dropped,
    as core's form drops it. A hidden required field could therefore be
    saved empty through the resource exactly as through core's form.
18. **A field is a fieldset.** Every field is a child, so a one-value
    field is a fieldset with one element, both labelled with the field's
    label. The form's cosmetic layer could flatten it; nothing does yet.
19. **Base fields compose by trait, and the derivation lists them one by
    one.** An owner, a changed time and a published flag come from
    `EntityOwnerTrait`, `EntityChangedTrait` and `EntityPublishedTrait`
    on many entity types; the bundle resource will want each trait's
    fields as one structure it [mixes in](resources.md#mixins), shared by
    every entity type that uses the trait. *Open.*

## Tests

`ContentEntityDerivationTest` (one resource per bundle with a form
display, the item structure per covered type, what is left out, defaults,
the registry cache and its tags), `ContentEntityAccessTest` (locked and
omitted children from field access, the parent's entity access, an
alter by base id, an override by field type), `ContentEntityStorageTest`
(add, edit with a revision, an entity violation on its key, a dry run,
the derived tools, the React door's `created`, the contracts) and
`ContentEntityResourceFormTest` (the two routes and the landing page in
a browser). No field refines another, so there is no browser test of a
refinement.
