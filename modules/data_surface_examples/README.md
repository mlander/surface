# Data Surface Examples

One surface that grows, one idea per example. The thing being described is
the settings of an event registration: a title, how many people, where,
what it costs, who to contact. Each example adds one idea, each example with a
form is a page of its own, and under each form, collapsed, is the contract the
form was built from: every key, what it allows right now, and the JSON
Schema a caller with no form is handed.

```bash
drush pm:install data_surface_examples
```

Then visit `/surface-examples` as an administrator. Every page asks for
"administer site configuration". Nothing else is needed: the venues and
rooms are fixed lists inside the module, and each example keeps its
settings in a config object of its own
(`data_surface_examples.registration_step1` to `_step3`), so the examples
never write over each other.

To take the examples again from the start, follow **Reset to defaults**
on the landing page (`/surface-examples/reset`). It puts all three
config objects back to the files the module ships in `config/install`,
including anything another module stored on them, such as the privacy
notice example 4's module adds under example 3.

## What a surface is, in one paragraph

A surface is a class that says what a piece of software accepts: each
value's name, type, label, and what it may hold. It says nothing about
forms. The form is generated from it, the validation is its, and the
same class is a tool an agent can call. The rule that makes this work is
that a surface may only ever *narrow* what it said: once a caller has
read it, nothing it accepts later contradicts what it advertised.

## Example 1: declare what you accept

`/surface-examples/1`

Three settings, each on one line: its name, its type, its label, and
what it allows. The situation at the top, `configure`, is how the
surface is asked for: it needs nothing, and only an account that may
administer site configuration may ask.

<!-- code: src/Surface/RegistrationStep1Surface.php -->

```php
#[Surface('registration.step1', target: RegistrationStep1Target::class)]
final class RegistrationStep1Surface implements SurfaceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Situation('configure', label: 'Configure registration', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', new TranslatableMarkup('Event title'))
      ->setRequired(TRUE);
    $inputs->add('capacity', 'integer', new TranslatableMarkup('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('open', 'boolean', new TranslatableMarkup('Registration open'), default: TRUE);
  }

}
```

That class is the whole of the form at `/surface-examples/1`, its
validation (try a capacity of 1001), and what is written: the target it
names keeps the values in config, and checks them against the config
schema before anything is saved.

**The classic twin**, at `/surface-examples/1/classic`, is the same
three settings written as a `ConfigFormBase`, as well as core allows
today: `#config_target` reads and writes each setting with no submit
handler, and the elements' own `#required`, `#min` and `#max` are its
validation. It edits the same config object. The landing page counts
both. What the classic form cannot do is be *asked*: nothing outside it
knows what its three settings accept.

<!-- code: src/Form/RegistrationStep1ClassicForm.php -->

```php
final class RegistrationStep1ClassicForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'data_surface_examples_registration_step1_classic';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['data_surface_examples.registration_step1'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event title'),
      '#required' => TRUE,
      '#config_target' => 'data_surface_examples.registration_step1:title',
    ];
    $form['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity'),
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#config_target' => 'data_surface_examples.registration_step1:capacity',
    ];
    $form['open'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration open'),
      '#config_target' => 'data_surface_examples.registration_step1:open',
    ];
    return parent::buildForm($form, $form_state);
  }

}
```

## Example 2: answers depend on answers

`/surface-examples/2`

A venue, and a room in it. Which rooms are on offer depends on the
venue, and how many people fit depends on the room. Each rule is one
method with `#[RefinesInput]`: it is handed the key's definition, and,
by parameter name, the value of the key it reads. Its signature is its
dependency.

<!-- code: src/Surface/RegistrationStep2Surface.php -->

```php
#[Surface('registration.step2', target: RegistrationStep2Target::class)]
final class RegistrationStep2Surface implements SurfaceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Situation('configure', label: 'Configure registration', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', new TranslatableMarkup('Event title'))
      ->setRequired(TRUE);
    $inputs->add('capacity', 'integer', new TranslatableMarkup('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('open', 'boolean', new TranslatableMarkup('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', new TranslatableMarkup('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', new TranslatableMarkup('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats.
   */
  #[RefinesInput('capacity')]
  public function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    return $capacity->addConstraint('Range', ['min' => 1, 'max' => Venues::seats($room) ?? 1000]);
  }

}
```

Change the venue on the form: the room list rebuilds over AJAX, and so
does the panel under it, whose room row now lists the new venue's
rooms, *narrowed* from the six declared. The room saved before is not
one of them, so the room select comes up on its empty option rather
than on some other room. The saved room has not gone anywhere: Save
refuses it, since it belongs to the venue you just moved away from,
until a room of the new venue is chosen, and putting the venue back
shows it chosen again.
Choose the garden room and the capacity's row says "from 1 to 30". A
refiner can only tighten: the framework checks every result is
narrower than what was declared, so the panel's first answer stays
true.

## Example 3: made of parts

`/surface-examples/3`

Two parts, each a surface of its own. The **ticket** is a *slot*: the
pricing chooses which surface fills it. Example 3 names neither ticket;
each ticket names example 3, with `#[SurfaceVariant]`, so a third kind of
ticket is a new class and example 3 never changes. The **contact** is a
fixed part, attached with `attach()`: always there, and validated in
its own frame, so its email is checked by its own rules.

<!-- code: src/Surface/RegistrationStep3Surface.php -->

```php
#[Surface('registration.step3', target: RegistrationStep3Target::class)]
final class RegistrationStep3Surface implements SurfaceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Situation('configure', label: 'Configure registration', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', new TranslatableMarkup('Event title'))
      ->setRequired(TRUE);
    $inputs->add('capacity', 'integer', new TranslatableMarkup('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('open', 'boolean', new TranslatableMarkup('Registration open'), default: TRUE);
    $inputs->add('venue', 'string', new TranslatableMarkup('Venue'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::VENUES]);
    $inputs->add('room', 'string', new TranslatableMarkup('Room'))
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => Venues::rooms()]);
    $inputs->add('pricing', 'string', new TranslatableMarkup('Pricing'), default: 'free')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['free', 'paid']]);
    $inputs->attachBy('ticket', by: 'pricing')
      ->setLabel(new TranslatableMarkup('Ticket'));
    $inputs->attach('contact', ContactSurface::class)
      ->setLabel(new TranslatableMarkup('Contact'));
  }

  /**
   * The room must be one of the chosen venue's rooms.
   */
  #[RefinesInput('room')]
  public function roomInVenue(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    return $room->addConstraint('LabeledChoice', ['choices' => Venues::rooms($venue)]);
  }

  /**
   * No more people than the chosen room seats.
   */
  #[RefinesInput('capacity')]
  public function capacityOfRoom(DataDefinitionInterface $capacity, string $room): DataDefinitionInterface {
    return $capacity->addConstraint('Range', ['min' => 1, 'max' => Venues::seats($room) ?? 1000]);
  }

}
```

The two tickets:

<!-- code: src/Surface/FreeTicketSurface.php -->

```php
#[Surface('registration.step3.ticket.free')]
#[SurfaceVariant(of: RegistrationStep3Surface::class, key: 'ticket', value: 'free')]
final class FreeTicketSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', new TranslatableMarkup('Note'))
      ->setDescription(new TranslatableMarkup('Shown beside the register button, for example "Donations welcome".'));
  }

}
```

<!-- code: src/Surface/PaidTicketSurface.php -->

```php
#[Surface('registration.step3.ticket.paid')]
#[SurfaceVariant(of: RegistrationStep3Surface::class, key: 'ticket', value: 'paid')]
final class PaidTicketSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('price', 'float', new TranslatableMarkup('Price'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0.01]);
    $inputs->add('currency', 'string', new TranslatableMarkup('Currency'), default: 'EUR')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['EUR', 'GBP', 'USD']]);
  }

}
```

And the contact:

<!-- code: src/Surface/ContactSurface.php -->

```php
#[Surface('registration.contact')]
final class ContactSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('email', 'email', new TranslatableMarkup('Email'))
      ->setRequired(TRUE);
    $inputs->add('phone', 'string', new TranslatableMarkup('Phone'));
  }

}
```

Switch the pricing to paid: the ticket rebuilds with a price and a
currency, and the panel shows the paid variant's keys. A paid ticket
with no price is refused as `ticket.price`, an email that is no email as
`contact.email`.

## Example 4: others get a say

`/surface-examples/4`, which says what to do:

```bash
drush pm:install data_surface_examples_compliance
```

then reload example 3. A second module, which example 3 does not know,
changes it with one class: it adds a privacy notice (stored under the
module's own name, at
`third_party_settings.data_surface_examples_compliance.privacy_notice`,
so example 3's storage never has to know it), rewords the title's label,
and makes its notice required once the capacity is above a hundred. No
form alter, no hook.

<!-- code: ../data_surface_examples_compliance/src/SurfaceAlter/RegistrationComplianceAlter.php -->

```php
#[AltersSurface(RegistrationStep3Surface::class)]
final class RegistrationComplianceAlter implements SurfaceAlterInterface {

  use StringTranslationTrait;

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('privacy_notice', 'string', $this->t('Privacy notice'));
    $inputs->describe('title', label: $this->t('Public event title'));
  }

  /**
   * Above a hundred people, a privacy notice is required.
   */
  #[RefinesInput('privacy_notice')]
  public function noticeForLargeEvents(DataDefinition $notice, int $capacity): DataDefinition {
    return $capacity > 100 ? $notice->setRequired(TRUE) : $notice;
  }

}
```

Choose a room that seats more than a hundred (Riverside Hall's main
hall), set the capacity to 101, and the notice turns required, in the
form and in the panel, whose row for it now depends on `capacity`. An
alter can add and tighten. It can never take away what the owner
declared.

## Example 5: same contract, no form

`/surface-examples/5`

Nothing to write. Example 3 names a target and has a situation that needs
nothing, so the Tool API bridge (`data_surface_tool`) derives a tool
from it, `data_surface:registration.step3:configure`. It takes what
example 3's form takes, refuses what it refuses, with the same messages,
and can rehearse a write without making it.

The script makes three calls to the tool and prints what each answered:

```bash
ddev exec php web/modules/custom/data_surface/scripts/examples-dry-run.php
```

```
1. Valid values
Success: Configure registration: the values were accepted and written.
  values: {"title":"Autumn meetup","capacity":90,"open":true,"venue":"harbour","room":"harbour_deck","pricing":"paid","ticket":{"price":12.5,"currency":"EUR"},"contact":{"email":"events@example.com","phone":""}}
  committed: true

2. A capacity above the room's
Failed: The values were refused: capacity: This value should be between 1 and 30.

3. A dry run
Success: Dry run of Configure registration: the values were accepted and prepared, and nothing was written.
  values: {"title":"Winter social","capacity":100,"open":true,"venue":"riverside","room":"riverside_east","pricing":"free","ticket":{"note":"Donations welcome"},"contact":{"email":"events@example.com","phone":""}}
  committed: false
  prepared: {"title":"Winter social","capacity":100,"open":true,"venue":"riverside","room":"riverside_east","pricing":"free","ticket":{"note":"Donations welcome"},"contact":{"email":"events@example.com","phone":""}}
```

The same tool and the same three calls, from Drush:

```bash
drush tool:info data_surface:registration.step3:configure

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Autumn meetup","capacity":90,"venue":"harbour","room":"harbour_deck","pricing":"paid","ticket":{"price":12.5,"currency":"EUR"}}}'

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"venue":"library","room":"library_garden","capacity":45}}'

drush tool:run data_surface:registration.step3:configure --uid=1 --input='{"values":{"title":"Winter social","capacity":100,"venue":"riverside","room":"riverside_east","pricing":"free","ticket":{"note":"Donations welcome"}}}' --input=dry_run=true
```

`--uid=1` because `tool:run` runs as anonymous unless told otherwise,
and the situation asks for "administer site configuration". The dry run
is a second input, which `tool:run` reads as `name=value` and decodes as
JSON, so `true` is a boolean.

The script and the Drush commands exercise the same tool, through the
same pipeline, against the same surface. The difference is where they
run: the script runs the calls inside a kernel test,
`ExamplesToolTest`, on a fresh database it throws away, so it can be
run any number of times and is what the test suite verifies; the Drush
commands run against your site, and the first one writes example 3's
settings. The calls are written down once, in `ExampleCalls`, and both
are generated from it. Every call says where the event is, so none
depends on an earlier one, and every capacity is a hundred or fewer, so
example 4's module does not change the answers. Enable it and send a
capacity of 120 without a privacy notice to see it refuse.

## Example 6: every door

To be written: the same contract through ECA, a decoupled page, and an
AI agent.

## How this page is kept true

Every code block above is the file it names, from its first attribute
or its class line to its closing brace, and `ExamplesReadmeTest` fails
when one drifts. The tool's three answers are asserted by
`ExamplesToolTest`, which also checks this page quotes them, and the
Drush commands are checked against `ExampleCalls`.

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesStepsTest` | Examples 1 to 3: each form builds and saves, example 1 as its classic twin does; the venue narrows the room and the room the capacity; the ticket slot resolves by pricing; the contact validates its email; the panel's rows as the answers move; each example's class stays screen sized. |
| `Kernel\ExamplesComplianceTest` | Example 4: the alter's key, label and requiredness above a hundred. |
| `Kernel\ExamplesToolTest` | Example 5: the three calls and their answers; what the script prints. |
| `Unit\ExamplesReadmeTest` | This page against the files it quotes, and its Drush commands. |
| `Functional\ExamplesRoutesTest` | Every route answers an administrator and refuses anonymous; the landing page; a save through example 3. |
| `Kernel\ExamplesResetTest` | Reset to defaults puts every example, and what another module stored on example 3, back to the shipped files. |
