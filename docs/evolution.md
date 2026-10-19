# Evolving a resource

A resource or structure is a promise twice over: to the values already
stored for it, and to every client that read its contract. This page
says which changes keep both promises, which break one, what a
breaking change needs, how the module finds one before a release does,
and what is promised at all. The decision behind it is [A resource
evolves in the open](decisions.md#a-resource-evolves-in-the-open).

In short: a compatible change needs nothing. A breaking change raises
the version of the resource or structure that owns it and migrates the
values stored at the old version, or records in its contract baseline
why it was accepted. The contract diff tells the two apart, as a test
and as a Drush command.

## What is promised

The module is experimental, and `main` today promises nothing: any
contract, stored layout or API may change in any commit, and nothing is
migrated.

From the first tagged release on:

- Every resource and structure of the examples and `data_surface_first`,
  the pages the documentation is pinned to, keeps a committed contract
  baseline, as will any resource the main module ships, and the suite
  fails on a breaking change to one that is neither versioned nor
  allowed. An allowance
  carries its reason, and the release notes name it.
- `x-surface.version` means what this page says: it counts the breaking
  changes made to a resource's or structure's stored values, and a
  compatible change never moves it.
- Once stored-value migrations are built, values stored by any tagged
  release are converted forward when they are read.

Not promised: the PHP API, which stays experimental; the other
submodules' configuration and resources, which are demonstrations and
test fixtures; what an alter installed on a
site does to a resource it does not own (the site runs the contract
diff on its own baselines); the Tool API's rendering of a tool; and the
React app's internals.

## Compatible and breaking

A change is **compatible** when every value already stored still loads,
every client that read the old contract still gets what it asked for,
and the full schema is still never stricter than the pipeline, judged
on the accepted document after stored values are converted. Otherwise
it is **breaking**.

"What a client asked for" is read from [value
semantics](semantics.md#absent-null-or-a-value): a key left out keeps
what it holds (its declared default on a create, what is stored on an
edit, and its default again for a stored value that predates the key),
`null` clears it, and the pipeline, not the client, fills defaults. A
client reads a document by the schema served with it: the documents are
closed (`additionalProperties: false`), so a full schema cached before a
key was added refuses a document that carries it, and a client fetches
the full schema again when the version it holds is not the one served.

| Change | Verdict | What stored values need | What a client needs | How it is detected |
| --- | --- | --- | --- | --- |
| An optional key added, with a default | compatible | Nothing: a value stored before reads the default until it is written. | Nothing: an old document leaves it out, so a create gives it the default and an edit keeps what it holds. | `optional key added` |
| An optional key added, with no default | compatible | Nothing: a value stored before holds nothing there. | Nothing: left out, a create holds nothing and an edit keeps nothing; a reader of `values` meets a new key holding `null`. | `optional key added` |
| A constraint widened (a bound raised, a pattern dropped) | compatible | Nothing. | Nothing; a read promises shape, not membership ([decision](decisions.md#a-read-promises-shape-not-membership)). | `maximum raised`, `pattern removed` |
| A choice added | compatible | Nothing. | A reader may meet a value it does not know, and shows it by its value. | `choice added` |
| A variant added | compatible | Nothing. | As for a choice: the deciding key offers a value, and its conditional is met only by it. | `choice added`, `rule added for a value the baseline did not allow` (a note) |
| An optional port added to a structure | compatible | Nothing. | A container that does not wire it reads nothing there. | `optional port added` (a note) on the def |
| A mixin added, every member optional and no name taken | compatible | Nothing: its members read their defaults. | As for optional keys. | `optional key added` per member; a name taken is refused at build |
| A label, a description, a default or an example changed | compatible | Nothing. | Nothing; a default changed changes what a create gives a key left out, which the note says. | a note |
| A required key made optional | compatible | Nothing. | A reader of `values` may meet `null` there. | `made optional` |
| A key removed | breaking | A migration drops it; `ConfigObjectStorage` otherwise keeps it stored, unread, since it lays values over what is stored. | Sending it is refused as an unknown key. | `key removed` |
| A key renamed | breaking | A migration moves the value. | The old name is refused as unknown; the new one is asked for. | `key removed` beside `optional key added` |
| A constraint tightened | breaking | A value stored outside it is refused at the next write, naming the key, since the pipeline validates what it keeps as well as what is sent: a migration brings it inside, or the author shows none is stored. | A value it sent before is refused. | `maximum lowered`, `pattern added`, `checked on the server now` |
| A choice removed | breaking | Nothing is lost: [the stale rule](semantics.md#stale-values-the-third-state) keeps the stored value and warns until someone chooses anew; a migration may map it to a value still offered. | Sending it is refused, with the values that apply. | `choice removed` |
| An optional key made required | breaking | A stored value holding nothing is refused at the next write: a migration fills it. | A create that leaves it out is refused. | `made required` |
| A required key added | breaking | As for an optional key made required. | A document that leaves it out is refused. | `required key added` |
| A type changed | breaking | A migration converts it. | A value of the old type is refused, or coerced where the coercion table reads it. | `type changed` |
| A storage layout changed (a layout, the config object's name, the storage class) | breaking for stored values, invisible to clients | The storage reads both layouts and writes the new one, as `EncryptedSettingsLayout` reads plaintext; when the accepted document moves too, a version and a migration. | Nothing: the documents do not move. | Not by the contract diff: by the storage's own tests and its config schema. |
| A variant removed | breaking | The deciding key's stored value is stale and the variant's keys are unread: a migration moves them to a variant still offered. | Choosing it is refused. | `choice removed`, `rule removed` |
| A required port added to a structure others attach | breaking for every container | Nothing. | Every container must wire it: the build refuses an unwired required port, at each site. | `required port added` on the def |
| A shape tightened by an alter, on a site that stores values outside it | breaking, on that site | As for a constraint tightened; the alter's module ships the post-update that converts or reports them. | As for a constraint tightened. | The site's own contract diff over its own baselines; the shape's module cannot see it. |
| An operation removed, or how it behaves changed (`readOnly`, `destructive`, `creates`, `idempotent`) | breaking | Nothing. | A client acts on those facts. | `operation no longer described`, `the operation's destructive changed` |

## The version

`#[Resource]` and `#[Structure]` take a `version:`, an integer from 1:

```php
#[Resource('example.newsletter', storage: NewsletterStorage::class, version: 2)]
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

It counts the breaking changes made to the values stored for it, so a
compatible change never moves it, and it is 1 until the first breaking
change after values were first stored. Three readers use it:

- **Clients.** Both documents stamp it, `x-surface.version`, at the top
  always (after `operation`, first when there is none) and on a def
  only above 1, since a def at 1 has said nothing yet. A client compares
  it before it trusts a cached document.
- **The contract diff**, which lets a breaking change through when the
  version of the id that owns it was raised.
- **Stored-value migrations**, keyed by it.

A structure carries its own version, because it is stored by every
container that holds it: a breaking change to a structure changes
every container's stored layout, and only the structure can say how to
convert its own map. A container's version never answers for a
structure it holds. A named scalar shape carries none: its values are
its users' keys, so a breaking change to a shape is a breaking change
to each resource and structure that uses it, and the contract diff
attributes it to the document it finds it in. A derived resource is at
1: its keys are the site's, not the code's.

**Today a version above 1 is refused at discovery.** It promises that
values stored at an older version are converted before anything reads
them, and the migrations that keep that promise are designed below and
not built (ROADMAP.md, Phase D item 13). Until they are, a breaking
change is accepted by an allowance in its baseline, with its reason.

## Stored-value migrations, as they will be

A resource or structure that raised its version implements
`Resource\StoredValueMigrationInterface`, on the class that declares the
version: one pure, static step from each version to the next, over its
own frame in input layout, the accepted document of the older version.

```php
#[Resource('example.newsletter', storage: NewsletterStorage::class, version: 2)]
final class NewsletterResource implements ResourceInterface, StoredValueMigrationInterface {

  public static function migrate(array $stored, int $from): array {
    // 1 to 2: the sender's name was stored as two keys.
    if ($from === 1) {
      $stored['sender_name'] = trim(($stored['first_name'] ?? '') . ' ' . ($stored['last_name'] ?? ''));
      unset($stored['first_name'], $stored['last_name']);
    }
    return $stored;
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

What the runner will do:

- **On read, before anything reads it.** The pipeline converts what
  `load()` hands it before `accept()` reads it as what is stored, so the
  form, the tool, the read tool, both documents' `values` and the React
  page all read converted values. A read that converts and writes
  nothing changes nothing: the step is pure, and the next read converts
  again to the same thing.
- **Step by step, in recorded order.** From the version the
  destination's ledger records to the one declared, one step at a time,
  never skipping. Each child converts its own map wherever it sits: an
  attached key, each item of a collection, a module's mount, a mixed-in
  structure's members inside its owner's map (the step handed the
  owner's map and allowed to change only its members).
- **The ledger is the storage's.** A storage owns the layout, so it
  says where the stamp lives, and stamps the declared versions at
  commit. `ConfigObjectStorage` keeps `_data_surface.versions`, keyed by
  id, beside `_core`, owning it as it owns `_core` and `langcode`:
  `load()` leaves it out, `commit()` writes it, the module describes it
  for every config object by altering core's `config_object` type, and
  it is written only once a version above 1 is stored, so every config
  object today stays as it is. A config entity keeps it in its
  `third_party_settings.data_surface`. A storage that keeps no ledger
  (State, a plugin's configuration, today) makes a version above 1 on
  what it stores a refusal at discovery.
- **The stamp travels with the data.** A config export carries it, so an
  environment that imports values exported before a migration converts
  them again when it reads them, and environments converge in the order
  the steps were recorded, whatever order they were deployed and
  imported in. A restore is the same: only up-steps are written, and
  going back is a restore of what was stored, older stamp and all.
- **Code older than its data is refused.** A value stamped above the
  declared version is refused at load, naming both.
- **An eager pass for code that reads storage itself.** Converting on
  read serves every door of the pipeline; a module's own code that reads
  its config object with `\Drupal::config()` reads it as stored. A
  command and a service a post-update calls load and commit every
  destination of a resource through the pipeline, so its stored values
  move once, at deploy.
- **A storage layout change is not a migration.** One that leaves the
  accepted document as it was is the storage's own: read both layouts,
  write the new one, no version.
- **A content entity resource, later, without redesign.** Its storage
  answers the same two questions, where the ledger lives (a field the
  storage owns on the entity, or the bundle once an eager batch has
  converted every entity of it) and how it stamps at commit, and the
  steps and the runner do not change.
- **Callers are not migrated.** A step converts stored values, never a
  document a caller sends: a document written for the old version is
  held to the new one and refused by name. The version is how a client
  learns to send the new one.

## The contract diff

The additive-diff lint compares each resource's and structure's full
schema with a committed baseline and classifies every difference.

**Baselines.** One file per resource or structure,
`tests/fixtures/contracts/<id>.json`, in the module that keeps them:
the full schema of each operation that needs no subject, and for a
structure with no operation of its own, the one it is described in
alone. They are emitted for one fixed account, the site's first, so the
documents do not depend on who runs the lint. This module pins the
examples and `data_surface_first`: nine files, the five resources and
the four structures they hold. An adopter commits its own, in its own
module.

**Verdicts.** `Contract\ContractDiff` compares two documents and names
each difference with its path (`configure#/properties/capacity/maximum`)
and what it does to the documents the schema accepts: it narrows, widens,
adds a key that may be left out, replaces, or only notes. For the
inputs, what a client sends, narrowing and replacing are breaking; for
the outputs, what a client reads, widening and replacing are. A
difference in a keyword the lint does not read is breaking: it is
never more lenient than it can show. Each breaking change is then:

- **versioned**, when the version of the id that owns it was raised
  (the document's resource or structure, or the structure whose def the
  path is inside);
- **allowed**, when its baseline lists its path under `allowed`, with a
  reason;
- **breaking** otherwise, and the lint fails.

**The test.** `ContractBaselineTest` emits every pinned declaration and
fails on a breaking change that is neither versioned nor allowed, and
on a declaration with no baseline. Compatible changes pass; the
baseline is the promise last made, and it is pinned again when a change
is accepted.

**The command.**

```
ddev drush data_surface:contract-diff \
  --module=data_surface_examples,data_surface_examples_compliance,data_surface_first \
  --dir=modules/custom/data_surface/tests/fixtures/contracts
```

prints every difference with its verdict and exits non-zero on a
failing one. `--dir` defaults to the first module's
`tests/fixtures/contracts`, which is where an adopter keeps its own.
`--write` pins today's full schemas as the baselines, and refuses to
while a breaking change is neither versioned nor allowed, unless
`--allow="<reason>"` says why, which is recorded in that baseline beside
each change it accepted.

**What it cannot see.** A storage layout, which no document states; the
answer of a runtime refiner or an options list read from the site; and
an alter a site installs, which only that site's own baselines cover.

## See also

- [Decisions: a resource evolves in the open](decisions.md#a-resource-evolves-in-the-open)
- [The served contract](served-contract.md), whose full schema the lint compares
- [Value semantics](semantics.md), which says what a client asked for
- [Storage](storage.md), which owns the layout and will own the ledger
