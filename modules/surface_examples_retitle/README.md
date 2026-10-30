# Surface API examples: retitle

Example 17 of [the examples](../surface_examples/README.md): a bulk
retitle of nodes that borrows each node's access, at
`/surface-examples/17`. Needs node, for the pages it retitles; nothing
contributed is installed, and no model is called.

```bash
drush pm:install surface_examples_retitle
```

## What it shows

`Resource\RetitleResource`, `ai.retitle`, has its own keys, a list of
nodes and a style, its own storage and its own permission, "retitle
nodes". Which nodes it may retitle is not its own: its one operation,
`retitle`, names two gates, `entity:node:update` and
`field:node.title:edit`, core's update access on each node and its edit
access on the node's title, with every module that alters them. Each is
asked of every node in the list; a node either refuses is refused on its
own item, `/items/0/node`, with the owner's reason, and the storage never
sees it
([A gate borrowed from another thing](../../docs/resources.md#a-gate-borrowed-from-another-thing);
why: [decision](../../docs/decisions.md#a-gate-is-borrowed-never-copied)).

`TitleRewriter` writes each title not sent, in title case, sentence case
or after a prefix: a pure function of the title and the style, so every
call answers the same. `Storage\RetitleStorage` works out each node's new
title in `prepare()`, which a dry run answers, and saves each node whose
title changes in `commit()`.

## What it ships

- The page bundle, as optional config, for a site that has none.
- Two roles: Node retitler (`node_retitler`), which may retitle and edit
  any page, as optional config so it is installed once the page bundle
  is there, and Retitle requester (`retitle_requester`), which may
  retitle and edit no page: the page's calls are made as accounts of
  each role alone.
- Three basic pages, written when the module is installed and deleted
  when it is uninstalled (`Retitle`), their ids in State,
  `surface_examples_retitle.nodes`, where the calls read them.
- A field access hook (`Hook\RetitleHooks`) that forbids editing the
  third page's title while the State flag
  `surface_examples_retitle.locked` is set, standing in for another
  module that answers through core's field access.

Its tests are the examples', listed in
[their README](../surface_examples/README.md#how-this-page-is-kept-true),
and the gates' own, `GatesTest`.
