# What Data Surface does

You describe once what a thing on your site accepts: each value it
holds, its type, its label, its default and what it allows. From that
one description the module makes the form, a Tool API tool for AI
agents, the HTTP API and the React page. They all accept the same
values, because they all read the same description.

![The performance settings, system.performance, described once with three values on the left; arrows to a form, a Tool API tool, an HTTP API and a React page on the right, each asking for the same three values](images/01-what-it-does.svg)

## The problem it removes

A Drupal settings form today describes its values in several places.
The defaults are in the config install file, the labels and widgets in
`buildForm()`, what is allowed in `validateForm()`, and the types in the
config schema. A REST resource or a tool for an AI agent on the same
settings describes them again. When one copy changes and another does not, the
tool accepts a value the form refuses.

## One description

Here are core's performance settings, `system.performance`, described
once. This is `PerformanceResource`, from this module's
`data_surface_performance` submodule, shortened:

```php
#[Resource('system.performance', /* ... */)]
final class PerformanceResource implements ResourceInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('max_age', 'integer', t('Browser and proxy cache maximum age'), default: 0);
    $inputs->add('css_preprocess', 'boolean', t('Aggregate CSS files'), default: TRUE);
    $inputs->add('js_preprocess', 'boolean', t('Aggregate JavaScript files'), default: TRUE);
    // ... and what each one allows.
  }

}
```

Where each class lives: [Namespaces](../concepts.md#namespaces).

`$inputs`, a `BlueprintInterface`, is the object the class adds its
keys to. There is no form class, no validation handler and no tool
class. For a config object your module owns, a Drush command generates
the config schema and the install file from the class and fails when
they drift from it, so they repeat the class's facts without being a
second description. The module makes the rest from this class:

| What you get | From the same three values |
| --- | --- |
| A form | a select of cache periods and two checkboxes |
| A Tool API tool | `data_surface:system.performance:configure`, which an AI agent calls |
| An HTTP API | `PATCH /surface-api/system.performance`, with a JSON Schema of the values |
| A React page | a form drawn in the browser from that JSON Schema |

The select comes from what `max_age` allows, which the shortened class
leaves out ([Rules on a key](04-rules-on-a-key.md) shows that line).
The form is served on a route: a routing entry that names the class and
the way of asking for it, which the `data_surface_performance`
submodule ships ([Operations](07-operations.md) shows one).

The tool, the HTTP API and the React page each come from a submodule
you install when you want it. A later page lists them
([Doors](11-doors.md)).

A value the description does not allow is refused at every one of them,
with the same message, because each one checks against the same
description. The module calls that description, once it is complete, a
data surface; [Sealed](15-sealed.md) shows how it is made.

## Three words to come

The next pages give each part of this a name:

- The thing described, here the performance settings, is a
  **resource** ([Resources](02-resources.md)).
- Each value it holds, such as `max_age`, is a **key**
  ([Keys](03-keys.md)).
- Each place that asks for the values, such as the form or the tool, is
  a **door** ([Doors](11-doors.md)).

Next: [Resources](02-resources.md)

Build it: [Your first resource](../first-resource.md)

See it in the examples: [Examples](../examples/index.md)
