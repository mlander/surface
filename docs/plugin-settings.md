# A plugin's settings

Read this page when a block, formatter or condition plugin of yours
builds its settings form by hand. The plugin names a structure instead,
and the module draws the form, checks the values and stores them in the
plugin's configuration. [Structures](introduction/03-structures.md)
introduces the idea, and [Keys](introduction/05-keys.md) and
[Rules on a key](introduction/06-rules-on-a-key.md) the lines inside it.

A block, a formatter or a condition has settings but no storage of its
own. Declare the structure as a class, name it on the plugin whose values
it describes, and delete the form code:

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

```php
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesStructure(TeaserBlockStructure::class)]
final class TeaserBlock extends DataSurfaceBlockBase {

  public function build(): array {
    return ['#markup' => $this->getConfiguration()['headline']];
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The structure class goes in the module's `src/Structure/`, where it is
discovered; nothing is registered by hand. Its declaration is static, asked
of the class, so it is never constructed, and its labels are `t()`. The
block has no `defaultConfiguration()`, no `blockForm()`, no
`blockValidate()` and no `blockSubmit()`. Its configuration form is
generated from the definitions, with a maxlength on the headline and a
number spinner bounded at 1 and 50.

A separate class is not required. A small plugin is its own structure:
`#[UsesStructure]` with no argument, `StructureInterface` on the plugin,
and the same static `defineInputs()` on it:

```php
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesStructure]
final class TeaserBlock extends DataSurfaceBlockBase implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);

    $inputs->add('limit', 'integer', t('Number of items'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
  }

  public function build(): array {
    return ['#markup' => $this->getConfiguration()['headline']];
  }

}
```

A separate class earns its place when more than one plugin shares the
settings.

The same data surface answers a caller that never renders anything:

```php
$result = \Drupal::service('data_surface.pipeline')->submit(
  $block->getDataSurface(),
  ['headline' => 'Latest', 'limit' => '5'],
  new PluginConfigurationStorage($block),
);
$result->isValid();
```

`'5'` is stored as the integer `5`, a misspelled key comes back as a
violation rather than vanishing, and the messages are the ones the form
would have shown.

## From structure to resource

A structure describes values something else stores, here the block's
configuration. It has no identity and no operations, so it has no form
route, tool or endpoint of its own. A resource has all three. The placed
block is one: `block.placement`, in the `data_surface_block` submodule,
is the block config entity by its machine name, with `create`, `update`
and `delete` operations, and its `settings` key is chosen by its `plugin`
key. Once `my_teaser` is chosen, the teaser's structure is what every
door of that resource asks for under `settings`, with no code added to
the block:

| Door | Where |
| --- | --- |
| Form API | `/admin/structure/block/surface/add`: choose the plugin and its settings are drawn |
| Tool API | `data_surface:block.placement:create`, with `data_surface_tool` |
| HTTP | `POST /surface-api/block.placement`, with `data_surface_react` |
| React | `/surface-react/block.placement/create` |

The same placement from the command line, as a dry run:

```bash
drush tool:run data_surface:block.placement:create --uid=1 --input='{"values":{"label":"Teaser","id":"teaser_front","plugin":"my_teaser","settings":{"headline":"Latest","limit":5},"theme":"olivero","region":"content"}}' --input=dry_run=true
```

A `limit` of 60 is refused at every door with the Range message, at the
key's path, and a machine name already in use is refused by name.

To make something of your own a resource, the same class takes
`#[Resource]`, naming its identity keys and its storage class, and an
`#[Operation]` for each thing a caller may do. Routes, tools and
endpoints are generated from those: [Your first resource](first-resource.md) for a settings page, [example 15](https://github.com/mlander/data_surface/blob/main/modules/data_surface_examples/README.md#example-15-a-config-entity-of-your-own)
for a config entity type of your own, and [Declaring a resource or structure](declaring-a-resource.md) for the whole vocabulary.
