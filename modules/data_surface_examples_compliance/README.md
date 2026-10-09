# Data Surface Examples - Compliance

Example 4 of [the examples](../data_surface_examples/README.md): another
module has a say in a surface it does not own.

## What it shows

One class, `SurfaceAlter\RegistrationComplianceAlter`, naming example 3's
surface with `#[AltersSurface]`. It adds an event licence and a number
of stewards, which are stored under this module's name at
`third_party_settings.data_surface_examples_compliance` and described by
this module's own config schema, and it rewords example 3's title label
with `describe()`. Its `#[RefinesInput('capacity')]` method watches the
licence it added itself: without one, example 3's capacity stops at a
hundred, and with one the room's limit applies again. Its
`#[RefinesInput('stewards')]` method watches example 3's `capacity`:
one steward per fifty people, at least one. Example 3 does not change
and does not know this module exists. There is no form alter and no
hook.

## How to try it

```bash
drush pm:install data_surface_examples_compliance
```

Then reload `/surface-examples/3`, choose Riverside Hall's main hall,
and see the capacity stop at 100, its help text naming both ceilings.
The licence asks for the four digits after EV- only. Type `2048` and it
goes back to 400; type `EV-2048` and the licence is refused under the
field while the capacity stays at 100, because an invalid licence is no
licence to the refiner. Set the capacity to 150 and the stewards field
asks for 3. The licence's help text and placeholder say its format, and
the panel explains the Regex by its message, never by the pattern.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesComplianceTest` | The keys appear under the module's name and the label changes; the capacity stops at 100 without a licence and at the room's limit with `2048`; `204` is refused with the alter's message; the stewards' minimum is 3 at 150 and 1 at 20, said under the field; the pipeline refuses 150 without a licence and writes it with one; the licence replaces the capacity, the stewards and the panel, the capacity the stewards and the panel; example 5's calls answer as without the module. |
| `Functional\ExamplesFullSubmitTest` | On `/surface-examples/3`, a capacity of 150 is refused at the capacity without a licence and written with `2048` and 3 stewards. |
