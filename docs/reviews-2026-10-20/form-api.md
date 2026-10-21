# Form API review: generated forms, themed and accessible

## Standpoint

I review as a site builder and front-end themer who expects never to hand-write a settings form again, and as an accessibility reviewer who has to sign off on what comes out. I hold the module to one bar: every generated form must be at least as good as the hand-written Form API form it replaces, in markup, in what a themer can control, and in what a keyboard or screen reader user hears.

Everything below was checked against pages fetched with `ddev drush uli` and curl at fad37cc (`/surface-examples/1` to `/11`, `/1/classic`, `/8/desk`, `/10/check`, the two first-resource pages), against AJAX requests posted to them, and against the code. Three probes changed site state briefly and were restored (example 10's workflow state and `published` flag, example 2's stored room); no save went through.

The good news first. Example 1's generated form and its hand-written twin (`/surface-examples/1/classic`) draw the same labels, the same `required` and `min`/`max` attributes, and the same checkbox; the one difference is the classic text field's default `maxlength="128"`, which the generated form drops on purpose (`src/Plugin/DataSurfaceWidget/StringWidget.php:137`). Radios and checkboxes come out as real `fieldset` and `legend` groups (`/surface-examples/11`). Focus after a blur-triggered rebuild works: the `disable-refocus` choice (`src/Form/DataSurfaceFormBuilder.php:1879-1884`) leaves core's `refocus-blur` to put focus back by `data-drupal-selector`, which survives the replacement. The gaps are in what a person is told, and in what a themer can arrange without a form class.

## The five changes that would most improve the authoring experience

### 1. Every error names its field, and an AJAX error is tied to it and announced

**Problem.** On a full submit, `flagSurfaceErrors()` hands Form API the violation's message unchanged (`src/Form/DataSurfaceFormBuilder.php:1654-1659`), and a constraint's stock message names no field. I posted capacity 5000 to `/surface-examples/1`: the error list at the top of the page said "This value should be between 1 and 1000." The same post to `/surface-examples/1/classic` said "Capacity must be lower than or equal to 1000." Inline Form Errors is not enabled on this site, so the list at the top is all a person gets. `aria-invalid` marks the input, but the message does not identify the item in error (WCAG 3.3.1). The 2026-10-19 fresh-eyes log (`docs/fresh-eyes-2026-10-19b.md:34`) quotes the same sentence without flagging it. On the AJAX path, the trigger's error is printed in the element's error slot (`src/Hook/InlineErrorHooks.php:55-58`). But in the response I captured for example 3's license field, the input's `aria-describedby` names only the description, and the error `div` has no id. The response holds only replace and remove commands (`src/Form/DataSurfaceFormBuilder.php:1036-1065`). A screen reader user who tabbed on is never told the value was refused.

I agree with [An element hints and the rule speaks](../decisions.md#an-element-hints-and-the-rule-speaks): one check, one message. The fault is in how the message is presented, not in which check sent it. The tool shows the violation's path beside its message. The form's error summary drops the path.

**Change.** Keep the violation as it is. When the form prints it where the element is not beside it, prefix the element's title. Tie an inline error to its input and announce it.

```php
// Before: src/Form/DataSurfaceFormBuilder.php:1654-1656
if (is_array($element) && isset($element['#parents'])) {
  $form_state->setError($element, $violation->message);
}
```

```php
// After
if (is_array($element) && isset($element['#parents'])) {
  // "Capacity: This value should be between 1 and 1000." unless the
  // message already names the element, or Inline Form Errors prints it
  // under the element and links it from its own summary.
  $form_state->setError($element, $this->named($element, $violation->message));
}
// In refreshSurface(), beside each ReplaceCommand of a trigger that
// carries an inline error: an id on the error, added to the input's
// aria-describedby, and
$response->addCommand(new AnnounceCommand($this->named($touched, $message), 'assertive'));
```

**Cost.** A behavior change on the form only. No new concept, no rename. **Breaks.** Tests that assert the exact text of form errors (`RefusalMessagesTest`, named in `docs/decisions.md:2245` and `:2266`). Nothing in either schema, the tool or React changes.

### 2. Declare the arrangement once, for every host and the React page

**Problem.** Grouping, order, vertical tabs, a suffix and a placeholder all live in `alterSurfaceForm()` of a cosmetic layer, and only `DataSurfaceOperationForm` calls one (`src/Form/DataSurfaceOperationForm.php:93`, `:219-222`). A block, condition, formatter or field type form has no seam short of `hook_form_alter()`. The React page draws from the contract and never sees the layer. The one shipped layer documents a "render-order landmine" and hand-tuned weights 10, 11, 12, 13 and 20 to work around it (`modules/data_surface_demo_node_type/src/Form/NodeTypeResourceFormCosmetics.php:44-91`). The placeholder has no spelling of its own either. It is always the first declared example (`src/Plugin/DataSurfaceWidget/StringWidget.php:59-62`, `NumberWidget.php:54-57`). On `/surface-examples/8/desk`, all three phone inputs show "+1 555 0100" whether or not they hold a value, and the two empty ones look filled. An author cannot give the contract an example without also giving the form a placeholder.

The module has already crossed this line twice. `setSetting('widget', 'radios')` is a presentation choice made on the definition, defended as "the page's layout and its author's call" (`docs/decisions.md:2362-2366`) and emitted as `x-surface.widget` only when a renderer asks (`src/Contract/ContractEmitter.php:104-108`, `:233-236`). `describe(..., after:)` places a fieldset, but only for an alter's own mount (`src/Resource/BlueprintAdditionsInterface.php:124`).

**Change.** Open that vocabulary to the owner and widen it a little: a named group, a key's group, `after:` for any key, a placeholder, a prefix and suffix. All of it is emitted under the same opt-in renderer hints. The cosmetic layer keeps the message, the redirect, and an escape hatch for what is truly bespoke (the machine name mirror).

```php
// Before: NodeTypeResourceFormCosmetics.php:66-71, 88-91
$form[$key]['workflow'] = [
  '#type' => 'details',
  '#title' => $this->t('Publishing options'),
  '#group' => $key . '][additional_settings',
  '#weight' => 12,
];
foreach ($groups as $name => $group) {
  $form[$key][$name]['#group'] = $key . '][' . $group;
  $form[$key][$name]['#weight'] = 20;
}
```

```php
// After: NodeTypeResource::defineInputs(), beside NodeTypeResource.php:125
$inputs->group('workflow', t('Publishing options'), as: 'tab');
$inputs->add('status', 'boolean', t('Published'), default: TRUE)
  ->setDescription(t('Whether new content of this type is published by default.'))
  ->setSetting('group', 'workflow');
```

**Cost.** One new optional concept (display hints) and one new blueprint method. The concept budget holds: a settings page uses none of it. **Breaks.** Nothing for the examples' contracts, since the hints appear only when `emit()` is asked for widgets. Adopters' cosmetic layers keep working.

### 3. A rebuild says what it changed

**Problem.** I posted example 7's frequency as Monthly over AJAX. The response replaced Send day's wrapper with an empty `div` and redrew Items per issue with `max="20"` in place of 5. The description "Up to 5 in a weekly issue." was gone. No command said any of it. A sighted person sees a field vanish. A screen reader user hears nothing (WCAG 4.1.3). Nothing in `src/` uses `AnnounceCommand`, `Drupal.announce` or a live region. Someone used to `#states` meets two more surprises. A text trigger changes its dependents only on blur, after focus has moved on (`docs/forms.md:207-217`). And a dependent's unsaved input does not survive the round trip. I chose Monday, switched to Monthly, then back to Weekly, and Send day came back as the stored Friday. A `#states`-hidden select would have kept Monday. [A key that does not apply](../decisions.md#a-key-that-does-not-apply) is right that the key gets no element. The fault is only that the change is silent.

**Change.** The callback already knows the closure it replaces (`TRIGGER_KEY`), which keys are ruled out (`PLACEHOLDER_KEY`), and the options the trigger was drawn with (`RENDERED_OPTIONS`). Record, the same way, whether each dependent applied, was required and what range it had. Then announce the difference politely, once per rebuild.

```php
// Before: src/Form/DataSurfaceFormBuilder.php:1036-1039
$response = new AjaxResponse();
foreach ($elements as $id => $element) {
  $response->addCommand(new ReplaceCommand('#' . $id, $element));
}
```

```php
// After
$response = new AjaxResponse();
foreach ($elements as $id => $element) {
  $response->addCommand(new ReplaceCommand('#' . $id, $element));
}
// "Send day no longer applies. Items per issue now allows 1 to 20."
if ($said = static::changesSaid($rendered, $elements)) {
  $response->addCommand(new AnnounceCommand($said));
}
```

Add a "Coming from `#states`" section to `docs/forms.md` covering the server round trip, the blur timing and the dropped unsaved input.

**Cost.** A behavior change and a handful of translatable sentences. No new concept. **Breaks.** Nothing outside the form.

### 4. The stale select and the required marker tell the truth

**Problem.** I stored a room example 2's venue no longer offers. The form drew Room as `- Select -`, selected. Its label carries the required asterisk class, but the select has no `required` attribute (`src/Plugin/DataSurfaceWidget/OptionsWidget.php:139-152`), and nothing on the page says a value is kept. Leaving it keeps the hidden value (`src/Widget/DataSurfaceWidgetBase.php:142-146`). So the screen shows three different things: "- Select -" asks for a choice, the asterisk says one is required, and assistive technology says none is. The truth is a fourth thing: a value nobody can see will be saved. Lists have the opposite fault. Days is `setRequired(TRUE)` (`modules/data_surface_examples/src/Resource/DoorsResource.php:53-56`), yet its legend has no marker, because the checkboxes widget never draws one (`CheckboxesWidget.php:94`). A dry run of the doors tool with `"days": []` was accepted. To a site builder, "required" there reads as "at least one day".

[The empty option rule](../decisions.md#the-empty-option-rule) rejected "Previous value X is no longer available" because the option posted a sentinel that was no value of the key (`docs/decisions.md:2333-2338`). This change posts `''` as today. Only the words change.

**Change.**

```php
// Before: OptionsWidget.php:139-141
$element['#default_value'] = $chosen ? $value : '';
$element['#empty_value'] = '';
$element['#empty_option'] = $holds ? $this->t('- Select -') : $this->t('- None -');
```

```php
// After
$element['#default_value'] = $chosen ? $value : '';
$element['#empty_value'] = '';
$element['#empty_option'] = match (TRUE) {
  $configured && !$chosen => $this->t('- Keep @value (no longer offered) -', ['@value' => $value]),
  $holds => $this->t('- Select -'),
  default => $this->t('- None -'),
};
// And the marker follows what the rule asks of a person: on a list,
// drawn when a Count asks for at least one, never for setRequired().
```

**Cost.** Wording, and one marker rule for lists; the served contract's `emptyOption` should carry the same words so React agrees. **Breaks.** Tests asserting `- Select -` on a stale select, and React parity tests.

### 5. A locked form reads as a record and gives the reason it was handed

**Problem.** With example 10 published in its workflow, the page said the reason once at the top (`src/Form/DataSurfaceOperationForm.php:217`). Below it, every element was `disabled` and carried "Fixed for this operation." four times. Headline still had `required="required"` and its asterisk. Disabled controls are skipped by Tab, so a keyboard user cannot reach the values except in browse mode. With only the fees locked, `FeesAccess` answered "The fees are fixed once the program is published." (`modules/data_surface_examples/src/Access/FeesAccess.php:57`), yet the Fees fieldset said "Fixed for this operation." The reason sits on the child's sealed surface (`src/DataSurfaceInterface.php:193`), but `describeLocked()` writes a constant (`src/Form/DataSurfaceFormBuilder.php:2154-2163`, called at `:285`). Separately, `/surface-examples/10/check` serves a `readOnly` operation behind a "Save" button (`DataSurfaceOperationForm.php:212`).

[Locked at the top](../decisions.md#locked-at-the-top) settles that a locked resource is shown, fixed, with no Save. I keep that. A form with nothing to submit is a record, though, and should be drawn as one.

**Change.**

```php
// Before: src/Form/DataSurfaceFormBuilder.php:279-285
if ($locked) {
  $element['#disabled'] = TRUE;
  $element = static::describeLocked($element);
}
```

```php
// After
if ($locked) {
  $reason = $definitions->entry($name)?->attachment?->child->lockedWhole();
  $element = $surface->lockedWhole() !== NULL
    // Locked whole: read-only text inputs (focusable, announced), no
    // required marker, no note per element; the reason is said once.
    ? static::asRecord($element)
    : static::describeLocked(['#disabled' => TRUE] + $element, $reason);
}
```

Also label the button of a `readOnly` operation with the operation's label ("Check a program").

**Cost.** A behavior change on the form. **Breaks.** `LockedAccessFormTest`, which asserts `disabled`. The contract already says `readOnly`.

## Borrow

**Drupal's own form display.** Core already generates forms for entities, and site builders already arrange them in Manage form display. Each field has a weight, a widget and widget settings (placeholder, size, rows). field_group adds tabs, details and fieldsets. None of it changes what a value means. That is exactly the cosmetic layer's promise, but declared, so it reaches every host. It fits this module's constraints. The hints are static declarations, read once at build. Refinement never touches them. They are not rules, so neither document can become stricter than the runtime. They travel only under the renderer's opt-in, as `x-surface.widget` already does. A settings page needs none of it, so the budget of four concepts holds.

**JSON Forms and react-jsonschema-form's UI schema.** These keep order, groups, placeholder and help apart from the data schema. That is the split this module already makes with `emit($widgets)`. Borrow the vocabulary (order, group, placeholder, help), not a second document.

**The GOV.UK error pattern.** Every error message names its field. A summary at the top links to each field. A field that is revealed or removed is announced. Inline Form Errors does the summary when it is enabled, so changes 1 and 3 should defer to it when it is on and do the work themselves when it is off.

**What Form API itself would have to grow** for generated forms to be the norm. The module works around each of these today:

- **`#required` as a hint with no check.** Today this is simulated by clearing the flag, marking the element and restoring it in `#pre_render` (`src/Form/DataSurfaceFormBuilder.php:1186-1213`).
- **A way to opt an element out of its type's own validation as a set.** Today `#element_validate` and `#maxlength` are cleared by hand (`NumberWidget.php:42`, `StringWidget.php:137`).
- **Partial rebuilds with stable wrapper ids.** Today the container id is posted back because `Html::getUniqueId()` randomizes ids on AJAX (`docs/forms.md:267-274`), and wrappers are built from string `#prefix` (`src/Form/DataSurfaceFormBuilder.php:1384-1388`). The same applies to a server-side `#states`.
- **An error that does not cancel the rebuild** (`docs/forms.md:276-295`).
- **A `#limit_validation_errors` that does not discard the other values** (`docs/forms.md:399-404`).
- **Error ids in `aria-describedby`.**
- **An `#empty_option` that means "keep".**

## Cut

- **The special case for placing a mounted fieldset.** Once owners can order keys (change 2), the render-time move that only an alter's own mount gets (`PLACED_AFTER_KEY`, `placeMounts()` at `src/Form/DataSurfaceFormBuilder.php:1252`, `docs/forms.md:148-180`) becomes the general `after:`. One ordering mechanism, not two.
- **Arrangement from `DataSurfaceFormCosmeticsInterface`.** The Cosmetic layer row in `docs/concepts.md` narrows to the message, the redirect and an escape hatch. Grouping and weights move to the display hints, so the concept count does not grow.
- **The note on every locked element.** Say the reason once, where it belongs (change 5).
- **The raw wrapper `div`s that only some keys get.** Keys that take part in refinement are wrapped in an unclassed `div` from `#prefix` (`src/Form/DataSurfaceFormBuilder.php:1384-1388`); the others are not. On `/surface-examples/2`, Title has no wrapper and Venue does, so sibling CSS behaves differently from key to key. Wrap every key, with a class naming the key, or none at all.

## Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| Cosmetic layer, `_data_surface_cosmetics` | Form display, `_data_surface_display` | Drupal's word for arranging a generated form; "cosmetic" sounds optional to the themer whose job it is. Keeps the `data_surface` prefix, so it does not cross [the product keeps its name](../decisions.md#the-product-keeps-its-name). |
| "Fixed for this operation." | The access answer's reason; failing one, "Set by this operation and cannot be changed here." | The constant hides the reason the access class wrote (`src/Form/DataSurfaceFormBuilder.php:2155`). |
| `- Select -` on a stale select | `- Keep @value (no longer offered) -` | It stands for a kept value, not an empty choice (change 4). |
| "Volunteers 1", "Greeters 2" | "Volunteers, item 1" | Plural label plus a number (`src/Form/DataSurfaceFormBuilder.php:447`, `ListWidget.php:144`). |
| "Remove" | "Remove Greeters 2", the suffix visually hidden | On `/surface-examples/11`, two buttons share one accessible name (`ListWidget.php:299`). |
| "Save" on a `readOnly` operation | The operation's label, "Check a program" | Nothing is saved (`DataSurfaceOperationForm.php:212`, `:266`). |
| Options "free" and "paid" | Refuse a bare `Choice` on a drawn key, or give `#[Variant]` a label | Example 3 draws the machine values as labels (`modules/data_surface_examples/src/Resource/RegistrationStep3Resource.php:54-56`). `Variant` has no label to fall back on (`src/Resource/Attribute/Variant.php:29-33`). |
| `setRequired(TRUE)` on a list, in docs | "Present", with "at least one" spelled as a `Count` | A site builder reads "required" as Form API's non-empty (change 4). |

## Verdict

I would adopt it today for admin settings pages and plugin settings forms. The markup matches a hand-written form, radios and checkboxes are properly grouped, and I never write validate or submit code again. The caveat: I would not ship it on a public form or one that faces a WCAG audit until errors name their fields and AJAX rebuilds announce what they change, because today both are a step back from the classic form. The one thing that would change my answer from "admin pages" to "every form" is change 2: arrangement declared once, so it reaches blocks, conditions, formatters and the React page, not only an operation route's form class.
