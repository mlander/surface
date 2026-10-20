# Review: the two documents, read by JSON Schema tooling

## 1. Standpoint

I review as the author of generic JSON Schema tooling (ajv and opis
validators, zod and TypeScript type generators, OpenAPI bundlers,
json-schema-to-form generators such as RJSF) who is handed
`/surface-api/{resource}/{operation}` and `.../schema` and nothing of
this module's React app. I hold the documents to the 2020-12 dialect
they declare, to what the most common tools do with them out of the
box, and to the module's own rule that neither document is ever
stricter than the runtime.

## Evidence

Everything below was run, not read off the grammar. I fetched both
documents for twelve operations (the examples, `first.newsletter`,
`first.store`, `event.program:check`) logged in through `ddev drush uli`,
and validated them plus the twenty pinned documents in
`tests/fixtures/contracts/` with ajv 8.20 (`Ajv2020`), ajv-formats 3,
@hyperjump/json-schema 1.18 (a spec-faithful 2020-12 implementation),
json-schema-to-typescript 15 and @rjsf/utils 5.24, all installed in a
scratchpad, never in the module.

| Run | Result |
| --- | --- |
| Against the 2020-12 metaschema | All 44 documents valid. |
| `new Ajv2020()` (defaults) | Every document throws `strict mode: unknown keyword: "x-surface"`. |
| `strict: true`, `x-surface` registered with `addKeyword` | 6 of 12 live full schemas and 6 of 20 baselines throw under `strictTypes` (`missing type "number" for keyword "maximum" at "#/allOf/3/then/properties/capacity"`, and `missing type "object" for keyword "properties"` in sales, desk, press); 3 of 12 GET documents too; event.press's GET adds `strictRequired: required property "name" is not defined` at its contact narrowing. |
| `strict: false` | Every document compiles. Each GET `values` validates against its own schema and against the full schema. |
| Thirteen instance cases (slot, mixin with `unevaluatedProperties`, `false` for a ruled-out key, a nested declared rule) | ajv and hyperjump agree on every verdict. |
| `format: "email"` | Strict ajv without ajv-formats throws `unknown format "email"`; with ajv-formats it asserts by default and refuses `jörg@example.com`, which `email.validator` accepts. |

The dialect is used correctly: `$ref` with siblings, `allOf` with
`unevaluatedProperties: false` over open mixin defs, `if` with
`required` on enumerated conditionals, `false` for a key that does not
apply, `oneOf` of `const` and `title`. Where the documents fall short is
in what tools other than a validator do with them.

## 2. The five changes, ranked

### 2.1 Keep conditional branches and narrowings plain JSON Schema

**Problem.** `x-surface` is written inside applicators: beside each
declared rule's `if` (`src/Contract/ContractEmitter.php:1349`, `:1355`,
`:1376`), inside a slot's `then` (`:1933`), and whole inside a site's
narrowing (`:1767-1768`). RJSF resolves `allOf` with
json-schema-merge-allof, which has no resolver for two differing
`x-surface` objects, so on registration.step3 it logs `No resolver found
for key x-surface` and falls back to the unmerged schema: the room
offers all six rooms for Riverside, the paid ticket resolves to `{type:
[object, null]}` with no keys, and on event.bulletin with
`{"frequency":"monthly"}` its `getDefaultFormState` fills `send_day:
"monday"`, a document its own validator then refuses. With `x-surface`
removed from the conditionals and `then`s only (properties keep theirs),
the same calls narrow the room to two, resolve the paid ticket's def and
drop `send_day`. The same branches state a bound without a type, which
ajv's `strictTypes` refuses and which json-schema-to-typescript reads as
an object: event.sales's locked `per_order` narrowing (`{"readOnly":
true, "x-surface": ...}`) generates `per_order?: {[k: string]: unknown}`
intersected with `number | null`.

**Change.** No `x-surface` below an `allOf` member, an `if` or a `then`.
The frame says which conditionals are rules, and the slot says which def
each value picks; a narrowing carries `type` beside any type-specific
keyword.

Before (`ContractEmitter.php:1349`, `:1933`):

```php
$branches[] = ($requiring ? $guard($branch, $below) : $branch) + [self::EXTENSION => ['declared' => TRUE]];
...
$schema[self::EXTENSION] = $this->extension('fieldset', FALSE, [], ...) + ['variant' => $id];
```

```json
{"if": {...licence null...}, "then": {"properties": {"capacity": {"maximum": 100, ...}}},
 "x-surface": {"declared": true}}
```

After:

```json
"x-surface": {"rules": [9]},
"properties": {"ticket": {"type": ["object", "null"],
  "x-surface": {"by": "pricing", "variants": {"free": "#/$defs/registration.step3.ticket.free",
                                              "paid": "#/$defs/registration.step3.ticket.paid"}}}},
"allOf": [..., {"if": {...}, "then": {"properties": {"capacity": {"type": ["integer", "null"], "maximum": 100}}}}]
```

**Cost.** A wire change under `x-surface`, no new authoring concept.
The React app reads both markers where they are now
(`modules/data_surface_react/app/src/contract.ts:507-509`, `:1654-1660`).
Repeating `type` in a `then` is the declared type, so it cannot narrow.
**Breaks.** Every baseline: `ContractDiff` classifies a keyword it does
not read as BREAKS (`src/Contract/ContractDiff.php:306`), so the lint
must learn the move before the baselines are re-pinned.

### 2.2 Give both documents an `$id`, and say what they validate

**Problem.** Neither document has `$id`; the root is `$schema`, `title`,
`x-surface`, `type` (`ContractEmitter.php:262`, `:350`). Every reference
is local (`#/$defs/...`), so the moment a tool embeds the schema, as an
OpenAPI bundler puts it under `components`, the references resolve
against the wrong root: ajv answers `can't resolve reference
#/$defs/registration.step3.ticket.free from id #`; with an `$id` added,
the same embedding validates. Two documents cannot share one ajv
instance (`schema with key or id "" already exists`), and hyperjump
cannot register one without a URI. The second gap is semantic. The
documents describe the accepted document, after `accept()`
(`docs/served-contract.md:106`), and a declared `absent` is `{type:
null}` with no `required` (`src/Refinement/Condition.php:371`,
`docs/decisions.md:792`). Nothing in the document says so, and it sits
next to `/submit`, whose body is partial on an update. Verified:
step3's full schema refuses `{"capacity": 120}` (required, and the
license rule holds vacuously) and refuses a complete document that
leaves `third_party_settings` out with capacity 300 in the main hall,
which an update reads as the stored license and accepts. A tool author
who wires the schema as the request body refuses valid updates.

**Change.** `emit()` and `emitFull()` take the URL they are served at
and write it as `$id`; the root gets a generated `description`.

Before (`ContractEmitter.php:350`):

```php
'schema' => ['$schema' => self::DIALECT] + $title + $this->lockedWhole(...),
```

After:

```php
'schema' => ['$schema' => self::DIALECT, '$id' => $url] + $title
  + ['description' => PlainText::of(t('Validates the accepted document: every key present, null for none. Not a request body; an update may leave keys out.'))]
  + $this->lockedWhole(...),
```

**Cost.** Additive; the emitter is the main module's, so `$url` is a
parameter the React controller fills, and a document emitted with no
route (the contract panel) keeps none. The full schema is per account
(`docs/served-contract.md:81-88`), so a registry shared across accounts
would hold one account's reading under the URL; that is the HTTP cache's
`Vary`, said again. **Breaks.** Nothing a validator reads; `ContractDiff`
needs `$id` in `ANNOTATIONS` (`ContractDiff.php:78-87`).

### 2.3 Close the pattern subset's three holes

**Problem.** `docs/decisions.md#a-pattern-both-engines-read-alike`
carries `\d`, `\w`, `\s` and `\b` anywhere, under `u` or not
(`src/Contract/PatternTranslation.php:167`). PHP's `u` also sets Unicode
properties, so the engines part ways, and in the stricter direction for
the schema. Run on PHP 8.4 / PCRE 10.46 and Node 24:

| Regex (carried as) | Value | PHP | ECMA, `u` flag (ajv) |
| --- | --- | --- | --- |
| `/^\d{4}$/uD` (`^\d{4}$`) | `٢٠٤٨` | accepted | refused |
| `/^\w+$/uD` (`^\w+$`) | `café` | accepted | refused |
| `/^\bé/uD` | `é` | accepted | refused |
| `/^[^\s]+$/D` (`^[^\s]+$`) | `a`, no-break space, `b` | accepted | refused |

`PatternTranslation::ecma()` returns the pattern for all four (checked
with `drush php:eval`), while it already refuses `\S`
(`tests/src/Kernel/Matrix/AdvertisedSchemaAcceptanceTest.php:949-950`)
for exactly the reason `[^\s]` fails. The suite cannot see it: its
validator is opis, which reads `pattern` with PCRE
(`tests/src/Traits/ContractValidationTrait.php:7`), and the audit says
"No ECMA engine is run here" (`docs/audit-never-stricter.md:49`).

**Change.** Before (`PatternTranslation.php:167`):

```php
if (in_array($next, ['d', 'w', 's', 'b', 'n', 'r', 't', 'f'], TRUE)) {
  return TRUE;
}
```

After (`escape()` is handed whether the body carries `u` and whether it
is inside a negated class):

```php
private static function escape(string $next, bool $in_class, bool $unicode, bool $negated): bool {
  if (in_array($next, ['n', 'r', 't', 'f'], TRUE)) {
    return TRUE;
  }
  // PHP's u reads \d \w \b by Unicode properties; ECMA never does.
  if (in_array($next, ['d', 'w', 'b'], TRUE)) {
    return !$unicode;
  }
  // ECMA's \s is wider than PCRE's: carried only where wider is safe.
  return $next === 's' ? !$negated : (str_contains(self::SYNTAX, $next) || ($in_class && $next === '-'));
}
```

Then run ajv in the app's Vitest over a table of PCRE/ECMA pairs, so an
ECMA reading is a test, not a grammar argument.

**Cost.** A behavior change: such patterns become `checkedOnServer`,
wider, with `patternMessage` still carried. **Breaks.** None of the
shipped patterns (all `/D`, no `u`: `Licence.php:25`, `PhoneNumber.php:30`,
`DoorsResource.php:45`, `ReviewStructure.php:29`); an adopter's `/u`
pattern loses its client-side check, which is the honest answer.

### 2.4 State a slot's variants as a union, beside the conditionals

**Problem.** A slot's property fixes nothing (`ContractEmitter.php:1897`);
its variants live only under `if`/`then`. No type generator evaluates
conditionals, so json-schema-to-typescript writes `export type Ticket =
{} | null;` for step3, and the two ticket defs are never generated.
Zod, OpenAPI codegens and every form builder that does not resolve
`if` see the same empty object.

**Change.** Add `anyOf` of the variants' references, and `null` where
the slot may be cleared, on the slot's own property. It is wider than
or equal to any one `then`, so it never narrows; the conditionals stay
and keep the exact rule, and there is still no `discriminator`
(`docs/decisions.md:2781`).

Before (step3 full schema):

```json
"ticket": {"title": "Ticket", "type": ["object", "null"], "x-surface": {"by": "pricing", ...}}
```

After:

```json
"ticket": {"title": "Ticket", "type": ["object", "null"],
  "anyOf": [{"$ref": "#/$defs/registration.step3.ticket.free"},
            {"$ref": "#/$defs/registration.step3.ticket.paid"}, {"type": "null"}],
  "x-surface": {"by": "pricing", ...}}
```

Generated: `Ticket = FreeTicket | PaidTicket | null`, with both
interfaces. ajv's verdicts on five ticket cases (free `{}`, free note,
paid valid, paid `null`, free with `price`) are unchanged.

**Cost.** Additive, one keyword per slot. **Breaks.** The lint reads an
added `anyOf` as alternatives changed (`ContractDiff.php:299`, `:472`),
BREAKS until taught that a union of every variant beside the
conditionals narrows nothing; the React `inlineSchema()` must skip it.

### 2.5 Title every def, so generated names are stable

**Problem.** A def has no title by design ("No label: that is the
parent's to give", `docs/served-contract.md:342`; `define()`,
`ContractEmitter.php:1612-1633`). Generators name types from `title`,
else from the reference. Where a site's title is beside the `$ref`, the
type is named after the site's label (`Contact`, `Compliance`); where
the reference is bare, as a mixin's is, json-schema-to-typescript cuts
`event.review` at the first dot and names it `Event`. event.sales
generates `EventSales`, `EventSales1`, `Standard1`, `Student1`,
`Speaker1` and `Event` for one form. Option labels compound it: every
`{const, title}` becomes a type named after its translated label
(`MainHall`, `Monday`) from `choices()` (`ContractEmitter.php:3025`), so
the type names change with the interface language.

**Change.** Each def carries `title`, its id, which is stable,
language-free, and already unique in the document.

Before (`ContractEmitter.php:1632`):

```php
$def = $this->versioned($child, $def, FALSE);
```

After:

```php
$def = ['title' => $id] + $this->versioned($child, $def, FALSE);
```

Retitled the same way, event.sales generates `EventSalesOnSale`,
`EventSalesLimits` and `EventTicketLimit`.

**Cost.** Additive, an annotation. The parent still labels the site,
with its own `title` beside the `$ref`, as now.
**Breaks.** Nothing a validator reads; option labels stay a generator
problem (2.5 does not touch `oneOf`).

## 3. Borrow

- **An OpenAPI 3.1 document for the five endpoints.** One route,
  `/surface-api/{resource}/{operation}/openapi.json`, generated like the
  rest: `components.schemas` refers to the full schema by its `$id`
  (2.2), the submit body's `values` is an open object, and the accepted
  document is named as such. It is how a tool author is told the
  difference between a request body and what it becomes, which
  `docs/served-contract.md:104-113` explains to people. It adds no
  authoring concept: every fact is in the operation already.
- **A published schema for `x-surface`.** OpenAPI's convention is `x-`
  and free-form; ajv's answer is `addKeyword({keyword, metaSchema})`.
  Shipping `x-surface.schema.json` turns "register it as an unknown
  keyword" into "validate the extension in strict mode". I would not
  move to a `$vocabulary` dialect: a custom `$schema` URI makes ajv
  refuse the document until the dialect is added, and as I understand
  the JSON Schema project's direction, prefixed `x-` keywords are the
  extension form it means to keep. The decision to keep the name holds
  (`docs/decisions.md:90`).
- **One flag for "not the whole rule".** For a generic tool, `dynamic`,
  `runtime`, `access: "runtime"` and a non-empty `checkedOnServer` mean
  the same thing: a local "valid" is not final. A root
  `x-surface.exact: false` (true when none appears anywhere) lets a tool
  decide once whether its verdict stands, without learning four markers
  the React app needs apart. Never-stricter is unchanged; this only says
  how much wider.
- **ajv in the app's Vitest.** The app already holds its narrowing to
  recorded `/refine` answers in Vitest (`docs/decisions.md`, "The page
  resolves what the schema states"). Compiling every baseline there with
  `strict: true` and the published `x-surface` schema would have caught
  2.1 and, with pattern cases, 2.3.

How `x-surface` reads to a tool that ignores it, from the table at
`docs/served-contract.md:281-306`:

| Ignoring it is safe | Restates a standard keyword | Ignoring it over-trusts a "valid" |
| --- | --- | --- |
| `widget`, `multiple`, `emptyOption`, `group`, `after`, `patternMessage`, `dependsOn`, `ports`, `reads`, `by`, `variants`, `chosen`, `variant`, `operation`, `version`, `refined` | `locked` (`readOnly`), `doesNotApply` (no property, `false`), `enumerated` and `declared` (the conditionals), `list` | `dynamic`, `runtime`, `access`, `checkedOnServer`; and `stale`, which changes what a `null` in `values` means |

## 4. Cut

- **`locked: false` and `dependsOn: []` on every property.** The other
  markers are written "Only when `true`" (`docs/served-contract.md:297-300`);
  these two are always written, about 12% of a full schema's bytes
  as `"locked":false,"dependsOn":[]` alone (event.doors, event.program). Write them only when they say something.
  The React type makes both required (`app/src/contract.ts:28-29`), so it
  is a wire change in one file.
- **`x-surface.operation.readOnly`.** `kind: "read"` already says it
  (`docs/served-contract.md:231-232`), and the same document uses
  JSON Schema's `readOnly` at the root for "locked whole"
  (`docs/served-contract.md:284`). Keep the attribute argument; drop the
  echo. This removes nothing from the inventory in `docs/concepts.md:20`.
- **Not cut:** `enumerated` and `declared` look like provenance a tool
  never needs, but the app evaluates an enumerated conditional only once
  every watched key holds a value and a rule whatever its keys hold
  (`docs/decisions.md:779-783`); after 2.1 the frame's `rules` list
  carries the second.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `x-surface.operation.readOnly` | drop (see Cut), or `readOnlyHint` | Two meanings of `readOnly` in one document; MCP spells the operation fact `readOnlyHint`. |
| `x-surface.access: "runtime"` beside `x-surface.runtime: true` | `x-surface.runtime: ["refiner", "access"]` | One word, two shapes, two meanings; a reader matching on `runtime` misses the access case. |
| `format: "email"` | `format: "idn-email"` | Drupal's validator accepts a non-ASCII local part, which `idn-email` (RFC 6531) admits and `email` does not; ajv-formats does not implement it, so a strict client must register it. |
| `$defs` keys with dots (`event.review`) | keep the key, add `title` (2.5) | Renaming is a `$ref` change on the wire; the title fixes what tools read. |
| `surface`, `situation` | `resource`, `operation` | Crosses `docs/decisions.md#the-product-keeps-its-name`; the decision already defers it to a contract version (`docs/decisions.md:148-151`). Ship it in the version that adds `$id`, so clients move once. |

## 6. Verdict

I would adopt the full schema today as a validation and agent-planning
contract: it is valid 2020-12, conservative where it must be, and ajv and
hyperjump agree with it case for case. The caveat is that every tool
beyond a validator (a form generator, a type generator, a bundler)
needs it hand-cleaned first: `x-surface` stripped from branches, an
`$id` added, the slot's union stated. The one thing that would change my
answer is 2.1 landing with a strict ajv compile of every baseline in CI,
because that turns "it works in our app" into "it works in yours".
