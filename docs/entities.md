# Content entities

This page covers content entities, such as nodes: how the module
derives a resource for each bundle's create and update form, and one
for every bundle of an entity type at once, with one structure per
field. Use it when you want an entity's form, tool or API
from the module instead of from a class you write.
[Storage](introduction/10-storage.md) and
[Access](introduction/15-access.md) introduce a content entity as
storage and field access as access.

A content entity's create and update form, said as a resource. It is
the example meant to find where the pattern is thin: field types
already describe their values as typed data, which is less than a
resource or structure says, so the resource and each field's structure are derived
from the typed data first and layered on top of it (labels, defaults,
what narrows, access, operations), with a class written by hand where
the derivation is not enough.

`surface_examples_entity` does it, through two derivers
([Derived resources](resources.md#derived-resources)):

| Deriver | Derives | One per |
| --- | --- | --- |
| `Resource\ContentEntityResources` | `entity.content:<entity type>.<bundle>`, `entity.content:node.page` | bundle of a content entity type with a bundle key and a stored default form display |
| `Resource\ContentEntityResources` | `entity.content:<entity type>`, `entity.content:node` | content entity type with at least one such bundle |
| `Resource\ContentEntityResources` | `entity.content:<entity type>.<bundle>.fields`, `entity.content:node.page.fields`, a structure | such bundle: its fields, the variant the entity type's resource holds for it |
| `Resource\FieldItemStructures` | `field.item:<entity type>.<bundle>.<field>`, `field.item:node.page.body` | field that bundle's resource carries |

Both are read at discovery, and each derivative is cached as an entry
of its own under the tags of what it was read from: a field item its
field's and its storage's configuration (a base field the override a
site may save for it), a bundle its bundle's configuration, its form
display and the fields it shows. The index of what there is carries
`entity_field_info`, `entity_bundles` and the form display list. Saving
a field clears the index, that field's entries and its bundle's, what
this request already read of them, and that bundle's derived tools; the
other bundles' entries and tools are kept
(why: [decision](decisions.md#what-is-paid-once-and-what-per-request)). They stay derivers, not
[keys from the site](resources.md#keys-that-come-from-the-site): a bundle
is a resource of its own, with operations, storage and identity, and a
field a structure every bundle carrying it shares; keys from the site
are for one resource asking a value or a group per thing.

The module serves `node.page` at `/surface-entity/node/page/add`,
`/surface-entity/node/{id}/edit` and `/surface-entity/node/{id}/delete`,
through the generic operation form, with the contract panel beside the
first two and a confirmation on the third; `/surface-entity` lists every
content entity resource the site has, what each carries and what it
leaves out and why, read from the registry.

## What is derived from what

| What | From |
| --- | --- |
| The content entity resource's keys | the identity (`entity_type`, `bundle`, `id`), then one child per component of the bundle's default form display, in its weight order |
| Its operations | `create`, knowing the entity type, the bundle and that there is no id yet (`id` known as NULL, so locked empty), or for an entity type whose ids the caller names, taking `id` as a required key ([below](#which-entity-types-take-an-id-on-create)); `update`, `read` and `delete`, each taking the identity key `id`, whose value the storage loads the entity by (an id that names nothing, or an entity of another bundle, is a not found at `id`); `read` is read only; `delete` is destructive, takes no values and removes the entity with every translation and revision ([below](#the-storage)) |
| Its access | the entity's: create access for the bundle on create, update access for the entity on update, view access on a read, delete access on a delete |
| Its storage | `ContentEntityStorage`, below |
| Its outputs | `id`, the entity's id once saved |
| A field item structure's keys | the item's property definitions the field type stores and a caller sets, typed as the coercion table carries them (the table below) |
| Each key's label | the field's, when the item has one key; the property's, when it has several |
| Each key's description | the field's, when the item has one key |
| The widget | the form display component's, read for the main property: `options_buttons` asks a key with a list of values for radios; `entity_reference_autocomplete` asks a reference key for the autocomplete, its `match_operator` and `match_limit` handed to core's autocomplete route; `string_textarea` and `text_textarea` draw the string as a textarea; `string_textfield`, `email_default`, `number`, `boolean_checkbox` and `options_select` draw what the stock rule draws, so no word is set; each component's `size`, `rows` and `placeholder` travel where a widget or the arrangement reads them; any other component type asks for nothing, and what is accepted stays the field's (`FieldItemDerivation::WIDGETS`) |
| Required | on the main property, when the field is required |
| Default | the field's literal default (`getDefaultValueLiteral()`), cast to the key's type |
| Constraints | `Length`, `Range`, `Regex` and `Choice` from the property definitions and from the field type's own `getConstraints()` (its `ComplexData` per property), and on a reference field's `target_id` the reference key's `SurfaceValidReference`, answered by the field's own handler; the rest are checked when its storage validates the entity |
| What settings narrow | a list field's allowed values (`LabeledChoice`), a text field's formats (`LabeledChoice`, then a runtime refiner), a reference's targets (a constraint and its options resolver), a date's form (`Regex`) |
| Its operations | `create`, `update`, `read` and `delete`, which the parent invokes to match its own; each knows the entity type, the bundle and the field, and `update`, `read` and `delete` the entity's id. Field access asks a `delete` as a write, as an `update` ([below](#the-storage)) |
| Its access | field access, as the child's access: open, locked or omitted; on a read, open or omitted, by view access |
| The entity type resource's keys | the identity (`entity_type`, `id`), `bundle`, and `fields`: variants `bundle` chooses, one per bundle with a form display, each that bundle's `.fields` structure, which attaches the same field children the bundle's resource holds at its root |
| Its operations | `create`, knowing the entity type and that there is no id, `bundle` held to the bundles the account may create (`SurfaceCreatableBundle`); `update`, `read` and `delete`, taking the identity key `id`, the entity loaded by the storage, `bundle` fixed to the entity's, the fields under `fields`; `delete` removes an entity of any bundle |
| Its access | on create, open to an account that may create at least one bundle, the chosen bundle's create access asked at `bundle` once a value is sent; on update, the entity's update access; on a read, its view access; on a delete, its delete access |
| Its storage | `ContentEntityStorage`, the bundle read from the values on create and from the entity on update, the fields under `fields` |

The parent labels each child's key with the field's label and
description, so the form draws a fieldset per field and the contract
names it as the field.

The entity type's resource holds the fields under `fields`, not at its
root, since variants attach at a key
(why: [decision](decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type)):

```json
{"bundle": "page", "fields": {"title": {"value": "Hello"}, "field_description": {"value": "A page."}}}
```

where the bundle's resource takes `{"title": {"value": "Hello"}, ...}`,
and a refusal's path is `fields.title.value`. The variants come from
the site (`Variants\BundleFieldStructures`), so while an entity type
has at most `surface.settings:site_list_limit` bundles both documents
state each bundle's fields as a conditional on `bundle`; past it the
full schema names the bundles and marks `fields` deferred, and the
fields of one bundle come from `GET .../schema?choose=/fields=<bundle>`
or the narrowing tool, `surface:entity.content:node:create:schema`
([Variants from the site](resources.md#variants-from-the-site)).
`ContentEntityTypeResourceTest` holds each bundle's variant equal to
that bundle's resource.

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
| `list_string`, `list_integer` | `value` | `string`, `integer`, a labeled choice of the allowed values |
| `list_float` | `value` | `string`, a labeled choice of the allowed values as written |
| `datetime` | `value` | `string` in the storage form: `YYYY-MM-DD` for a date, `YYYY-MM-DDTHH:MM:SS` in UTC for a date and time, held to it by a `Regex` |
| `timestamp`, `created`, `changed` | `value` | `integer`, seconds since the epoch |
| `link` | `uri`, `title` | `uri`, `string`; no `title` when the field switches titles off |
| `entity_reference` | `target_id` | `integer` or `string`, as the target entity type's ids are |

A date travels as the string core stores, and a timestamp as the
integer it stores, because those are the two the [coercion table](semantics.md#the-coercion-table) carries without guessing: a
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
'field.item', overriddenBy: FieldItemStructure::class)]` names the attribute,
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

The items are the entity's own on an update, and a throwaway entity of
the bundle's on a create, as core's own form makes one. What both answers read
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

## Reading with view access

Both resources declare `read`, read only, taking the entity as `id`,
rather than take the one the build adds beside `update`, which would
ask update access and the edit answers above
(why: [decision](decisions.md#a-read-is-an-operation-of-the-resource)).
`ContentEntityAccess` answers a context whose id is `read` with the
entity's `view` access, as core's own page does, and each field's item
is invoked through its own `read`, which `FieldItemAccess` answers with
`fieldAccess('view')` alone:

| View | The child, on a read |
| --- | --- |
| allowed | open: read, whether the account may edit it or not |
| not allowed | omitted: not answered, at any door |

So an account that may view a page and not edit it reads it through
the tool, `surface:entity.content:node.page:read`, and through `GET
/surface-api/entity.content:node.page/{id}`, and is refused `update`; a
field it may view and not edit is open in the read and locked in the
update. Neither read declares a permission, as the update declares
none: core's view access is asked once the entity is loaded. On the
entity type's resource, `read` fixes `bundle` to the entity's as
`update` does, and answers the bundle's fields under `fields`, the same
fields the bundle's read answers (`ContentEntityReadTest`,
`ContentEntityTypeResourceTest`).

## The storage

`ContentEntityStorage`, in `surface_examples_entity` rather than beside the
engine's storage classes, because what it reads and writes is the field
item derivation's keys, which the engine does not own
(why: [decision](decisions.md#the-content-entity-storage)).

- **The entity an id names** is the storage's to load: an operation is
  handed the identity key `id`, and its context asks the storage for the
  entity (`ContentEntityStorage::entity()`), which refuses an id that
  names nothing with a not found at `id`
  ([decision](decisions.md#a-parameter-names-a-key)).
- **Load** reads the entity the context names: its identity, and each
  carried field's first item, property by property, cast to the key's
  type. An empty field is absent, so it starts from its default.
- **Prepare** sets the accepted values on an entity: a new one of the
  bundle on a create, a fresh copy of the stored one on an update
  (`loadUnchanged()`), never the object a route or a form holds. Each
  field goes through the entity's own `set()`, its first item merged
  over what the item holds, so a property the resource does not carry (a
  link's options) keeps its value. On a create, a field nobody answered is
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
- **Revisions**: on an update, a resource that carries the revision log field
  makes a new revision, by the account the write is made as, at the
  request time, with the log the caller gave; one that does not leaves
  revisions to the entity type's default (none, for a node saved
  outside a form). A new entity with an owner is that account's too,
  unless the values name one. The account is handed to `prepare()` and
  `commit()`, the current user when the caller names none
  ([No door-specific context](resources.md#no-door-specific-context)).
- **Delete** (`DeletableStorageInterface`): `prepareDelete()` finds the
  entity the context names and refuses one that is gone, `not_found` at
  the whole call; it asks no access, which the access class answers,
  and validates nothing. `delete()` loads the entity as stored
  (`loadUnchanged()`) and deletes it, which removes every translation
  and every revision it has: core's storage rule, in one transaction of
  core's own. Core's delete form, on a translation that is not the
  default, removes that translation alone; the example follows storage,
  since it writes the default translation only. The rule is the entity
  type's storage handler's, so a module that replaces the handler
  replaces it: the contrib trash module's keeps the entity, its
  translations and its revisions in the database, marked deleted and
  restorable, and a load finds it no more. What the entity's
  delete hooks do beside the database is theirs. The access class asks
  core's delete access, for a node what `delete any <type> content` and
  `delete own <type> content` grant. Each field child is invoked under
  the delete through its own `delete`, which `FieldItemAccess` asks as
  a write, as under an update (edit access, else view access): it
  decides which keys the delete answers, so a field the account may not
  see is not answered, never whether the entity is removed. The delete
  carries the keys the update carries for the same account, so an
  update and a delete of one thing in a stage compare one token
  (`ContentEntityDeleteTest`).
- **In a workspace**: core forbids delete access, in a workspace that is
  not Live, to an entity the workspace did not create, so a delete there
  is refused `access`, made at once or at a stage's apply, and nothing
  is removed; an entity created in the workspace may be deleted there
  ([Staging](staging.md#stages-and-workspaces)).
- **Secrets**: none; a node has no secret field.

A dry run stops after prepare; a PHP caller reads the prepared values
on the result (the identity, whether a revision would be made, and each
field's item), and a door answers the accepted values as the read
would, a field whose view access is denied left out
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## Which entity types take an id on create

Whether `create` takes `id` is read per entity type from its id key's
base field definition (`FieldItemDerivation::callerNamesId()`):

- **Computed**: an integer id, which core's SQL storage makes serial and
  assigns on save, or an id field with a default of its own. The create
  knows there is no id yet, and its `id` output answers the one storage
  gave. Every content entity type core and the common contributed
  modules ship is one: node, media, user, term, block content, file.
- **Named by the caller**: any other id, a string with no default. The
  create takes `id` as a required key, its length the id field's, and
  the entity is created under it; an id an entity already has is
  refused at `id`, code `taken`, so the create is idempotent, a second
  identical call refused for the id the first took. `update`, `read`
  and `delete` take the id as for any entity type.

Why the field and not the entity type: an integer is what makes the
storage assign the id, and a string id may have a default the entity
holds once created; the field definition says both
(why: [decision](decisions.md#an-entity-types-id-is-computed-or-named-by-the-caller)).

## The doors

- **The form**: `/surface-entity/node/page/add`,
  `/surface-entity/node/{id}/edit` and `/surface-entity/node/{id}/delete`,
  the generic operation form, with the contract panel on the first two;
  the delete draws a confirmation that names what is removed and one
  button, "Delete Basic page". The update and delete routes hand the
  operation `{id}` as the path carries it, and the resource's storage
  loads the node
  (`ContentEntityStorage::entity()`): an id no node has, and a node of
  another bundle, are values that name nothing here.
  A derived content entity operation declares no permission, since
  entity access decides per entity once the node is loaded, so no
  account is let in before the value is read, and every door refuses
  such a value alike, saying nothing of what it names: the form's route
  with a 403 that gives no reason, the React page, the API and the tool
  with their one fixed refusal. The 404 a form route gives an account
  the operation lets in, for a value that names nothing, needs a
  permission to ask first
  ([forms](forms.md#refusal-by-who-chose-the-value); whether derived
  operations should declare one is
  [open](decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for)).
- **One route for every bundle**: a module may serve every bundle's add
  page at one route, the bundle in its path filling the resource's id,
  `_surface_resource: 'entity.content:node.{node_type}'`, gated by
  create access for that bundle
  ([One route for every bundle](forms.md#one-route-for-every-bundle));
  this module ships the basic page's two routes alone. An edit route for
  every bundle puts the bundle in its path too,
  `/{node_type}/{id}/edit`, since a node's bundle is known only once the
  node is loaded, and refuses a node of another bundle as above.
- **The tools**: `surface:entity.content:node.page:create`, `:update`,
  `:read` and `:delete`, derived like every other operation's. The field
  item structures have no storage, so they are no tools. Every bundle
  with a form display has its four. Every entity type with such a bundle
  has six: `surface:entity.content:node:create`, `:update`, `:read`,
  `:delete`, and a narrowing tool beside each write that takes values,
  `:create:schema` and `:update:schema`, since its fields are variants
  the site lists. A delete tool takes `id`, `dry_run` and `stage`.
- **HTTP**: `GET /surface-api/entity.content:node.page/{id}` serves the
  read, its envelope and its schema at `.../{id}/schema`; the update's
  envelope is its custom method's, `GET .../{id}:update`, and `PATCH
  .../{id}` runs it as before; `DELETE .../{id}` runs the delete,
  `?dryRun=true` rehearses it.
- **Both kinds**: a site has `surface:entity.content:node:create` and
  `surface:entity.content:node.page:create`, `POST
  /surface-api/entity.content:node` and `POST
  /surface-api/entity.content:node.page`, and the same for the edit,
  `PATCH /surface-api/entity.content:node/{id}`. Call the bundle's
  resource when the bundle is known ahead and a contract stated
  outright matters: a client generated from the OpenAPI document, a
  form. Call the entity type's when the bundle is chosen at call time or
  the tool list must stay short: choose the bundle, ask the schema with
  it, then create. Nothing here declares which; the MCP server's tool
  configuration and an agent's own selection choose
  (why: [decision](decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type)). The
  entity type's resource has no form route here; a route that names it
  gets the bundle select, whose change redraws the fields.
- **The React app**: `/surface-react/entity.content:node.page/create` and
  `/surface-react/entity.content:node.page/update?id=<nid>`. The derived id
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
- Translations. The resource edits the entity's default translation,
  whatever the language of the request; `langcode` is left out.
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
   a create, and the entity applies the callback when it is created. The
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
10. **A reference field's `target_id` is a reference key.** It is the
    id of the field's target type with `SurfaceValidReference`, its bundles the
    ones the field's handler settings name, answered by the field's own
    selection handler with the field's settings
    (`References::definition()` with `handler:` and `handlerSettings:`),
    so it lists, looks up and accepts what core's widget offers and the
    field's `ValidReference` accepts, for the account asking. Which
    targets that is depends on the account, so the list is live: listed
    while there are at most `surface.settings:reference_list_limit` (50
    as shipped, [Options](options.md#a-key-that-refers-to-an-entity)),
    carrying what the selection read: `user.permissions`, and
    `user.node_grants:view` for a node or a comment, `user` for a target
    type core does not list
    (why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects));
    past that the key takes an id, the lookup (`GET .../{x}:lookup`)
    finds one and the constraint's validator asks the handler. Both
    documents mark it `x-surface.reference`. A form display that draws
    the field with `entity_reference_autocomplete` asks for the
    autocomplete widget, its `match_operator` and `match_limit` handed
    to core's autocomplete route
    (why: [decision](decisions.md#a-reference-field-is-a-reference-key)).
11. **Which text formats an account may use** is a fact about the
    account, so it is a runtime refiner, never run ahead of time; the
    full schema lists the field's formats and marks the key `live`.
    Core's text widget also preselects the account's first format; the
    structure leaves the format unanswered, and a body saved without one
    renders through the fallback format.
12. **A static tool definition is no account's**
    (why: [decision](decisions.md#a-static-tool-definition-is-no-accounts)):
    the `create` tool's cached `values`, which no parameter refines, is
    built for no account, every field there and the ones field access
    may lock or leave out marked, and derived as the anonymous account.
    A tool instance lists `values` again for the account that asks, its
    static field access stated and its runtime field access marked.
13. **Tool definitions are cached apart.** The registry clears itself
    when a field or a display changes, and the tool plugin manager's
    cache did not, so a new field reached the `create` tool's static
    definition only at the next cache rebuild. Settled 2026-10-19:
    the tool door clears the tool definitions when a tag they were
    derived from is invalidated
    (why: [decision](decisions.md#tool-definitions-after-a-field-changes)).
14. **The entity's id is storage's.** The create operation knows `id` as
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
    field is a fieldset with one element, both labeled with the field's
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
(create, update with a revision, an entity violation on its key, a dry run,
the derived tools, the React door's `created`, the contracts),
`ContentEntityDeleteTest` (the delete through the pipeline, the tools
and the form route, every revision and translation removed, core's
delete access, field access asked as a write) and
`ContentEntityResourceFormTest` (the two routes and the landing page in
a browser) and `ContentEntityTypeResourceTest` (the entity type's
resource narrowed by each bundle equal to that bundle's, the bundles an
account may create, a bundle refused at its key, the edit's fixed
bundle, the deferred fields past the limit, the tools and the paths). No field refines another, so there is no browser test of a
refinement.
