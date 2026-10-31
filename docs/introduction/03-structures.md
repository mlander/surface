# Structures

A structure is a group of values with no [identity](08-identity.md), no
[storage](10-storage.md) and no [operation](09-operations.md) of its
own. Nothing tells one apart from another, nothing saves it by itself,
and nobody asks to change it by itself. It is only ever held by
something else, and that holder is the resource.

![On the left, the teaser block's settings, a structure with headline and limit, with no id, nowhere saved by itself and nothing to ask for; on the right, the placed block, block.placement, a resource with an id, saved as block.block.teaser_front, and create, update and delete, holding the settings under settings](images/03-structures.svg)

## Why a block is not a resource

Take a teaser block you would write in your own module: a block plugin
with a headline and a number of items. Its settings are values, but
nobody creates, saves or edits a block plugin by itself. Drupal uses a
block only where something places it. The block layout places it in a
region of a theme as a block config entity: that placement has a
machine name, is saved as `block.block.<id>`, and can be added, edited
and removed. The plugin's settings are saved inside the placement,
under its `settings`.

So the placement is the resource. The `surface_examples_block` example
module makes core's block config entity the resource `block.placement`, and
the teaser's settings are a structure that `block.placement` holds
under `settings`. Any other place that uses a block holds its settings
inside what it saves in the same way.

## Declaring one

A structure is a class like a resource, with `#[Structure]` in place of
`#[Resource]`. Its `defineInputs()` lists its values, each with a type,
a label, a default and what it allows, which [Keys](05-keys.md) and
[Rules on a key](06-rules-on-a-key.md) explain:

```php
#[Structure('block.my_teaser')]
final class TeaserBlockStructure implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    $inputs->add('limit', 'integer', t('Number of items'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
  }

}
```

`block.my_teaser` is the structure's id, a machine name you choose;
`block.` and the plugin id keep it beside the plugin it belongs to.

The block plugin names the structure and extends the module's
`TypedBlockBase` instead of `BlockBase`:

```php
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesStructure(TeaserBlockStructure::class)]
final class TeaserBlock extends TypedBlockBase {
  // build() is unchanged.
}
```

The block loses its `defaultConfiguration()`, `blockForm()`,
`blockValidate()` and `blockSubmit()`: `TypedBlockBase` supplies
them from the structure. Core's block form asks the plugin for its
settings form with `buildConfigurationForm()`, which calls
`blockForm()`, so wherever core asks, the form is made from the
structure: a text field with a maximum length of 50 for the headline,
and a number field bounded at 1 and 50 for the limit, each starting
from the default the structure gives it.

A small plugin can be its own structure: `#[UsesStructure]` with no
argument, and `StructureInterface` and `defineInputs()` on the block
class itself. A separate structure class earns its place when more
than one plugin shares the settings.

## Three kinds of thing

The module describes three kinds of thing. The third, a
[shape](04-shapes.md), is one value used under many names.

| | Has [identity](08-identity.md)? | Has [storage](10-storage.md)? | Has [operations](09-operations.md)? | Held by | [Another module](13-other-modules.md) may change it? |
| --- | --- | --- | --- | --- | --- |
| Resource | yes | yes | yes | nothing: it is asked for on its own | yes: it may add values under its own name and tighten what the values allow, never loosen it |
| Structure | no | no | no | a resource, or another structure, under one of its values | yes, the same way |
| [Shape](04-shapes.md) | no | no | no | a resource or a structure, under each of its values that uses it | yes: it may tighten the one value, which then holds everywhere the shape is used; it adds nothing |

Next: [Shapes](04-shapes.md)

Build it: [On a plugin](../declaring-a-resource.md#on-a-plugin), then [A plugin's settings](../plugin-settings.md)

See it in the examples: [Examples](../examples/index.md)
