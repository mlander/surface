# Data Surface Examples - Compliance

Example 4 of [the examples](../data_surface_examples/README.md): another
module has a say in a resource it does not own.

## What it shows

Three classes. `Shape\Licence` is what an event licence is wherever it
is asked for, a shape (`#[Shape('event.licence')]`): a string of four
digits, its pattern explained by a message, its description and its
example, declared once in a static `define()`. `Structure\ComplianceStructure`
is what compliance asks of any event: a licence of that shape,
`$inputs->add('licence', Licence::class, t('Event licence'))`, and a
number of stewards, one per fifty people. The served contract writes the
licence once, as the def `event.licence`, and the structure's key refers
to it. The structure is meant for any resource, so it names none: the capacity it
counts stewards for it reads through a port, under its own name,
`$inputs->reads('capacity', 'integer', required: TRUE)`, and its static
`#[RefinesInput('stewards')]` method watches the port. It translates
with `t()` and holds no service, so the served contract runs it ahead of
time where it can.

`Alter\RegistrationComplianceAlter`, naming example 3's resource with
`#[Alters]`, is the adapter. It attaches the structure at its own mount
and wires the port to example 3's capacity:

```php
$inputs->attach('third_party_settings.data_surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
```

so the licence and the stewards are stored under this module's name at
`third_party_settings.data_surface_examples_compliance` and described by
this module's own config schema, exactly where the alter's own keys were
before the structure existed. The rest is glue for this one owner. It
rewords example 3's title label with `describe()`, titles the fieldset
and places it right after the capacity the licence lifts:

```php
$inputs->describe('third_party_settings.data_surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
```

The fieldset is drawn there on the form and in React; its keys are
still stored and posted under this module's name. Its rule on the
capacity, declared as data in `alterInputs()`, reads the structure's
licence at its mount:

```php
$inputs->narrow('capacity')
  ->when(Condition::absent('licence'))
  ->constrain('Range', ['max' => 100])
  ->describe($this->t('Up to 100 without an event licence.'));
```

Without a licence, example 3's capacity stops at a hundred, and with
one the room's limit applies again; the full schema states the rule as
written, so the capacity is exact there. Its
`#[RefinesInput('room', runtime: TRUE)]` method leaves out the rooms a
site has closed, which it reads from State through the service the
alter is built with: a runtime refiner, because what it answers is known
only when it is asked, so no schema can state it ahead of time, and the
one instance method, which only a runtime refiner may be. Example
3 does not change and does not know this module exists. There is no
form alter and no hook. Another module could attach the compliance
structure to a resource of its own and wire the port to its own
capacity key ([reusing a structure](../../docs/resources.md#reusing-a-structure-from-another-module)).

## How to try it

```bash
drush pm:install data_surface_examples_compliance
```

Then reload `/surface-examples/3`, choose Riverside Hall's main hall,
and see the Compliance fieldset right under the capacity, and the
capacity stop at 100, its help text saying why.
The licence asks for the four digits after EV- only. Type `2048` and it
goes back to 400; type `EV-2048` and the licence is refused under the
field while the capacity stays at 100, because an invalid licence is no
licence to the rule. Set the capacity to 150 and the stewards field
asks for 3. The licence's help text and placeholder say its format, and
the panel explains the Regex by its message, never by the pattern.

### Closing a room

No room is closed until a site says so. The list is the State key
`data_surface_examples_compliance.closed_rooms`, a list of room ids:

```bash
drush state:set data_surface_examples_compliance.closed_rooms '["riverside_east"]' --input-format=json
```

Reload `/surface-examples/3` (or `/surface-react/registration.step3/configure`
with `data_surface_react` on) and choose Riverside Hall: the room offers
the main hall alone, its help says "The East room is closed.", and a
submit choosing the East room is refused at the room. In the contract
panel the room reads "narrowed, and checked at runtime", and both schemas mark it
`runtime: true` beside `enumerated: true`. The full schema still lists
the East room under Riverside Hall, since it is stated for any answers
and a closed room is only known when asked: wider than what the form
accepts, never narrower. **Reset to defaults** (`/surface-examples/reset`)
deletes the key, which opens every room again; so does
`drush state:delete data_surface_examples_compliance.closed_rooms`.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesComplianceTest` | The keys appear under the module's name, the compliance structure's, attached at the mount with its port wired to the capacity, and the label changes; the Compliance fieldset is built where its values post and drawn between the capacity and the pricing, the group it is stored in drawing nothing; the capacity stops at 100 without a licence and at the room's limit with `2048`; `204` is refused with the alter's message; the stewards' minimum is 3 at 150 and 1 at 20, said under the field; the pipeline refuses 150 without a licence and writes it with one; a room closed in State is left out, said in the room's help, and refused on submit; the licence replaces the capacity, the stewards and the panel, the capacity the stewards and the panel; example 5's calls answer as without the module. |
| `Kernel\ServedContractTest::testClosedRoomIsLeftToTheRuntime` | Nothing closed, nothing changes; with the East room closed the full schema is the same document and still lists it, the schema for these answers leaves it out and says why, a validator holding the full schema accepts it and the pipeline refuses it. |
| `Kernel\ServedContractTest::testExampleFourStatesTheCapacityExactly` | The full schema states the capacity per room and its licence rule as written, `x-surface.declared`, and nothing about it `dynamic`; the stewards stay `dynamic`; for no values the rule is evaluated, a hundred. |
| `Kernel\StaticRefinerEnforcementTest::testTheExamplesAgree` | With the module on and a room closed, every conditional the served contract enumerates for example 3 is what refining afresh for its values answers, and the licence rule's conditional is what refining with the rules alone answers for sampled values of the licence: the static refiners are what they declare. |
| `Functional\ExamplesFullSubmitTest` | On `/surface-examples/3`, a capacity of 150 is refused at the capacity without a licence and written with `2048` and 3 stewards. |
| `FunctionalJavascript\ExamplesComplianceRefreshTest` | In a browser: the Compliance fieldset below the capacity and above the pricing, and still there after `2048` and Tab lift the capacity, which, with the stewards, is all the refresh replaced; with the East room closed, choosing Riverside Hall offers the main hall alone. |
