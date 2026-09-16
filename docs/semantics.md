# Value semantics

A surface says what a key may hold. This page says what happens to the
value on its way in: when a key counts as configured, how a raw value
becomes the type the definition declared, what "required" means, and
what the surface refuses outright. It is the rules reference for
[the pipeline](pipeline.md), which is where the stages themselves are
described.

Every rule here is applied in one place and only there — one predicate
and one casting table, shared by `accept()`, `validate()` and
`refine()` — so a form, a config action, a REST payload and an agent all
get the same answer. The unit test
`tests/src/Unit/DataSurfacePipelineCastingTest.php` states the same rules
as a table of cases; if this page and that file ever disagree, the file
is the one that runs.

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
else a caller can send is something the caller chose. A surface that
wants to refuse `FALSE`, `0` or `[]` says so with a constraint of its
own, not by hoping the pipeline reads them as absences.

## The casting table

`accept()` turns raw input into the type the definition declares. Two
rules govern the whole table:

1. Input that is not configured becomes `NULL`, for every data type.
   (Lists are the one exception, below; secret keys are the other.)
2. A cast never loses information. A notation that means the same value
   is converted; anything else is left exactly as it arrived, so that a
   constraint refuses it with a message about the value rather than a
   cast quietly turning it into a different value.

| Definition type | Input | Result |
| --- | --- | --- |
| any type | `NULL`, `''` | `NULL` |
| `integer` | `7` | `7` |
| `integer` | `'7'`, `' 7 '`, `'+7'`, `'-7'`, `'007'` | the integer |
| `integer` | `2.0`, `'2.0'` | `2` (a whole number, nothing lost) |
| `integer` | `1.9`, `'1.9'` | unchanged — the constraint refuses it |
| `integer` | `'seven'`, `TRUE` | unchanged |
| `integer` | a literal beyond PHP's integer range | unchanged |
| `float` | `1.5` | `1.5` |
| `float` | `2`, `'2'`, `'1.5'`, `' 1.5 '`, `'1e3'` | the float |
| `float` | `'half'`, `TRUE` | unchanged |
| `boolean` | `TRUE`, `FALSE` | itself |
| `boolean` | `1`, `0` | `TRUE`, `FALSE` |
| `boolean` | `'1'`, `'true'`, `'on'`, `'yes'` | `TRUE` |
| `boolean` | `'0'`, `'false'`, `'off'`, `'no'` | `FALSE` |
| `boolean` | any other word or number | unchanged — the constraint refuses it |
| `string`, `email`, `uri` | any scalar | that scalar written out (`7` becomes `'7'`) |
| `any` | anything | untouched |
| any other type | anything | untouched |

Boolean words are matched case-insensitively and after trimming, so
`' OFF '` is `FALSE`. `FALSE` asked for as a string writes out as the
empty string, which holds nothing, so the key holds `NULL`.

Types the table says nothing about — `any`, a map that declares no
properties, a type a contributed module added — pass through untouched.
A cast that is not defined would be a guess.

### Lists

A list input is normalized to a clean list: keys are dropped, entries
that hold nothing are dropped, the result is reindexed from zero, and
each surviving item is cast through the item definition (recursing when
the items are complex). So a multiple select submitting
`['us' => 'us', 'ca' => 'ca']` and a payload sending `['us', 'ca']` mean
the same thing.

A list is the one place where input that holds nothing does not mean
`NULL`: a form that rendered no list widget, or a payload that named no
list, has said nothing about the list, so **what the list already holds
stands** — the stored value, or the declared default. Emptying a list is
said with an empty list, which is a configured value.

### Maps

A complex definition with declared properties merges in one order at
every depth: the **declared defaults**, then **what storage holds**,
then **the input**. A property the input does not name keeps what it
held; a property nothing has ever held starts from its declared default.

Two consequences worth stating:

- The result holds exactly the declared properties. A key that only
  storage knows about stays in storage and does not round-trip through
  the surface, because the surface never advertised it and must not
  become responsible for it.
- A default declared on the map itself merges *over* the defaults its
  properties declare, rather than replacing them. Naming one property's
  starting value on the map is a statement about that property, not an
  instruction to forget its siblings. A declared default that is not an
  array has nothing to merge into and stands on its own.

A map whose input holds nothing becomes `NULL`. A complex definition
that declares no properties describes nothing to recurse into, so its
value passes through as it arrived.

## Secrets keep what they hold

One deliberate exception to the `''` rule, and the only one.

A key declared secret — `DefinitionMetadata::setSecret()`, see
[Declaring a surface](declaring-a-surface.md#secrets) — is a key whose
stored value is never read back to the caller that writes it. A
generated form therefore renders an empty password box every time, so a
person who edits the setting *next to* the secret and saves has
submitted an empty secret without meaning to say anything about it at
all. Read the ordinary way, that would empty the stored token on every
unrelated save.

So for a secret key, and only for a secret key:

| Input | Result |
| --- | --- |
| absent | keeps the stored value (as for every key) |
| `NULL`, `''` | **keeps the stored value** |
| `DataSurfacePipelineInterface::CLEAR_SECRET` | `NULL` — the secret is gone |
| anything else | the new secret, cast and validated like any string |

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
  `#required`.
- **Locked wins.** A key that is both locked and secret takes what
  storage holds and ignores the input, marker included, because that is
  what locked means.

Encryption is a separate concern and lives one layer down, at the
storage shape: see [Targets](targets.md#secrets-at-the-codec).

## Shape mismatches

Casting is for values that mean the same thing in another notation. A
value that means nothing in any notation is not cast, it is refused:

| Definition | Input | Refused as |
| --- | --- | --- |
| `string`, `integer`, `float`, `boolean`, `email`, `uri` | an array | `string`/`integer`/… expected |
| a list | anything configured that is not an array | `list` expected |
| a map with declared properties | anything configured that is not an array | `map` expected |

`accept()` throws `ShapeMismatchException`, naming the dotted path of the
offending value and what was expected. `submit()` turns it into a
violation on that surface key, the same way it turns an undeclared key
into one. This is the same bargain as unknown keys: a caller that sends
the wrong shape learns about it instead of watching the value vanish.

## Required

`validate()` raises the surface's own violation, **"@label is
required."**, when a required key is not configured. It uses the
predicate above and nothing else, so:

| Value of a required key | Result |
| --- | --- |
| `NULL`, `''` | violation: "@label is required." |
| `FALSE` | passes — then held to its own constraints |
| `0`, `'0'` | passes |
| `[]` (a required list or map) | passes |
| anything else | passes |

That a required list or map may be empty is the deliberate reading:
"required" says the key must be answered, not that the answer must be
large. A surface that needs at least one item says so with a `Count`
constraint, which then produces a message about the count rather than
about the key being missing.

The message is always the surface's own, never a type-specific one from
a constraint, so an empty required number and an empty required string
read alike to the person filling in the form.

## Violation messages are objects

Every message in a violation is a `TranslatableMarkup` — the
surface's own required message, the pipeline's unknown-key and shape
refusals, and the message a constraint or a config schema produced —
and it stays one the whole way out: `DataSurfaceResult`, a form error
through `FormStateInterface::setError()`, a caller's own report.
Placeholders are still placeholders until something renders it, so a
value that contains markup is escaped once, by whoever prints it, rather
than escaped at build time and escaped again on the page.

There are exactly three places a message becomes text, all of them
exception messages, because PHP has nowhere to put an object in one:
`UnknownKeysException`, `TargetViolationsException`, and the refusal
`DataSurfaceConfigurationTrait::setConfiguration()` throws. All three
render through `Pipeline\ViolationSummary`, which names the first five
entries and counts the rest; the full list is still on the exception.

## Locked keys

A locked key is the degenerate refinement: its value space is narrowed
to exactly one value. That value is **whatever storage already holds**,
and the declared default only for a key storage has never held. Input
for a locked key is ignored — it is not an error, just not a way to move
the value. This is why editing an entity whose id is locked no longer
resets it to the default.

## What each stage decides

| Stage | Decides |
| --- | --- |
| `accept()` | Whether a key was configured, what type its value takes, whether the shape is acceptable at all, and how input merges over what is stored. Keeps a secret key's stored value when the input says nothing. Refuses undeclared keys and shape mismatches. Needs no services. |
| `validate()` | Whether a required key was answered, and whether the answers satisfy the constraints of the surface **refined against those same answers**. |
| `refine()` | Which definitions narrow, given which dependencies are configured. A dependency holding `FALSE` or `[]` is an answer and does narrow. |
| `prepare()` / `commit()` | Nothing about values; the target's storage shape and the write. |

After `accept()`, every surface key is present and every value is either
`NULL` or a configured value in its declared type. That is the shape
`validate()`, a target, and any consumer can rely on.
