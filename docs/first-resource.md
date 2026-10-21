# Your first resource

A settings page, from nothing: a newsletter's sender, how often it goes
out and how many recipients one send reaches. You write one class that
says what the settings are, and the module derives the form, the tool an
agent calls and the JSON Schema from it.

This page uses four things and nothing else:

| You write | It says |
| --- | --- |
| `#[Resource]` | what the thing is called and where it is stored |
| `#[Operation]` | how it is asked for, what that does, and the permission that gates it |
| a storage | the config object it lives in |
| `defineInputs()` with `add()` | its keys: name, type, label, what each allows |

Every file below is the shipped module `data_surface_first`, word for
word; a test fails if the page and the module differ, so what you copy
works. The module also holds [Your second resource](second-resource.md),
which this page does not need.

```
data_surface_first/
  data_surface_first.info.yml
  data_surface_first.permissions.yml
  data_surface_first.routing.yml
  config/install/data_surface_first.newsletter.yml
  config/schema/data_surface_first.schema.yml
  src/Resource/NewsletterResource.php
  src/Storage/NewsletterStorage.php
```

## 1. The module

Any module. It needs Data Surface and nothing else:

<!-- lines: modules/data_surface_first/data_surface_first.info.yml -->

```yaml
name: Data Surface - Your first resource
type: module
package: Data Surface
core_version_requirement: ^11.3 || ^12
dependencies:
  - data_surface:data_surface
```

## 2. Where the settings live

The settings are a simple config object, so they are described the way
any Drupal config is: a config schema, and the values the module ships
with. You write neither by hand: once the resource in step 4 exists,
`drush data_surface:config-schema --module=data_surface_first --write`
writes both from it.

<!-- yaml: modules/data_surface_first/config/schema/data_surface_first.schema.yml#data_surface_first.newsletter -->

```yaml
data_surface_first.newsletter:
  type: config_object
  label: 'Newsletter settings'
  constraints:
    FullyValidatable: ~
  mapping:
    sender_name:
      type: string
      label: 'Sender name'
      constraints:
        Length:
          max: 64
    sender_email:
      type: email
      label: 'Sender email'
    frequency:
      type: string
      label: 'Frequency'
    max_recipients:
      type: integer
      label: 'Maximum recipients per send'
      nullable: true
      constraints:
        Range:
          min: 1
          max: 10000
```

<!-- code: modules/data_surface_first/config/install/data_surface_first.newsletter.yml -->

```yaml
sender_name: 'The newsletter'
sender_email: newsletter@example.com
frequency: weekly
max_recipients: 500
```

The schema matters: every save is checked against it before anything is
written, and a key it does not describe is refused.

## 3. The storage

<!-- code: modules/data_surface_first/src/Storage/NewsletterStorage.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the newsletter settings in their config object.
 */
final class NewsletterStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_first.newsletter';

}
```

`ConfigObjectStorage` reads the object, checks each save against the
schema above, and writes it. The subclass names the object, and that is
all it does. It lives in `src/Storage/` and nothing registers it: the
resource names it, and that is how it is found.

## 4. The resource

<!-- code: modules/data_surface_first/src/Resource/NewsletterResource.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_first\Storage\NewsletterStorage;

/**
 * The newsletter settings: a form, a tool and a schema, from this class.
 */
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  description: new TranslatableMarkup('Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.'),
  permission: 'administer data_surface_first',
)]
final class NewsletterResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sender_name', 'string', t('Sender name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);
    $inputs->add('sender_email', 'email', t('Sender email'))
      ->setRequired(TRUE);
    $inputs->add('frequency', 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'weekly' => t('Weekly'),
          'monthly' => t('Monthly'),
        ],
      ]);
    $inputs->add('max_recipients', 'integer', t('Maximum recipients per send'), default: 500)
      ->setDescription(t('Larger lists are sent in batches of this size.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
  }

}
```

Read it top to bottom:

- **`#[Resource('first.newsletter', storage: ...)]`** names the resource
  and its storage. The class goes in `src/Resource/`, where it is found;
  nothing is registered by hand.
- **`#[Operation('configure', ...)]`** is the one way it is asked for,
  on the class beside `#[Resource]`. There is only one newsletter, so
  asking for it needs nothing more than the attribute says, and there is
  no method to write. The label is the tool's title and the schema's
  title, the description is the first thing an agent reads about the
  tool and the schema's description, and the permission is the one the
  route and the tool both check (step 5).
- **`defineInputs()`** declares the keys. `add()` takes the key, its type
  (core's typed data types: `string`, `email`, `integer`, `boolean`,
  ...), its label and, optionally, a `default:`, and returns core's own
  data definition, so the rest is core API: `setRequired()`,
  `setDescription()`, `addConstraint()`.
- **The label** is the third argument of `add()`. It is the form
  element's title, the tool's property label and the schema's `title`;
  `setDescription()` is the text under the element and the property's
  description.
- **What a key allows** is a constraint. `Length` becomes a maximum
  length, `Range` a number field bounded at 1 and 10000, and
  `LabeledChoice` a select whose options are its labels. The form, the
  tool and the schema all read the same constraint, and a value outside
  it is refused with the constraint's message, wherever it came from.
- **Everything is static.** The class is never instantiated, so there is
  nothing to inject a translation service into: labels call the global
  `t()` with a literal string. An attribute argument cannot call a
  function, so the operation's label and description are
  `new TranslatableMarkup()`.

## 5. The permission

The operation names a permission; the module declares it, as any module
declares a permission:

<!-- code: modules/data_surface_first/data_surface_first.permissions.yml -->

```yaml
administer data_surface_first:
  title: 'Configure the newsletter and the store location'
  restrict access: true
```

## 6. The route

A route names the resource class and the operation, and the generic
operation form serves it. There is no form class:

<!-- yaml: modules/data_surface_first/data_surface_first.routing.yml#data_surface_first.newsletter -->

```yaml
data_surface_first.newsletter:
  path: '/admin/config/services/surface-newsletter'
  defaults:
    _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
    _title: 'Newsletter settings'
    _data_surface_resource: 'Drupal\data_surface_first\Resource\NewsletterResource'
    _data_surface_operation: 'configure'
  requirements:
    _data_surface_operation_access: 'TRUE'
```

`_data_surface_operation_access: 'TRUE'` asks the operation who may use
it: its permission. A person without it gets a 403, and the tool refuses
them the same way; the tool declares it, so `drush tool:info` prints it.

## 7. Try it

```bash
drush pm:install data_surface_first
```

On a site run with DDEV, every `drush` command on this page is
`ddev drush`, from the project root.

Visit `/admin/config/services/surface-newsletter` as someone with the
permission. The form has a text field for the sender's name, at most 64
characters; an email field; a select of Weekly and Monthly; and a number
field bounded at 1 and 10000. Leave the name empty, or ask for 20000
recipients, and Save marks the field, says what is wrong in the rule's
own words ("This value should be between 1 and 10000."), and writes
nothing.

With the Tool API bridge enabled, the same resource is a tool:

```bash
drush pm:install data_surface_tool
drush tool:info data_surface:first.newsletter:configure
drush tool:run data_surface:first.newsletter:configure --uid=1 --input='{"values":{"sender_name":"Weekly news","sender_email":"news@example.com","frequency":"monthly"}}'
drush tool:run data_surface:first.newsletter:configure:read --uid=1
```

`tool:info` lists the same four keys, bounded as the class says. The
operation changes settings that exist, so the tool requires none of
them: the three the class requires say "Required; a value already
stored stands when left out." in their descriptions. `values` itself is
always sent, `{}` when nothing changes. The write answers, in the one
result shape every door answers
([decision](decisions.md#one-result-at-every-door)):

```
Success: Configure the newsletter: the values were accepted and written.

Outputs:
  ok: true
  committed: true
  changed: true
  changes: ["sender_name","sender_email","frequency"]
  values: {"sender_name":"Weekly news","sender_email":"news@example.com","frequency":"monthly","max_recipients":500}
  violations: []
  notes: []
  stale: []
```

The key left out, `max_recipients`, kept what was stored. A key the
class requires may be left out too once a value for it is stored, and
the stored value stands. Where nothing is stored for it, on an
operation that creates or for a thing that has no value yet, it cannot
be left out: the resource refuses the call in the key's own words,
"Sender name is required.", and nothing is written. The read tool
beside the write tool answers with what is stored now:

```
Success: Configure the newsletter: the stored values were read, and nothing was written.

Outputs:
  values: {"sender_name":"Weekly news","sender_email":"news@example.com","frequency":"monthly","max_recipients":500}
```

`--uid=1` because `tool:run` runs as anonymous unless told otherwise,
and anonymous does not have the permission.

You wrote no form, no validation handler, no tool and no schema: the
form, the tool and the schema came for free, from the one class.

## Next

- [Your second resource](second-resource.md): one key whose allowed
  values depend on another's.
- [Concepts](concepts.md): every other idea in the module, the question
  that makes you need it, and how to tell you do not.
- [Common settings recipes](recipes.md): a time of day, a multi-select,
  a text area and a few more, one snippet each.
