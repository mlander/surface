# Value semantics

<!-- cspell:ignore nmae -->

A resource or structure says what a key may hold. This page says what
happens to the value on its way in: when a key counts as configured, how
a raw value becomes the type the definition declared, what "required"
means, and what the data surface refuses outright. It is the rules reference for
[the pipeline](pipeline.md), which is where the stages themselves are
described.

Every rule here is applied in one place and only there (one predicate
and one coercion table, shared by `accept()`, `validate()` and
`refine()`), so a form, a config action, a REST payload and an agent all
get the same answer. The coercion table is the Tool API's, so a tool
caller gets that answer too. The unit test
`tests/src/Unit/DataSurfacePipelineCastingTest.php` states the same rules
as a table of cases, and the kernel test `InputCoercionMatrixTest` holds
the form and the tool to the same accepted value row by row; if this
page and those files ever disagree, the files are the ones that run.

## Configured, or not

A key is **not configured** when its value is `NULL` or the empty string
`''`. It holds a value in every other case. This is the same for every
data type.

| Value | Configured? | Why |
| --- | --- | --- |
| `NULL` | no | Nothing was said about the key. |
| `''` | no | What a browser submits for an untouched text field, a cleared number, or an unchosen select. |
| `FALSE` | yes | A checkbox that is off is an answer. |
| `0`, `0.0`, `'0'` | yes | A number is a number. |
| `[]` | yes | A list or map with nothing in it is an answer. |
| `' '` | yes | Whitespace is content until a constraint says otherwise. |
| anything else | yes | |

The empty string is on the "no" side only because it is the notation a
web form has for "nothing here". Nothing else is, because everything
else a caller can send is something the caller chose. A resource or
structure that wants to refuse `FALSE`, `0` or `[]` says so with a
constraint of its own, not by hoping the pipeline reads them as absences.

### Absent, null, or a value

A key can be sent three things, and they mean three things
([decision](decisions.md#absent-keeps-null-clears)):

| Sent for the key | Optional key | Required key |
| --- | --- | --- |
| left out | what it holds stands | what it holds stands |
| `NULL`, or `''` (a form's nothing) | **cleared**: the key holds `NULL` | `NULL`: "@label is required." |
| a value | the value, coerced | the value, coerced |

"What it holds" is what storage holds for it on an operation that
changes something that exists, or its declared default on one that
creates. So a partial update keeps every key it leaves out, a required
one included: a required key left out is refused only where it holds
nothing, a create's key with no default or a stored thing's key with no
value yet, and the refusal is "@label is required.", judged on the
merged document. That is why a generated tool for an operation that
changes what exists requires no key at its door, and says "Required; a
value already stored stands when left out." on each key the resource
requires ([decision](decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
A key that is sent `null`, an emptied text box, or an optional select's
"- None -" is cleared. Two exceptions keep what they hold when sent
nothing: a list, which is emptied with `[]` ([Lists](#lists)), and a
secret, which is emptied with its clear marker
([Secrets](#secrets-keep-what-they-hold)). And null clears only where
clearing is possible: a key its storage always holds (a config object
whose fully validatable schema does not call it nullable) refuses null
and `''` naming its default, and is kept when left out
([decision](decisions.md#a-key-its-storage-always-holds)). The schema
says the same: an optional key's type allows `null`, a required key's,
an always held key's and a secret's does not
([served contract](served-contract.md#the-schema)).

This is where the module parts from the Tool API, whose rule is that a
caller's null for an optional input means the default. A tool's
defaults are static, so its null and its absent key say the same thing;
an operation that edits stored values defaults to what is stored, so if
null meant the default too a caller could never clear a key. The Tool
API itself tells an absent key from a null where it matters, for
outputs (`omit_when_null`).

### A key that does not apply

A refiner may answer that a key does not apply to the values it watches
([Refinement](refinement.md#when-a-key-does-not-apply)): a send day for
a monthly bulletin. Such a key is read as left out, whatever it is sent:

| Sent for a key that does not apply | Result |
| --- | --- |
| left out | what it holds stands, in storage; the accepted document leaves the key out |
| `NULL`, or `''` | the same: nothing said, nothing to clear |
| exactly what it holds | the same |
| any other value | refused: "Send day does not apply when Frequency is Monthly." |

Nothing about it is required, and nothing about it is validated. Storage
keeps what it holds, so the key holds it again when it applies: a weekly
send day survives a month of monthly sends
([decision](decisions.md#a-key-that-does-not-apply)). Inside a
collection's item it is not put back: a list sent is written as sent.
A map whose every key does not apply is an empty map, and its JSON
document, `DataSurfacePipelineInterface::document()`, writes it `{}`.

A form posted without JavaScript keeps the stored value too. The form
draws no element for a key that does not apply, and draws it again over
AJAX when the key it depends on changes; with JavaScript off nothing is
redrawn, so the post says nothing about that key, and a key left out
keeps what it holds ([Absent keeps, null
clears](decisions.md#absent-keeps-null-clears)). A person who turns
after-hours access on and saves posts no door code: the stored code
stands and the save is accepted, where "required when on" might have
been expected to refuse it (a key that holds nothing is refused, and the
form, redrawn, now shows it). The other way round, a fieldset the
original form hid with `#states` and the module rules out with a
declared `doesNotApply()` keeps its stored ticks when its controlling
box is ticked and the form is saved without JavaScript, where the
original form, whose `#states` hides nothing without JavaScript, saved
whatever its boxes held. Absent keeps: what a post says about a key that
does not apply for the values it carries is not read as an answer for
it.

## The coercion table

`accept()` turns raw input into the type the definition declares by the
Tool API's coercion table, the one its `InputTypeCoercionSubscriber`
applies to a tool's inputs, mirrored rule for rule in
`Pipeline\Coercion` (the Tool API offers it only as an event on a
tool's inputs, and this module does not depend on the Tool API). The
tool door runs the Tool API's own subscriber and then this one, which
changes nothing the first made. Its principle is the Tool API's:
**coerce only when the intended value is unambiguous**, and leave
anything doubtful exactly as it arrived, so that a constraint refuses it
with a message about the value rather than a coercion quietly turning it
into a different one.

Input that holds nothing never reaches the table: it is read first, by
[the rule above](#absent-null-or-a-value).

| Definition type | Input | Result |
| --- | --- | --- |
| any type with a list of values | a string that matches exactly one value but for case and surrounding whitespace (`' Alpha '`) | that value (`'alpha'`) |
| a list | a JSON string of a list (`'["a","b"]'`) | the list |
| a list | a single item: a scalar, or one map where the items are maps | a list of that one item |
| a map, a child | a JSON string of an object (`'{"a":1}'`) | the map |
| `boolean` | `TRUE`, `FALSE` | itself |
| `boolean` | `1`, `0`, `1.0`, `0.0` | `TRUE`, `FALSE` |
| `boolean` | `'1'`, `'true'`, `'on'`, `'yes'`, any case, trimmed | `TRUE` |
| `boolean` | `'0'`, `'false'`, `'off'`, `'no'`, any case, trimmed | `FALSE` |
| `boolean` | any other word or number | unchanged: the constraint refuses it |
| `integer` | `7` | `7` |
| `integer` | `'7'`, `' 7 '`, `'+7'`, `'-7'`, `'007'`, `'2.0'`, `'1e3'` | the integer |
| `integer` | `2.0` | `2` (a whole number, nothing lost) |
| `integer` | `TRUE`, `FALSE` | `1`, `0` |
| `integer` | `1.9`, `'1.9'` | unchanged: the constraint refuses it |
| `integer` | `'seven'`, a literal beyond PHP's integer range | unchanged |
| `float` | `1.5`, `2` | itself (an integer is a float's value) |
| `float` | `'2'`, `'1.5'`, `' 1.5 '`, `'1e3'` | the float |
| `float` | `'half'`, `TRUE` | unchanged |
| `string` | `7`, `1.5` | its spelling (`'7'`, `'1.5'`; a float as fixed-point, never `1.0E-7`) |
| `string` | `TRUE`, `FALSE` | unchanged: neither `'1'` nor `'true'` is the spelling of a boolean |
| `decimal` | `7`, `19.99` | its spelling, as for a string |
| `email`, `uri` | anything | unchanged but for the near miss above |
| `timestamp` | `'1700000000'`, `1700000000.0` | `1700000000` |
| `timestamp` | a date string (`'2023-11-14T22:13:20+00:00'`) | its timestamp |
| `datetime_iso8601` with a `DateTime` constraint | a date and time with an offset (`'2026-11-14T09:32:00-07:00'`, `Z`, fractional seconds, a space for the `T`) | the UTC storage format, `'2026-11-14T16:32:00'` |
| `datetime_iso8601` with a `DateOnly` constraint | `'2026-11-14'` | itself |
| `datetime_iso8601` with neither | a parsable date and time | ISO 8601 with its offset; a bare date, a number and anything unparsable unchanged |
| `any`, a map with no declared properties | anything | untouched |
| any other type | anything | untouched |

Two rows are narrower than the Tool API's code, on its own principle: a
numeric string with a fraction for an integer (`'1.9'`), and for a
timestamp, is left for the constraint where the subscriber truncates
it, and a literal beyond PHP's integer range is left where the
subscriber saturates it. A tool caller that sends `'1.9'` for an
integer is answered `1` by the Tool API before the pipeline sees it
(`InputCoercionMatrixTest::testFractionalStringForAnIntegerDivergesAtTheToolDoor()`).

Core's typed data check is looser than the table in a few places, so a
value the table leaves for "the constraint" is not always refused by
it: `TRUE` for a float or a string, and `'01'` or `'1.0'` for a
boolean, pass core's PrimitiveType check and are stored as sent, at
both doors alike. With the table, `TRUE` for an integer is `1`, which
is what core's check read it as.

### Lists

A list input is normalized to a clean list: a JSON string of a list is
read as one, a single item is wrapped, keys are dropped, entries that
hold nothing are dropped, the result is reindexed from zero, and each
surviving item is coerced through the item definition (recursing when
the items are complex). So a multiple select submitting
`['us' => 'us', 'ca' => 'ca']`, checkboxes read back as the values
checked, a payload sending `['us', 'ca']` and a lenient caller sending
`'["us","ca"]'` mean the same thing. Checkboxes all unchecked, and a
list of rows with every row removed, send `[]`, which empties the list.

A list that is sent nothing keeps what it holds, required or not: a
form that rendered no list widget, or a payload that named no list, has
said nothing about the list, so **what the list already holds stands**:
the stored value, or the declared default. Emptying a list is said with
an empty list, which is a configured value.

### Collections

A collection's value ([Collections](resources.md#collections)) is a list
of item maps, in order: delta is position, and the keys a sender used
are dropped. It is a list, so the list rule above holds: a list the
input says nothing about, or sends empty (`NULL`, `''`), keeps what it
holds, and emptying it is said with `[]`.

- **Each item is the child's.** It is accepted by the child in its own
  frame, over the child's defaults and not over what is stored at the
  same delta, since the list is sent whole and an item may have moved
  (a secret the item leaves out excepted: [it keeps its
  position's](#secrets-keep-what-they-hold));
  an unknown key inside an item is refused by its path
  (`racks.1.brand`), as is an item that is not a map (`racks.1`) and a
  list that is not a list (`racks`).
- **Empty items are dropped.** An item that holds nothing at all, every
  key of it `NULL` or `''` at any depth (a row a form added and left
  blank), is dropped before validation, the way core drops an empty
  delta, once its keys are read: an unknown key in a blank item is
  refused by its path, as in any other. One holding `FALSE` or `0` is
  an answer, not empty. A form drops it on extraction and remembers
  where each kept item was drawn, so a violation about a later item is
  flagged on its own row.
- **Count is the list's, items the child's.** `validate()` holds the
  list itself to its own constraints, its `Count` as the parent's
  refiners left it, and reports them on the list's key; then each item
  is judged by the child, its own refiners run against that item alone,
  and each violation is filed under the list's key with the item's
  delta in front: `tags.2.target_id`.
- **Stale, per item.** The stale rule applies inside an item as it does
  anywhere: an item's key holding exactly what is stored at the same
  delta, no longer offered, every key it refines against unmoved, is
  kept and reported stale (`shelves.1.unit`), and never blocks. The
  same value sent at another delta was chosen by whoever moved it, and
  is refused.
- **Locked and omitted.** A locked list keeps what is stored, each item
  read through the child, and ignores what is posted for it; an omitted
  one is not in the data surface, so a value posted for it is an unknown
  key.

### Maps

A complex definition with declared properties merges in one order at
every depth: the **declared defaults**, then **what storage holds**,
then **the input**. A property the input does not name keeps what it
held; a property nothing has ever held starts from its declared default.

Two consequences worth stating:

- The result holds exactly the declared properties. A key that only
  storage knows about stays in storage and does not round-trip through
  the data surface, because the data surface never advertised it and
  must not become responsible for it.
- A default declared on the map itself merges *over* the defaults its
  properties declare, rather than replacing them. Naming one property's
  starting value on the map is a statement about that property, not an
  instruction to forget its siblings. A declared default that is not an
  array has nothing to merge into and stands on its own.

A map, or a property inside one, that is sent nothing holds nothing,
`NULL`, by [the rule above](#absent-null-or-a-value); left out, it keeps
what it holds. A child (an attached structure, or the chosen
variant) sent nothing is each of its keys sent nothing: its optional
keys clear, a required one is refused in its own words ("Email is
required.", at `contact.email`), and the accepted value is still a map
the child's def describes, at every door. A complex definition that declares
no properties describes nothing to recurse into, so its value passes
through as it arrived.

## Secrets keep what they hold

One deliberate exception to [the rule above](#absent-null-or-a-value):
a secret keeps what it holds when it is sent nothing, *whether or not
it is required*, and it is cleared by a marker instead.

A key declared secret (`DefinitionMetadata::setSecret()`, see
[Declaring a resource](declaring-a-resource.md#secrets)) is a key whose
stored value is never read back to the caller that writes it. A
generated form therefore renders an empty password box every time, so a
person who edits the setting *next to* the secret and saves has
submitted an empty secret without meaning to say anything about it at
all. Read the ordinary way, that would empty the stored token on every
unrelated save.

So for a secret key:

| Input | Result |
| --- | --- |
| absent | keeps the stored value (as for every key) |
| `NULL`, `''` | **keeps the stored value**, required or not |
| `DataSurfacePipelineInterface::CLEAR_SECRET` | `NULL`: the secret is gone |
| anything else | the new secret, coerced and validated like any string |

`CLEAR_SECRET` is the literal `@data_surface:clear-secret`, in the same
reserved `@` namespace as the access violation key. Clearing a secret is
an explicit act and has to be said out loud: a host renders a "remove
the stored token" checkbox beside the box and sends the marker when it is
ticked, an API caller sends the marker itself. The marker means this for
a secret key and nothing else; sent for any other key it is an ordinary
string.

Two consequences:

- **Required still means required.** A secret that has never been set and
  is submitted empty is `NULL` after `accept()`, so `validate()` raises
  the ordinary "@label is required." violation. What the keep rule
  changes is a secret that *has* been set: then an empty box is a legal
  submission, which is why the generated element carries no browser-side
  `#required`, and why both schema documents advertise every secret as
  optional and not nullable, its declared type, `writeOnly`, and its
  value in `values` as `null`
  ([decision](decisions.md#a-secret-is-optional-never-nullable)).
- **Locked wins.** A key that is both locked and secret takes what
  storage holds and ignores the input, marker included, because that is
  what locked means. Anything sent for it is noted as not taken, a right
  value as a wrong one, and so is a secret inside a locked map or
  child, so a note never tells a caller what a secret holds.
- **Inside a collection's item, by position.** An item that leaves a
  secret out keeps what is stored at its position, when the list sent
  is as long as the one stored (an edit in place: what a read, edit,
  write loop sends, since no read returns the secret). With items added
  or removed, a position may hold another item, so an item that leaves
  out a secret stored where it now sits is refused by the secret's path
  (`mirrors.0.password`): send it again, or the marker
  ([decision](decisions.md#every-door-projects-what-the-read-tool-projects)).
- **No door answers it.** The read leaves a secret out, and so does
  every other answer: the write tool's `values`, dry run or written, and
  React's `/validate`.

Encryption is a separate concern and lives one layer down, at the
storage layout: see [Storage](storage.md#secrets-at-the-codec).

## Stale values: the third state

A key can be in three states, not two. It can be unanswered, it can hold
an answer, and it can hold an answer that **was** true and is not any
more: a bundle that was deleted, a plugin whose module was uninstalled,
a value a refinement has since narrowed away. The third state is not
something any caller did, and that is what the rules turn on.

A value is **stale** when all four of these hold at once:

| Condition | Why it is in the test |
| --- | --- |
| It is exactly what storage holds for that key | A value that differs was chosen by whoever sent it, so it is refused however far outside the list it falls. This is the whole line between "re-choose this" and "that is not a valid answer". |
| Every key it refines against also holds exactly what storage holds | A run that moves a dependency is what narrowed the list away from the stored value: a venue moved, and the room stored under the old venue is sent back. That refusal is about this run's own answers, not about the site, so it blocks. |
| The key offers a list of values at all | Read from the options service: the same list a generated select renders. A key with no list has no membership to have fallen out of. |
| The value is not in that list | If it is, the refusal came from some other constraint, and that is an ordinary refusal of a value the key still offers. |

Then:

| | never set | holds a live value | holds a stale value |
| --- | --- | --- | --- |
| **optional** | valid, `NULL` | held to its constraints | kept, reported stale, saved |
| **required** | violation: "@label is required." | held to its constraints | kept, reported stale, saved |
| **the form shows** | the empty option, selected | the chosen option; a required select shows no empty option | the empty option, selected; the stored value is not an option |
| **untouched save** | stays unanswered (required: refused) | stays as it is | **stays as it is** |

Four consequences worth stating:

- **Display never errors.** The select renders with its empty option
  selected, and the stale value is never injected back into the list as
  something that can be chosen, nor named by an option of its own.
  Nothing real is auto-selected in its place either: a browser handed a
  select whose value is missing picks the first option, and that is how
  an unrelated save used to silently rewrite the value.
- **Untouched means keep.** The element carries the stored value
  (`#data_surface_stale`), and an empty submission from an element that
  carries one is read as that value on extraction, so an unrelated save
  cannot clear it. This is the trap, and it is the same one secrets
  have: an empty select otherwise means clear. A build made from the
  submission itself learns which selects stood for a stored value from
  a hidden input the container posts beside them
  (`DataSurfaceFormBuilderInterface::STALE_MARKER_KEY`).
- **Stale never blocks.** A run that found only stale entries is valid,
  commits, and carries the stale references on its result (see
  [the pipeline](pipeline.md#stale-never-blocks)). A new value outside
  the list is still a hard violation.
- **Required splits.** Never set plus required is the ordinary required
  violation, and on a form a required select left on its empty option
  is refused with that message. Stale plus required stashes and nags
  like any other stale, because there is a value there and losing it
  helps nobody.

Only an explicit new choice replaces a stale value on a form. Choosing
the empty option of an optional select clears the key when the select
could show what it holds; on a stale select the empty option is what
was selected already, so it keeps, which the stale marker (the form's
hidden input, the contract's `stale` paths) says. A caller with no form
keeps a stale key by leaving it out, or by sending it empty with its
stale path, and clears it by sending it empty without one. The
presentation is [the empty option rule](decisions.md#the-empty-option-rule).

Two boundaries, both deliberate:

- **Lists of values are not covered.** A multiple select's items are
  each members of the same set, so a stale item would have to be
  stashed and warned about per item, and a partial keep is a shape
  neither the widget nor the rule has. A list whose items went stale is
  refused as it always was. A collection is covered, inside each item,
  since each item is its own frame ([Collections](#collections)).
- **`setConfiguration()` keeps what it is given.** A plugin's
  `setConfiguration()` is the load path (every host calls it from
  inside its own constructor with what the site saved), and it has one
  array and no separate notion of what is stored, so it cannot tell a
  value that went stale from a bad one a caller invented, and it keeps
  both rather than throwing. It still throws for everything else the
  data surface refuses. The write path, `submit()`, does have both and does
  refuse the invented one.

## Type mismatches

Coercion is for values that mean the same thing in another notation. A
value that means nothing in any notation is not coerced, it is refused:

| Definition | Input | Refused as |
| --- | --- | --- |
| `string`, `integer`, `float`, `boolean`, `email`, `uri`, `timestamp`, `datetime_iso8601`, `decimal` | an array | `string`/`integer`/… expected |
| a collection | anything configured that is not an array or a JSON list | `list` expected |
| a map with declared properties, a child | anything configured that is not an array or a JSON object | `map` expected |

A plain list is never a mismatch: a single item is wrapped, and the item
is then held to the item definition.

`accept()` throws `TypeMismatchException`, naming the dotted path of the
offending value and what was expected. `submit()` turns it into a
violation on that key, the same way it turns an undeclared key
into one. This is the same bargain as unknown keys: a caller that sends
the wrong type learns about it instead of watching the value vanish.

## Required

`validate()` raises its own violation, **"@label is required."**, when a
required key is not configured. It uses the predicate above and nothing
else, so:

| Value of a required key | Result |
| --- | --- |
| `NULL`, `''` | violation: "@label is required." |
| `FALSE` | passes, then held to its own constraints |
| `0`, `'0'` | passes |
| `[]` (a required list or map) | passes |
| anything else | passes |

"Required" means present and not null, and says nothing about size, as
the Tool API reads it: a required list or map may be empty, and that is
the deliberate reading, "required" says the key must be answered, not
that the answer must be large. A resource or structure that needs at
least one item says so with a `Count` constraint, or `NotBlank` for
"not empty", which then produces a message about the count rather than
about the key being missing.

The message is always the data surface's own, never a type-specific one
from a constraint, so an empty required number and an empty required string
read alike to the person filling in the form.

## Violation messages are objects

Every message in a violation is a `TranslatableMarkup` (the data
surface's own required message, the pipeline's unknown-key and shape
refusals, and the message a constraint or a config schema produced),
and it stays one the whole way out: `DataSurfaceResult`, a form error
through `FormStateInterface::setError()`, a caller's own report.
Placeholders are still placeholders until something renders it, so a
value that contains markup is escaped once, by whoever prints it, rather
than escaped at build time and escaped again on the page.

A constraint message may spell its placeholders Symfony's way,
`At most {{ max }} recipients per send.`, as a custom
`notInRangeMessage` usually is, or Drupal's, `%max`, as core's own
constraints do. Core's validator translator fills in only Drupal's, so
the pipeline wraps a message holding a Symfony placeholder in
`Pipeline\ViolationMessage`, which fills each one in, escaped and
emphasized as `%max` would be, when the message is rendered: the form,
the tool's refusal and the served contract all print the number. The
tool's input definitions carry the constraint across respelled `%max`,
so whatever holds a value to the listing's constraints says the same;
the tool itself holds `values` to its shape alone before it runs, and
leaves every rule to the pipeline. The respelled copy is a second
source a translator meets; a message spelled Drupal's way is copied as
it is, so every door meets the one source its author wrote.

A message option may be written with `t()`; the build hands Symfony its
source string. Written so, potx extracts it as it extracts every label:

```php
->addConstraint('Regex', [
  'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/D',
  'message' => t('Write the time as HH:MM, such as 18:30.'),
]);
```

A Symfony constraint holds its message as a string, and core's
validator translator translates that string when it makes a violation,
in the language of the request. So when a data surface is sealed, and
when a shape is copied for a key, each message option written with
`t()` is replaced by its untranslated string (`ConstraintMessages`),
and the validator, the served contract's `x-surface.patternMessage`
(translated into the document's language, read as plain text) and the
contract panel all meet that one source. Only a plain source is
unwrapped: a message with arguments, a context or plural forms is left
as the object it is, and Symfony reads it in the language of the
request. A bare literal still validates and is still translated at
violation time, but no translator sees it until someone has been
refused with it.

A refusal says what is allowed for the values sent, per key, in whole
sentences. One a refiner caused goes on to name what it depends on and
what that holds, as the refiners were handed it: too few stewards for
example 4's capacity reads "This value should be 3 or more. This
applies when Capacity is 150." The pipeline adds it where the refused
key, or the mounted property the refusal is about, is watched by a
refiner and the refiners changed its constraints for these values; a
rule the key declared itself names no condition. A watched value its
own key refused was handed over as nothing, and is said so: "... This
applies when Room is Main hall and Event license is empty (EV-2048 was
refused)." A value not on its key's list is followed by what may be
chosen, as refined for the values sent, at most ten and then how many
more: "The value you selected is not a valid choice. This applies when
Venue is Riverside Hall. Choose Main hall or East room." The
constraint's own message comes first, whole, still the object it built,
and each sentence added is one source a translator sees entire, never a
concatenation of translated fragments
([decision](decisions.md#a-refusal-names-what-it-depends-on)).

The same sentence has two renderings. Rendered for a form, the
default, a key it depends on is named by its label and a value by its
option label, as above. Rendered for a machine
(`Sentences::machine()`), which the tool's text and the `message` of
its result are, a key is named by its dotted path in the
values a caller sends and a value by what is sent, its label beside
it: "... This applies when venue is riverside (Riverside Hall). Choose
riverside_main (Main hall) or riverside_east (East room)." The keys and
values are `Pipeline\Named` placeholders, so a translator still meets
one source per sentence. The renderer is the door's choice: React's
`/validate` and `/submit`, a person's door, render `message` as the
form does, with the same `path` and `code` as the tool.

There are exactly three places a message becomes text, all of them
exception messages, because PHP has nowhere to put an object in one:
`UnknownKeysException`, `StorageViolationsException`, and the refusal
`DataSurfaceConfigurationTrait::setConfiguration()` throws. All three
render through `Pipeline\ViolationSummary`, which names the first five
entries and counts the rest; the full list is still on the exception.

## Codes

Every violation carries a stable `code` beside its message, for a
script or an agent to branch on: the message moves with the site's
language and with what an alter relabels, the code does not
([decision](decisions.md#one-result-at-every-door)). A refusal a
constraint made has its constraint's code: Symfony's own where the
constraint defines one, by the name its constraint gives it, lower case
and without `_ERROR` (Symfony's code is a UUID, and
`Constraint::getErrorName()` names it one to one); a constraint that
sets no code is named for itself, in snake case
(`UniqueNodeTypeConstraint` is `unique_node_type`,
`ReferenceableConstraint` `referenceable`). Every refusal the module
makes itself has one of its own (`Pipeline\Violation`'s constants). A
refusal of the whole call has an empty path.

| Code | Path | Says |
| --- | --- | --- |
| `required` | the key | A key, or a property of a map, that must hold a value holds none; also a required output not emitted. |
| `does_not_apply` | the key | A value was sent for a key a rule ruled out for the values sent. |
| `unknown_key` | the key | A key the resource does not declare, at any depth; within two edits of a key declared beside it, and fewer than half its length, the message names that key: "Unknown key venue_nmae. Did you mean venue_name?" |
| `type_mismatch` | the key | A value in a shape its definition cannot hold. |
| `wrong_variant` | the key | A key sent for a variant its deciding key did not choose. |
| `no_variant` | the key | A key with variants holds a value while its deciding key holds none. |
| `cannot_clear` | the key | Null sent for a key its storage always holds. |
| `secret_in_item` | the secret | A secret stored for a collection's item whose position items added or removed have moved. |
| `no_schema` | the module's mount | A storage holds settings no config schema describes. |
| `has_data` | `settings` | A field's storage settings would change its columns while it has data. |
| `access` | empty | The resource's access answer refused the whole call. |
| `permission` | empty | The operation's permission refused the whole call. |
| `locked` | empty | The resource may be seen and not changed, so nothing was written. |
| `not_found` | empty | The call's parameters name nothing, or a read is asked of what an operation creates. |
| `conflict` | empty | What storage holds changed since the caller read it (React's fingerprint). |
| `locked` | the key | In `notes`: a value sent for a locked key, not taken. |
| `stale` | the key | In `stale`: a stored value kept that the key no longer offers, with `stored`. |
| `not_in_range`, `too_high`, `too_low` | the key | Range: a number outside its bounds. |
| `too_long`, `too_short` | the key | Length: a string outside its bounds. |
| `no_such_choice`, `too_few`, `too_many` | the key | Choice, AllowedValues or LabeledChoice: a value not on the list; Count on a list: too few or too many items. |
| `regex_failed` | the key | Regex: a value not matching its pattern. |
| `invalid_format` | the key | Email and the other format constraints. |
| `is_null`, `is_blank` | the key | NotNull, NotBlank, where a definition carries them; `is_null` with an empty path for a tool's `values` sent null or left out, where the tool answers the Tool API's own refusal. |

Symfony's codes are listed as the module's examples meet them; any
other constraint's are its own, read the same way. The codes and the
result shape are promised from the first tagged release; the messages
are not ([What is promised](evolution.md#what-is-promised)).

## Locked keys

A locked key is the degenerate refinement: its value space is narrowed
to exactly one value. That value is **whatever storage already holds**,
and the declared default only for a key storage has never held. Input
for a locked key is ignored: it is not an error, just not a way to move
the value. This is why editing an entity whose id is locked no longer
resets it to the default. A value sent for it that is not what it holds
is said, though, once per key and blocking nothing: the run's notes
(`DataSurfaceResult::$notes`), which the tool puts after its message
and the React `/validate` and `/submit` answer as `notes`, say "Machine
name is locked and kept as stored; the value sent was not taken." The
form says nothing, since it draws the key disabled
([decision](decisions.md#locked-at-the-top)).

### Locked and omitted children

A child's access ([Child operations](resources.md#child-operations))
may answer that its keys are shown and fixed, or not there at all.

- **A locked child** is a locked key whose value is a map: the parent's
  key is locked, so `accept()` keeps what storage holds for the whole
  child, read through the child the way an unanswered child is (a key
  the child added since starts from its default), and ignores what was
  posted for it. It is still validated, like any locked key. The form
  draws its fieldset disabled, every element inside with it, showing
  what is stored; the contract marks the fieldset and every key inside
  it `readOnly` and `x-surface.locked`, each holding its stored value
  as `const` (the full schema, which reads no stored value, holds none);
  the tool's `values` input marks the map and every property in it
  locked.
- **An omitted child** is not in the data surface: no definition, no
  element, no property in either contract or in the tool's `values`
  input. A value posted for it is an undeclared key, which `accept()`
  refuses with `UnknownKeysException`, exactly as for a key the data
  surface never had. It is omitted in both directions: the read tool does not
  return it, and a write leaves what storage holds under it as it was
  (the storage adapter hands that back to the parent's storage, since
  the caller could not have seen it), inside a child the parent stores
  too, by its path.
- **A mixed-in structure's members** are locked or omitted by its access
  the same way, one key at a time, since they are the owner's keys: a
  locked member holds what is stored, shown and fixed, `readOnly`, with
  a `const` of what is stored only in the schema for these answers; an
  omitted member is gone, and storage keeps it
  ([Mixins](resources.md#mixins)).

What a value is held to does not depend on whose answer locked or
omitted it, the access class's or an alter's
([An alter's answer](resources.md#an-alters-answer)), nor on which kind
of access answer it was: the pipeline, the form and the schema for these
answers apply a static answer (the account alone decides it) and a
runtime one (the subject or site state does) alike, and a locked key
keeps what is stored however it was posted. The kind decides only what
is said ahead of time: the full schema and the tool listing state a
static answer for the account that asks and mark a runtime one, its key
there and writable with `x-surface.access: "live"`, so a document
valid against the full schema may still post a key the runtime answer
then locks (kept) or omits (refused as an unknown key)
([Static and runtime access](resources.md#static-and-runtime-access)).

## What each stage decides

| Stage | Decides |
| --- | --- |
| `accept()` | Whether a key was configured, what type its value takes (the coercion table), whether its type is acceptable at all, and how input merges over what is stored. Keeps what a key left out, a list or a secret holds; clears an optional key sent nothing. Leaves out a key that does not apply to what it accepted, unless it was sent another value. Refuses undeclared keys and type mismatches. Asks only the options service, for the values a near miss snaps onto. |
| `validate()` | Whether a required key was answered, and whether the answers satisfy the constraints of the data surface **refined against those same answers**. Given the stored values, it also tells a stale reference from a refusal, and refuses a value sent for a key that does not apply. |
| `refine()` | Which definitions narrow, given which dependencies are configured. A dependency holding `FALSE` or `[]` is an answer and does narrow. |
| `prepare()` / `commit()` | Nothing about values; the storage layout and the write. |

After `accept()`, every declared key is present but one that
[does not apply](#a-key-that-does-not-apply), and every value is either
`NULL` or a configured value in its declared type, as far as the
coercion table can make it one. That is the shape `validate()`, the
storage, and any consumer can rely on, and the document the schema is
never stricter than ([served contract](served-contract.md#never-stricter-than-the-runtime)).
