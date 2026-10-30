# Keys

A key is one value a resource holds. Each key has a name, a type, a
label and, where it needs one, a default. You declare a key by adding
it with `add()` in the resource class's static `defineInputs()` method.

![The system.performance resource with its three keys: max_age, an integer labeled Browser and proxy cache maximum age with the default 0; css_preprocess and js_preprocess, booleans labeled Aggregate CSS files and Aggregate JavaScript files with the default true](images/03-keys.svg)

## Declaring keys

The performance settings have three keys. This is
`PerformanceResource`, from the `data_surface_performance` submodule,
with what each key allows left for the next page:

```php
#[Resource('system.performance', /* ... */)]
final class PerformanceResource implements ResourceInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('max_age', 'integer', t('Browser and proxy cache maximum age'), default: 0)
      ->setDescription(t('This is used as the value for max-age in Cache-Control headers.'));
    $inputs->add('css_preprocess', 'boolean', t('Aggregate CSS files'), default: TRUE);
    $inputs->add('js_preprocess', 'boolean', t('Aggregate JavaScript files'), default: TRUE);
    // ...
  }

}
```

Where each class lives: [Namespaces](../concepts.md#namespaces).

`defineInputs()` receives `$inputs`, a `BlueprintInterface`: the object
the class adds its keys to. The method and the object are named for
inputs, the values a caller sends; each input is a key.

## The four parts of a key

`add()` takes the key's name, its type, its label and its default:

- **Name**: what the value is sent and read under, `max_age`.
- **Type**: a Drupal typed data type, such as `string`, `integer`,
  `float`, `boolean`, `email` or `uri`. A value sent as the text
  `"300"` to the `integer` key `max_age` is accepted as the integer
  `300`.
- **Label**: the form element's title, the tool's property title and
  the JSON Schema's `title`, one text for all three. Write it with
  `t()`.
- **Default**: what the key starts from when nothing is saved for it.

`add()` returns core's own `DataDefinition`, so anything more about the
key is core's API: `setDescription()` for the text under the field, and
the methods the next page uses for what a key allows.

`defineInputs()` is static and runs with no values, so it cannot read
what another key holds. A key whose allowed values depend on another
key is a later page's idea ([Rules between keys](05-rules-between-keys.md)).

## What you get

The form has a checkbox titled "Aggregate CSS files" and one titled
"Aggregate JavaScript files". The JSON Schema the module writes for a
tool or an HTTP client states the same two keys, with the same titles
and defaults (trimmed):

```json
"css_preprocess": {"title": "Aggregate CSS files", "type": "boolean", "default": true},
"js_preprocess": {"title": "Aggregate JavaScript files", "type": "boolean", "default": true}
```

Next: [Rules on a key](04-rules-on-a-key.md)

Build it: [Declaring a resource or structure: the class](../declaring-a-resource.md#the-class)

See it in the examples: [Examples](../examples/index.md)
