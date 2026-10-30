# Operations

An operation is one way of asking for a resource: place a block, edit a
placed block, remove one. Each operation has an id, a label and the
permission an account needs to ask it. The module makes routes and
tools from operations, so a resource is reachable only through the
operations it declares.

![The block.placement resource with three operations, create, update and delete, each asking the permission administer blocks; arrows lead from the operations to a route and a tool](images/07-operations.svg)

## Declaring them

The placed block, `block.placement`, declares three operations. An
operation is an `#[Operation]` attribute with an id, a label and a
permission, in one of two places:

```php
#[Resource('block.placement', identity: ['id'], /* ... */)]
#[Operation('create',
  label: new TranslatableMarkup('Place a block'),
  permission: 'administer blocks',
  creates: TRUE,
)]
final class BlockPlacementResource implements ResourceInterface {

  #[Operation('update',
    label: new TranslatableMarkup('Edit a placed block'),
    permission: 'administer blocks',
  )]
  public static function update(BlockInterface $id): OperationContext {
    // ...
  }

  // delete() is declared as update() is, with deletes: TRUE and destructive: TRUE.
}
```

Where each class lives: [Namespaces](../concepts.md#namespaces).

- **On the class**, when asking needs nothing more. `create` knows
  nothing yet: the caller chooses the machine name. The performance
  settings declare their one operation this way, with the id
  `configure`. That id is the usual one for a settings operation, not a
  reserved word.
- **On a static method**, when the operation knows something. `update`
  edits one block, so it knows that block's machine name.

The method's parameters are what the operation needs, and a route or a
tool supplies them by name. The parameter `$id` is named after the
identity key. The update route declares `{id}` as `entity:block` in
its options, so Drupal's param converter upcasts it and the method
receives the loaded block entity. The method returns an `OperationContext`: the
object that says which operation is asked and what it already knows.
Here it knows the `id`:

```php
return new OperationContext(known: ['id' => (string) $id->id()]);
```

The real `update()` also holds the theme to the one the block is in;
[Operations](../resources.md#operations-create-update-and-the-rest) has
the rest of what a method can add.

A route names the resource class and the operation:

```yaml
data_surface_block.update:
  path: '/admin/structure/block/surface/{id}'
  defaults:
    _form: 'Drupal\data_surface\Form\DataSurfaceOperationForm'
    _data_surface_resource: 'Drupal\data_surface_block\Resource\BlockPlacementResource'
    _data_surface_operation: 'update'
  # ...
```

That route is a working form with no form class of its own. The same
operation is also the tool `data_surface:block.placement:update`.

## What is known is locked

An identity key the operation knows is locked to that value. In the
JSON Schema the module writes for editing Olivero's main content
block, `olivero_content`, the machine name says so (trimmed):

```json
"id": {"title": "Machine name", "type": "string", "default": "olivero_content", "readOnly": true, "const": "olivero_content"}
```

For `create`, the same key has no `readOnly` and no `const`: the caller
types it.

## The permission

Every route and tool made from an operation checks that operation's
permission, here `administer blocks`, the block layout's own. An
operation may also say how a call behaves: `creates: TRUE` for one that
adds a thing, and `deletes: TRUE` for one that removes it.
`deletes: TRUE` makes the call take no values, only the parameters that
name the thing; the module requires `destructive: TRUE` beside it,
which tells a client to ask before it calls. A later page shows how
[access](14-access.md) decides, beyond the permission, which keys an
account may change.

Next: [Storage](08-storage.md)

Build it: [Operations: create, update, and the rest](../resources.md#operations-create-update-and-the-rest)

See it in the examples: [Examples](../examples/index.md)
