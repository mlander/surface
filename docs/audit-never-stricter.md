# Audit: never stricter than the runtime

*2026-10-18. An audit, reported and not fixed: nothing in the module's
behavior changed with it. Each verdict is pinned by a test in
`tests/src/Kernel/NeverStricterAuditTest.php` asserting what happens
today; where a verdict breaks the invariant the test says so with a
`@todo` naming its section, so changing a verdict changes a test on
purpose.*

The invariant ([decision](decisions.md#two-documents-never-stricter-than-the-runtime)):
the JSON Schema the emitter writes is never stricter than what the
pipeline (accept, refine, validate) accepts. The full schema
(`emitFull()`) must hold it for any values, the schema for these answers
(`emit()`) for the values it was built with. Exactness is best effort;
width is the guarantee.

## How it was read

For each case: which document, the value set, what the pipeline said
(accepted, and the value it stored or would store), what opis said, and a
recommendation: **widen** the schema, **tighten** the runtime, or
**neither**, with the reason. The user decides each one.

Two readings of the invariant run through the whole audit, and most
verdicts turn on which one is meant:

- **On the raw input.** What a caller sends: text from a form, typed JSON
  from the React page and the tool, a partial update from a PHP caller.
  `accept()` normalizes it (casts, reads `''` as nothing, fills an absent
  key from storage or its default, keeps a locked key's stored value).
  On this reading the schema is stricter in every place `accept()`
  normalizes.
- **On the accepted document.** What `accept()` makes of the input, every
  key present, each value in its declared type. This is what the schema
  describes: the contract's `values` always carry every key, typed.
  On this reading most of those cases hold.

The audit recommends the second as the stated scope of the invariant
(see [the recommendation](#the-scope-of-the-invariant)), and reports as
**broken** only what fails on it too, or what a client does post as is.

The validator is opis/json-schema 2.6, as `ServedContractTest` runs it,
with two properties that matter here:

- **It evaluates `pattern` with PCRE**, flags `uD` (UTF-8 with Unicode
  properties; `$` only at the very end), not with an ECMA engine. Where
  the schema carries a PCRE construct ECMA does not have, opis agrees
  with PHP and an ECMA client (a browser, ajv) would refuse the pattern
  as a syntax error or read it otherwise. No ECMA engine was run for
  this audit; since 2026-10-20 the React app's Vitest reads the pattern
  subset's rows (`tests/fixtures/pattern-engines.json`) with one
  ([decision](decisions.md#a-pattern-both-engines-read-alike)).
- **`Validator` runs with its extensions on.** One of them,
  `allowDefaults`, drops every property that has a `default` from
  `required` and fills it in before validating. A standard validator
  does not; `Opis\JsonSchema\CompliantValidator` turns the extensions
  off, and section 6 uses it where the two differ. A verdict the suite
  states about a required key with a default, left out, would be opis's
  extension and not the standard; none of `ServedContractTest`'s value
  sets leaves one out today.

## Summary

| # | Case | Verdict | Recommendation |
| --- | --- | --- | --- |
| 1 | Casting | Broken on the raw input; holds on the accepted document | Neither: state the scope as the accepted document; do not widen `type` |
| 2 | Unknown keys | Holds, except a blank collection item, whose unknown key is dropped silently | Tighten: read an item's keys before dropping it as empty |
| 3 | Untouched stale values | The audit's construction holds; a kept stale value breaks `emit()` (and the full schema for a static list that changed) | Widen `emit()`: a stale key allows `null` (listed); neither for the full schema |
| 4 | Regex translation | Broken: `$` and a trailing newline; PCRE-only constructs passed verbatim; `\S`/`\Z` | Tighten the shipped patterns (`D`), carry `D`; widen: omit any body the translation cannot vouch for |
| 5 | Null and empty string | `null` and absent hold; `''` broken for every optional key but a plain string | Neither: `''` is a notation for nothing, as in case 1 |
| 6 | Required | Broken on a partial update; holds on the document, except a required secret | Neither for the partial update; widen for the secret (listed) |
| 7 | Constraints with no keyword | Holds; PluginExists with `allowFallback` is narrower (latent) | Widen: no list for a fallback PluginExists |
| 8 | Collections | Broken: blank items dropped before the Count, and refused by `items` | Neither, or tighten: the drop is the decided rule |
| 9 | Narrowing composition | Broken in one site: a locked collection's items in `emit()` | Widen: describe a locked list's items without `const` |
| 10 | Locked keys | Broken on the raw input (`const`); holds on the accepted document | Neither: `readOnly` says it, the document holds |

Outside the ten: `format` is narrower than Drupal's validators (email,
uri, date-time), and core's loose PrimitiveType check lets the pipeline
store a value in another type, which no document accepts
([below](#outside-the-ten-cases)).

## 1. Casting

**Documents:** both. **Test:** `testCastingTableAcceptsNotationsTheSchemaRefuses`.

Every cast the [casting table](semantics.md#the-coercion-table) lists,
on optional keys:

```php
['count' => '50']   // integer, Range 1..1000
['ratio' => '12.5'] // float
['flag' => 'on']    // boolean
['name' => 7]       // string
```

| Input | Pipeline | Stored | Full | These | Full, on what is stored |
| --- | --- | --- | --- | --- | --- |
| integer `'50'`, `' 7 '`, `'+7'`, `'007'`, `'2.0'`, `'1e3'` | accepted | `50`, `7`, `7`, `7`, `2`, `1000` | refused | refused | accepted |
| integer `2.0` | accepted | `2` | accepted | accepted | accepted |
| integer `'1.9'` | refused | | refused | refused | |
| float `'12.5'`, `'2'`, `' 1.5 '`, `'1e3'` | accepted | `12.5`, `2.0`, `1.5`, `1000.0` | refused | refused | accepted |
| boolean `'1'`, `'true'`, `'on'`, `'yes'`, `1` | accepted | `TRUE` | refused | refused | accepted |
| boolean `'0'`, `'false'`, `'off'`, `'no'`, `' OFF '`, `0` | accepted | `FALSE` | refused | refused | accepted |
| string `7`, `1.5`, `TRUE` | accepted | `'7'`, `'1.5'`, `'1'` | refused | refused | accepted |

Example 2 as the form posts it, `capacity: '120'`, `open: '1'`: accepted,
stored `120` and `TRUE`; the full schema refuses the post and accepts
what is stored.

**Verdict:** broken on the raw input, for a form client and for a JSON
client that sends a number as a string. Holds on the accepted document.

**Recommendation: neither.** Widening `type` to admit the notations
(`["integer", "string"]` with a numeric pattern, and so on) would make the
schema describe the transport instead of the value and would lose `type`
for every client. The form never validates against the schema; it posts
to the pipeline, whose casts are its tolerance of notation. State the
scope instead ([below](#the-scope-of-the-invariant)).

## 2. Unknown keys

**Documents:** both. **Test:** `testUnknownKeysAreRefused`.

| Position | Input (over example 3, or the cellar) | Pipeline | Full | These |
| --- | --- | --- | --- | --- |
| Top | `fax: '1'` | refused, `UnknownKeysException` at `''` | refused | refused |
| Attached structure | `contact.fax` | refused, at `contact` | refused | refused |
| Slot's variant | `ticket.fax` (free) | refused, at `ticket` | refused | refused |
| Another variant's key | `ticket.price` (free) | refused, `VariantMismatchException` | refused | refused |
| `third_party_settings`, no module mounting | `third_party_settings.other` | refused, at `''` (no such key) | refused | refused |
| `third_party_settings`, compliance mounting | `third_party_settings.other` (even `null`) | refused, at `third_party_settings` | refused | refused |
| Collection item | `racks.0.brand: 'red'` | refused, at `racks.0` | refused | refused |
| **Blank collection item** | `racks: [{brand: ''}, rack]` | **accepted**, the item dropped, `brand` with it | refused | refused |
| A map that declares no properties | `bag: {anything: 1}` | accepted, kept | accepted | accepted |

```php
$blank = ['racks' => [['brand' => ''], $rack]] + $cellar;
// accept(): one rack; validate(): nothing. The full schema: additionalProperties.
```

**Verdict:** holds, with one exception: an item whose every key holds
nothing is dropped as empty (`Collection::isEmptyItem()`) before
`acceptLevel()` reads its keys, so an unknown key in it is dropped
silently rather than refused. That also contradicts
[semantics](semantics.md#collections) ("an unknown key inside an item is
refused by its path"). It is a refusal everywhere else, never a silent
drop.

**Recommendation: tighten.** In `DataSurfacePipeline::acceptCollection()`,
check an item's keys against the child's definitions before
`isEmptyItem()` drops it. The schema is right.

## 3. Untouched stale values

**Documents:** both. **Test:** `testUntouchedStaleValue`.

As constructed: example 2 stores Riverside's East room; the venue moves
to the library; the served contract shows the room stale (`values.room`
`null`, `stale: ["room"]`); the client sends that back with the stale
path and no room.

```php
$input = $served->keepStale($moved['values'], $stored, ['room']);
// $input['room'] === 'riverside_east', $input['venue'] === 'library'
$this->submit('registration.step2', $input); // committed: FALSE, room refused
```

| Value set | Pipeline | Stored | Full | These |
| --- | --- | --- | --- | --- |
| The submission as the pipeline reads it (library, East room) | refused at `room`, not stale (the venue moved) | unchanged: riverside, East room | refused | refused |
| The same with the room left out (the form's reading) | refused | unchanged | | |
| The moved document's own `values` (room `null`) | | | | refused |
| What stays stored (riverside, East room) | | | accepted | accepted |

So the constructed case **holds**: nothing is stored, and both documents
refuse what the pipeline refuses and accept what stays stored.

The stale value the pipeline does keep is the one a site narrowed away
while nothing the key watches moved:

- **A runtime refiner** (example 4 closes the East room after it was
  stored). The untouched submission, `null` at `room` with the stale
  path, is accepted and committed with a stale entry; the East room stays
  stored. The full schema never runs the runtime refiner and accepts the
  stored document: **holds**. The schema for these answers is refined by
  it for the stored values, so it refuses the stored document it was built
  for, and its own `values` (`room: null`, required): **broken**.
- **A static list that changed** (a `Choice` of `x`, `y` with `z`
  stored): the pipeline keeps `z` stale, untouched or sent back. The full
  schema states the list as it is and refuses `z`; so does the schema for
  these answers, and its own `values` when the key is required:
  **broken**, for both documents.

**Recommendation.** For `emit()`, **widen**: a key shown stale also
allows `null`, so the document validates its own `values`, which is what
a client sends back with the stale path (listed below, not applied). The
stored value itself is deliberately never in the document, so
`emit()` cannot accept it without leaking it. For the full schema,
**neither**: it reads no storage, and a value outside the list is
accepted only because it is what storage holds; widening would drop
`oneOf` for every key that could ever go stale. State it as the one
exception to the scope: a kept stale value is storage's, not an answer.

## 4. Regex translation

**Documents:** both. **Test:** `testRegexTranslation`.

`ContractEmitter::pattern()` strips the delimiters (the first character,
to its last occurrence), allows the `u` modifier and no other, and passes
the body through unchanged. Any other modifier, or a bracket delimiter,
leaves the `pattern` out and names `Regex` under `checkedOnServer`.

| PCRE | Schema `pattern` | Reading |
| --- | --- | --- |
| `/^\d{4}$/`, `/^\d{4}$/u` | `^\d{4}$` | carried; `$` differs, below |
| `#^a/b$#`, `/^a\/b$/` | `^a/b$`, `^a\/b$` | carried |
| `/^(?<y>\d{4})$/`, `/(?<=@)x$/`, `/^\p{Lu}+$/u` | verbatim | carried; ECMA 2018 has named groups and lookbehind, `\p{}` with the `u` flag |
| `/…/D`, `/…/i`, `/…/x`, `/…/s`, `/…/m` | omitted | wider (`D` is ECMA's own `$`, so it could be carried) |
| `{^ab$}` (bracket delimiters) | omitted | wider |
| `#^a\#b$#` | `^a\#b$` | the escaped delimiter is kept: an ECMA syntax error with the `u` flag |
| `/\A\d{4}\z/` | verbatim | no `\A`, `\z` in ECMA: a syntax error with `u`, a literal `A` and `z` without |
| `/^\d{4}\Z/` | verbatim | opis refuses to compile the schema (`InvalidKeywordException`) |
| `(?i)`, `a++`, `(?>…)`, `(?P<y>…)`, `[[:alpha:]]`, `\x{263A}`, `\Q…\E`, `\h`, `\R` | verbatim | PCRE only: opis agrees with PHP; an ECMA engine refuses or reads them otherwise |

Verdicts on values:

| Pattern, value | Pipeline | opis (full and these) |
| --- | --- | --- |
| The shipped license `/^\d{4}$/`, `"2048\n"` (capacity 150, 3 stewards) | accepted, stored `"2048\n"` | refused |
| `/^\S+$/`, `"a\u{00A0}b"` | accepted (bytes) | refused (a no-break space is white space with Unicode properties; ECMA agrees) |
| `/^\W+$/`, `"é"` | accepted | refused (opis's Unicode reading only; ECMA agrees with PHP) |
| `/^\d{4}\Z/`, `"2048"` | accepted | the schema does not compile |
| `/^(?i)[a-z]+$/`, `"ABC"` | accepted | accepted (PCRE); ECMA: a syntax error |
| `/^[a-z]+$/i`, `"A1"` | refused | accepted (the pattern omitted): wider |

**Verdict: broken.** Without `D`, PCRE's `$` also matches before a final
newline; ECMA's (and opis's) does not. Every shipped pattern ends in `$`
without `D`: the license, the content type's machine name, the field
instance's `field_name`, the audience tag and the derived date patterns
(and, among the test fixtures, the telephone). A string with a trailing newline is accepted by the
pipeline and refused by the schema. Separately, the body is never read,
so a PCRE-only construct reaches the schema as written.

**Recommendation.** **Tighten** the shipped patterns with `D` (or `\z`),
since a license or a machine name ending in a newline is surely not
meant, and teach the translation that `D` is ECMA's `$` and carry it.
For a pattern without `D`, **widen**: rewrite each `$` outside a class to
`\n?$`, or omit the pattern. And **widen** for the body: carry it only
when it uses nothing outside a subset both engines read alike (no `\A`
`\z` `\Z` `\G` `\Q` `\E` `\h` `\R` `\K` `\x{…}`, inline flags,
possessive quantifiers, atomic groups, `(?P<`, POSIX classes; without
`u`, no `\s` or `\S`, which ECMA reads with Unicode white space; with
`u`, which gives PHP Unicode properties, no `\d` `\D` `\w` `\W` `\b`
`\B`, which ECMA keeps to ASCII), unescape an escaped delimiter, and
omit everything else. Never approximate. (opis, always reading with
Unicode properties, differs from a pattern without `u` on all of those
classes; that is opis's reading, not a client's.)

## 5. Null and the empty string

**Documents:** both. **Test:** `testNullEmptyStringAndAbsent`.

Optional keys of every scalar type, and a select and a map, with a value
stored:

| Input | Pipeline | Stored |
| --- | --- | --- |
| `''` | accepted | `NULL` |
| `null` | accepted | `NULL` |
| absent | accepted | what was stored (the default when nothing was) |

| Key | `''` in either document | `null` | absent |
| --- | --- | --- | --- |
| string | accepted | accepted | accepted |
| integer, float, boolean | refused (`type`) | accepted | accepted |
| email, uri | refused (`format`) | accepted | accepted |
| a pattern, a minimum length | refused (`pattern`, `minLength`) | accepted | accepted |
| a select | refused (`oneOf`) | accepted | accepted |
| a map | refused (`type`) | accepted | accepted |

The shipped license cleared in the React page's text input (which posts
`''` for an emptied text field, `null` for an emptied number or select):
accepted, stored `NULL`; the full schema refuses `''`.

A secret is the one key where `''` and `null` keep what is stored, as
absence does; the schema allows both for an optional secret: holds.

**Verdict:** `null` and absence hold. `''` is **broken** for every
optional key but a plain string, on the raw input; on the accepted
document (`NULL`) it holds. `''` and `null` store the same; absence keeps.

**Recommendation: neither**, the same scope as case 1: `''` is the web
form's notation for nothing, and the document holds `null`. If the React
page should validate locally one day, it should post `null` for an
emptied text field, as it does for a number.

## 6. Required

**Documents:** both. **Test:** `testRequiredKeysAbsent`.

`accept()` fills an absent key from what is stored, or from its default
when nothing is, so a partial update is accepted:

| Value set | Pipeline | Stored | Full |
| --- | --- | --- | --- |
| Example 2, `{capacity: 60}` | accepted | title, venue, room as stored | refused (`required`) |
| What `accept()` made of it | | | accepted |
| Example 4, no `stewards` (required, default 1) | accepted | `stewards: 1` | opis as run: accepted (allowDefaults); standard: refused |
| A required secret stored `tok`, sent `''` | accepted | `tok` | accepted |
| The same sent `null` | accepted | `tok` | refused (`type: "string"`) |
| The schema for these answers, against its own `values` (the secret `null`) | | | refused |
| The same secret never set, sent `null` | refused | | refused |

By door:

- **The tool** refuses a missing required key before the pipeline runs
  (Tool API `TypedInputsTrait::validateInputValue()`), default or not:
  `ExamplesToolTest::testPartialUpdateIsRefusedByTheToolApi()`. Stricter
  than the pipeline, and no stricter than the schema.
- **The form** posts every key it drew an element for; a key with no
  element keeps what is stored (`extractKey()`).
- **The React page** posts every key the contract's `values` carry, and
  those always carry every key.
- **A PHP caller** of `submit()` may send a partial update.

**Verdict:** broken on a partial update; holds on the accepted document,
except a **required secret**: the contract's `values` carry `null` for
every secret, the pipeline reads `null` as "keep", and the schema's type
for a required key does not allow `null`, so the schema for these
answers refuses its own values.

**Recommendation.** For the partial update, **neither**: the schema
describes the whole document, and the [decision](decisions.md#required-means-must-hold-a-value)
already says presence is never the question. For the required secret,
**widen**: a secret's type allows `null` whatever `required` says (listed
below). And for the suite: `ServedContractTest` validates with opis's
extensions on, so its verdicts on a required key with a default are
opis's, not the standard's; using `CompliantValidator` there would make
them a standard validator's.

## 7. Constraints with no keyword

**Documents:** both. **Test:** `testConstraintsWithNoKeyword`.

`UniqueField`, `DataSurfaceUniqueNodeType`,
`DataSurfaceDemoExtrasUniqueItems`, `DataSurfaceDemoExtrasReviewDeadline`
and a constraint nobody declares: each is named under
`x-surface.checkedOnServer` and otherwise leaves the property exactly as
it is without it, in both documents. Never approximated.

A constraint with an options resolver is stated as `oneOf`, and named in
`checkedOnServer` besides. That is a keyword, so the question is whether
the list is narrower than the validator:

| Constraint | Resolver lists | Validator accepts | Verdict |
| --- | --- | --- | --- |
| `LanguageExists` | `getLanguages($states)` | the same call | holds (tested: `en` both, `xx` neither) |
| `EntityBundleExists` | `getBundleInfo($entityTypeId)` | the same bundle info, after resolving a `%parent` token (the resolver, reading the token literally, lists nothing: wider) | holds (read, not run here) |
| `PluginExists` | the manager's definitions, by `interface` | the same, or **any id** with `allowFallback` on a fallback manager | **broken** with `allowFallback` (latent) |
| `Country` | the address module's list | not read: the address module is not in the suite | not verified |

```php
DataDefinition::create('string')->addConstraint('PluginExists', [
  'manager' => 'plugin.manager.block', 'allowFallback' => TRUE,
]);
// 'no_such_block': the pipeline accepts it; the full schema's oneOf refuses it.
```

**Verdict:** holds. PluginExists with `allowFallback` is narrower; nothing
shipped sets it.

**Recommendation: widen**, in `PluginExistsOptions::resolve()`: no list
when the constraint allows a fallback on a fallback manager.

## 8. Collections

**Documents:** both (the Count and `items` are the same in both). **Test:**
`testCollectionsCountAfterDroppingEmptyItems`.

`accept()` drops an item that holds nothing (every key `NULL` or `''`),
and a plain list's empty entries, before `validate()` counts. The schema
counts every item and holds each to the child's def.

| Value set (the cellar: racks 1 to 4) | Pipeline | Stored | Full |
| --- | --- | --- | --- |
| Four racks and an all-`null` row | accepted | four racks | refused (`maxItems: 4`) |
| `[null, rack]` | accepted | one rack | refused (`items`: an object) |
| `[{}]` | refused (Count, after the drop) | | accepted (one item): wider |
| A rack with 41 spices | refused | | refused |
| A plain list, Count max 1, `['a', '']` | accepted | `['a']` | refused (`maxItems: 1`) |

**Verdict: broken**, one blank row over `maxItems`, and for a blank or
`null` item that `items` refuses.

**Recommendation: neither**, or tighten. The drop is
[decided](decisions.md#empty-items-are-dropped): a row a form added and
left blank is not an answer, and the form drops it on extraction too.
On the accepted document it holds. A JSON client has no blank rows to
send; if the scope is the raw input, tighten instead: count before
dropping, which changes the decided rule. The unknown key in a blank
item is case 2.

## 9. Narrowing composition

**Documents:** the schema for these answers. **Test:** `testNarrowingComposition`.

A site composes its def with what differs: the instance's own refinement
for the values in the document, which the pipeline applies the same way
(a shelf in inches: `maximum: 80` in both; 81 refused by both), and the
locks of a context, which are case 10. One site says more than the
runtime: a **collection locked by access** in `emit()`. Its items are
described from the child's locked keys alone (`collection()` hands
`instance()` the child's `known()` values, frozen), so every key of every
item is `const` the child's default, not what any item stores.

```php
// The cellar, racks locked ('inspect spice rack'), two racks stored:
// items.properties.spices.const === 3, label.const === 'Spices'.
```

The pipeline keeps the stored racks (a locked list ignores what is
posted). The schema for these answers refuses the stored document and
its own `values`; the full schema writes no `const` there and accepts
them.

**Verdict: broken**, in that one site.

**Recommendation: widen**: describe a locked list's items with
`readOnly` and no `const` in `emit()`, as the full schema does; each
item's values are in `values` already. Every other composition holds.

## 10. Locked keys

**Documents:** both. **Test:** `testLockedKeyIgnoresPostedValue`.

The cellar's `edit` knows its larder, `main`, and locks it.

```php
['larder' => 'elsewhere'] + $cellar
// accept(): larder 'main', the posted value ignored; validate(): nothing.
```

| Value set | Pipeline | Stored | Full | These |
| --- | --- | --- | --- | --- |
| `larder: 'elsewhere'` | accepted | `main` | refused (`const: "main"`) | refused |
| What `accept()` made of it | | | accepted | accepted |

The full schema writes the `const` too, since it is described for the
locked keys the operation knows.

**Verdict:** broken on the raw input; holds on the accepted document.

**Recommendation: neither.** `readOnly` already says the value cannot be
moved, and dropping `const` would lose the one fact a validator can
check about identity. The accepted document holds.

## Outside the ten cases

### `format` is narrower than the validators

**Test:** `testFormatIsNarrowerThanTheValidator`. opis asserts `format`.

| Key | Value | Pipeline | Full |
| --- | --- | --- | --- |
| `email` type, or `Email` constraint | `üser@example.com`, `a@例子.com` | accepted | refused (`format: email`) |
| `uri` type | `http://a b.example`, `http://例子.测试/`, `a:b c` | accepted (a scheme is all it asks) | refused (`format: uri`) |
| `datetime_iso8601` | `2020-01-01`, `tomorrow` | accepted (nothing checks it) | refused (`format: date-time`) |

**Verdict: broken.** Example 3's contact email is shipped; `uri` reaches
a derived link field's item. No shipped key is a `datetime_iso8601`.

**Recommendation: widen**: drop `format` where Drupal's check is not the
format's (each one line in `ContractEmitter::type()` and the `Email` case
of `keywords()`, listed below), naming the constraint under
`checkedOnServer` instead; or `idn-email` for email, if the email
validator is held to it.

### The pipeline stores a value in another type

**Test:** `testValuesTheCastLeavesInAnotherTypeAreStored`. An outright
finding, not the invariant; reported and left.

| Key | Input | Pipeline | Stored |
| --- | --- | --- | --- |
| integer, no Range | `TRUE` | accepted | `TRUE` |
| float | `TRUE` | accepted | `TRUE` |
| boolean | `1.0`, `'01'`, `'1.0'` | accepted | as sent |

The casting table leaves these "unchanged — the constraint refuses it"
([semantics](semantics.md#the-coercion-table) says so for an integer
`TRUE`), but core's PrimitiveType check compares loosely
(`filter_var(TRUE, FILTER_VALIDATE_INT)` is `1`; `1.0 == '1'`), so it
passes and the value is stored in a type the key does not declare. With
a Range beside it the integer `TRUE` is refused. No document accepts
what is stored. The fix belongs to the runtime: refuse in `cast()` what
the table leaves unchanged for a typed key.

### The scope of the invariant

Cases 1, 5, 6 and 10 (and 8, by its decided rule) are the same finding:
the schema describes the document, the pipeline accepts notations of it.
The recommendation is one sentence in the decision: *the schema is never
stricter than the runtime on the document `accept()` makes of the input;
`accept()`'s casts, its reading of `''`, its filling of absent keys and
its keeping of a locked key are tolerances of notation the schema does
not state*, with a kept stale value as the one exception (case 3).

## One-line widening changes, listed and not applied

Each is a line or two on the schema side; none is applied.

| Section | Where | Change |
| --- | --- | --- |
| 3 | `ContractEmitter::property()`, where a key is marked stale (and the orphan branch of `frame()`) | add `null` to `type` and `{const: null}` to `oneOf` |
| 6 | `ContractEmitter::property()`, the secret branch | make the type nullable whatever `required` says |
| 7 | `PluginExistsOptions::resolve()` | no list when `allowFallback` is set on a fallback manager |
| 9 | `ContractEmitter::collection()` | describe a locked list's items without `const` in `emit()` |
| Outside | `ContractEmitter::type()`, `keywords()` | drop `format` for `email`, `uri`, `datetime_iso8601` and `Email` |

## Not verified

- No ECMA engine ran for this audit: every ECMA reading in section 4 is
  from the ECMA-262 grammar, not from a run. Since 2026-10-20 one does,
  over the rows of `tests/fixtures/pattern-engines.json` only, in the
  React app's Vitest.
- `Country`'s resolver against the address module's validator (the
  module is not in the suite).
- Whether a content type or a field storage with a trailing newline in
  its machine name gets past its storage's own checks (section 4 stops
  at the pipeline's verdict).
- The form door of section 6 was read in the code, not driven.

## Resolved

*2026-10-18. Each case decided with the Tool API as the precedent (it
coerces a tool's inputs by a documented table first and validates
after, and its matrix tests compare the advertised schema with what a
tool accepts post-coercion), and applied. `NeverStricterAuditTest` is
gone; its pins became two matrix tests in the Tool API's shape,
`tests/src/Kernel/Matrix/AdvertisedSchemaAcceptanceTest.php` (tables of
accepted and refused documents for the specimen, examples 2, 3 and 4
and the cellar, each held to the pipeline and both schemas by a
standard validator) and `tests/src/Kernel/Matrix/InputCoercionMatrixTest.php`
(the coercion table, row by row, at the form door and the tool door).
The audit above is the record of what was found; this is what each case
became.*

| # | Case | Became | Where it is held |
| --- | --- | --- | --- |
| Scope | Raw input or accepted document | The invariant is stated on the **accepted document**: what `accept()` makes of the input after coercion ([decision](decisions.md#two-documents-never-stricter-than-the-runtime), [served contract](served-contract.md#never-stricter-than-the-runtime)) | every row of `AdvertisedSchemaAcceptanceTest` |
| 1 | Casting | The module's casting table is replaced by the Tool API's coercion table, mirrored once in `Pipeline\Coercion` ([semantics](semantics.md#the-coercion-table), [decision](decisions.md#one-coercion-table-the-tool-apis)); the notations are the accepted document's, not the schema's | `InputCoercionMatrixTest::testBothDoorsCoerceAlike()`, `::testCoercedValuesAreFixedPoints()`, `::testBothDoorsRefuse()`; the "notations" and "as the form posts it" rows |
| 2 | Unknown keys | Tightened: a blank collection item's keys are read before it is dropped, so an unknown key in it is refused by its path | the cellar's "an unknown key in a blank rack" row; `::testUnknownKeysAreRefusedEverywhere()` |
| 3 | Untouched stale values | The stale path keeps reading an empty answer as the stored value; an optional stale key's `null` is a value its type allows. A kept stale value itself is storage's, the one stated exception: no schema that states the list accepts it | `::testStaleValueIsStoragesNotAnAnswer()` |
| 4 | Regex translation | Every shipped pattern gained `D`; the translation carries `u` and `D` and a body only inside the subset both engines read alike, and leaves `pattern` out otherwise, `patternMessage` still carried ([decision](decisions.md#a-pattern-both-engines-read-alike)) | `::testRegexTranslation()` (the audit's table, asserting the new translations and verdicts) |
| 5 | Null and the empty string | Three-way ([decision](decisions.md#absent-keeps-null-clears)): a key left out keeps what is stored (or the default, creating); `null`, or `''` for nothing, clears an optional key; a value is that value. An optional key's type allows `null` and an optional choice keeps its untitled `{const: null}`; a secret's does not. `''` is a notation of the cleared document, as case 1 | the "clears" rows of both tests; `InputCoercionMatrixTest::testAbsentKeyKeepsWhatItHolds()` |
| 6 | Required | `required` means present and not null, nothing about size; the partial update is a notation of the accepted document. A secret is optional, never nullable, in both documents and the tool ([decision](decisions.md#a-secret-is-optional-never-nullable)). The suite's validator no longer fills defaults (`allowDefaults` off) | the "partial update" rows; `::testSecretIsOptionalNeverNullable()` |
| 7 | Constraints with no keyword | Unchanged where it held; `PluginExists` with `allowFallback` on a fallback manager offers no list, so the schema is as wide as the validator | `::testConstraintsWithNoKeyword()` |
| 8 | Collections | The decided drop stands; on the accepted document it holds (a blank row is not in it) | the cellar's "four racks and a blank row" row |
| 9 | Narrowing composition | A locked list's items carry no `const`, only the lock | `::testLockedCollectionItemsCarryNoConst()` |
| 10 | Locked keys | Neither: the posted value is a notation; the accepted document holds the locked value, which `const` states | the cellar's "a posted larder is ignored" row |
| Outside | `format` | Stays, as an annotation per JSON Schema 2020-12; the suite's validator does not assert it ([decision](decisions.md#format-is-an-annotation)) | the "format is advisory" rows |
| Outside | Values stored in another type | An alignment with the Tool API's table: `TRUE` for an integer is `1` now. `TRUE` for a float or a string, and `'01'` or `'1.0'` for a boolean, are left by the table and let through by core's PrimitiveType check, at both doors alike | `InputCoercionMatrixTest::testValuesTheTableLeavesAndCoreLetsThrough()` |

What the decisions cost, said once:

- **A required stale key** is served as `null` with its path; the schema
  for these answers refuses that `null` (required), and it is the path
  that lets the pipeline read the stored value.
- **The tool door and one row of the table**: the Tool API's subscriber
  truncates a fractional numeric string for an integer (`'1.9'` to `1`)
  before the pipeline sees it; the form door refuses it
  (`InputCoercionMatrixTest::testFractionalStringForAnIntegerDivergesAtTheToolDoor()`).
- **The Tool API's own schema** leaves a pattern with the `D` modifier
  out (its translation allows only `i`, `m` and `s`), so the derived
  tools no longer advertise the shipped patterns; the Regex still
  validates every call.
- **A first reading of case 5**, that `null` and `''` keep what is
  stored as the Tool API's "null means the default" does, was applied
  and reverted the same day: it left no way to clear an optional key
  (an address override, an optional select). The decision says why the
  module parts from the Tool API there.
