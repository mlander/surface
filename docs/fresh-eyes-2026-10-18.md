# Data Surface, with fresh eyes

A newcomer's attempt to build a newsletter settings page with
`data_surface`, using only `README.md` and `docs/*.md`. I read the source
only where the docs left me stuck, and I read the examples modules only
at the end (step 5), to compare. I did not open the module's tests, git
history, CLAUDE.md or the planning files.

Site: Drupal 11 at `drupal_8_22` (ddev). I built two modules:
`ds_fresh_eyes` and `ds_fresh_eyes_legal`.

## The log

In the order things happened. Each entry gives what I was doing, what I
expected, what happened, how I got past it, and the page that should
have told me.

### Reading

1. **No quick start for a resource.** The README quick start is a block
   *structure*. Most people's first job is a settings page, which means a
   *resource* with storage, and there is no ten-line version of that
   anywhere. I had to piece it together from `pattern.md` (field
   instance), `resources.md` (node type) and `forms.md` (route YAML).
   *Should be:* README quick start, or a "your first settings page"
   section in `declaring-a-resource.md`.

2. **Too many concepts on the first page.** The README glossary lists
   twelve terms. `index.md` then has a "pieces" table with 22 rows
   (definition map, entry, collection, derived variants, derived resource,
   catalogue, and more). Before I could tell which pieces a settings page
   needs, I had read about derived resources, collections and the served
   contract. *Should be:* `index.md` should say, near the top, "for a
   settings page you need: a resource class, a storage class, an
   operation, a route".

3. **One doc contradicts the others about the schema.**
   `declaring-a-resource.md`, under "What phase B will emit", says
   "Nothing emits a schema yet". The README, `served-contract.md` and the
   contract panel all show that a JSON Schema 2020-12 emitter ships.
   *Should be:* delete or update that section of
   `declaring-a-resource.md`.

4. **Config object storage: the README says one thing, storage.md
   another.** The README says storage writes values "where they live (a
   plugin's configuration, a field's settings, a content type ..., a
   config object, or state)". `storage.md` says the config object storage
   class "was removed for having no adopter", and that a resource stored
   in a simple config object needs "a storage class of its own, held to
   the schema with `SchemaViolations`". So the most common Drupal case,
   a settings form, is the one case the engine does not cover, and no
   doc shows that class. I read `src/Resource/ResourceStorageInterface.php`
   and `src/Storage/SchemaViolations.php` to learn the signatures. (In
   step 5 I found that `data_surface_examples` has exactly this class,
   `RegistrationStorage`, about 25 lines long. The docs never point to it.)
   *Should be:* `storage.md` should show that class in full, and the
   README should not list "a config object" as if it were built in.

5. **Two storage interfaces, one checklist.** `storage.md`'s "Writing a
   storage class" checklist talks about `PreparedValues`,
   `calculateDependencies()`, and refusing "an artifact that did not come
   from this storage". That describes the pipeline-level
   `DataSurfaceStorageInterface`, whose methods take `$surface`. A
   resource's storage implements `ResourceStorageInterface` instead, with
   `load($context)`, `prepare($context, $values): array` and
   `commit($context, $prepared)`. Both interfaces use the same three
   verbs, and the checklist does not say which one it means. *Should be:*
   `storage.md`, with one checklist for each interface.

6. **Operation labels are spelled two ways.**
   `declaring-a-resource.md` ("Translatable strings") says
   `#[Operation(label:)]` uses `new TranslatableMarkup`. The
   `NodeTypeResource` example in `resources.md` passes a plain string
   (`label: 'Add a content type'`). The attribute takes
   `string|\Stringable`, which I learned from the source. *Should be:*
   make the `resources.md` example agree with the rule.

7. **`OperationContext`'s constructor is never listed.** The docs only
   show it in examples (`creates: TRUE`, `known: [...]`). For a singleton
   I guessed `new OperationContext('configure')`, then checked
   `src/Resource/OperationContext.php`. *Should be:* `resources.md`
   "Operations".

8. **"configure" means two things.** The glossary says a context's id is
   "the operation id, or a host's own verb such as `configure`". I wanted
   an operation called `configure` on a resource, and could not tell
   whether that would clash with the plugin hosts' verb of the same name.
   It does not clash, but the docs give the word two jobs. *Should be:*
   the glossary.

9. **Where does the permission come from?** `#[Operation(permission:)]`
   names a permission string. No doc says that I must declare it myself
   in `MODULE.permissions.yml`. `installation.md` says the node type demo
   "adds its own permission", which hints at it. I assumed I had to, and
   that was right. *Should be:* one sentence in `resources.md` "Access".

10. **Slots cannot express "only applies when weekly".** For the send
    day, the obvious tool looked like a slot,
    `attachBy('schedule', by: 'frequency')`. But "the deciding key's
    allowed values become exactly the values the variants fill", and
    `#[Variant]` is not repeatable. So I would need three variant
    classes, two of them empty. I chose a refiner instead (but see 13
    and 14).

### Building and running

11. **The module installed, and the tool appeared, first time.**
    `data_surface:ds_fresh_eyes.newsletter:configure` and its `:read`
    twin were listed by `tool:list` with no extra wiring, and the route
    rendered on the first load. This is the module's strongest point.

12. **`tool:info` says "Permission: None"** even though the operation has
    a permission. Access is enforced when the tool runs: anonymous gets
    "Access denied ... pass --uid=<id>". Also, an anonymous run with bad
    input got input-validation errors *before* the access refusal (that
    ordering is the Tool API's). *Should be:* the tool bridge should put
    the operation's permission in the tool definition.

13. **Narrowing a key to nothing turns its select into a text field.**
    This was the worst surprise. My refiner narrows `send_day` to
    `LabeledChoice` with `choices: []` when the frequency is not weekly.
    That is the same move as the ribbon example in `refinement.md`. When
    I changed Frequency to Monthly in the browser, the AJAX rebuild
    turned "Send day" into a **free text field holding `monday`**.
    `widgets.md` does say the options widget only claims a *non-empty*
    list, so an empty list falls through to the string widget. Saving
    then fails with "The value you selected is not a valid choice." on a
    text field that offers no choices. The person's only fix is to erase
    the text by hand. The empty-option rule and the discard cascade
    (`decisions.md`, `forms.md`) only work on selects, so neither helps
    here. I kept this design, because it is still correct in the
    pipeline, but I would not ship that UX. *Should be:* `refinement.md`
    should warn about this, and an empty list should render as nothing
    (or a disabled, empty element), not as a free-text input.

14. **"Hidden" crashes the page.** To get "hidden" instead of "narrowed",
    I tried the slot anyway: `DailyScheduleStructure` and
    `MonthlyScheduleStructure` with no keys, and
    `WeeklyScheduleStructure` holding `send_day`. Loading the page gave
    **HTTP 500: "No data surface widget serves the "map" data type"**.
    An empty variant is a map with no properties, and the map widget
    needs at least one. So the pattern has no way to say "this choice
    has no extra settings". I reverted the slot. *Should be:*
    `resources.md` "Children": either support empty variants, or say
    plainly that they are not supported and what to do instead.

15. **Switching frequency through the tool needs an explicit null.**
    Weekly to monthly with `send_day` left out was refused ("send_day:
    The value you selected is not a valid choice."), because a key the
    caller does not send keeps its stored value. The caller has to send
    `"send_day": null`, and the message does not mention the frequency.
    At the same time, the tool's `values` input requires every required
    key, so a partial update is impossible anyway. So required keys must
    always be sent, while optional keys left out are filled from storage.
    That mix is hard to predict. *Should be:* `semantics.md` and
    `resources.md` "Tools" should explain it together, with this case as
    the example.

16. **An empty string on an optional URL is refused.** My install config
    had `footer.link_url: ''`. A dry run merged it in and refused it:
    "footer.link_url: This value should be of the correct primitive
    type." `semantics.md` says `''` means "not configured" for
    *required*, but core's `uri` type still validates `''` on an optional
    key, and the message gives no hint why. I fixed it by storing `null`.
    *Should be:* `semantics.md`, a note on `uri`/`email` and `''`.

17. **The runtime refiner worked first time.**
    `#[RefinesInput('frequency', runtime: TRUE)]` on a *static* method
    that reads `\Drupal::state()` (allowed: "a static method may still
    declare runtime"). With the State key unset, the browser offered only
    Weekly and Monthly and the tool refused `daily`. After `state:set`,
    Daily appeared on the next load with no cache clear. The catch is
    that `tool:info` and the full schema still list `daily`. That is
    documented (a runtime rule "promises the schema nothing"), but the
    README's promise that a contract is "never contradicted later" does
    not mention this exception. *Should be:* qualify the README
    sentence.

18. **The narrowed schema is looser than the full schema.** In the
    panel's "schema for these answers", with frequency monthly,
    `send_day` comes out as `"type": ["string","null"]` with **no
    enum**. That accepts `"monday"`, which the pipeline refuses, and it
    is looser than the full schema, which lists the seven days and null.
    The panel table reads "Allows: one of" followed by nothing. An empty
    list should be emitted as `type: null` / `const: null`. *Should be:*
    an emitter fix. As far as I can tell the docs' rule ("never stricter
    than the runtime") allows this, but it is clearly not intended.

19. **The full schema's conditional misses `required`.** The full schema
    has an `if frequency = weekly then send_day: string, seven days`. It
    does not add `send_day` to `required`, although my refiner calls
    `setRequired(TRUE)` for weekly. There is no conditional at all for
    daily or monthly, so `monthly + monday` passes the schema. The
    runtime would refuse both. *Should be:* `served-contract.md` "Exact
    where it can be" should say which refinements become conditionals
    and which are dropped.

20. **The tool schema's default is the stored value.** In the panel's
    tool schema, `frequency` has `default: "monthly"`, the stored value
    rather than the declared `weekly`. That is documented in
    `resources.md` "Tools", but it surprised me when I saw it.

21. **The multiline setting worked as documented.**
    `->setSetting('multiline', TRUE)` gave a textarea, as `widgets.md`
    says.

### The alter module

22. **The alter worked first time.** `#[Alters(NewsletterResource::class)]`,
    `add('legal_notice', ...)`, and a static refiner on `max_recipients`
    that watches `?string $legal_notice`, as `resources.md` says (handed
    NULL while unanswered). `describe('third_party_settings.ds_fresh_eyes_legal',
    label:, after: 'max_recipients')` placed the fieldset straight after
    the capped key. The cap showed up as `#max=1000` on the number field.

23. **Nobody documents the config schema for an alter's keys.** An alter
    mounts its key at `third_party_settings.<module>.<key>`. A simple
    config object has no `third_party_settings` in core's schema, so the
    owner has to declare one, and the alter has to describe its part. I
    designed `third_party_settings: sequence of
    ds_fresh_eyes.newsletter.third_party.[%key]` myself, and the legal
    module declares `ds_fresh_eyes.newsletter.third_party.ds_fresh_eyes_legal`.
    In step 5 I found the examples use exactly this convention, but it
    appears nowhere in `docs/`. An owner who does not declare it leaves
    every alter's keys without a schema, and `SchemaViolations` lets data
    with no schema through silently. *Should be:* `storage.md` and the
    alters section of `resources.md`.

24. **No doc says which event triggers a rebuild.** The legal notice is
    a textarea, and its rebuild fires on **blur**. Typing did nothing
    until I left the field, and then the max rose to 10000. That is
    reasonable, but no doc says it.

25. **My custom message never reaches the form.** I added
    `notInRangeMessage` to the refined `Range`, so a person would learn
    *why* the cap is 1000. (`refinement.md` says only the bounds are
    compared, so this is allowed.) The tool shows my message. The form
    does not: core's number element checks `#max` first and says
    "Maximum recipients per send must be lower than or equal to 1000."
    `forms.md` warns that element validation can override the
    surface's own violation, but only when you swap an element type in
    the cosmetic layer. The stock number widget does the same thing.
    *Should be:* `widgets.md` or `forms.md`.

26. **A `{{ max }}` placeholder in a custom message is printed literally.**
    The pipeline's violation read "At most {{ max }} recipients ...",
    while core's default Range message is filled in. I hard-coded the
    number. *Should be:* `semantics.md` "Violation messages are objects".

27. **The schema check hid a real problem.** My schema used `label` and
    `text`, which are translatable types, and the install config had no
    `langcode`. Typed config reports "must specify a language code" at
    the root path. `SchemaViolations`, which by default reports only
    mapped paths, dropped that violation, and my first `prepare()` only
    validated the keys being written anyway. I found it by validating the
    stored config by hand. I fixed `prepare()` to check the written
    values laid over the stored object, and added `langcode: en`.
    *Should be:* `storage.md` should show which `$data` to pass to
    `SchemaViolations` (the whole object, or only what is written?).

28. **The alter interface contradicts the docs.**
    `ShapeAdditionsInterface` declares `attach()`. `pattern.md` says it
    is "the same minus `attachBy()`", and `resources.md` says that an
    alter calling `attach()` is refused by name. Minor, but the interface
    and the docs disagree.

29. **Things that worked:** the route requirement
    `_data_surface_operation_access` refuses anonymous and allows uid 1.
    The catalogue (`data_surface.resource_catalogue`) lists the resource,
    its operation, its permission and the alter with its refiner. It does
    not say that the footer structure is attached by the newsletter.

30. **Not a module issue:** Gin's sticky top bar blocks Playwright's
    click on Save, so I submitted with `form.requestSubmit()`.

## What I built

- `ds_fresh_eyes`: the `ds_fresh_eyes.newsletter` resource in
  `src/Resource`, `FooterStructure` in `src/Structure`, `NewsletterStorage`
  in `src/Storage`, a permission, a route, a config schema and the
  default config.
- `ds_fresh_eyes_legal`: `NewsletterLegalAlter` in `src/Alter`, plus a
  schema for its mounted key.
- Route `ds_fresh_eyes.newsletter` at **`/admin/config/services/newsletter`**,
  gated by `configure ds_fresh_eyes newsletter`.
- Tools `data_surface:ds_fresh_eyes.newsletter:configure` and
  `...:configure:read`.

The resource:

```php
#[Resource('ds_fresh_eyes.newsletter', storage: NewsletterStorage::class)]
final class NewsletterResource implements ResourceInterface {

  public const PERMISSION = 'configure ds_fresh_eyes newsletter';

  #[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: self::PERMISSION)]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('sender_name', 'string', t('Sender name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 128]);
    $inputs->add('sender_email', 'email', t('Sender email'))
      ->setRequired(TRUE);
    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => self::frequencies()]);
    $inputs->add('send_day', 'string', t('Send day'))
      ->setDescription(t('Only a weekly newsletter has a send day.'))
      ->addConstraint('LabeledChoice', ['choices' => self::days()]);
    $inputs->add('max_recipients', 'integer', t('Maximum recipients per send'), default: 1000)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
    $inputs->attach('footer', FooterStructure::class)
      ->setLabel(t('Footer'));
  }

  /**
   * A send day is required when weekly, and nothing is allowed otherwise.
   */
  #[RefinesInput('send_day')]
  public static function sendDayOnlyWeekly(DataDefinitionInterface $send_day, string $frequency): DataDefinitionInterface {
    if ($frequency === 'weekly') {
      return $send_day->setRequired(TRUE);
    }
    return $send_day->addConstraint('LabeledChoice', ['choices' => []]);
  }

  /**
   * Daily is only offered while the site allows it (State).
   */
  #[RefinesInput('frequency', runtime: TRUE)]
  public static function dailyWhenAllowed(DataDefinitionInterface $frequency): DataDefinitionInterface {
    if (\Drupal::state()->get('ds_fresh_eyes.daily_allowed')) {
      return $frequency;
    }
    $choices = self::frequencies();
    unset($choices['daily']);
    return $frequency->addConstraint('LabeledChoice', ['choices' => $choices]);
  }

  // frequencies() and days(): value => t() label maps.
}
```

The structure:

```php
#[Structure('ds_fresh_eyes.footer')]
final class FooterStructure implements StructureInterface {

  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('text', 'string', t('Footer text'))
      ->setSetting('multiline', TRUE);
    $inputs->add('link_url', 'uri', t('Link URL'))
      ->setDescription(t('An absolute URL, such as https://example.com/unsubscribe.'));
  }

}
```

The storage (it has to exist; see log entry 4):

```php
final class NewsletterStorage implements ResourceStorageInterface {
  use DependencySerializationTrait;
  public const NAME = 'ds_fresh_eyes.newsletter';
  private const PATHS = [/* each top-level key => itself, incl. third_party_settings */];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  public function load(OperationContext $context): array {
    return array_intersect_key($this->configFactory->get(self::NAME)->getRawData(), self::PATHS);
  }

  public function prepare(OperationContext $context, array $values): array {
    $prepared = array_intersect_key($values, self::PATHS);
    $whole = $prepared + $this->configFactory->get(self::NAME)->getRawData();
    unset($whole['_core']);
    SchemaViolations::check($this->typedConfig, self::NAME, $whole, self::PATHS);
    return $prepared;
  }

  public function commit(OperationContext $context, array $prepared): void {
    $config = $this->configFactory->getEditable(self::NAME);
    foreach ($prepared as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }
}
```

The alter:

```php
#[Alters(NewsletterResource::class)]
final class NewsletterLegalAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  public const CAP = 1000;
  public const MIN_NOTICE = 20;

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('legal_notice', 'string', $this->t('Legal notice'))
      ->setDescription($this->t('Printed under every newsletter. At least @min characters lifts the cap of @cap recipients per send.', ['@min' => self::MIN_NOTICE, '@cap' => self::CAP]))
      ->setRequired(TRUE)
      ->setSetting('multiline', TRUE);
    $inputs->describe('third_party_settings.ds_fresh_eyes_legal', label: $this->t('Legal'), after: 'max_recipients');
  }

  /**
   * At most 1000 recipients until the legal notice is 20 characters long.
   */
  #[RefinesInput('max_recipients')]
  public static function capWithoutLegalNotice(DataDefinitionInterface $max_recipients, ?string $legal_notice): DataDefinitionInterface {
    if ($legal_notice !== NULL && mb_strlen($legal_notice) >= self::MIN_NOTICE) {
      return $max_recipients;
    }
    return $max_recipients->addConstraint('Range', [
      'min' => 1,
      'max' => self::CAP,
      'notInRangeMessage' => 'At most 1000 recipients per send until the legal notice is at least 20 characters long.',
    ]);
  }

}
```

The route:

```yaml
ds_fresh_eyes.newsletter:
  path: '/admin/config/services/newsletter'
  defaults:
    _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
    _title: 'Newsletter settings'
    _data_surface_resource: 'Drupal\ds_fresh_eyes\Resource\NewsletterResource'
    _data_surface_operation: 'configure'
    _data_surface_panel: 'data_surface_tool.contract_panel'
  requirements:
    _data_surface_operation_access: 'TRUE'
```

Plus the files the docs never mention: `ds_fresh_eyes.permissions.yml`,
`config/schema/ds_fresh_eyes.schema.yml` (including the
`third_party_settings` sequence), `config/install/ds_fresh_eyes.newsletter.yml`,
and the legal module's `config/schema/ds_fresh_eyes_legal.schema.yml`.

Checked: the form renders, rebuilds over AJAX on frequency and on the
legal notice, and saves. The tool dry-runs refuse `daily` while State
forbids it, refuse more than 1000 recipients with a short legal notice,
and accept everything valid. `:read` returns the stored values, including
the mounted legal notice. Anonymous users are refused on the route and
on the tool.

## Concept count

Marked **[1]** if I needed it for step 1 alone (the resource, its form
and its storage), **[all]** if I only needed it for the rest of the task.

| Concept | Needed for |
| --- | --- |
| Resource (`#[Resource]`, `ResourceInterface`, `src/Resource`) | [1] |
| Singleton resource (no identity keys) | [1] |
| Shape: `defineInputs()`, `ShapeInterface::add()`, core data definitions | [1] |
| Static-only class, `t()` vs `new TranslatableMarkup` vs `$this->t()` | [1] |
| Constraints as allowed values: `LabeledChoice`, `Range`, `Length` | [1] |
| Options resolver (only to understand why a select appears or does not) | [1] |
| Widget selection rule (options widget only for a non-empty list) | [1] (learned the hard way) |
| Refiner (`#[RefinesInput]`, watched siblings by parameter name) | [1] |
| The narrowing table (what a refiner may return) | [1] |
| Required semantics (`setRequired`, NULL/'' as not configured) | [1] |
| Stale values and the empty-option rule | [1] |
| Partial update: an omitted key keeps its stored value | [1] |
| Operation (`#[Operation]`) and `OperationContext` | [1] |
| Permission on the operation, plus my own `permissions.yml` | [1] |
| Access route requirement `_data_surface_operation_access` | [1] |
| Storage: `ResourceStorageInterface` load / prepare / commit | [1] |
| `SchemaViolations` and config schema for the stored object | [1] |
| Operation form (`DataSurfaceOperationForm`, route defaults) | [1] |
| Data surface (the sealed build; mostly invisible to me) | [1] |
| Slot and variant (considered, rejected for step 1) | [1] |
| Structure (`#[Structure]`, `StructureInterface`) | [all] (step 2) |
| Attached child, `attach()`, the parent stores the child under its key | [all] (step 2) |
| The wall (parent and child cannot read each other) | [all] (step 2) |
| Alter (`#[Alters]`, `AltersInputsInterface`, `ShapeAdditionsInterface`) | [all] (step 4) |
| Mounting under `third_party_settings.<module>.<key>` | [all] (step 4) |
| Third-party config schema convention for a simple config object | [all] (step 4) |
| `describe()` with `after:` placement | [all] (step 4) |
| Owner's chain vs contribution slice (where an alter's refiner runs) | [all] (step 4) |
| Watched mounted key handed as NULL (`?string`) | [all] (step 4) |
| Runtime refiner (`runtime: TRUE`), max-age 0, the static-refiner guard | [all] (step 5) |
| Derived tool, `values` / `dry_run`, read tool, tool access with `--uid` | [all] (step 6) |
| Contract panel (`_data_surface_panel`), served contract, the two documents | [all] (step 6) |
| x-surface markers (`refined`, `runtime`, `enumerated`) | [all] (step 6) |

That is 20 concepts for step 1 and 33 for the whole task. Of the twelve
glossary words, I never needed host, context-as-host-verb, or resolver
as an author. I also did not need collections, derived resources,
derived variants, cosmetics, secrets or outputs.

## Three suggestions

1. **Ship a config object storage class, and a "settings page" recipe
   that uses it.** Bring back the removed config object storage (or make
   `#[Resource(config: 'my.settings')]` imply it). It should validate
   the whole object against its schema, own `langcode`, and declare
   `third_party_settings` for alters. Then put a complete singleton
   settings page in the README: resource, permission, route, schema,
   default config. That alone would have removed log entries 1, 2, 4, 5,
   7, 9, 23 and 27, and every adopter currently writes the same 25-line
   class.

2. **Make "this key does not apply" a first-class idea.** A key narrowed
   to no allowed values should drop out of the form (or render disabled
   and empty), be cleared rather than kept as stale, and be emitted as
   `null`/absent in both schemas. That needs either an "applies when"
   refinement or slots that accept empty variants. Today the obvious
   spelling silently turns a select into a free-text field (entry 13),
   the alternative crashes the page (entry 14), and the "narrowed"
   schema ends up looser than the full one (entry 18). "Field X only
   when Y" is the most common dependent-settings rule in Drupal, and
   both spellings fail.

3. **Make violation messages from refinements reach the person.** A
   refiner's own message should win over the element's built-in check
   (entry 25), its Symfony placeholders should be filled in (entry 26),
   and a refusal caused by a sibling should name that sibling (entries
   13 and 15). The module's whole case is "one set of rules, every
   caller agrees". At the moment the form, the tool and the schema each
   say something different about the same refusal.

## Verdict

I would use this on a real project for the *contract* half, with
caution, and not yet for the *form* half. The good part is real: one
static class gave me a working form, AJAX dependencies, an
operation-level permission, an agent tool with a dry run, a read tool,
a JSON Schema, and a second module that extended all of it without a
single form alter. Every piece I wrote worked the first time it was
wired, and the runtime refiner and the alter were both pleasant. What
held me back was not the model but the gap between the docs and a first
project. The docs are thorough and precise about edge cases (secrets,
derived resources, collections, the narrowing table), but they never
show the one thing most people will build first: a settings page. The
one thing it needs that the engine does not ship, config object storage,
is mentioned only in a paragraph explaining why it was removed. The
vocabulary is also heavy for a newcomer: 20 concepts before step 1
works, several words with two jobs, and a 1,400-line decisions page
that I needed for two answers. What I would tell the maintainers: put a
settings-page recipe at the top of the README and ship the storage it
needs; treat "this key does not apply" as a first-class case, because
right now its natural spelling breaks the form and loosens the schema;
and test what a *person* sees on a refusal (element checks overriding
the module's messages, placeholders printed raw) as carefully as the
narrowing proofs are tested. The architecture deserves documentation
that starts where its users start.
