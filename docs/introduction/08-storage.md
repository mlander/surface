# Storage

Storage is where a resource's values are saved: a config object, a
config entity or a content entity. A resource names one storage class,
and that class reads and writes the values for it. The resource's keys
and rules stay the same whichever storage holds them.

![The system.performance resource with three keys, max_age, css_preprocess and js_preprocess, each with an arrow to the path where its storage saves it in the system.performance config object](images/08-storage.svg)

## What a storage class does

The resource says what is accepted. The storage class says where it
goes, in three steps:

- **load** reads what is saved, as the resource's keys, so an edit
  starts from the current values;
- **prepare** turns accepted values into what will be saved and checks
  them, writing nothing, so a request can be tried without saving: a
  tool call that passes `dry_run` stops here
  ([Dry runs](../pipeline.md#dry-runs));
- **commit** writes them.

The performance settings name a storage class that extends the
module's `ConfigObjectStorage`:

```php
#[Resource('system.performance', storage: PerformanceStorage::class)]
// ...

final class PerformanceStorage extends ConfigObjectStorage {

  public const CONFIG = 'system.performance';

  public const STORED_AT = [
    'max_age' => 'cache.page.max_age',
    'css_preprocess' => 'css.preprocess',
    'js_preprocess' => 'js.preprocess',
  ];

  // commit() also deletes the aggregated CSS and JavaScript files, as
  // core's performance form does after it saves.
}
```

Where each class lives: [Namespaces](../concepts.md#namespaces).

The keys are flat; core keeps them at nested paths:

| Key the resource declares | Where core keeps it in `system.performance` |
| --- | --- |
| `max_age` | `cache.page.max_age` |
| `css_preprocess` | `css.preprocess` |
| `js_preprocess` | `js.preprocess` |

The form, the tool and the HTTP API ask for `max_age`. Core goes on
reading `cache.page.max_age`. The translation between the two lives in
one constant on the storage class.

## The three kinds

| What Drupal keeps | The storage class | Used by |
| --- | --- | --- |
| A config object | a subclass of `ConfigObjectStorage` | the performance settings |
| A config entity | a subclass of `ConfigEntityStorage` | the placed block (`block`), the content type (`node_type`) |
| A content entity | `ContentEntityStorage`, in the `data_surface_entity` submodule, used as it is | a node |

A config entity's storage names the entity type and the keys that are
the entity's own properties. The placed block's says:

```php
final class BlockPlacementStorage extends ConfigEntityStorage {
  public const ENTITY_TYPE_ID = 'block';
  public const PROPERTIES = ['id', 'status', 'weight', 'theme', 'region', 'plugin'];
  // ...
}
```

Where each class lives: [Namespaces](../concepts.md#namespaces).

## Why keep them apart

The keys and rules live on the resource, so every place that asks for
the values asks for the same keys, whatever the storage. Moving a value
to another place changes the storage class, not the resource. One
storage class also serves every operation of its resource: the same
`BlockPlacementStorage` saves a new placed block and an edited one,
because the operation tells it which block, through the identity it
knows.

Next: [Structures](09-structures.md)

Build it: [A simple config object](../storage.md#a-simple-config-object) and [A config entity](../storage.md#a-config-entity)

See it in the examples: [Examples](../examples/index.md)
