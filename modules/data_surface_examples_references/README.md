# Data Surface examples: references

Example 16 of [the examples](../data_surface_examples/README.md): keys
that refer to an entity, at `/surface-examples/16`. Needs node, for the
article the sponsorship's announcement is; nothing contributed is
installed.

```bash
drush pm:install data_surface_examples_references
```

## What it shows

`Resource\SponsorshipResource` declares each key by the data type string
of what it refers to: `entity:event_venue`, `entity:user`,
`entity:node:article`, and three bare `entity` keys, each two parts, an
entity type and an id, filtered by what kind of entity type it is and by
bundle. Each stores the id, as its entity type keeps it.

The candidates are core's selection handler's for the account asking,
listed while there are at most
`data_surface.settings:reference_list_limit` and looked up past that:
on the form with core's entity autocomplete, over HTTP with the
resource's lookup, `GET /surface-api/event.sponsorship:lookup?key=/venue&q=riv`
([A key that refers to an entity](../../docs/options.md#a-key-that-refers-to-an-entity);
why: [decision](../../docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up)).

## What it ships

- The sponsorship's settings, `data_surface_examples_references.sponsorship`,
  its config schema generated from the resource.
- The article and page bundles, as optional config, for a site that has
  neither.
- A role, Sponsorship editor (`event_sponsorship_editor`), which may set
  the sponsorship and not see unpublished content: one of the page's
  calls is made as an account of that role alone.
- Three nodes, written when the module is installed and deleted when it
  is uninstalled (`Sponsorship`): an article, a draft article and a page,
  their ids in State, `data_surface_examples_references.nodes`, where
  the calls read them.

Its tests are the examples', listed in
[their README](../data_surface_examples/README.md#how-this-page-is-kept-true).
