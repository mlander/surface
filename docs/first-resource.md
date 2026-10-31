# Your first resource

A settings page, from nothing: a newsletter's sender, how often it goes
out and how many recipients one send reaches. You write one class that
says what the settings are, and the module derives the form, the tool an
agent calls and the JSON Schema from it.

A resource, its keys, an operation and a storage are each introduced in
plain words on a page of their own:
[Resources](introduction/02-resources.md),
[Keys](introduction/05-keys.md), [Operations](introduction/09-operations.md)
and [Storage](introduction/10-storage.md). This page shows every file
in full, so you see each piece. You write the resource, the storage,
the permission and the route. The config schema and the install file
are generated from the resource class by a command, which also fails
when they drift from it, so they repeat the class's facts without being
a second description. A command that writes all of the files for you
from an interview follows, at the end of step 7.

This page uses four things and nothing else:

| You write | It says |
| --- | --- |
| `#[Resource]` | what the thing is called and where it is stored |
| `#[Operation]` | how it is asked for, what that does, and the permission that gates it |
| a storage | the config object it lives in |
| `defineInputs()` with `add()` | its keys: name, type, label, what each allows |

Every file below is the shipped module `surface_examples_first`, word for
word; a test fails if the page and the module differ, so what you copy
works. The module also holds [Your second resource](second-resource.md),
which this page does not need.

```
surface_examples_first/
  surface_examples_first.info.yml
  surface_examples_first.permissions.yml
  surface_examples_first.routing.yml
  config/install/surface_examples_first.newsletter.yml
  config/schema/surface_examples_first.schema.yml
  src/Resource/NewsletterResource.php
  src/Storage/NewsletterStorage.php
```

## 1. The module

Any module. It needs Surface API and nothing else:

<!-- lines: modules/surface_examples_first/surface_examples_first.info.yml -->

```yaml
name: 'Surface API examples: your first resource'
type: module
core_version_requirement: ^11.3 || ^12
dependencies:
  - surface:surface
```

## 2. Where the settings live

The settings are a simple config object, so they are described the way
any Drupal config is: a config schema, and the values the module ships
with. You write neither by hand: once the resource in step 4 exists,
`drush surface:config-schema --module=surface_examples_first --write`
writes both from it, before the module is installed (step 7).

<!-- yaml: modules/surface_examples_first/config/schema/surface_examples_first.schema.yml#surface_examples_first.newsletter -->

```yaml
surface_examples_first.newsletter:
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

<!-- code: modules/surface_examples_first/config/install/surface_examples_first.newsletter.yml -->

```yaml
sender_name: 'The newsletter'
sender_email: newsletter@example.com
frequency: weekly
max_recipients: 500
```

The schema matters: every save is checked against it before anything is
written, and a key it does not describe is refused.

## 3. The storage

<!-- code: modules/surface_examples_first/src/Storage/NewsletterStorage.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Keeps the newsletter settings in their config object.
 */
final class NewsletterStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples_first.newsletter';

}
```

`ConfigObjectStorage` reads the object, checks each save against the
schema above, and writes it. The subclass names the object, and that is
all it does. It lives in `src/Storage/` and nothing registers it: the
resource names it, and that is how it is found.

## 4. The resource

<!-- code: modules/surface_examples_first/src/Resource/NewsletterResource.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_first\Storage\NewsletterStorage;

/**
 * The newsletter settings: a form, a tool and a schema, from this class.
 */
#[Resource('first.newsletter',
  label: new TranslatableMarkup('Newsletter settings'),
  storage: NewsletterStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  description: new TranslatableMarkup('Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.'),
  permission: 'administer surface_examples_first',
)]
final class NewsletterResource implements ResourceInterface {

  public const SENDER_NAME = 'sender_name';
  public const SENDER_EMAIL = 'sender_email';
  public const FREQUENCY = 'frequency';
  public const MAX_RECIPIENTS = 'max_recipients';

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add(self::SENDER_NAME, 'string', t('Sender name'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 64]);

    $inputs->add(self::SENDER_EMAIL, 'email', t('Sender email'))
      ->setRequired(TRUE);

    $inputs->add(self::FREQUENCY, 'string', t('Frequency'), default: 'weekly')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'weekly' => t('Weekly'),
          'monthly' => t('Monthly'),
        ],
      ]);

    $inputs->add(self::MAX_RECIPIENTS, 'integer', t('Maximum recipients per send'), default: 500)
      ->setDescription(t('Larger lists are sent in batches of this size.'))
      ->addConstraint('Range', ['min' => 1, 'max' => 10000]);
  }

}
```

Read it top to bottom:

- **`#[Resource('first.newsletter', label: ..., storage: ...)]`** names
  the resource, says what it is called, and names its storage. The id is
  the resource's own machine name and needs no module prefix; the
  config object is `surface_examples_first.newsletter` because Drupal
  requires a module's config object names to start with the module's
  name. The label is what the catalog and other lists print beside the
  id. The class goes in `src/Resource/`, where it is
  found; nothing is registered by hand.
- **`#[Operation('configure', ...)]`** is the one way it is asked for,
  on the class beside `#[Resource]`. There is only one newsletter, so
  asking for it needs nothing more than the attribute says, and there is
  no method to write. The label is the tool's title and the schema's
  title, the description is the first thing an agent reads about the
  tool and the schema's description, and the permission is the one the
  route and the tool both check (step 5).
- **The constants** name each key once, and every later mention is the
  constant, `self::SENDER_NAME`, so a key can be renamed or its uses
  found ([Key names](declaring-a-resource.md#key-names)).
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
- **Everything is static.** The class is never instantiated, so labels
  call the global `t()` and attribute arguments construct
  `new TranslatableMarkup()`, and the module can read the class ahead of
  any request ([Translatable strings](declaring-a-resource.md#translatable-strings),
  [Nothing live in the blueprint](declaring-a-resource.md#nothing-live-in-the-blueprint)).

## 5. The permission

The operation names a permission; the module declares it, as any module
declares a permission:

<!-- code: modules/surface_examples_first/surface_examples_first.permissions.yml -->

```yaml
administer surface_examples_first:
  title: 'Configure the newsletter and the store location'
  restrict access: true
```

## 6. The route

A route names the resource class and the operation, and the generic
operation form serves it. There is no form class:

<!-- yaml: modules/surface_examples_first/surface_examples_first.routing.yml#surface_examples_first.newsletter -->

```yaml
surface_examples_first.newsletter:
  path: '/admin/config/services/surface-newsletter'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _title: 'Newsletter settings'
    _surface_resource: 'Drupal\surface_examples_first\Resource\NewsletterResource'
    _surface_operation: 'configure'
```

A route that names a resource and an operation asks the operation who
may use it: its permission. A person without it gets a 403, and the
tool refuses them the same way; the tool declares it, so `drush
tool:info` prints it.

## 7. Try it

```bash
drush surface:config-schema --module=surface_examples_first --write
drush pm:install surface_examples_first
```

The command reads the module's classes whether it is installed or not,
so write the files first and install once, and run it again without
`--write` after any change to the resource: it prints what drifted and
fails until you write again.

On a site run with DDEV, every `drush` command on this page is
`ddev drush`, from the project root.

Visit `/admin/config/services/surface-newsletter` as someone with the
permission. The form has a text field for the sender's name, at most 64
characters; an email field; a select of Weekly and Monthly; and a number
field bounded at 1 and 10000. Leave the name empty, or ask for 20000
recipients, and Save marks the field, says what is wrong in the rule's
own words ("This value should be between 1 and 10000."), and writes
nothing.

With the `tool` module installed, the same resource is a tool:

```bash
drush pm:install tool
drush tool:info surface:first.newsletter:configure
drush tool:run surface:first.newsletter:configure --uid=1 --input='{"values":{"sender_name":"Weekly news","sender_email":"news@example.com","frequency":"monthly"}}'
drush tool:run surface:first.newsletter:configure:read --uid=1
```

`tool:info` lists the same four keys, bounded as the class says. The
operation changes settings that exist, so the tool requires none of
them: the three the class requires say "Required; a value already
stored stands when left out." in their descriptions. `values` itself is
always sent, `{}` when nothing changes. The write answers, in the one
result shape every door answers
(why: [decision](decisions.md#one-result-at-every-door)):

```
Success: Configure the newsletter: the values were accepted and written.

Outputs:
  ok: true
  committed: true
  changed: true
  changes: ["/sender_name","/sender_email","/frequency"]
  values: {"sender_name":"Weekly news","sender_email":"news@example.com","frequency":"monthly","max_recipients":500}
  violations: []
  notes: []
  stale: []
```

The key left out, `max_recipients`, kept what was stored. A key sent
`null` is cleared instead, unless its storage always holds it, and then
the call is refused: here, every key the schema in step 2 does not mark
`nullable: true`
([Absent, null, or a value](semantics.md#absent-null-or-a-value)). A key the
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

**The one command.** `drush generate surface:resource` writes every file
on this page from an interview: the module (any in the code base), the
resource's id and label, the operation, the config object, the
permission and the route path, then each key in turn, with its type,
label, whether it is required, its default and what it allows. An answer
can be given ahead, in the order asked, with `--answer`: `drush generate
surface:resource --answer=surface_examples_first --answer=first.newsletter
--answer='Newsletter settings'` asks only from the class name on, and
`--dry-run` prints the files without writing them. A routing or
permissions file the module has gains the new entry and keeps every
other. This page still shows each file in full and stays the source of
truth: a test answers the interview as the newsletter needs and fails
when a single byte it writes differs from the files above. The keys can
also come from a config schema entry or a JSON Schema document
([Generating a resource](generating.md)).

## Next

- [The site's catalog](catalog-command.md): check that the resource is
  found and see which doors it reaches.
- [Your second resource](second-resource.md): one key whose allowed
  values depend on another's.
- [Concepts](concepts.md): every other idea in the module, the question
  that makes you need it, and how to tell you do not.
- [Common settings recipes](recipes.md): a time of day, a multi-select,
  a text area and a few more, one snippet each.
