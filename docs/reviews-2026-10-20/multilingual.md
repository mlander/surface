# Review: multilingual

## 1. Standpoint

I review as a Drupal multilingual specialist (locale, config translation, content translation, language negotiation) asked to run this module on a site in three languages. I hold it to core's rule: every string a person or an agent reads is a translatable source that potx can extract, stays lazy until the response it is rendered into, and anything cached across requests is either still lazy or keyed by language.

**What I could check on the site.** `ddev drush language:add` does not exist here: the language module is not installed, and Drush lists only `locale:*` commands. Installing `language` would change `core.extension` and add config, so I did not add a language. Instead I ran in-process probes: a translator registered in memory for one Drush process, and a serialized tool definition read back under it. They changed nothing persistent. One caveat makes these bugs invisible on this site: `cache.discovery` is a `NullBackend` here, so every request rediscovers tools in its own language. On a production cache, the first request's language sticks.

## 2. The five changes that would most improve the authoring experience

### 1. Keep the tool listing lazy: today it is cached in the language that discovered it

**Problem.** The module stores labels as lazy `TranslatableMarkup` and then flattens them to strings at derivation. The tool label is `$this->t('@label', ['@label' => (string) $operation->label])` (`modules/data_surface_tool/src/Plugin/Derivative/ResourceOperationToolDeriver.php:166`, read tool `:207`). The tool description does the same (`modules/data_surface_tool/src/OperationInputs.php:565`, `:595`). Every key description that gets a note appended is cast first (`modules/data_surface_tool/src/InputDefinitions.php:216`, `:513`, `:525`) and then wrapped in `'@description @note'` (`:1177`). The registry caches derived resources language-blind (`src/ResourceBuild/ResourceRegistry.php:131`). Core's bundle labels, which core caches per language as `entity_bundle_info:$langcode` (`core/lib/Drupal/Core/Entity/EntityTypeBundleInfo.php:101`), are baked into `t('Edit @bundle', ...)` there (`modules/data_surface_entity/src/Resource/ContentEntityResources.php:83-96`).

I serialized the English-discovered definition of `data_surface:first.newsletter:configure` and read it back with a French translator active. The label read "Configure the newsletter" while `dry_run` read "Essai à blanc" and `values.sender_name` read "Nom de l'expéditeur". So an agent sees one language for the tool and another for its keys. In the cached definitions, `@description` is a plain string for `values.room`, `values.ticket.note`, `…compliance.stewards` (step3) and `values.region` (store).

Two related defects:

- `PlainTextMarkup::translatable()` rebuilds a markup from `getUntranslatedString()` (`modules/data_surface_tool/src/PlainTextMarkup.php:47`). That breaks plurals. Tested on the compliance stewards description, it renders `"At least 1 steward for 120 attendees.\u0003At least @count stewards for 120 attendees."`, which is both forms with a literal `@count`. Any refined `values` (an operation with parameters) whose refiner follows the docs' advice to use `PluralTranslatableMarkup` reaches this.
- `LanguageExistsOptions` returns language names, which config translation overrides per interface language, with only a tag and no `languages:language_interface` context (`src/Plugin/DataSurfaceOptionsResolver/LanguageExistsOptions.php:87-89`). `CountryOptions` gets this right (`CountryOptions.php:118-121`).

The docblock at `InputDefinitions.php:1128` says "the tool's own label and description stay TranslatableMarkup". That is true of the type and false of the content.

**The decision.** "A static tool definition is one account's" (`docs/decisions.md:2152-2166`) already concedes that the deriver runs once, for whoever discovers. Language is the same mechanism. For accounts, the answer was to re-list `values` per instance. For language there is a cheaper one: keep the arguments as objects, since `TranslatableMarkup` serializes its source and arguments and renders on read.

**Change.**

```php
// Before, ResourceOperationToolDeriver.php:166
$this->t('@label', ['@label' => (string) $operation->label]),
// After
$operation->label instanceof TranslatableMarkup
  ? $operation->label
  : $this->t('@label', ['@label' => $operation->label]),
```

```php
// Before, InputDefinitions.php:1176-1180 (callers cast the description first)
protected function appended(string $description, TranslatableMarkup $note): TranslatableMarkup {
  return PlainTextMarkup::translatable($description === '' ? $note : $this->t('@description @note', [
    '@description' => $description,
// After: callers pass $this->description($target) uncast
protected function appended(TranslatableMarkup|string $description, TranslatableMarkup $note): TranslatableMarkup {
  return PlainTextMarkup::translatable($description === '' ? $note : $this->t('@description @note', [
    '@description' => $description,
```

`PlainTextMarkup` should become a decorator: hold the original markup, render it, strip it, and add it to `__sleep()`, rather than rebuilding it from its source. The registry should key derived resources by language, or keep bundle labels as the objects core hands over. `LanguageExistsOptions` should add the context.

**Cost.** Internal only, with no new concept. Add one docs sentence: the decisions entry becomes "one account's, and every string in it lazy".

**Breaks.** Nothing on the wire in English. The MCP bridge (contrib) casts the description again (`mcp_server_tool_bridge/src/Plugin/Derivative/McpToolConfigDeriver.php:85`) and normalizes the input schema from an instance at its own discovery (`:117`). The existing *Open* note at `docs/decisions.md:2162-2164` should say this holds for language too.

### 2. Refusals are sentences glued from fragments

**Problem.** "A refusal names what it depends on" (`docs/decisions.md:1870-1893`) is the module's best feature in English and its worst string in translation. `Clause` strips an ASCII full stop off another module's translated message (`src/Pipeline/Clause.php:51`). It does not touch `。` or `।`. Then `'@message when @conditions.'` (`src/Pipeline/DataSurfacePipeline.php:878`) glues it to conditions joined with `' ' . t('and') . ' '` (`:874`, `:1055`, `:1092`), which is wrong for languages written without spaces. Each condition is `t('@key is @value')` (`:929`), with `@value` filled by the bare words `t('empty')`, `t('set')`, `t('on')`, `t('off')` (`:922-925`). These are the most overloaded single-word sources in Drupal's locale, they have no context, and French has to agree "activé/activée" with a key it cannot see. `t('choose @choices and @count more')` (`:968`) has no plural. The output, verbatim from `drush tool:run --uid=1` (dry run): "This value should be between 1 and 100 when Room is library_garden and Event licence is empty."

`docs/declaring-a-resource.md:361-362` promises "never a concatenation of translated fragments". The engine is where it happens most.

**Change.** Keep the constraint's message whole and add one whole second sentence. Every condition kind gets its own string with a context:

```php
// Before, DataSurfacePipeline.php:922-929
!ValueState::isConfigured($held) => $this->t('empty'),
...
$conditions[] = $this->t('@key is @value', ['@key' => ..., '@value' => $shown]);
// After
$context = ['context' => 'data_surface refusal'];
$conditions[] = match (TRUE) {
  !ValueState::isConfigured($held) => $this->t('@key is empty', $args, $context),
  is_bool($held) => $held ? $this->t('@key is on', $args, $context) : $this->t('@key is off', $args, $context),
  default => $this->t('@key is @value', $args, $context),
};
// and the refusal: "@message" then $this->t('This applies because @conditions.', ...)
// with formatPlural() for "and @count more".
```

**Cost.** A behavior change in message wording, plus an amendment to the decision: two sentences per key, not one. Tests that assert exact sentences change.

**Breaks.** No contract changes, since the decision says "this is the message, not the schema". An adopter who matched message text breaks, and should not have done that.

### 3. The React page drops the language it was opened in

**Problem.** The page passes `apiBase => $request->getBasePath() . '/surface-api'` and `pageBase` the same way (`modules/data_surface_react/src/Controller/ResourceReactPage.php:96-97`). A base path never carries the language prefix, which is stripped on the way in. So `/fr/surface-react/registration.step2/configure` fetches `/surface-api/...`, which negotiates the default language. The full schema varies by `languages:language_interface` (`modules/data_surface_react/src/Controller/ResourceApiController.php:157`; `docs/served-contract.md:1056-1057`), but the page never asks for the right variation.

The app's own words are English literals with no `Drupal.t`: "Loading the form…" (`app/src/SurfaceForm.tsx:269`), the saved and valid notices (`:292`, `:294`), and a hand-rolled plural `'One value was refused' : \`${n} values were refused\`` (`:299`). Others are the "Asked the server" reasons (`app/src/contract.ts:858-866`), `SECRET_NOTE` and "This field is required." (`app/src/widgets/Shell.tsx:29`, `:38`), and `` `Add ${title.toLowerCase()}` `` (`app/src/widgets/Field.tsx:305`), which lowercases a German noun and glues it to English. `dist/app.js` contains zero `Drupal.t(` calls. The server half of the same string, `$this->t('Loading the form…')`, is translated (`ResourceReactPage.php:89`), so the page switches language mid-load.

**Change.**

```php
// Before, ResourceReactPage.php:96
'apiBase' => $request->getBasePath() . '/surface-api',
// After: each endpoint built by its route, so the outbound language
// processor adds /fr/; bubble each GeneratedUrl's cacheability
'endpoints' => array_map(
  fn (string $route) => Url::fromRoute($route, ['resource' => $id, 'operation' => $op])->toString(TRUE)->getGeneratedUrl(),
  ['contract' => 'data_surface_react.contract', 'schema' => 'data_surface_react.schema', /* refine, validate, submit */],
),
```

In the app, put all strings in one `strings.ts` that calls `Drupal.t('…')` and `Drupal.formatPlural(…)` with literal arguments, and write the add-row button as `Drupal.t('Add @item', {'@item': title})`. Add a build check that the minified `dist/app.js` still contains literal `Drupal.t('` calls, because locale's JS parser reads the built file.

**Cost.** A rebuild of `dist/` and one `drupalSettings` key (`endpoints` replaces `apiBase`), with no concept and no authoring API change.

**Breaks.** Nothing on the wire. Snapshot tests of English text keep passing on an English site.

### 4. Constraint messages are invisible to translators and untranslated in the contract

**Problem.** A custom message is a bare literal in an options array: `['pattern' => '/^\d{4}$/D', 'message' => 'A licence number is four digits, such as 2048.']` (`modules/data_surface_examples_compliance/src/Shape/Licence.php:25`). The same holds at `DoorsResource.php:46`, `ReviewStructure.php:30` and `PhoneNumber.php:31`. Potx only extracts `t()`, `TranslatableMarkup` and `formatPlural`, so these never reach a `.po` file. Core's validator translator does look them up at runtime, so they become translatable only after a person has hit them once.

The served contract copies the raw option into `x-surface.patternMessage` (`src/Contract/ContractEmitter.php:2917-2919`), so a French schema says the pattern in English while the pipeline's refusal for the same value is in French.

The tool copy respells `{{ max }}` as `%max` (`modules/data_surface_tool/src/InputDefinitions.php:1102-1103`), which creates a second locale source for one message. The decision gives as its reason not to rewrite "what a translator matches on" (`docs/decisions.md:1866-1868`), and the tool copy does exactly that.

**Change.** Let an author write `t()` for a message, and unwrap it at build so the validator's own lookup still matches. Emit `patternMessage` through the same `TranslatableMarkup` the validator would build:

```php
// Before, Licence.php:25
$pattern = ['pattern' => '/^\d{4}$/D', 'message' => 'A licence number is four digits, such as 2048.'];
// After: extractable; the blueprint stores getUntranslatedString() for Symfony
$pattern = ['pattern' => '/^\d{4}$/D', 'message' => t('A license number is four digits, such as 2048.')];
```

```php
// Before, ContractEmitter.php:2919
$keywords[self::EXTENSION . '-pattern-message'] = $options['message'];
// After: the same source the validator translates, in the document's language
$keywords[self::EXTENSION . '-pattern-message'] = PlainText::of(new TranslatableMarkup($options['message']));
```

**Cost.** A small behavior change, since `patternMessage` is now translated, plus one docs paragraph in `docs/semantics.md#violation-messages-are-objects`.

**Breaks.** Nothing for an English site. A client that compared `patternMessage` to the English literal would break.

### 5. The examples and docs teach an English-only site

**Problem.** `docs/first-resource.md` writes its option labels with `t('Weekly')` (`modules/data_surface_first/src/Resource/NewsletterResource.php:42-45`). The examples a reader copies next do not:

- Venues and rooms are `const` arrays of English strings (`modules/data_surface_examples/src/Venues.php:19-35`). They feed `LabeledChoice` in steps 2 and 3 and are never translatable. `LabeledChoiceConstraint.php:66` allows that without a word.
- `t('Up to @seats for the @room.')` (`RegistrationStep3Resource.php:82`) leaves the article outside the placeholder, so French cannot choose le or la.
- `'The @rooms is closed.'` uses `implode(' and the ', $closed)` (`modules/data_surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php:95-99`), which is English grammar inside a value.
- The shipped `data_surface_examples.ticket_types` has `label`-typed values and no `langcode`. `config.typed->get(...)->validate()` answers "must specify a language code, because it contains translatable values".
- "The event title is stored as a `string`, not a translatable `label`" because the classic twin's `#config_target` would demand a language code (`docs/decisions.md:2748-2750`). That reason is gone: the storage writes `langcode` itself (`src/Storage/ConfigObjectStorage.php:137-139`), and the twin only needs `langcode: en` in `config/install`. Meanwhile the one string on the page a translator most needs to translate is invisible to config translation.

No page says what a multilingual site gets. I had to read this from the code:

- `load()` reads `getRawData()` (`ConfigObjectStorage.php:224`). The form, the tool and the contract therefore always show and write the source language, never an override.
- `TicketTypes::keys()` reads `get()` with overrides (`modules/data_surface_examples/src/Keys/TicketTypes.php:49-52`), so key labels are in the request's language.
- Config translation needs a `*.config_translation.yml` mapper on the module's own route (for example `base_route_name: data_surface_first.newsletter`) and `label`/`text` schema types. Its form holds a translation to the config schema at most, never to the resource's constraints. `Length max 64` on `sender_name` does not reach a French translation, and `sender_name` is `string`, so it is not translatable at all.
- Content entity resources load the default translation (`modules/data_surface_entity/src/Storage/ContentEntityStorage.php:116`). `docs/entities.md:215-216` says "the language it loads in", which is always the original.

**Change.**

```php
// Before, Venues.php:19
public const VENUES = ['riverside' => 'Riverside Hall', ...];
// After
public static function venues(): array {
  return ['riverside' => t('Riverside Hall'), 'library' => t('Old Library'), 'harbour' => t('Harbour Center')];
}
```

Fix the other examples in the same way: `t('Up to @seats people in this room.')` with `formatPlural`, one closed-room sentence per room, and `langcode: en` in `ticket_types.yml`. Store the title as `label`. Add "On a multilingual site" to `docs/storage.md`: raw versus overridden reads, the mapper file, and what config translation does not check. Add a PHPStan rule beside `StaticRefinerRule` (`phpstan-rules.php:15-21`) that refuses a literal string as a label, a description or a `LabeledChoice` label.

**Cost.** Docs and examples, plus one rule.

**Breaks.** The examples' contracts are byte-identical in English. `ConfigSchemaPanel` shows `title` as `label`. An adopter who copied `Venues` keeps working.

## 3. Borrow

- **Lazy strings, or a language in the cache key.** This is core's own split. `EntityTypeBundleInfo` and views data cache per language, and every plugin definition keeps `TranslatableMarkup`. The module already has the lazy half everywhere except the seven casts above, so finishing it costs no declaration and no concept.
- **Schema types as the translation signal.** Config translation decides what is translatable from `label` and `text` alone. The contract could state it as `x-surface.translatable: true` on such a key, derived from the schema the storage already reads (`ConfigObjectStorage.php:137`). The author declares nothing new and the settings page stays at four concepts. It is only an annotation, so neither document becomes stricter than the runtime.
- **Context on short strings.** Core says `t('May', [], ['context' => 'Long month name'])`. The engine's "on", "off", "set", "empty" and "and" need the same.
- **`entity.repository->getTranslationFromContext()`.** When the entity side resumes, this gives a content resource the request's translation and keeps access per translation. Until then, `docs/entities.md` should say "the default translation".
- **Locale's JS parser contract.** `Drupal.t` must be called with literals that survive the build. The React door is the only place a build step stands between the source and the extractor.

## 4. Cut

- **`Clause`'s full-stop surgery** (`src/Pipeline/Clause.php:51`). It exists only to make one sentence out of two, and a second whole sentence reads as well in English and translates everywhere.
- **The layout-only sources** `'@label'`, `'@description @note'`, `'@value: @keys'` and `'@message @notes'` (`ResourceOperationToolDeriver.php:166`, `InputDefinitions.php:498`, `:1177`, `ResultReportingTrait.php:121`). They put strings in every `.po` that a translator can do nothing with. Pass the object where there is one. Where there is not, join outside `t()`, or write a real sentence.
- **`PlainTextMarkup` as a re-constructor.** Fold it into a decorator of the original markup (change 1). It stays the one name in the namespaces table and loses the plural bug.
- **Plain-string labels in `LabeledChoice`** (`src/Plugin/Validation/Constraint/LabeledChoiceConstraint.php:66`). Keep accepting them, but flag them through the PHPStan rule, as `StaticRefinerRule` flags a non-static refiner. This is no new concept.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `PlainTextMarkup` | `TranslatablePlainText` | It says what stays true: still translated on read. Concept name, does not cross the product-name rule. |
| `t('on')`, `t('off')`, `t('set')`, `t('empty')`, `t('and')` | `t('@key is on', …, ['context' => 'data_surface refusal'])` and siblings | Bare words collide with every other module's source. |
| `t('choose @choices and @count more')` | `formatPlural($count, 'choose @choices and 1 more', 'choose @choices and @count more')` | `@count` is a number. |
| `t('@lines lines of code.')` (`ExamplesController.php:172` and others) | `formatPlural()` | Same. |
| `Licence`, "Event licence", "Harbour Centre" | `License`, "Event license", "Harbour Center" | A Drupal source string is US English by core's standard. A British source is a second key a translator meets as a near-duplicate. Class rename only, and no wire identifier crosses `#the-product-keeps-its-name`. The shape id `event.licence` is on the wire (a `$defs` key), so it keeps its spelling. |
| `Venues::VENUES` (const) | `Venues::venues()` | A const cannot hold `t()`. |
| "the language it loads in" (`docs/entities.md:215`) | "the default translation" | That is what `load()` returns. |

## 6. Verdict

I would adopt it for a monolingual settings page today, and on a three-language site for the form door only. The form renders every label lazily, and its storage edits the source language exactly as core's config forms do. The caveat is that the tool listing, the MCP listing and the React page each show an agent or a person text in whichever language discovered or defaulted, and refusals are built from fragments no translator can repair. One change would make me adopt every door: removing the seven `(string)` casts in `data_surface_tool` and making `PlainTextMarkup` a decorator, which is a day's work with no new concept. The React prefix fix and contexted refusal sentences would follow without argument.
