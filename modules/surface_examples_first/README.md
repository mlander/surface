# Surface API - Your first resource

The code of two documentation pages, shipped as a module so the pages
cannot drift from code that works:

- [Your first resource](../../docs/first-resource.md): a newsletter
  settings page, its form, its tool and its schema, from a resource, an
  operation, a storage and `add()`. At
  `/admin/config/services/surface-newsletter`.
- [Your second resource](../../docs/second-resource.md): a store
  location whose region is one of the chosen country's, the same four
  things and one refiner. At `/admin/config/services/surface-store`.

```bash
drush pm:install surface_examples_first
```

Both pages ask for "administer surface_examples_first". With
`surface_tool` enabled, each resource is also the tools
`surface:first.newsletter:configure` and
`surface:first.store:configure`, each with its read tool.

`FirstResourceDocsTest` fails when a page and a file here differ;
`FirstResourceTest` renders both forms and runs the Drush calls the pages
quote. Change a file here and the page together, or not at all: the
first page is the module's concept budget (ROADMAP item 13), and every
later feature has to leave it unchanged.
