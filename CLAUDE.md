# Surface API — working notes

A data surface is an immutable, sealed group of core data definitions,
built once from a resource or structure and read by every caller: a
generated form renders it, a pipeline accepts and validates values
against it, and storage writes them. The rule the whole module turns on
is that refinement may only *narrow*, so what a data surface advertised
when sealed stays true afterwards.

Where to look: **`docs/`** is the current documentation, and the only place
kept true (start at `docs/index.md`; the pattern is `docs/pattern.md`, the
build step `docs/how-it-fits.md`, every decision the pattern left open
`docs/decisions.md`). **`ROADMAP.md`** is what is next.
**`PLAN.md`**, **`ADOPTION.md`**, **`HARDENING.md`** are design history —
read them for why, never as a description of today. **`REWORK.md`** is
the record of the rework that brought the module to the pattern.

## Vocabulary

- **Resource**: a thing with identity and storage that operations read
  and write; the form, the tool and the schema are derived from it. It
  may live in a config object, a config entity, State, a plugin's
  settings or a content entity, and only its storage knows which (the
  registration settings first, a content type, then a basic page,
  `entity.content:node.page`, the same shape applied to content). One
  with no identity keys, such as a settings object, is a singleton
  resource. `#[Resource(id, identity:, storage:, access:, label:,
  description:)]`.
- **Structure**: a group of keys with no identity or storage of its
  own, held by a parent (attached or as a variant) or a plugin's host.
  `#[Structure(id, access:, label:, description:, widget:)]`, `widget:`
  the word of the widget that draws it as a whole wherever it is
  attached, checked at the build like every key's `widget` setting
  (`docs/decisions.md#a-structure-may-ask-for-its-widget-and-a-widget-never-validates`). The label and
  description, both optional, say what either is called and is for:
  `ResourceDefinition::label()` and `description()`, else the one
  hosting plugin's (`ResourcePlugins::hostNames()`); they title and
  describe its def in both documents (a def with no label is titled by
  its key), offer a variant whose `#[Variant]` gives no label, print in
  the catalog, and read back from a def's `title` and `description`
  (`docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for`).
- **Shape**: a named type (Smithy's word), of three kinds: resources,
  structures, and named scalar shapes. The third is `#[Shape(id)]` on a
  class in `src/Shape/` whose static `define()` returns one scalar
  `DataDefinition` (no default, not required), used as
  `$inputs->add('license', License::class, t('Event license'))`: the key
  may only narrow it (checked at build), a port may read it
  (`reads('code', Sku::class)`, wired only to a key of that shape), an
  `#[Alters(License::class)]` tightens it at every key with
  `narrow('<id>')` and `describe('<id>')`, and both documents write it
  once as `$defs.<id>`, each key `$ref` first with what it adds beside
  (`docs/declaring-a-resource.md#named-shapes`).
- **Operation**: a way a resource is asked for, `#[Operation]`, its
  static facts on the attribute: id, label, `description:` (the tool's
  first sentence and both documents' top `description`), permission and
  `creates:`, and `gate:`, access borrowed from another thing's owner
  (another resource's operation, or core's `entity:<type>:<op>` and
  `field:<type>.<field>:<op>`), asked after the permission about the
  entity the context knows or per item of a collection, the access class
  narrowing on top (example 17,
  `docs/decisions.md#a-gate-is-borrowed-never-copied`). On the class, repeatable, when the context needs nothing
  more (the default; no method); on a static method returning an
  `OperationContext` when it needs more, the attribute's id and
  `creates` stamped on it and a context saying otherwise refused
  (`docs/decisions.md#an-operation-on-the-class`), the one exception an
  update, read or delete of a thing a stage creates, which
  `ResourcesInterface::operation(..., $stage)` builds as the create's
  context under the asked id, unstamped and still creating
  (`docs/decisions.md#a-stage-decides-the-context-of-a-thing-it-creates`); only ever on its
  resource's or structure's own class. Its parameters are values, never
  objects: a method's is typed with a scalar named for a key of its
  resource (`string $id`), a class type refused at discovery
  (`OperationParameter::fromReflection()`), and the operation loads its
  subject through its resource's storage (`ConfigEntityStorage::named()`,
  `ContentEntityStorage::entity()`), which refuses a value that names
  nothing; `OperationArguments` loads nothing, and no surface route
  converts a parameter an operation takes, every door handing the raw
  value (`docs/decisions.md#an-operation-is-handed-values-never-objects`).
  Its context adds `known`
  identity, `withConstraint()` (a Choice of one value with `surface_fixed`
  fixes the key),
  `withDefaults()` (what keys start from, only on a context that
  creates) and `withChild()`, the first two by key path, a path into a
  fixed child handed to its context, never an identity key it knows; a
  route fixes or fills keys of the operation it serves with them,
  `_surface_fixed` and `_surface_defaults`, and a fixed key
  holds its value (required, drawn fixed, `const`)
  (`docs/forms.md#a-route-that-fixes-or-fills-keys`); a child with operations
  is invoked through the one its parent names, else the one with its
  parent's id, else the one of its parent operation's kind. It states how a call
  behaves, `readOnly:` and `destructive:`, and idempotence, derived
  from whether it creates unless declared (a create whose caller names
  the thing declares it), and its kind, `create`, `update`, `read` or
  `delete` (`deletes:`, which takes no values), always derived from the
  attribute. The default operation of a kind has the kind's name as its
  id (`create`, `update`, `read` or `list`, `delete`; `configure` for an
  update on a singleton), its label Drupal's word ("Add a venue"); an
  id `add`, `edit`, `remove` or `get` is refused at discovery
  (`docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals`).
  The read of one thing is `read`, read only, taking no structure
  (`OperationDefinition::readsOne()`): its parameters in, the stored
  values out. Where a resource with storage has a default update that
  can be asked on its own and declares no `read`, the build adds one
  (`OperationDefinition::defaultRead()`, `ResourceRegistry::defaultRead()`),
  labeled "Current values:" and the update's label, its `reads` the
  update's id: it takes the update's parameters, permission and gates
  and is asked in the update's context (`contextId()`;
  `ResourcesInterface::operation()` builds the update's), so nothing
  loosens; the catalog lists it as "default read of" the update. An
  owner that declares `read` replaces it and answers access for reading
  (the content entity example asks core's view access)
  (`docs/decisions.md#a-read-is-an-operation-of-the-resource`).
  One about the things together names the structure whose keys it
  takes in place of the resource's, `structure:` (a listing's page, a
  reorder's list; `docs/resources.md#an-operation-that-takes-keys-of-its-own`).
  A create's tool requires what the resource requires, an update's
  requires nothing at its door
  (`docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door`); a tool
  carries the facts to the Tool API and both documents state them under
  `x-surface.operation`
  (`docs/decisions.md#an-operation-states-how-it-behaves`).
- **Blueprint**: what a resource or structure declares its members
  into, handed to `defineInputs()` and `defineOutputs()`
  (`BlueprintInterface`); an alter gets the add-only
  `BlueprintAdditionsInterface`. A class may instead declare its keys as
  properties, each carrying the attribute of the verb it stands for
  (`#[Input]`, `#[Attach]`, `#[Rule]`, nineteen in `src/Resource/Attribute/`),
  with `DeclaresByProperties`, whose `defineInputs()` and
  `defineOutputs()` call the same verbs in property order
  (`ResourceBuild\PropertyDeclaration`, which reads no value): a second
  spelling, never a second contract, either per class, never both
  (example 18,
  `docs/decisions.md#a-class-may-declare-its-keys-as-properties`). A key holds one value of a type in the
  one table the blueprint, the emitter and the reader read
  (`Contract\LeafTypes`: `string`, `email`, `uri`, `datetime_iso8601`,
  `integer`, `timestamp`, `float`, `boolean`, or a shape or config
  schema type of one); any other type is refused at the blueprint, at
  any depth, naming the method meant
  (`docs/decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader`).
  An object is declared only by a method that is one JSON Schema
  construct: `attach()` / `attachList()` (`$ref`), `addMap()` (inline
  object), `mixIn()` (`allOf`), the variants (`if`/`then`),
  `addEach()` / `attachEach()` (an object keyed by the site), `addList()`
  (array);
  `addDefinition()` takes one value and refuses a map or a list
  (`docs/decisions.md#a-blueprint-takes-no-complex-data-types`).
- **Anonymous map**: `addMap($key, $label, fn (BlueprintInterface $map)
  => ...)`, an object only its owner holds, written inline with no def,
  the one way to declare a map of plain keys (the schema reader and the
  generator write it too);
  plain keys make a map definition, a callable that attaches, mixes in,
  narrows or resolves makes a structure with no id
  (`ResourceRegistry::defineMap()`, whose inputs run the owner's
  declaration again to the key, so the callable may run more than once
  and is held to the keys it declared first), still written inline; an alter's and
  an output's hold plain keys alone (`docs/decisions.md#an-anonymous-map`).
- **Storage**: where a resource's values live: `load()`, `prepare()`,
  `commit()` on a `ResourceStorageInterface`.
- **Layout**: how a storage lays accepted values out where that differs
  from how they are asked for, `StorageLayoutInterface`; an alter hands
  one for its mount with `AltersStorageLayoutInterface::storageLayout()`,
  and a `ConfigObjectStorage` subclass answers one from `layout()`. A
  layout implementing `StoredSchemaInterface` says each translated key's
  stored schema, which the build's check skips and the config schema
  generator writes, both through the storage's `translatedKeys()`
  (`docs/decisions.md#a-layout-is-the-storages-one-word-on-its-shape`).
- **Alter**: another module's class that adds keys to a resource or
  structure and narrows it, `#[Alters]` in `src/Alter/`; one that
  implements `OperationAccessInterface`, the access class's interface,
  answers access for it too, after the owner, the stricter standing (an
  access class named on `access:` that carries `#[Alters]` is refused).
  It adds no outputs: what a call answers is its operation's, and a
  reaction to a save is ECA's; one with `alterOutputs()` is refused
  (`docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands`).
- **Attach**: a fixed child at a key, `attach()`; an alter attaches only
  at its own mount, `third_party_settings.<module>`, as an adapter.
- **Port**: a value a structure reads from its container, declared under
  a name of its own with `$inputs->reads('capacity', 'integer')` and
  wired by the container with `attach(..., reads: ['capacity' =>
  'seats'])`; a live sibling value, never stored by the structure.
- **Mixin**: a structure whose members are merged into the owner's own
  keys, `$inputs->mixIn(TimestampsStructure::class, reads: [...])`:
  stored flat, refined in the owner's frame, emitted as `allOf` of its
  def and the owner's properties; the owner's only, never an alter's. A
  child it attaches at a fixed key or in a collection is the owner's
  child at that key, nothing wiring its ports.
  Nest for a thing of its own, mix in for shared members that are part
  of this thing (`docs/resources.md#mixins`).
- **Variants**: a tagged union whose tag is a sibling key: structures
  that key chooses between, attached at one key,
  `attachVariants($key, by: $sibling)`. Always open: the parent names
  none, any module may declare one.
- **Stored flat**: variants whose chosen keys the storage keeps flat
  beside the deciding key, `->setSetting(Variants::STORED_AS,
  Variants::FLAT)` on the map `attachVariants()` returns (`#[Setting]`
  beside `#[AttachVariants]`), applied by `Storage\FlatVariantsLayout`
  for the config storages; the contract is unchanged
  (`docs/resources.md#stored-flat`,
  `docs/decisions.md#variants-stored-flat-are-a-layout`).
- **Variant**: one structure (or resource) among the variants, for one
  value of the deciding key, `#[Variant(of:, at:, for:)]`; `at` is the
  key the variants sit at.
- **Variants from the site**: variants a provider lists rather than
  classes declare, `attachVariants($key, by: $sibling, from:
  Provider::class)`; the provider is the one keys from the site name, a
  `SiteListInterface` (one listing for several owners a
  `BoundSiteListInterface`, handed `arguments:` and asked `listFor()`),
  whose `list()` answers a `SiteList` (per value,
  its label and the JSON Schema of what it chooses, `schema:`, or a
  structure class, `structure:`; a start or a lock refused), asked at
  every build and refused uncacheable or varying by the account. Each variant is a structure read
  from its schema, id `<owner>.<key>.<value>`; a `#[Variant]` wins for a
  value both name; stored open (`type: ignore` in the config schema),
  unless the provider is closed (`ClosedSiteListInterface`, a static
  `structures()` naming every structure it may list, its list naming
  values and labels alone), whose variants are written as a class's. A
  field type's settings are one (`surface_examples_field`'s
  `FieldSettingsSchemaVariants`, each read from its config schema), and
  count against the site list limit as any list the site decides. A deciding key whose variants offer one value is
  drawn as that value and stated `const`, its label under
  `x-surface.fixed`; a secret of a variant not chosen is kept as stored
  (example 14, `docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter`).
  Defs the entries share are listed once, `SiteList::addDefs()`, and
  each read structure is built once per request and context, wherever it
  is attached (`Resources::buildRead()`), so variants nest: a component's
  slots, each holding a component with its own props and slots. A
  provider implementing `EntrySchemasInterface` lists values and labels
  alone and answers `entrySchema($id)` and `entryDefs()` when a door
  reads a variant (`SiteEntries`), as `ComponentProps` and
  `ComponentSlots` do; the pipeline asks an unsettled variant's document,
  not the variant, whether it holds a secret
  (`SchemaReader::holdsNoSecret()`)
  (`docs/resources.md#variants-from-the-site`,
  `docs/decisions.md#a-slot-holds-text-a-component-or-components`).
- **Reading a schema**: `Contract\SchemaReader`
  (`surface.schema_reader`), the emitter's mapping run backwards: a
  JSON Schema document (2020-12; draft-04 to -07 converted first,
  `upconvert()`), taken as an array and walked as `Schema` values, read
  into a blueprint, for a variant from the site, or registered as a
  structure, `structure($document, $id)`. A `$ref` to a type of the
  Portable Schema library and a `portable-typeBy` are read through the
  registry `surface.portable_registry` (the type library, a site's
  schemas added). A def is one
  structure (a read structure, registered by
  `ResourceRegistry::defineRead()` as `schema:<id>:<hash>` and listed by
  nothing), a conditional of the vocabulary a declared rule. What no
  keyword says is a **foreign constraint**: the part left over, a
  `JsonSchema` constraint on the key, or on the frame
  (`DefinitionMap::frameConstraints()`), checked with opis and named
  under `portable-check` as `drupal:JsonSchema` with its schema. A
  constraint and its keywords
  are one table, read both ways (`Contract\ConstraintKeywords`, a row per
  constraint in `Contract\Keywords\`: `Range`, `Length`, `Count`,
  `UniqueItems`, `NotBlank`, `Email`, `Regex`, `Uuid`, `Sequentially`,
  `AtLeastOneOf`; `@internal`, closed), whose one type guard,
  `appliesTo()`, holds both ways: a row that does not apply, or cannot
  state its options, is named under `portable-check` on write and kept
  in the
  `JsonSchema` constraint on read
  (`docs/decisions.md#one-table-maps-a-constraint-to-its-keywords-both-ways`).
  The full schema of a
  declaration reads back to itself (`SchemaRoundTripTest`)
  (`docs/reading-a-schema.md`,
  `docs/decisions.md#a-schema-can-be-read-back-into-a-structure`).
- **Reader**: a class that reads a definition written elsewhere into
  the blueprint's verbs, `Reader\DeclarationReaderInterface`:
  `ConfigSchemaReader`, a config schema type read straight, with no
  JSON Schema between (registered as `config_schema:<type>`, `:own` for
  its keys less those of its frame type, the type itself or one it
  extends, matched by keys; a caller's defaults laid over as a second
  source; a type chosen by its own key, `condition.plugin.[id]`, the
  fallback's keys and the chosen type's at `configuration`, stored flat
  beside the own key), and `TypedDataReader`, a typed data definition;
  `LeafRules` holds what both share and `Reading` what no verb took, by
  path. A key
  either reads no shape for is declared **open**, `any` held whole to
  nothing (`LeafRules::open()`), since a derived structure is closed.
  `Variants\ConfigSchemaTypes` lists the variants a type named through
  another key chooses, bound by keyed arguments (`['type' =>
  'block.block.*', 'path' => 'settings']`, `['type' =>
  'condition.plugin.[id]']`, `'by'` optional, checked when given), a
  plugin's `#[UsesStructure]` before its schema where a plugin manager
  gives the values (`docs/reading-a-schema.md#config-schema`).
- **Collection**: a list of child instances at a key, `attachList()`.
- **Keys from the site**: one key per thing the site has, in a map at a
  key, `addEach('forms', 'boolean', from: Provider::class, label:
  t('Forms'))` (a value of a type or shape per thing, its label
  required, or of an entry definition as `addList()` takes an item; a
  reference per thing is that definition with the reference constraint,
  `addEach('pages', DataDefinition::create('integer')->addConstraint(
  'SurfaceValidReference', ['entityTypeId' => 'node']), from:
  Provider::class, label: t('Pages'))`, `#[Each('integer', ...)]` with
  `#[Constraint(..., item: TRUE)]` as a property) or `attachEach('rows',
  Row::class, from: ..., label: ...)` (a structure per thing); the provider is a service implementing
  `SiteListInterface`, whose `list()` answers a `SiteList` (id, label,
  optional start and lock, and the cacheability of what it read; an id
  with a dot or a colon, a schema or a structure refused), asked at
  every build. Refused uncacheable or varying by the account; a
  locked entry is held to what is stored; `attachEach(..., reads:
  ['selling' => 'on_sale'])` wires each entry's port to the entry of the
  same id in a sibling map from the same provider
  (`docs/resources.md#keys-that-come-from-the-site`).
- **Reference key**: a key that refers to an entity, the id's type with
  the constraint every reader reads: `add('page', 'integer', t(...))
  ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'node',
  'bundles' => ['article']])`, the type checked against the entity
  type's id at build (`References::settle()`, from
  `Declaring::settleReferences()`, which also marks a content entity's
  key, `References::CONTENT`, and checks the definition an `addEach()`
  entry is copied from). A reference to any entity type is a
  structure the example declares (`surface_examples_references`'
  `AnyEntityStructure`, one subclass per filter naming its entity types
  in `ENTITY_TYPES`): `entity_type` with `SurfaceReferenceableType`, `id`
  a string with `References::TARGETS`, narrowed by the structure's
  refiner; `attach()`ed, a config storage keeping its id where the
  attached map says `References::STORED_AS`. `add($key, 'entity:…')` and
  `addEach($key, 'entity:…', ...)` are refused naming the constraint
  (`docs/decisions.md#a-reference-is-a-constraint-on-a-value`); every door sends and answers the id, and a config
  storage stores a content entity's uuid (`ReferenceStorageLayout`, which
  the engine applies; `References::STORED_AS` keeps the id) and a config
  entity's id, a config entity depending on what its keys refer to
  (`docs/decisions.md#a-config-object-stores-a-reference-by-uuid`). Its candidates are core's
  selection handler's for the account (`Reference\References`), listed
  up to `surface.settings:reference_list_limit` and looked up past
  it, with core's entity autocomplete on the form and `GET
  .../{x}:lookup` over HTTP; both documents state what it refers to as
  its check's options and name the lookup as its `lookup` link under
  `x-portable-links` (example 16, `docs/options.md#a-key-that-refers-to-an-entity`,
  `docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up`).
  A content entity's reference field's `target_id` is one, answered by
  the field's own selection handler and settings
  (`References::definition(handler:, handlerSettings:)`, the helper for
  code that derives a reference key), drawn with the
  autocomplete where its form display uses core's
  (`docs/decisions.md#a-reference-field-is-a-reference-key`).
- **Computed key**: a key of a resource whose value nobody types,
  `$inputs->compute('slug', 'string', t('Slug'), from: ['label'], using:
  Slugify::class, when: Compute::ON_CREATE)`; the class implements
  `ComputesValueInterface`, a static `compute(array $from, ?DataDefinitionInterface $key = NULL)`
  run under the static guard (one allowance: `MachineName` may use the
  `transliteration` service, `PipelineGuard::COMPUTE_ALLOWED`). Computed in `accept()` from the accepted sources while storage
  holds nothing for it (and on every write that changes a source with
  `Compute::ON_CHANGE`), judged by its own constraints, a refusal naming
  its sources; stored and read as any key; `readOnly` with
  `x-surface.computed` in both documents, left out of the write tool's
  `values`; a value sent equal to it noted, any other refused, code
  `computed` (example 15's slug,
  `docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources`).
  With `Compute::UNLESS_SENT` the key is computed only when left out (null
  or `''` read as left out) and a sent value is kept: not `readOnly`,
  offered by the write tool, `x-surface.computed.when: unless_sent`. A
  machine name is that pair: `compute('id', 'machine_name', t('Machine
  name'), from: ['label'], using: MachineName::class, when:
  Compute::UNLESS_SENT)` on the identity key, with core's `machine_name`
  config schema type for the pattern and length, `SurfaceUnique` added
  to the identity key of every `creates` operation (the storage answers
  `exists()`), and core's machine name element as the widget (example 15's
  id, the content type example,
  `docs/decisions.md#a-machine-name-is-a-computed-identity`).
  A key **resolved** at use is asked and stored as declared, and read
  otherwise by the code that uses the resource: `$inputs->resolve('api_key',
  from: ['key_id'], using: ManagedKeyValue::class)` (owner or alter, an
  alter's own keys bare, recorded by stored path in a `Resolve` on the
  key's definition) and `Resources::resolved($resource, $operation)`, the
  PHP door that loads the stored values and puts the class's answer at
  the key while every source holds a value; never stored, in no document,
  tool, form or read, a second resolve of a key refused naming both; a
  structure's own applied wherever a resource holds it, by the
  structure's own paths (example 14's webhook and `WebhookSigner`,
  `docs/decisions.md#a-key-may-stand-for-another-at-use`).
- **Stage**: a set of writes kept until they are applied together, or
  discarded: the base module's one resource, `surface.stage`
  (`Resource\StageResource`), its id a UUID, its record one row of the
  expirable key-value collection `surface.stage` (`Staging\StageStore`,
  the `surface.stages` service, typed `Staging\StageStoreInterface`,
  which a site may swap), kept for its lifetime `keep` after its last
  write (long, `tempstore.expire`, unless a flow says `keep: short`,
  `form_cache_expiration`), its owner the only account its access lets in
  (`Access\StageAccess`). Code writes into one by handing a
  `Staging\Stage` to `ResourcesInterface::storage()`, which wraps the
  storage in a `Staging\StagedStorage`: load answers the stage first,
  prepare is the storage's, commit writes the record. `changes` lists
  the writes; `apply` (`Storage\StageStorage`) rehearses them all in
  prepare and makes them in commit, in order, in one transaction, and
  keeps the record marked applied. A stage takes creates, updates and
  deletes where one rule, `Staging\Stageable`, says a stage applies,
  asked at every door: the resource's storage class implements
  `Pipeline\TransactionalStorageInterface` (a transaction undoes its
  writes whole; `rolledBack()` drops what it cached after an apply rolls
  back), the content entity storage and both config storages among
  them, every routed child's does too (prepare alone checks that), and
  the operation names a thing
  (it creates, the resource has no identity keys, or its parameters name
  one; `event.venue:reorder` names none), any other refused at prepare,
  the header on such a call 422 (`docs/decisions.md#one-rule-says-where-a-stage-applies-at-every-door`);
  after a rolled back apply, `Staging\ConfigWrites`
  (`surface.config_writes`) resets what config kept beside the database
  for every config name the apply wrote, and on a database without
  transactional schema changes (MySQL, MariaDB) a schema change makes
  the writes at and before the failing one permanent: apply marks the
  stage `voided` (`StageStorage::VOIDED`), which takes no write and no
  apply, only a discard
  (`docs/decisions.md#config-is-staged-and-apply-resets-what-its-rollback-leaves-behind`);
  two identity-less entries of one storage class are one thing
  (`docs/decisions.md#two-identity-less-entries-of-one-storage-class-are-one-thing`);
  every secret in an entry is sealed with `surface.secret_codec`, its
  path recorded under `sealed`, a cleared one under `cleared`, opened by
  those paths alone at a staged read and at apply (`Staging\StagedSecrets`,
  `docs/decisions.md#a-secret-is-sealed-in-the-stage-record-by-its-recorded-paths`); a create is an entry of kind
  `create`, always appended whole with no token, a caller-named name
  unique per storage class in the stage (`taken`), and named by a local
  id, its `address`: `-position` for an id storage gives (`id: -3` in
  every answer), the caller's name otherwise; an update, read or delete
  handed the stage and naming it is built as the create's context,
  knowing `StagedStorage::POSITION`, and merges into the entry or drops
  it, so apply only sees whole creates; a local id names nothing
  without its stage (`docs/staging.md#a-staged-create`,
  `docs/decisions.md#a-staged-create-is-named-by-a-local-id-and-a-later-write-merges-into-it`); a delete is an entry of kind
  `delete`, values `[]` and `changed: ['']`, replacing an update of the
  thing at its position, and a later write to the thing is refused
  `not_found` (`docs/staging.md#a-staged-delete`)
  (`docs/decisions.md#a-stage-is-a-resource-of-the-base-module-kept-in-an-expirable-key-value-collection`,
  `docs/decisions.md#apply-builds-for-the-current-user-and-writes-as-the-owner`).
  The doors name one only where a stage applies: the **stage input**,
  `stage` (`OperationInputs::STAGE`), last on every write tool (after
  `dry_run`), read tool and narrowing tool, default `''` so the `values`
  refiner still fires, handed to the runner, which asks it before the
  thing's context and builds the context through it (a missing id
  `not_found`, another account's `access` with its reason, answered by
  `execute()`; a value that is not a string the tool's own `not_found`);
  and
  the **stage header**, `X-Surface-Stage` (`ServedPaths::STAGE_HEADER`),
  read in `target()` into `ServedTarget::$stage`, asked by the runner
  in the route check and the controller alike, before the thing's
  context: 422 on a call a stage does not apply to (whatever stage it
  names), then 404 naming nothing, then another account's 403 "The
  stage is another account's."; 400 on the stage's own
  paths, from the controller, whose own refusals (an id naming no stage
  404, another account's 403 with its reason) every account hears;
  ignored by `GET
  .../schema`, OpenAPI's `SurfaceStage`; a read,
  a narrowing and a write's `contract` and `ETag` are the staged view;
  the `ETag` of the stage's own `apply` also covers its writes
  (`StageStorage::listed()`, the confirm page's fingerprint), so `PATCH
  :apply` with a stale `If-Match` is 412
  (`docs/staging.md`,
  `docs/decisions.md#a-stage-input-is-a-door-argument-handed-to-storage-never-context`).
  What a run reports (a stage's id after `create`, `items`, `applied`)
  is read through the pipeline, `SurfacePipelineInterface::outputs($built,
  $storage, $result)`, at every door that reads them; the adapter's
  `OutputsStorageInterface` stays internal
  (`docs/decisions.md#a-door-reads-what-a-run-reports-through-the-pipeline`).
- **Flow**: several steps a person or an agent takes over one stage,
  ending in its `apply`; experimental. Written as a class,
  `#[Flow(id, label, keep?, redirect?)]` on a class in a module's
  `src/Flow` implementing `Flow\FlowInterface`, whose
  `defineSteps(StepsInterface $steps)` adds `step(id, resource,
  operation, arguments)` and says `keys(...)` or `group(id)` (neither:
  the whole surface; both refused), `label()`, `description()` (a
  heading above the fields, no step counter) and `when(callable)`, found
  by `ResourceCollectorPass` (`flows`) and built by `Flow\FlowRegistry`
  (`surface.flows`) through the class resolver; or as its short form, a
  map on the route. A route names either,
  `_surface_flow: <flow id>` or `_surface_flow: { steps: [...], label:
  ..., redirect: ..., keep: ... }`, and both compile to one
  `Form\CompiledFlow` (internal). A route naming a flow class may leave
  out `_surface_resource` and `_surface_operation`:
  `OperationRouteSubscriber` writes them from the first step at the
  rebuild, so `defineSteps()` declares only; a route that writes them
  must write the first step's, and the first step takes no literal
  arguments and no `when`. A step of the map is a group id of the
  route's operation, `{ keys }` of it, or a step over another resource,
  `{ resource, operation, keys? or group?, arguments? }`, served by that
  resource's operation (`CompiledFlow::servedAt()`), its whole surface
  when `keys` and `group` are absent; a class step over the route's own
  resource and operation with no arguments is a step of the route's;
  the first step is the route's. Drawn a step at a time
  (`buildSurfaceForm(..., $keys)`), a key a step lists there, keys in no
  group and listed by no step at the first step over their operation,
  `?step=N` and `?stage=<id>` in the query, the first step opening the
  stage, Next staging a step and going to the next step drawn, Back
  staging nothing and going to the one drawn before, Cancel discarding
  the stage. A step whose `when` answers FALSE for a
  `Flow\StagedInterface` over the stage (`value(resource, key,
  arguments)`, staged else stored through `StagedStorage::load()`,
  `Flow\Staged`) is passed over by Next, Back and the resume offer, and
  a link to it answers 303 to the next step drawn, and a post into it
  stages nothing; the access check does not ask `when`, and what a step
  staged before it turned false stays in the stage. `value()` reads as
  the system and never answers a secret; a resource no step draws is
  read through its `read`. `FlowRegistry::get()` compiles afresh on each
  call; the form keeps one flow and one reader per stage for its request.
  A flow id no class or two classes give closes the route naming it
  (`_access: 'FALSE'`) and the rebuild goes on. A flow class implementing `Flow\ChecksBeforeApplyInterface`
  is asked `beforeApply(StagedInterface)` after the confirm page's
  rehearsal, its violations refusing Save (the form door's alone). A
  confirm page at
  `?step=confirm` that lists `changes` of every resource as a table,
  draws the route's panel after the buttons (as every operation form
  does: `placeRefreshedBeside()`), says how many writes cannot be
  undone, and Save applies what it listed (a fingerprint it posts back); once
  applied it goes to the flow's `redirect` route or stays. A step asked
  with an applied or voided stage answers 303 to the confirm page, which
  says so and links "Start again"; a post into such a stage is judged by
  each button's submit (`closedGuard()`), which lets Back through, and
  Cancel on a voided stage. The route's operation gates every
  page; a step over another resource adds its permission and access.
  The rebuild refuses a malformed `_surface_flow` default (a step with
  keys and a group, an empty key list, a group or key listed twice), a
  flow class no class has the id of or written wrong, and a flow on
  a route whose `_form` is not the operation form; the match (the access
  check and the form, through `CompiledFlow::refusal()`,
  `CompiledFlow::stepRefusal()` and `CompiledFlow::servedAt()`) refuses
  an operation a flow cannot stage (a delete, a read-only operation, one
  a stage does not apply to), a step over another resource that creates
  or is the route's own operation, an unknown group or key, a step that
  draws no key, a key drawn before a key it is refined by (each per
  operation, every step over another resource at step 1 before a stage
  is opened), and a create's required key with no default after the
  first step. A flow over a
  create writes each later step into its first step's create
  (`Stage::creating()`, internal).
  The form door reads `?stage` on every operation form page, the React
  page reads it too (its app sends the header), and any other page
  served by an operation refuses it
  (`OperationRoute::stageRequested()`: the stageable rule, the stage,
  its access, then the thing's), says what was staged as the tool door
  does, skips the cosmetics' message and redirect, and varies by
  `url.query_args:stage` and `:step`, max-age 0 under a stage
  (`docs/staging.md#the-form-door`, `docs/staging.md#flows`,
  `docs/decisions.md#a-flow-is-a-class-and-the-map-is-its-short-form`,
  `docs/decisions.md#a-flow-is-declared-on-the-route-that-draws-it-not-a-resource`).
  The entity example's bulk delete from the content list stays
  hand-built, a `system.action` that stages one delete per selected node
  and a confirm page over the stage that applies it, since it spans many
  things (`docs/staging.md#a-flow-by-hand-bulk-delete-from-the-content-list`,
  `docs/decisions.md#a-destructive-bulk-operation-stages-then-confirms-over-the-stage`).
  The examples around a stage: the entity example's preview of one
  staged node (`/surface-entity/stage/{stage}/preview/{id}`,
  `ContentEntityStorage::preview()`, `docs/staging.md#preview`), an ECA
  model as optional config in `surface_examples`, shipped disabled
  (`eca.eca.surface_stage_walkthrough`, `docs/staging.md#eca`), and a
  drush walk-through (`docs/staging.md#walk-through-with-drush`).
- **Contract**: the JSON Schema a built resource or structure emits, a
  Portable Schema document since contract 5.
- **Portable Schema**: the dialect every served document is written in,
  `$schema` `https://portable-schema.dev/v1/schema`
  (`ContractEmitter::DIALECT`): JSON Schema 2020-12 with a profile, fixed
  rules where the standard leaves a choice, and a vocabulary of its own,
  the package `fago/portable-schema` (`PortableSchema\`, required by the
  module). Four of its keywords are written beside the standard ones,
  in this order, before `x-surface` (`Contract\PortableKeywords`):
  **`portable-check`**, what only the server checks, a map from
  `drupal:<constraint plugin id>` to its options (`{}`,
  `{"schema": ...}` for `drupal:JsonSchema`, or what a reference refers
  to, the plugin's own options `{"entityTypeId": ..., "bundles"?: [...]}`, for
  `drupal:SurfaceValidReference`); **`x-portable-messages`**,
  a message per error code, a Regex's under `pattern` (or `not`), then
  each check's under `check.drupal:<id>`; and **`x-portable-ui`**, the
  widget hints (`Contract\PortableUi`): `widget`, `options`
  (`multiple`, the arrangement's texts, `emptyOption`), `group` by id,
  `weight`, and each group declared once on its frame under `groups`,
  all but `options.emptyOption` written only when a renderer asks for
  the hints (`docs/decisions.md#the-ui-hints-are-x-portable-ui`); and
  **`x-portable-links`** (`Contract\PortableLinks`), where a client asks
  the server for what a document leaves out, never a hint: a `lookup`
  link on a reference key the lookup finds, where the HTTP door is
  installed and `GET` serves the operation at its path,
  `<lookup URL>?key=<pointer>{&q,limit}`, the URL concrete as the schema
  URL is (a bare id's with `&type={type}` and `source: {type:
  "1/entity_type"}`), and a `choose` link on a deferred list, the full
  schema with one entry stated; a link is a site's, written at a key of
  the frame or at a site's narrowing, never in a def
  (`docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links`). They
  stay on their node below an applicator, where `x-surface` moves to
  `within`. `format: email`, not `idn-email`,
  which the profile refuses
  (`docs/decisions.md#the-served-document-is-a-portable-schema-document`).
- **Markers**: what a key's `x-surface` says of how far the schema holds
  it, and what an invoker does: `enumerated`, stated in full, narrow
  locally; `deferred`, a list the site decides left out for its size,
  fetch per choice (`GET .../schema?choose=<pointer>=<value>`, the
  key's `choose` link expanded) and cache, or ask the narrowing;
  `refine`, a static rule that cannot be written out, ask the narrowing
  with values; `live`, a runtime rule or a runtime access answer, ask
  per request and never cache; beside them, the dialect's
  `portable-check`, a constraint only the server checks
  (`docs/served-contract.md#what-a-key-marked-says-and-what-to-do`,
  `docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined`).
- **Version**: `#[Resource(version:)]` or `#[Structure(version:)]`, from
  1, counting the breaking changes made to its stored values; stamped
  `x-surface.version` at the top of both documents and on a def above 1.
  A structure versions itself; a shape has none. Refused above 1 until
  stored-value migrations (`StoredValueMigrationInterface`, designed,
  not run) are built (`docs/evolution.md`,
  `docs/decisions.md#a-resource-evolves-in-the-open`).
- **Contract baseline** and **contract diff**: a module's committed full
  schemas, `tests/fixtures/contracts/<id>.json`, and the lint that
  judges today's against them (`Contract\ContractDiff`,
  `ContractBaselineTest`, `drush surface:contract-diff`): a
  breaking change passes only versioned or allowed with a reason.
  Regenerate with `--write` after an accepted change; never by hand.
- **Access answer**: what the operation's permission, then the access
  class, then each alter that answers access, answer for an account,
  combined with `andIf()` so the stricter stands; the access class's is
  an `AccessAnswer` (open, locked or omitted). Its kind, static or runtime,
  is read from its cacheability and never declared. Static varies by the
  account's permissions or roles alone (`user.permissions`,
  `user.roles`, `user.roles:<role>`, no tag, permanent max-age) and is stated in the
  full schema and the tool listing for the account that asks; runtime
  (a tag, the `user` context, max-age 0, or no cacheability at all) is
  marked there, `x-surface.live` (`access: "live"` until contract 4), and
  settled by the schema
  for these answers. `ResourcesInterface::buildStatic()` builds for the
  account alone; the operation's permission is the first static answer
  (`OperationDefinition::staticPermission()`); narrowing a key's values
  by account is a runtime refiner, not access
  (`docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability`).
- **Runner**: the one service that runs an operation,
  `Operation\OperationRunner` (`surface.runner`, typed
  `OperationRunnerInterface`), modeled on the Tool API's `ToolRunner`:
  the resource and operation known, the permission from the two ids, the
  stage (`Stageable`, it exists, the account may write into it), the
  context, the access class made decisive and ANDed with the stage's,
  then the build and the storage with the stage, the pipeline's
  `submit()`, and the outputs, the stage resource's held to the surface
  (`conformOutput()`; a mismatch logged, never thrown after a commit,
  `OperationRun::conformed()`), every `NotEmitted` key stripped whether
  or not they conform. `gate()` stops
  after the access and builds nothing itself, `run(values: NULL)` after
  the build (a resource with no storage of its own is built with none,
  `hasStorage()` FALSE, and refused when run with values), and the
  runner's `submit($prepared, $values, $dryRun)` runs the rest on that
  prepare, its surface and storage, as the account it was prepared for
  (`OperationRun::account()`), so a door that reads the storage first
  builds once; `run()` with values is a prepare, then `submit()`. A
  prepare is written through once: after a submit that committed, a
  second `submit()` to it throws (a `\WeakMap` of spent prepares); a dry
  run or a refused submit leaves it usable. No account, or the current
  user's own (same id and roles), is the current user, never checked
  for being blocked: core vouches for it; another, by id or by roles, is
  run as through core's account switcher, switched back in a `finally`,
  a blocked or missing user refused first, by the one wrapper `gate()`,
  `run()` and `submit()` go through (`asAccount()`). What a caller can cause is a `Refusal` on the
  `OperationRun`, never an exception; only a `StageRaceException`, a
  `\BadMethodCallException` from `submit()` handed what it cannot
  submit to, and a bug are thrown. A `Refusal`'s fields (`category`,
  `code`, `reason`, `cacheability`, `access`) are what the account may
  hear: what the two ids decide is told to anyone; a refusal that had
  to read an argument, a `%key` permission's among them, is withheld
  from an account without the permission, which hears one refusal,
  `Refusal::WITHHELD`, whatever refused; the stage a call goes through
  is told to every account past the permission (category `Stage`:
  `does_not_apply`, gone `not_found`, another account's `access` with
  its reason), and so is every refusal of the stage resource's own
  operations (a stage id is a random UUID); `actual()`, a
  `RefusalDetail`, keeps the truth for the server, never shown. A
  caller names itself with a `Door` (an id and enum capabilities, none
  defined yet, read nowhere yet): `php` by default, `drush` for `drush
  surface:run <resource> <operation>` (`--arguments`, `--values`,
  `--stage`, `--dry-run`, `--uid`, anonymous by default, `--json`),
  which prints the `ResultDocument` with `outputs`, prepares only
  without `--values`, and confirms a destructive run (`--yes`). On it
  so far: the HTTP door (its route check calls `gate()`, door `http`, or
  the route's `_surface_door` default, `react` for the React page; the
  controller gates the full schema, prepares every other action and
  submits a write to its prepare; a committed write whose re-read the
  runner refuses answers `contract: null` and no `ETag`, never a 403 or
  404 after the commit), the tool door (the access hook calls `gate()`
  with the account it is handed, door `tool`, `schema tool` for the
  narrowing tool, and answers from the refusal's public fields,
  `ResourceOperationToolBase::answer()`, a refusal the caller can
  correct or one about its stage let through for `execute()` to answer
  with its code; the write tool's `execute()` is one `run()` with the
  values and the dry run, the read and narrowing tools and the `values`
  refiner prepare; `ResourceOperationToolBase::refusal()` turns a
  refusal into the result; a committed write is never answered as a
  refusal: outputs the Tool API's own check would refuse are left out
  and logged, and only a refusal of the inputs is rewritten into a
  document that says nothing was committed), the form's runs of the stage's own
  operations (`stageRun()`) and the examples that ran one by hand for an
  account; an install hook acts for none and calls the pipeline; the
  form door keeps its own sequence until it moves
  (`docs/resources.md#running-an-operation-from-php`,
  `docs/decisions.md#one-runner-runs-an-operation`).

"Data surface" names the project, and the sealed object the build step
makes (`SurfaceInterface`); it is not a third kind. "Shape" is the
named type, of which resources, structures and named scalar shapes are
the three kinds; in code `#[Shape]` names the third, and the word never
names the blueprint or the layout. A value that arrives of the wrong
type is a `TypeMismatchException`. Product names
and on-the-wire identifiers keep the word surface (module, namespaces,
services, routes and paths, `x-surface`, the `surface[...]` form key,
React, the `Surface*` engine, tool ids; the contract's `surface` and
`situation` keys became `resource` and `operation` with contract 2); every concept name uses the new word
(`docs/decisions.md#the-product-keeps-its-name`).

## One spelling

A resource is a class in a module's `src/Resource/` carrying
`#[Resource]`, a structure one in `src/Structure/` carrying
`#[Structure]`; the attribute decides the kind, and either directory
works. Each has a static `defineInputs()` (and, on `DefinesOutputsInterface`,
a static `defineOutputs()`), or declares its keys as properties with
`DeclaresByProperties`, which supplies both; static `#[RefinesInput]` methods and
`#[Operation]` on the class or on static methods; it is never
instantiated. Another module
changes either with an alter in `src/Alter/` carrying `#[Alters]`. A
plugin names the resource or structure it holds with `#[UsesStructure]`,
or, with no argument, is its own structure: the plugin class implements
`StructureInterface`, is found through its plugin definition
(`ResourcePlugins::ownStructures()`), gets the id
`<host type>:<plugin id>` unless it carries `#[Structure]`, and alters
name it by the plugin class (the example formatter). Everything else is
discovered by `ResourceBuild\ResourceCollectorPass` and built by the
`surface.resources` service (`ResourcesInterface::build($resource,
$context)`), which writes the blueprint, the alters, the context and the
bound refiners into the engine's `SurfaceBuilder` and seals it into a
`SurfaceInterface` (`docs/resources.md`). The builder, the definition
map and `SurfaceRefinerInterface` are internal: nothing an author
writes touches them. There is no build event, no declaration method, no
provider service, no policy filter and no output refiner; step 5 of
`REWORK.md` deleted them.

Every plugin host reads `#[UsesStructure]` from the plugin definition:
block, formatter, condition, action and field type base classes, and
`SurfacePluginForm` for any configurable plugin. A host builds the
named class in a context of its own verb (`configure`, `field_settings`),
supplies the storage (the plugin's configuration array; the field config
for a field type) and answers static defaults from the class's own
declaration (`ResourcesInterface::defaults()`). A storage class has three
verbs, `load()`, `prepare()` (rehearse the write with the store's own
checks, no side effects) and `commit()` of what prepare returned; a dry
run stops after prepare and reports it.

Decisions live in `docs/decisions.md`: one entry per point the pattern
did not settle, with its reason. A new undecided point is added there,
marked open, not left as a code comment; code points at an entry with
`// Decision: see docs/decisions.md#<anchor>.` only where a reader at
that spot needs it.

Operations are what routes and tools are generated from. A route names
`_surface_resource` and `_surface_operation` and maps its
parameters onto the operation's by name (`OperationRoute`), gated by
`_surface_operation_access`, which `OperationRouteSubscriber` adds
to every such route (a routing file may still say it);
`SurfaceOperationForm` serves it.

A route's seeds, `_surface_fixed` and `_surface_defaults`, are
literals or references: `'{name}'` whole (`OperationRoute::REFERENCE`),
the raw value of a variable of the route's path that a requirement or a
converter bounds, left out when an optional placeholder is absent, and
one its key refuses names nothing (`NamesNothingException`). A derived
resource's id may hold such a variable, `entity.content:node.{node_type}`,
filled at match (`OperationRoute::named()`), so one route serves every
bundle (`docs/forms.md#a-value-from-the-routes-path`).

`_surface_query: { '<key path>': '<query name>' }` lists the keys a
link may start, each name plain (`[A-Za-z0-9_-]`, since PHP renames a
`.` and splits a `[`): prefills, laid on the form's first draw only,
held to the key and dropped without a word when it refuses them
(`KeyPaths::heldOn()`), never in the context, a build or a document; the
page varies by `url.query_args:<name>`. Code hands the form the same,
`getForm(SurfaceOperationForm::class, $values)`. A React page a
module serves at its own route (`ResourceReactPage::served()`, told
apart by `OperationRoute::reactPage()`) starts keys from both and fixes
none (`docs/forms.md#keys-a-link-or-the-code-starts`).

A default with no leading underscore is a route parameter, core's own:
`to: 'friends'` pins the operation's `$to`. One that nothing reads (the
operation's parameters, `_controller`, `_form`'s `buildForm()`,
`_title_callback`, `_custom_access`) is refused at match. A plain default
is never the subject, which is the first of the operation's parameters
that is a path variable with a value
(`docs/decisions.md#a-plain-route-default-pins-an-operation-parameter`).

What the route alone shows to be wrong (a `{name}` naming no bounded
variable, one path both fixed and filled, a query map no link can use,
`_surface_fixed` on a React page) fails the router rebuild for the
whole site (`OperationRoute::assertWritten()`, from
`OperationRouteSubscriber` at ALTER -230, after core's converters at
-220) and is refused again at match, and by the access check before the
permission. Three answers changed on 2026-10-29: the access check asks
the permission from the two ids before reading the URL; a value of the
URL that names nothing is a 404 for an account the permission lets in,
let through in the request's own dispatch, only to the operation form
(`_form`) or a React page (any other `_controller` gets a 403 with no
reason), and refused with no reason for a link (`OperationGate`), and,
at the form's route, refused with no reason to all where the operation
declares no permission (`OperationAccessCheck`); and a route's held build is kept in the build cache
only when nothing in its context came from the request, else held for
the request alone (`ResourcesInterface::holdDeclared()`)
(`docs/decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for`).

With the `tool` module installed, the module derives one tool per
operation that can be asked on its own, `surface:<id>:<operation>`
(the Tool API door, `docs/tools.md`): its resource names storage, no
plugin uses it, and every `%key` in its permission can be supplied by
a parameter (`ResourceCatalog::standalone()`). The read of one thing is the tool
`surface:<id>:read`, of its own class, taking the parameters and
answering with the stored values less secrets
(`ResourceOperationReadTool`); no tool is derived beside an operation
but the narrowing one. Where an
operation's listing leaves a key's entries out, it derives a narrowing
tool too, `surface:<id>:<operation>:schema`, answering what
`POST .../schema` answers for the values sent, and the key's description
names the fetch beside it, `GET .../schema?choose=<pointer>=<value>`. The
listing leaves out variants the site lists whatever
`surface.settings:site_list_limit` says, stating the keys of the
variant the stored values choose and no other (the listing of an
operation that needs nothing reads what is stored, for an account it is
open to), and a map of keys from the site past the limit; the limit
governs the two schema documents otherwise
(`InputDefinitions::deferred()`, `OperationInputs::narrows()`,
`ResourceOperationSchemaTool`,
`docs/decisions.md#a-budget-for-what-the-site-decides`). Both declare
the operation's permission to the Tool API, unless it has a `%key`
(`ResourceOperationToolDeriver::declaredPermission()`). A tool's title
is the operation's label (with its resource added where labels are
shared; the read the build adds is labeled "Current values:" and its
update's label), and its
description the operation's description and one line of call rules
for its kind (`OperationInputs::description()`). The field
tools are `surface:field.instance:create` / `:reuse` / `:update`; the
hand-written `field_add` / `field_update` are gone.
`surface.resource_catalog` lists the static layer;
`surface.site_catalog` adds each operation's doors (each module
that opens one names it, a `CatalogDoorsInterface` service tagged
`surface.catalog_doors`; a route's `form` or `react` line says
what it pins, fixes, fills and starts) and every build refusal and
route written wrong (phase `route`), which `drush
surface:catalog` prints and the page `/admin/config/development/surfaces`
shows, its doors linked (`Controller\CatalogController`,
`docs/catalog-command.md`); `docs/catalog.md`
is its markdown for this repository's modules, written by
`ResourceCatalogTest` through `SiteCatalog::markdown()`
(`scripts/generate-catalog.php` runs it). An adopter holds its own
resources to the invariants by extending `ResourceTestBase`
(`docs/testing-a-resource.md`); its checks are this module's, in
`NeverStricterTrait`, `SchemaRoundTripTrait` and the two agreement
traits, which the module's own matrix and round trip tests use.

Attached structures are `attach()` (a fixed child, by class) and
`attachVariants()` (variants a sibling key chooses between, always
open: every `#[Variant]` at the key is one, and the parent names none).
Every verb that declares a key takes its label with the key, after what
fills it: optional on `attach()`, `attachList()`, `attachVariants()`
and `attachEach()`, NULL leaving the structure's own, required on
`addEach()`, whose values have none to borrow
(`docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for`).
Each returns the core definition at the key, which the owner describes
with the core setters; `describe()` rewords a key for an alter that does not own it,
and arranges a key (group, weight, placeholder, prefix, suffix, help)
for its owner or an alter, after the key is declared; a second alter
setting a fact another alter set on the key is refused at build.
A child may be a resource with storage of its own, an attached resource
(the field's storage, `FieldStorageResource`). A provider named by
`attachVariants(from:)` lists variants for the values no variant class
declares (`surface_examples_field` lists a field type's settings, and its
storage settings, from its config schema). A key
whose shape depends on another key's value is variants: `add()` refuses
`any`, and only the variants' placeholder may become another type. A structure reads a value of its container
through a port it declares, `reads()`, which `attach(..., reads:)` wires
to a key of the container's; what depends on a port is stated at the
site, on the container's `allOf`, never in the structure's def
(`docs/resources.md#ports`). `mixIn()` mixes a structure in: its
members are the owner's own keys, its refiners and rules bound in the
owner's frame on its members and its ports, its access locking or
omitting its members (`docs/resources.md#mixins`). `attachList()` is a
collection: a list of child instances, the list (its Count) the
parent's, each item the child's, its entry a `Collection`
(`docs/resources.md#collections`).

Keys one resource asks per thing the site has (a checkbox per form) are
`addEach()` or `attachEach()` with a provider, not a deriver
(`docs/decisions.md#keys-that-come-from-the-site`); the derived tools are
cleared when a tag any of them was built with is invalidated
(`Tool\ToolDefinitionsInvalidator`).

A resource or structure whose keys are the site's rather than the
code's (one per bundle, one per field) is derived: a class in `src/Resource/` or `src/Structure/`
carrying `#[DerivesResources(base:)]` and implementing
`ResourceDeriverInterface` returns `DerivedResource` values at
discovery, each `<base>:<derivative>` with no class
(`ResourceDefinition::$class` NULL; every caller asks by `name()`), its
inputs and operations `ResourceCallable`s, each operation's parameters
the names of keys it declares, `['id']`, handed their values and loaded
by nothing but the storage (`docs/decisions.md#a-parameter-names-a-key`), cached under the tags of what
it was read from. Its kind follows from what it names: one that names
storage or identity keys is a resource, any other a structure.
`surface_examples_entity` derives a resource per content entity bundle
(`entity.content:*`) and a structure per field (`field.item:*`), with
field access as each field child's access, and a resource per entity
type, `entity.content:node`, whose `bundle` chooses the bundle's fields
as variants under `fields` (`docs/entities.md`); each has `create`,
`update`, `read` and `delete`, the delete asking core's delete access
and removing the entity with every translation and revision
(`ContentEntityStorage` implements `DeletableStorageInterface`);
whether `create` takes `id` is read from the id field (a serial integer, or a default, is
computed; a string with no default is the caller's to name, required,
and refused at `id` when taken;
`docs/decisions.md#an-entity-types-id-is-computed-or-named-by-the-caller`). A
child is built by the same build step in its own frame and sealed into
the parent's entry as an `Attachment`, or among its `Variants`; the
pipeline, refinement, the form and the tool bridge recurse through those
entries, and nothing in a parent can name a key inside its child (an
alter's glue reads the structure at its own mount, never narrowing it). `docs/resources.md` has the rules.

## Running the suite

`scripts/check.sh` runs the suite and the three gates below, in two
lanes; `scripts/check.sh --help` lists them and their variables. Both
print the wall time of each gate and end with a PASS line only when no
test errored or failed and every gate passed.

- **The fast lane**, `scripts/check.sh --fast` (or
  `SURFACE_CHECK_FAST=1`): unit and kernel tests through phpunit,
  one process per class, `SURFACE_CHECK_CONCURRENCY` at once (one
  process for all of them takes 15 minutes, since every kernel test
  installs its modules), the base module's and those of `surface_http`
  and `surface_openapi`. Each class has its own result cache in the
  container's `/tmp/surface-phpunit-cache` and runs with
  `--order-by=defects`, so what failed last runs first; classes with a
  defect last time start first, then the slowest. A class's output is
  printed only when it has a problem. Then phpcs, phpstan and cspell on
  the files changed since HEAD, untracked ones included. phpstan takes the whole module when no
  PHP file changed or a `.neon` or `phpstan-*.php` file did. **Iterate
  on the fast lane.** It runs no functional or browser test, so it is
  never the last run.
- **The full lane**, `scripts/check.sh`: every class through core's
  `run-tests.sh`, each in its own phpunit process with its own database
  prefix and site directory. Unit, kernel and functional classes run
  `SURFACE_CHECK_CONCURRENCY` at a time (default 8), then the
  browser classes `SURFACE_CHECK_BROWSER_CONCURRENCY` at a time
  (default 4); then phpcs, phpstan and cspell over the whole module.
  **Run the full lane once before handing work back**; its PASS line is
  the one that counts. `run-tests.sh` builds each class's phpunit command
  itself, so the full lane has no `--order-by=defects` and no result
  cache; it orders classes slowest first.

On this machine (Docker VM of 14 CPUs and 8 GB), as of 2026-10-23: the
full lane takes 5m07s at the defaults (phpunit 4m40s) and 23m03s with
both set to 1 (phpunit 22m36s; a plain phpunit run takes 21m11s); the
fast lane takes 2m31s, 3m30s while its caches are empty. For the first
run, eight classes at once took 3m12s, six 3m54s, ten 3m35s (ddev's
php-fpm serves eight requests at once). The add-on's Chrome takes 12
sessions, but six browser tests at once crash its tabs ("tab crashed")
for want of memory, so the browser classes run apart, four at once (five
was no faster).

Everything runs inside the ddev container, the only place that can
reach the test database; `check.sh` works from either side of it. The
browser tests (`tests/src/FunctionalJavascript`) drive Chrome through
the ddev add-on `ddev/ddev-selenium-standalone-chrome` (`ddev add-on get
ddev/ddev-selenium-standalone-chrome`, then `ddev restart`): a
`selenium-chrome` service with a webdriver on port 4444, and, in the web
container's environment (`.ddev/config.selenium-standalone-chrome.yaml`),
`MINK_DRIVER_ARGS_WEBDRIVER` pointing at it and `SIMPLETEST_BASE_URL=http://web`,
the site under a name both containers reach. `http://localhost` still
serves kernel and functional tests, but the browser would look for the
site in its own container; leave the base URL to the environment. One
class, or the plain phpunit run, from the site root:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  ../vendor/bin/phpunit -c core modules/custom/surface/tests/src/Kernel/SomeTest.php'
```

The full lane's phpunit line reads, as of this writing:

```
phpunit: Tests: 2298, Assertions: 56526, Errors: 0, Failures: 0, Skipped: 3. Classes: 321 of 321, 8 at a time, browser classes 4.
```

the same counts a plain phpunit run of the tree gives. The skipped
tests are the round trips `ResourceTestBase` reports by kind, as it
reports a document that cannot read back to itself:
`FirstResourceInvariantsTest`'s store location (a coded refiner) and
`ExamplesSecretsInvariantsTest`'s mailer and webhook. `run-tests.sh`
prints one line per class counting passed tests only, so `check.sh`
reads the counts from the JUnit log each class's phpunit writes, and
checks them against the classes `run-tests.sh` listed. A failure or an
error is printed with its message; a class with no log (it did not run,
or its process died) fails the gate by name, as does `run-tests.sh`
exiting 2 or more with every log clean (an error outside any test). Its
exit 1 with every log clean is a PHPUnit warning or a risky test, which
the gate has never failed on; the classes are named. Deprecations never
fail the gate, in either lane: plain phpunit counts them (42 as of this
writing) and passes, and the full lane passes `--suppress-deprecations`,
without which `run-tests.sh` adds `--fail-on-deprecation` to every class.
The fast lane's line sums phpunit's own lines, one per class:
`Tests: 2184, Assertions: 51972, Skipped: 3, Deprecations: 159. Classes:
268, 8 at a time.`; its deprecation count is per process, so it is higher than one
run's.

The test and assertion counts drift upward as work lands and are not the
thing to check. **No test may error and no test may fail**, the browser
tests included; any error or failure is a real regression.

**Which kind of test.** A test that only submits a form and reads config
back is a kernel test; a functional test is for routing, access and
rendering; a browser test is for an AJAX swap. A functional test costs a
site install and a browser test a Chrome session, which is most of the
full lane's time. Existing functional tests convert as units touch them
(ROADMAP.md, Maintenance).

The suite needs three packages a site may not have: the Tool Belt module
(`drupal/tool_belt`, under `require-dev` in `composer.json`), which the
two tool comparison tests install; `opis/json-schema`, which the
contract tests validate values with and which the module itself needs
at runtime, under `require`, to check what a structure read from a JSON
Schema keeps of it (the `JsonSchema` constraint); and
`fago/portable-schema` (`dev-main`, under `require`), the dialect,
whose registry and validator `ServedContractTest`,
`PortableDocumentTest` and `PortableServedContractTest` load every
document in, and whose `Schema` `ServedContract::schema()` answers. On
a site where this module is a checkout and not a Composer package,
require them at the site root. Without them those
tests error; they do not skip.

There are no open findings. The flaky
`ExamplesEntryPortsTest::testTheFormFollowsEachType` (a race between the
save and the config read) waits for the saved page now, as
`ExamplesSpotlightAjaxTest` does. The two that stood here
(`NodeTypeResourceFormTest::testAddStoresTheTypeAndItsOverrides` and
`NodeTypeResourceFormTest::testDuplicateMachineNameIsRefusedOnTheTypeElement`)
are fixed: the cosmetic layer no longer lets core's machine name
element validate beside the data surface and swallow its violation, and the
test drops the field definitions its own process memoized before the
request wrote them. Both are pinned by kernel tests now. They surfaced
only once node could be installed inside functional tests at all, which
the project-level `web/core/phpunit.xml` and its bootstrap at the site
root (`phpunit-bootstrap.php`) made possible; delete those two files and
the old `node_make_sticky_action` install failure comes back, hiding
these tests again.

## The three gates

Run from the site root (`ROOT` below), which is the parent of `web/`:

```
# phpcs — the stored installed_paths point inside the container.
cd web/modules/custom/surface && $ROOT/vendor/bin/phpcs -d memory_limit=1G \
  --runtime-set installed_paths \
  "$ROOT/vendor/drupal/coder/coder_sniffer,$ROOT/vendor/slevomat/coding-standard"

# phpstan — the module's own config; needs the raised memory limit.
cd $ROOT && vendor/bin/phpstan analyse \
  -c web/modules/custom/surface/phpstan.neon --no-progress --memory-limit=1G

# cspell — core's .cspell.json with globRoot set to the module, its
# dictionaryDefinitions paths made absolute, and .cspell-project-words.txt
# merged into "words"; then, from the module root:
npx --yes cspell@8 --config /tmp/merged.json --no-progress --no-summary "**"
```

`scripts/check.sh` bakes all four in; read it rather than retyping them.

## The HTTP door, OpenAPI, and the React example's app

The served contract's emitter is the main module's:
`Contract\ContractEmitter` (`surface.contract_emitter`) turns a
sealed data surface and its values into a Portable Schema document (a
dialect of JSON Schema 2020-12, contract 5) with an `x-surface` keyword
per property, variants as an `allOf` of `if`/`then`
on their deciding sibling. That is the schema as it stands, for one set
of values; `emitFull()` is the second document, the full schema, which
reads no value: every key as declared, every listable dependency as
conditionals, the rest `refine` or `live`, a list the site decides
past its limit `deferred`, and never stricter than the pipeline
(`docs/decisions.md#two-documents-never-stricter-than-the-runtime`).
The emitter is the walk both documents share; `Contract\PropertySchema`,
`Contract\Conditionals` and `Contract\DocumentHead` write what it asks
for (`docs/decisions.md#the-emitter-is-one-walk-and-three-writers`).
It names no widget unless asked
(`emit(..., widgets: TRUE)`); the contract panel (`Form\ContractPanel`)
shows it without, beside what the Tool API can advertise where the tool
module is installed (which flattens
variants, and is what `tool:info` prints). The HTTP door, the submodule
`surface_http` (`modules/surface_http/`, route `surface.api`, namespace
`Drupal\surface_http`), serves it with the widget hints on
an API where the path is the resource and the method the operation
(contract 3's paths, `ServedPaths`, both documents in contract 5's
keywords, `Contract-Version: 5`): `GET /surface-api/{resource}[/{id}]` the read's
envelope, its `ETag` the stored values' fingerprint (the read the build
adds served as its update, `ServedOperations::resolve()`, so the
envelope is the update's); `GET .../schema` the
full schema itself; `POST .../schema` the envelope narrowed for values
in progress (the AJAX rebuild's equivalent, emitted from the form
builder's `refinementOverlay()`, so an orphan is held unanswered and
listed `stale`); `PATCH` the default update, `POST` on the collection
the default create (201 with `Location`), `:{operation}` any other,
`?dryRun=true` a run that writes nothing, `If-Match` a 412 when storage
changed since, a refusal 422 problem details, every path a JSON Pointer
and every contract 2 path a 410. The submodule `surface_openapi`
(`modules/surface_openapi/`, routes `surface.openapi.*`, namespace
`Drupal\surface_openapi`, depends on `surface_http`) describes that API
as an OpenAPI 3.1 document at `/surface-api/openapi.json`
(`jsonSchemaDialect` 2020-12, `OpenApiDocument::JSON_SCHEMA_DIALECT`,
each component keeping the dialect's `$schema`), with Swagger
UI and Redoc over it (`docs/served-contract.md#an-openapi-document`).
Neither is installed with the base module, which works at the form and
tool doors without them (`WithoutHttpModuleTest`) and reaches a served
path only through `ResourceBuild\ServedPathsInterface`, the service of
that name `surface_http` provides: the tool's schema and lookup URLs and
the contract panel's note. A site that had the API gets both from
`surface_post_update_install_http_and_openapi()`
(`docs/decisions.md#the-http-door-and-the-openapi-document-are-their-own-modules`).
Their tests are in each module's `tests/src/`; a test of another
subject that calls the API stays in the base module and lists
`surface_http` in its `$modules`. A kernel test resolves no
dependency, so one that enables `surface_examples` or the React example
lists `surface_http` too; `KernelTestModulesTest` fails a kernel test
that lists a module without a `surface*` module it depends on, unless
the class sets `MODULES_WITHOUT_DEPENDENCIES` to TRUE
(`ReactWithoutHttpModuleTest`, the state before the update has run).
The example `surface_examples_react` is a JavaScript client of the door:
`/surface-react/{resource}/{operation}` renders it with a React app,
which answers a URL that names nothing as the form's route does; a
module's own route serves the same page with `ResourceReactPage::served()`
and `servedTitle()`, handing the app `seeds.defaults` and `prefill`, the
values the app starts from over the stored ones, and fixing nothing
(`modules/surface_examples_react/README.md`). Either page reads
`?stage=<id>`, gated as the form door gates it, and hands the app
`stage` and `stageLabel`; the app then sends `X-Surface-Stage` on every
API request (never on the token's fetch), says a write was staged, keeps
the stage in the page a staged create moves to, links the stage's own
`changes` and `apply` pages, and draws any run's `outputs`, a list of
maps as a table.
`docs/served-contract.md` has the format, with children and variants
as `$defs` that the properties reference; `docs/decisions.md` (Served
contract) has the points it settled.

The app is `modules/surface_examples_react/app/`: Vite, React 18 and
TypeScript, every version pinned exactly. Run npm on the host, never
inside ddev:

```
cd modules/surface_examples_react/app
npm ci          # node_modules/ is ignored
npm test        # Vitest + React Testing Library
npm run build   # tsc --noEmit, then dist/app.js and dist/app.css
```

**`dist/` is committed**, so the module works on a site with no Node,
and it must be rebuilt and committed with every change under `app/`.
Nothing checks that the two agree (a timestamp test would be fragile),
so the rule is this sentence. `npm test` is not part of
`scripts/check.sh`; run it beside the check when `app/` changed.
`node_modules/` is excluded from phpcs and phpstan, and cspell skips it,
`dist/` and `package-lock.json`.

## Conventions that are load-bearing

- Contracts are objects; payloads are arrays. Never pass a definition as an
  array or a value bag as an object.
- A resource or structure is declared in one place: its `#[Resource]`
  or `#[Structure]` class, or the plugin class that is its own
  structure. A plugin names it with `#[UsesStructure]`; the host builds
  it.
- A resource or structure is static: `defineInputs()`,
  `defineOutputs()` and its `#[RefinesInput]` methods are `public
  static`, called on the class, so it has no constructor, holds no
  service and is never instantiated; a refiner points at a list with a
  constraint and the options resolver fetches. A static refiner is a
  static method, a resource's, a structure's and an alter's alike; only
  a runtime one may be an instance method. Alters, storage classes and
  access classes are autowired services and may hold services
  (`docs/decisions.md#a-resource-or-structure-is-static`).
- A `#[RefinesInput]` method is static, a pure function of what it
  watches that the served contract runs ahead of time, unless it says
  `runtime: TRUE`: a rule no schema can state (a lookup, uniqueness),
  never run ahead, marked `runtime`, applied by the pipeline
  (`docs/decisions.md#static-by-default-runtime-by-declaration`).
- The kind is enforced, not trusted: the build refuses an instance
  refiner not declared runtime; with assertions on, a static refiner runs
  with the container guarded (`Pipeline\PipelineGuard`) and the
  emitter asks it twice; `StaticRefinerAgreementTrait` holds conditionals
  to a fresh `refine()`; and the module's PHPStan rule flags a static
  refiner calling `\Drupal::` (`docs/refinement.md#enforcing-the-kind`).
- Nothing an operation runs reads the request, the route, the session,
  the form, the theme or the current user; the account is an argument
  (`docs/resources.md#no-door-specific-context`).
- A rule between keys whose condition and effect a schema can state is
  declared in the blueprint, `$inputs->narrow($key)->when(Condition::...)`
  and its effects: a static refiner, bound after its contributor's coded
  ones, waiting for nothing, only tightening, stated by the full schema
  as written. A refiner is for an effect that must be computed or a
  condition that is runtime (`docs/decisions.md#a-rule-declared-as-data`).
- `#[RefinesInput]` methods narrow a definition, or answer that the key
  does not apply, `DoesNotApply::here()`, which the return type then
  names (`docs/decisions.md#a-key-that-does-not-apply`); an alter adds
  keys and, with `extendChoices()`, offers more values on a fixed choice
  list (the one widening verb). Nothing removes a key or a value: a remove-only
  policy is a later concept (`ROADMAP.md`).
- Requiredness appears only when it says something: `setRequired(TRUE)` on
  the keys that must be configured, nothing at all on the rest, because
  core data definitions are optional by default.
- Violations are message objects end to end, from the pipeline to the form,
  never pre-rendered strings.
- Translatable strings: static context (a resource or structure, a
  static protocol method, an enum, a value object's static helper) calls
  the global `t()` with a literal string (the same lazy
  `TranslatableMarkup`, and extractable); a container-built class of
  ours injects `string_translation` and calls `$this->t()`; `new
  TranslatableMarkup` appears only inside attribute arguments, where a
  call is not allowed; a key declared as a property takes bare strings,
  which its reader wraps, and `new TranslatableMarkup` inside a
  constraint's options.
- No closures anywhere a form array or a data surface carries: both are
  serialized. Use a service, a callable string, or a class.
- House style for `defineInputs()`, `defineOutputs()` and a shape's
  `define()`: one blank line between key groups (a key's add, its chained
  setters and its `describe()`), shared helpers computed at the top, a
  one-line `//` topic comment only where a long method turns, key
  constants one line each with no docblock
  (`docs/declaring-a-resource.md#the-class`); the generator writes it.
- Iteration order is load-bearing: the definition map is in declaration
  order, and `FieldToolsComparisonTest`'s drift assertion is the tripwire.
- `modules/surface_examples_field/COMPARISON.md` and
  `modules/surface_examples_node_type_tool/COMPARISON.md` are generated
  by `scripts/generate-comparison.php`, `docs/catalog.md` by
  `scripts/generate-catalog.php` (the markdown of `drush surface:catalog`
  for this repository's modules), and the contract baselines under
  `tests/fixtures/contracts/` by `drush surface:contract-diff
  --write`, and the config schema and install files of every resource
  stored in a `ConfigObjectStorage`, and the schema of a config entity
  type a module declares and stores a resource in, by `drush
  surface:config-schema --write` (the test modules' through
  `ConfigSchemaDriftTest` with `SURFACE_CONFIG_SCHEMA_WRITE=1`), and
  example 15's documents under `tests/fixtures/venues/` by
  `VenueResourceTest` with `SURFACE_VENUE_WRITE=1`, and example
  16's under `tests/fixtures/references/` by `ReferenceKeysTest` with
  `SURFACE_REFERENCES_WRITE=1`, and example 17's under
  `tests/fixtures/retitle/` by `RetitleExampleTest` with
  `SURFACE_RETITLE_WRITE=1`, and the OpenAPI document's request examples,
  `modules/surface_openapi/tests/fixtures/openapi/examples.json`, by
  `OpenApiDocumentTest` with `SURFACE_OPENAPI_EXAMPLES_WRITE=1` (the
  official OpenAPI 3.1 schema beside it is vendored, not generated).
  Never edit any of them by hand.
- Commit messages are one line.

## Naming

- A resource class ends in `Resource`, a structure class in
  `Structure`, and the id on either attribute is dotted (`node.type`,
  `field.instance`); a shape class is named for the value with no
  suffix (`License`, `event.license`); an alter ends in `Alter`, a storage class in
  `Storage`, an access class in `Access`. A deriver is named in the
  plural for what it derives (`ContentEntityResources`,
  `FieldItemStructures`).
- A class-swap adopter prefixes the swapped class with `Surface`
  (`AddressItem` → `SurfaceAddressItem`), and the hook implementation's
  docblock **must name the replacement class**, so grepping the original
  lands on the line that replaces it.
- The catalog lists a plugin as `<host type>:<plugin id>` —
  `block:my_teaser`, `field_type:address`.
- A context's id is an operation id (`create`, `update`, `reuse`) or a
  host's own verb (`configure`, `field_settings`) and never carries
  identity. Identity is the `#[Resource(identity:)]` keys the context
  knows.

See `docs/declaring-a-resource.md` for all four in full.

## Where things live

```
src/                     Data surface, builder (internal), definition map, host trait;
                         Computed and Resolve, a computed key's and a
                         resolved key's metadata.
src/Reader/              Readers into the blueprint: ConfigSchemaReader,
                         TypedDataReader, LeafRules and Reading.
src/Resource/            The authoring API and attributes (Attribute/), the
                         property attributes among them, and
                         DeclaresByProperties, which reads them
                         (ResourceBuild/PropertyDeclaration.php); also the
                         base module's one resource, StageResource.
src/Structure/           The base module's two structures, the stage's:
                         StageChangesStructure and StageAppliedStructure.
src/ResourceBuild/       Discovery pass, registry, build step, adapters,
                         derived variants, catalog, operation routes.
                         The build step is Resources.php, the order and
                         the caches, over its five phases: Declaring.php,
                         Mixing.php, Attaching.php, Binding.php, Gating.php.
src/Access/              The operation route's access check; StageAccess,
                         the stage's.
src/Hook/                #[UsesStructure] into plugin definitions.
src/Pipeline/            Access, accept, validate, prepare, commit; results.
                         SurfacePipeline.php keeps the write path and
                         the document, over its three verbs: Accepting.php,
                         Validating.php, Conforming.php. The storage
                         interfaces; TransactionalStorageInterface is
                         what a storage implements to be staged.
src/Storage/             Where accepted values are written; settings layouts.
src/Staging/             The store contract (StageStoreInterface, and
                         the optional ListsStagesInterface), the
                         shipped store (StageStore), the record's shape
                         and lookups every store shares (StageRecord,
                         public), the staged-storage wrapper
                         (StagedStorage), the Stage it is handed, and
                         StageRaceException,
                         public, which a staged write, an apply or a
                         stage operation throws on a race; the one
                         rule of where a stage applies (Stageable),
                         the sealing of a record's secrets
                         (StagedSecrets) and what records and resets
                         the config an apply wrote (ConfigWrites).
src/Contract/            The served contract's emitter (a Portable Schema
                         document): ContractEmitter
                         walks the frames, PropertySchema writes each key,
                         Conditionals the if/then, DocumentHead the top,
                         PortableKeywords the dialect's portable-check and
                         x-portable-messages, PortableUi its
                         x-portable-ui, PortableLinks its
                         x-portable-links; Schemas holds what the emitter
                         and the reader need of a
                         PortableSchema\Builder\Schema that the builder
                         lacks (the array form, first(), defaults(),
                         same(), map(), at(), decoded()), since every
                         writer builds Schema values and the reader and
                         SchemaResolver read them; ServedContract, the
                         document in the array form, whose schema() and
                         outputs() answer the Schema values built;
                         the contract diff; the schema reader; the two
                         tables both read, LeafTypes and ConstraintKeywords,
                         whose rows are in Keywords/.
src/Drush/               Drush commands (the site's catalog, the contract diff, the
                         config schema, surface:run) and the resource
                         generator, drush generate surface:resource.
src/Operation/           The runner: OperationRunnerInterface and
                         OperationRunner, the OperationRun it answers,
                         Refusal (what the account may hear) with
                         RefusalCategory and RefusalDetail (the truth,
                         for the server), and Door.
src/Flow/                A flow written as a class: the interfaces a flow
                         class implements and declares into
                         (FlowInterface, StepsInterface, StepInterface,
                         StagedInterface, ChecksBeforeApplyInterface),
                         and, internal, Steps, Step, Staged and the
                         FlowRegistry that compiles a class for a route.
src/Form/                Form builder, host traits, the operation form
                         and its contract panel (ContractPanel.php);
                         CompiledFlow.php, a route's flow, either
                         spelling, compiled (internal).
                         The form builder is SurfaceFormBuilder.php,
                         the service and its Form API callbacks, over
                         Drawing.php, Extracting.php and Refreshing.php.
src/Tool/                The Tool API door: what a tool takes and answers
                         (OperationInputs, InputDefinitions), its catalog
                         doors and the invalidator, registered by
                         SurfaceServiceProvider only with the tool module.
src/Plugin/tool/ src/Plugin/Derivative/
                         The tool plugins, which run an operation through
                         the runner, and their deriver, found only by the
                         tool module's plugin manager.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Variants/            Variants from the site: ComponentProps and ComponentSlots;
                         ConfigSchemaTypes, a config schema type's.
src/Refinement/          Narrowing and choice sets.
modules/surface_http/     The HTTP door, Drupal\surface_http: ServedPaths
                         (which implements ResourceBuild\ServedPathsInterface)
                         and ServedOperations, which asks the runner, its
                         ServedOperation and RefusedException,
                         Controller\ResourceApiController,
                         Access\ServedOperationAccessCheck, the two
                         EventSubscriber\ classes (problem details, the
                         schema's tag), problem details, and its catalog
                         door, HttpDoors; route surface.api; its tests.
modules/surface_openapi/  The OpenAPI document, Drupal\surface_openapi:
                         OpenApiDocument, SchemaComponents,
                         Controller\OpenApiController, its catalog door,
                         OpenApiDoors; the surface.openapi.* routes, the
                         Swagger UI and Redoc libraries and js/, the
                         permission access surface api docs; its tests
                         and modules/surface_openapi/tests/fixtures/openapi/.
modules/                 Besides those two, nineteen example submodules,
                         each with its own README, in two groups (below).
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generators.
```

The base module is the project and the only thing meant to ship, with
`surface_http` and `surface_openapi` (package `Surface API`): the
framework and its doors, the form, the Tool API tool and with it the
command line (where the tool module is installed), and in the two
submodules HTTP and its OpenAPI document. Every other submodule is an
example, in the package
`Surface API examples` and `lifecycle: experimental`, in one of two
groups, which
`surface_examples`' `ExampleModules` (the landing page's list),
`docs/examples/index.md`, `docs/installation.md` and README.md's "The
examples" give alike:

- **Learn the pattern:** `surface_examples` and the numbered series'
  submodules (`surface_examples_compliance`, `_workflow`, `_keys`,
  `_references`, `_retitle`), `surface_examples_first`, `_plugins`,
  `_classic`, `_extras` and `_react`.
- **The pattern on Drupal's own things:** `surface_examples_block`,
  `_component_block`, `_entity`, `_node_type`, `_node_type_tool`,
  `_performance`, `_field` and `_address`. They try the pattern on
  what Drupal and a contrib module already have; they are not part of
  the project and will not ship in this form, and each one's
  description and README say so.

A new example goes into one group in all four places;
`ExamplesRoutesTest` fails when `ExampleModules` misses a directory.

A module that declares its own puts resources in `src/Resource/`,
structures in `src/Structure/`, shapes in `src/Shape/`, alters in
`src/Alter/`, flow classes in `src/Flow/`, storage classes in
`src/Storage/` and access classes in `src/Access/`; only the first five
are scanned, and the rest are registered because a resource or
structure names them.

The declared flows' fixture is `tests/modules/surface_stage_test`'s
itinerary (`ItineraryResource`, `ItineraryStorage`, and the flow routes
in `surface_stage_test.routing.yml`, one of them `keep: short`, one
of a single step, and three with a step over another resource: the
ledger row, `my_module.settings`, and the refused `tool_test.vault`),
and its flow classes in `src/Flow/`: `ItineraryFlow`, keys per step,
the ledger row under a `when`, and a `beforeApply()`, named by two
routes, one with no resource and one that writes it, two refused
when a route names them, `FirstStepArgumentsFlow` and
`FirstStepConditionFlow` (`FlowClassTest`), and `SkipsSecondStepFlow`,
whose second step is drawn under a `when`, which `StageStoreSwapTest`
names on a route it builds; the flow classes in
`src/OutsideDiscovery/` are refused or read by a registry
`FlowClassTest` builds over them (an id two classes give, an empty id
or label, no step, no `FlowInterface`, `keep` and `redirect`);
the examples are the content type's `surface_examples_node_type.flow`
route, a map, linked "Edit in steps" from the content types list, and
`surface_examples.step19`, example 19, a flow class,
`surface_examples`' `Flow\EventSetupFlow`, over `event.setup`'s keys
split over two steps and `event.notices` while ticket sales are open or
a banner is shown (`EventSetupResource`, `EventNoticesResource` and their storages in
`surface_examples`).
The same module's `Staging\ArrayStageStore` is the second store, in
memory, which `StageStoreSwapTest` swaps in for `surface.stages`.
`tests/modules/surface_reserved_test` holds a resource whose operations
know the stage's reserved keys, which `StageStoreTest` enables to pin
the refusals of `Resources::operation()`.

`surface_examples_entity` also holds the hand-built flow: the bulk
action in `src/Plugin/Action/` (`StageDeleteAction`), its confirm page
and the delete form's cosmetics in `src/Form/`
(`StageDeleteConfirmForm`, `EntityFormCosmetics`), the shipped
`system.action` in `config/install/`, and its hand-written action
configuration schema in `config/schema/`, which no generator writes.
