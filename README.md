# Surface API

You describe once what a thing on your site accepts: each value, its
type, and what it allows. From that one description the module makes
the settings form, a Tool API tool, the command line (`drush tool:run`),
and an HTTP API with its OpenAPI document; an example module adds a
React page. All of them accept the same values, because they all read
the same description.
Everything that runs Tool API tools can run that one tool: the command
line, ECA, FlowDrop, Maestro, MCP servers and AI agents.

The module exists to prove this out for
[the core issue behind it](https://www.drupal.org/project/drupal/issues/3622144).
Core's typed data definitions describe one value each. Core has no
object that holds a group of them for a form, an HTTP API and a tool to
validate against, so each of those describes the same values again and
the copies can disagree.
[Built on typed data](docs/on-typed-data.md) says what the module adds
to core's definitions to hold such a group, and why each part is needed.

## Requirements

Drupal 11.3 or 12, PHP 8.3 or newer, and
[opis/json-schema](https://github.com/opis/json-schema) (Apache-2.0),
which Composer installs with the module
([Installation](docs/installation.md#requirements)).

## Installation

```
composer require drupal/surface
drush pm:install surface
```

The submodules, the optional integrations and the production settings
are in [Installation](docs/installation.md).

## Documentation

- [Start with the introduction](docs/introduction/index.md): short
  pages, one idea each, with nothing to install.
- [Build your first resource](docs/first-resource.md): a settings page,
  its form, its tool and its schema. You write four pieces (a resource
  class, an operation, a storage and its keys), plus the route, the
  permission and the config schema any Drupal settings page has; a
  command generates the config schema and the install file.
- [Look it up](docs/index.md): every page of the documentation, by
  section.
- [Examples](docs/examples/index.md): every example module, what each
  shows, and how to try it on a site.

## Glossary

Every term the module uses has one sense, defined in
[the glossary](docs/glossary.md).

## The examples

The base module, `surface`, is the project and the only thing meant to
ship. Every submodule under `modules/` is an example: read it, install
it and remove it as you like. The base module depends on none of them.
They come in two groups:

- **Learn the pattern.** The numbered examples in `surface_examples` and
  their submodules, the code of the build pages
  (`surface_examples_first`), a plugin's settings written from a
  structure and by hand (`surface_examples_plugins`,
  `surface_examples_classic`), a module extending another module's
  resources (`surface_examples_extras`), and a React client of the HTTP
  door (`surface_examples_react`).
- **The pattern on Drupal's own things.** Placed blocks, a component
  block, content entities, content types and a comparison of their
  tools, the performance settings, field settings, and the Address
  module's field type. These examples apply the pattern to things Drupal
  and a contrib module already have, to test it across scenarios; they
  are not part of the project and will not ship in this form. Read them
  as a proof of concept.

Every submodule is `lifecycle: experimental`: supported, but its API and
its configuration may change without a deprecation path. Each one's
install command and what it opens are in
[Installation](docs/installation.md#experimental-submodules); what each
shows and its page on a site are in [Examples](docs/examples/index.md).

## Experimental submodules

Every submodule is an example: see [The examples](#the-examples).

## Development

`scripts/check.sh` runs the test suite, PHPCS, PHPStan and cspell in one
go, with the incantations each of them needs; `CLAUDE.md` explains what a
passing run looks like.

The design history is kept beside this file: [PLAN.md](PLAN.md) for the
gaps and phases as they were reasoned through, [ADOPTION.md](ADOPTION.md)
for the full core survey, [HARDENING.md](HARDENING.md) for the audit
findings and the decisions taken on them, and [REWORK.md](REWORK.md) for
the rework that brought the module to the pattern;
[ROADMAP.md](ROADMAP.md) is the forward plan. The current documentation
is `docs/`, and the decisions the pattern left open are in
[docs/decisions.md](docs/decisions.md).
