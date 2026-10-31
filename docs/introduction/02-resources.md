# Resources

A resource is a thing your site keeps values for, that can be asked for
on its own. A content type is one: each has a name, a machine name and
its settings, and you can add, edit or delete one. A placed block is
another, with its theme, its region and its plugin. Your own module's
settings are a third: one config object you change.

![Three resources side by side, each a thing the site keeps values for: node.type, a content type; block.placement, a placed block; my_module.settings, a module's settings; each with two of its stored values](images/02-resources.svg)

## On its own

"On its own" means a caller can name the resource and ask to change it,
with nothing around it. A content type, a placed block and a
module's settings each pass that test.

A block plugin's settings do not. They are values too, but nobody asks
for them apart from the placed block that holds them. The next page
covers values like these, held by something else
([Structures](03-structures.md)).

## A resource is a class

You make something a resource with a class that carries the
`#[Resource]` attribute, in your module's `src/Resource/` directory.
The attribute's first argument is the resource's id. The settings of
[the previous page](01-what-it-does.md) are the class
`MySettingsResource`, and these are its attributes:

```php
#[Resource('my_module.settings',
  label: new TranslatableMarkup('My module settings'),
  description: new TranslatableMarkup('What my module lists and how: its title, how many items a page shows, whether the author is shown, where notices go and the layout.'),
  storage: MySettingsStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure my module'),
  permission: 'administer my_module',
)]
```

The second attribute, `#[Operation]`, and the `storage:` argument are
the ideas of [Operations](09-operations.md) and [Storage](10-storage.md).
`label:` and `description:` are optional: what the resource is called
and what it is for, shown beside its id to a person or an AI agent who
would otherwise see only the id.

The content type and the placed block are resources because two
example modules make them so, to try the pattern on things core already
has; the base module does not. The `surface_examples_node_type`
example's `NodeTypeResource` makes a content type the resource
`node.type`. The `surface_examples_block` example's
`BlockPlacementResource` makes core's placed block config entity,
`block`, the resource `block.placement`, and ships the routes that serve
it. Later pages show parts of both.

The module finds the class the way core finds a hook class in
`src/Hook`, so nothing is registered in a services file. It never
constructs the class: everything the class says is in static methods,
so the module reads it without building anything, ahead of any request.
[Keys](05-keys.md) starts on those methods.

## A resource describes, it does not store

The class says what the values are and what they allow. The values
stay where Drupal already keeps them: the node type config entity, the
block config entity `block.block.<id>`, the `my_module.settings` config
object. A later page shows how a resource names that place
([Storage](10-storage.md)). Because the class only describes, you can
make a resource of something core already has without changing how
core saves it.

## What the module sees

`drush surface:catalog --format=markdown` lists every resource the
module finds on a site. Its entry for the placed block starts:

```
## block.placement

`Drupal\surface_examples_block\Resource\BlockPlacementResource`, in `surface_examples_block`.

- Label: Placed block
- Description: A block placed in a region of a theme under a machine name: its label, the settings its plugin takes, its weight, its status and when it shows.
- Kind: resource
```

`Label` and `Description` are the attribute's `label:` and
`description:`. `Kind` says what sort of entry it is: a resource, or a
group of values held by something else, which
[Structures](03-structures.md) covers.

## One of three kinds

A resource is one of three kinds of thing the module describes. The
next two pages give the others: a group of values held by a resource
([Structures](03-structures.md)), and one value used under many names
([Shapes](04-shapes.md)). Only a resource is asked for on its own, so
only a resource names a permission for each way of asking for it; an
access class named with `access:` on `#[Resource]` may then lock its
values for an account or refuse the whole request
([Access](15-access.md)).

Next: [Structures](03-structures.md)

Build it: [Your first resource, step 4: the resource](../first-resource.md#4-the-resource)

See it in the examples: [Examples](../examples/index.md)
