# Review: a Smithy maintainer's reading

## 1. Standpoint

I review as a maintainer of the Smithy IDL, where shape, structure,
resource, identifier, operation and mixin have exact meanings that model
validation and smithy-diff enforce, and where optionality is one table
of traits read differently by servers and clients. I hold the module to
those meanings wherever it borrows the word, and to its own promise that
the two documents and the tool listing are a contract a client can be
generated from and trust.

## 2. The five changes that would most improve the authoring experience

### 1. Put an operation's static facts on the attribute, as Smithy binds lifecycle operations

**Problem.** In Smithy, whether an operation creates, reads or updates
is declared on the resource (`create: CreateCity`, `read: GetCity`) and
validated without running anything. Here `creates` exists only on the
`OperationContext` the method returns
(`src/Resource/OperationContext.php:52-60`), so everything that cannot
call the method guesses. The catalogue reports `creates` as unknown for
any operation that needs a subject (`src/ResourceBuild/ResourceCatalogue.php:203-214`,
`docs/decisions.md:2402-2407`). The tool bridge decides whether an
operation is an update, and so whether its door requires nothing, by
reading the PHP signature (`modules/data_surface_tool/src/OperationInputs.php:397-401`
calling `OperationDefinition::namesOneThing()`,
`src/ResourceBuild/OperationDefinition.php:207-226`). The decision log
leaves exactly this open (`docs/decisions.md:2124`). Meanwhile the id is
written three times on every operation, and the match is enforced only
when the operation is asked (`src/ResourceBuild/Resources.php:1963`).
Twelve of the fourteen operations in `data_surface_examples`,
`data_surface_first` and the node type demo are the bare form, a method
whose whole body restates its attribute.

**Change.** `#[Operation(creates:)]`, read at discovery, with the
context's own answer checked against it the way its id is. The
context's id becomes optional and is stamped from the attribute. And
`#[Operation]` may stand on the class (repeatable) for an operation
whose context is bare.

Before, `modules/data_surface_first/src/Resource/NewsletterResource.php:18-27` (docblock left out):

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }
```

After:

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
final class NewsletterResource implements ResourceInterface {
```

and, for one that needs a body,
`modules/data_surface_demo_node_type/src/Resource/NodeTypeResource.php:64-68`
becomes:

```php
#[Operation('add', label: new TranslatableMarkup('Add a content type'), permission: self::PERMISSION, creates: TRUE)]
public static function add(): OperationContext {
  return (new OperationContext())->withConstraint('type', 'DataSurfaceUniqueNodeType');
}
```

**Cost.** A new attribute target and parameter; `OperationContext`'s
id optional (hosts that build `new OperationContext('configure')`
themselves are unaffected); `docs/first-resource.md` and
`FirstResourceDocsTest` change. The first page still uses four concepts:
the operation moves, it does not multiply. `namesOneThing()` and the
catalogue's NULL disappear.

**What it breaks.** Nothing on the wire: `x-surface.operation` carries
the same facts. Existing method-form operations keep working.

### 2. One optionality table, with Smithy's authoritative and non-authoritative columns

**Problem.** Smithy answers "may this member be absent, may it be null"
in one place, the structure member optionality table, whose cells are
decided by `@required`, `@default`, `@clientOptional` and `@input`, and
whose two columns say what a server (authoritative) and a client
(non-authoritative) conclude. The module has every one of those
distinctions, each settled in its own entry and found by someone
tripping on it: required means must hold a value
(`docs/decisions.md:2931`), absent keeps and null clears (`:2958`), a
key its storage always holds (`:2994`, which is Smithy's non-null
`@default` member), a secret optional and never nullable (`:3044`), and
an update tool that requires nothing at its door (`:2050`, which is
Smithy's `@input`). The adopters hit two of them as bugs: "Optional
grows an empty choice" and "Required keys must be resent on every tool
call" (`docs/adopters-2026-10-19.md:98-102`). Today the doors disagree
about the same operation by design: the newsletter's full schema for its
update lists `"required"` keys
(`tests/fixtures/contracts/first.newsletter.json:79`), while
`drush tool:info data_surface:registration.step3:configure` prints
`Required: No` for every key. Both are right, because the schema
describes the accepted document, not the request
(`docs/served-contract.md:102-113`), but a Smithy reader takes
`required` in an operation's document as "must send", and no single page
says otherwise.

**Change.** Docs only. Replace the two-column table in
`docs/semantics.md:47-51` with one table whose rows are how a key is
declared and whose columns are the doors.

Before (`docs/semantics.md:47-51`): optional or required, pipeline only.

After (sketch):

| Declared | Pipeline | Full schema | Create tool | Update tool |
| --- | --- | --- | --- | --- |
| required, no default | left out: stored value stands, else refused; null refused | in `required`, not nullable | required | optional, "Required; a value already stored stands" |
| required, default | left out: stored or default | in `required`, `default` | optional | optional |
| optional | left out keeps; null clears | nullable | optional | optional |
| optional, storage always holds | left out keeps; null refused | not nullable, not `required` | optional | optional |
| secret | left out or null keeps | `writeOnly`, never `required` | optional | optional |

Add one sentence at the top of "Two documents": both describe the
accepted document; on `kind: update`, a key in `required` may be left
out where a value is stored.

**Cost.** One table and one sentence. **Breaks** nothing.

### 3. A required key added with no default is breaking, as smithy-diff says

**Problem.** `docs/evolution.md:45-47` defines compatible as "every
client that read the old contract still gets what it asked for". Yet the
table rates a required key added with no default "compatible, with a
note" (`docs/evolution.md:73`), and the lint agrees
(`src/Contract/ContractDiff.php:571-583`). The same row's client column
says "A create sends it" and an edit of a thing stored before "sends it
once": a client written against the old contract does neither, and is
refused. Two rows down, "An optional key made required" is breaking
because "A create that leaves it out is refused" (`docs/evolution.md:77`),
the same reason. The test shows the everyday case: `organizer` added as
required to `registration.step2`'s configure
(`tests/src/Kernel/ContractBaselineTest.php:157-169`), a singleton whose
stored config predates the key, so every old client's next write is
refused. The decision's reason, that nothing said before changes meaning
(`docs/decisions.md:3251-3265`), holds for stored values, not for
requests. Smithy's `AddedRequiredMember` evaluator rates this an ERROR
unless the member also has `@default` or `@clientOptional`.

**Change.** Without a default it narrows; with one it stays a note.

Before (`src/Contract/ContractDiff.php:579-583`):

```php
default => [
  sprintf('required key added: a write must now supply %s where nothing is stored', $key),
  self::NOTE,
],
```

After:

```php
default => [
  sprintf('required key added with no default: a client that leaves %s out is refused', $key),
  self::NARROWS,
],
```

**Cost.** A behavior change in the lint; `ContractDiffTest.php:222-233`
and `ContractBaselineTest.php:151-169` flip; the decision entry is
rewritten. **Breaks.** No committed baseline carries an allowance, so
no example fails. An adopter who adds a required key must give it a
default or an allowance with a reason; the version path is closed until
migrations exist (`src/Resource/Attribute/Resource.php:44-50`), which is
honest about what the module can promise today.

### 4. Identity is the address; "locked once known" is a second fact

**Problem.** A Smithy identifier is the address of an instance; an
instance operation is one that binds all of them. The attribute promises
the same: "The input keys that say WHICH thing this is", "part of the
address: what a discovery document lists, what a tool or route must
supply" (`src/Resource/Attribute/Resource.php:30-36`). But the field
storage resource names `identity: ['field_type']`
(`modules/data_surface_tool/src/Resource/FieldStorageResource.php:43`),
which says nothing about which storage. Its address, the entity type and
field name, travels as known keys that are not identity
(`FieldStorageResource.php:73-79`, `docs/decisions.md:415-424`), and the
field type "became identity when it started choosing the settings slot"
(`docs/decisions.md:423`). Field instance identity includes `field_type`
too (`modules/data_surface_tool/src/Resource/FieldInstanceResource.php:51`).
So `identity` means "locked once the context knows it", which in
Smithy's AWS traits is create-only mutability (`@cfnMutability("create")`),
not an identifier. Every reader that trusts identity as the address is
wrong for these resources; `namesOneThing()` would take a scalar
`field_type` parameter as naming the storage whole
(`src/ResourceBuild/OperationDefinition.php:220-226`).

**Change.** `identity:` names the address, and may name keys that are
known rather than asked, as Smithy identifiers need not be properties. A
new `fixed:` names input keys a create sets and nothing changes after;
an identity key that is an input stays locked once known, as now.

Before: `#[Resource('field.storage', identity: ['field_type'], storage: FieldStorageStorage::class)]`

After: `#[Resource('field.storage', identity: ['entity_type_id', 'field_name'], fixed: ['field_type'], storage: FieldStorageStorage::class)]`

**Cost.** One attribute argument, a concept only resources with
discriminators meet (never on the settings page). **Breaks** the field
resources' derived tool definitions, which read identity off the
signature (`docs/decisions.md:1829-1836`), and any adopter that named a
discriminator as identity. No baseline fixture covers the field
resources.

### 5. A create the caller names is Smithy's put, and is idempotent

**Problem.** `#[Operation]` defines idempotent as "Whether the same call
twice leaves what the first left" and derives that "one that creates is
not" (`src/Resource/Attribute/Operation.php:69-75`,
`src/ResourceBuild/OperationDefinition.php:115-116`). Smithy separates
create (the service assigns the identifier) from put (the client
supplies it, and put MUST be idempotent), with `@noReplace` for a put
that refuses to overwrite. The content type's add is a put with
noReplace: the caller names `type`, and a second identical call is
refused by `DataSurfaceUniqueNodeType` (`NodeTypeResource.php:64-68`),
leaving what the first left. By the module's own definition it is
idempotent; the derivation says not, so `x-surface.operation.idempotent`
and MCP's `idempotentHint` tell an agent a retry is unsafe. The content
entity add is a true create: it locks `id` to NULL, "Storage gives the
id" (`modules/data_surface_entity/src/Resource/ContentEntityResources.php:170-177`).

**Change.** A creating context that leaves an identity key open for the
caller is a put, idempotent; one that knows every identity key is a
create, not.

Before: `return $this->idempotent ?? ($this->readOnly || !$creates);`

After: `return $this->idempotent ?? ($this->readOnly || !$creates || $callerNames);`

**Cost.** One derived fact, computed from the context it already reads.
**Breaks** the facts of `node.type:add` and `field.instance:add`, which
no baseline pins, and `OperationFactsTest`'s logbook cases if one is a
caller-named create.

## 3. Borrow

**Lifecycle words, derived.** Changes 1 and 5 together give `kind` the
values Smithy uses: `create`, `put`, `update`, `read`, still derived and
never declared, which keeps the decision's reason (it cannot disagree
with `readOnly` and `creates`). It transfers because it is static: a
discovery-time fact, exactly what a tool listing and a catalogue need.

**The optionality table** (change 2). Smithy learned that nullability
and presence must be decided per consumer; the module learned the same
one adopter at a time. Writing it as one table costs no concept.

**`AddedRequiredMember`** (change 3). The module already borrowed
suppressions with a reason as baseline allowances, which is right.

**Not `@default`'s rule.** Smithy says a member default "SHOULD NOT be
changed" because clients fill defaults. Here the pipeline fills them, so
a note is correct (`docs/decisions.md:3334-3337`); I agree with the
module.

**Not Smithy's member-trait precedence.** In Smithy, "Traits applied to a
member supersede traits applied to the shape targeted by the member", so
a member may widen its target's `@range`. The module holds a key's
additions to a shape to narrowing only (`docs/decisions.md:222-230`),
which is stricter and right when an alter tightens a shape site-wide.

**Traits in one syntax, lightly.** A key's facts are spelled four ways:
core setters, `addConstraint()`, `setSetting('multiline', TRUE)`
(`docs/recipes.md:126-128`, read at `src/Plugin/DataSurfaceWidget/StringWidget.php:47`
and nowhere validated, so a misspelling is silently a one-line field),
and `DefinitionMetadata::setExamples($headline, ...)`, which breaks the
chain (`modules/data_surface_demo/src/Structure/DemoBlockStructure.php:44-48`).
`add()` already takes `default:` as a named argument
(`src/Resource/BlueprintAdditionsInterface.php:62`); `examples:` and
`secret:` beside it would follow that precedent without a new concept.

**Mixins: keep the module's choice, say where it parts.** Smithy mixins
carry a marker, "MUST NOT be targeted by members", and are elided by
flattening. The module lets one structure be mixed in by one owner and
attached by another (`docs/decisions.md:1132`) and emits `allOf` with a
`$ref` (`:1156`), paying with a def written open where it is mixed and
closed elsewhere (`:1165`). The reason, that mixing is the owner's
choice, fits Drupal's base-field traits. Keep it, and say in the
glossary that this is not Smithy's mixin.

**Not the selector language or the service shape.** The catalogue
answers what a Drupal site asks of its model, and a module is already
the service; per-resource versions fit stored config better than a
service API version. Both would break the concept budget for nothing.

What the module does that Smithy cannot, conditional narrowing, reads
coherently to a Smithy user. A Smithy author would model the bulletin's
send day as a union of weekly and monthly structures; the declared rule
(`modules/data_surface_examples/src/Resource/BulletinResource.php:53-59`)
says the same thing without inventing two structures, only narrows, and
is stated in the full schema as `if`/`then`. The slot is a union
discriminated by a sibling rather than by member name, and the module
says why it emits no discriminator (`docs/decisions.md:2781`). The two
documents map to Smithy's model and a runtime-specialized view of it;
that split is sound.

## 4. Cut

**A child operation matched by the parent's operation id.** The second
of four resolution steps (`src/ResourceBuild/Resources.php:1480-1491`,
`docs/decisions.md:345-352`) binds a child's operation because its id
happens to equal the parent's. Smithy never binds by coincidence of
names; renaming a parent's `edit` would silently move every child to its
default. Naming (`withChild(operation:)`) and `default: TRUE` cover the
case. Cost: the content entity resource names each field child's
operation in its `add()` and `edit()` loops, where `FieldItemStructures`
now relies on the match (`modules/data_surface_entity/src/Resource/FieldItemStructures.php:85-86`).

**"Shape" as an umbrella of three kinds.** The glossary says a shape is
a resource, a structure or a named scalar (`README.md:143-152`), but no
code treats the first two as shapes except the shared def-id namespace
(`src/Resource/Attribute/Shape.php:16-17`). Fold the word to what
`#[Shape]` names, Smithy's simple shape with its traits, and one word
loses two meanings.

Nothing else in the inventory (`docs/concepts.md:17-59`) looks
superfluous from my standpoint.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `identity:` meaning locked once known | `identity:` for the address, `fixed:` for create-only keys | A Smithy identifier is the address (change 4). No surface word involved. |
| Shape, three kinds (`README.md:143`) | Shape: a named scalar, Smithy's simple shape | The code names only the third kind. |
| "The schema as it stands" (`docs/served-contract.md:63`), "the schema for a set of answers" (`README.md:32`), `emit()` (`src/Contract/ContractEmitter.php:245`) | "the schema for these answers" everywhere (33 uses already), `emitForAnswers()` | One thing, one name; `emit()` beside `emitFull()` says neither. |
| `kind: create` for a caller-named create | `kind: put` | Smithy's word for a create whose identifier the client supplies (change 5). |
| `situation` and `surface` wire keys (`tests/fixtures/contracts/first.newsletter.json:2-6`) | `operation`, `resource` | Crosses the product rule; `docs/decisions.md:150-151` defers it to a contract version, and I agree. |
| `with()` | keep | It is Smithy's own IDL spelling, `structure Journal with [Timestamps]`. |
| `readOnly`, `idempotent` | keep | Smithy's `@readonly` and `@idempotent`, same meaning. |
| `defineInputs()` on a resource | keep; say a resource's inputs are its properties | In Smithy an operation owns its input; here every operation's input is the resource's keys, narrowed, which the tool listing already says ("The values input is the resource itself"). |
| Mixin, in the glossary | keep, add "not flattened, unlike Smithy's" | The emitted `allOf` is not Smithy's semantics. |

## 6. Verdict

I would adopt it for Drupal configuration and content contracts: the
vocabulary is mostly used as Smithy uses it, the narrowing-only rules
are stricter than Smithy's trait precedence, and the contract diff is a
real smithy-diff. The caveat is that two borrowed words, identity and
the evolution verdict on required keys, mean less than a Smithy user
will assume, and the operation's lifecycle facts are still learned by
running code. Declaring `creates` on `#[Operation]` and rating a
required key added with no default as breaking would turn my answer from
"yes, with a list of footnotes" into "yes".
