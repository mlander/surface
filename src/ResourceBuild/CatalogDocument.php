<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

/**
 * Renders the site catalog as markdown.
 *
 * What `drush surface:catalog --format=markdown` prints. docs/catalog.md
 * is this document for the modules in this repository, written by
 * ResourceCatalogTest through SiteCatalog::markdown(), the same call the
 * command makes, so the two cannot drift. The rows, counts and notes the
 * command's table and the catalog page both show are read here too.
 *
 * @internal
 */
final class CatalogDocument {

  /**
   * Where the repository's document lives, from the module root.
   */
  public const PATH = 'docs/catalog.md';

  /**
   * The environment variable that writes the repository's document.
   */
  public const WRITE_VARIABLE = 'SURFACE_WRITE_CATALOGUE';

  /**
   * Renders the catalog.
   *
   * @param array $catalog
   *   What SiteCatalog::describe() answers.
   * @param array $derivers
   *   What SiteCatalog::derivers() answers.
   * @param array $refused
   *   What SiteCatalog::refused() answers.
   *
   * @return string
   *   The markdown.
   */
  public static function render(array $catalog, array $derivers, array $refused): string {
    $groups = self::readGroups($catalog);
    $catalog = array_filter($catalog, static fn (array $resource): bool => $resource['read'] === NULL);
    $document = self::intro();
    $document .= "| Resource or structure | Label | Class | Identity | Operations |\n| --- | --- | --- | --- | --- |\n";
    foreach ($catalog as $resource) {
      $document .= sprintf(
        "| [`%s`](#%s) | %s | `%s` | %s | %s |\n",
        $resource['id'],
        self::anchor($resource['id']),
        self::cell($resource['label'] ?? ''),
        $resource['class'] === NULL ? 'derived' : self::short($resource['class']),
        self::codes($resource['identity']),
        self::codes(array_column($resource['operations'], 'id')),
      );
    }
    foreach ($catalog as $resource) {
      $document .= "\n## " . $resource['id'] . "\n\n";
      $document .= $resource['class'] === NULL
        ? 'Derived under `' . $resource['deriver'] . '`, in `' . $resource['module'] . "`.\n\n"
        : '`' . $resource['class'] . '`, in `' . $resource['module'] . "`.\n\n";
      $document .= self::names($resource);
      $document .= '- Kind: ' . $resource['kind'] . "\n";
      $document .= '- Identity: ' . self::codes($resource['identity']) . "\n";
      $document .= '- Storage: ' . ($resource['storage'] === NULL ? 'none; its host or its parent stores it' : '`' . $resource['storage'] . '`') . "\n";
      $document .= '- Access: ' . ($resource['access'] === NULL ? 'the operation\'s permission alone' : '`' . $resource['access'] . '`') . "\n";
      if ($resource['plugins'] !== []) {
        $document .= '- Used by: ' . self::codes($resource['plugins']) . "; configured through the plugin's host, never on its own.\n";
      }
      if ($resource['operations'] !== []) {
        $document .= "\n| Operation | Label | Needs | Creates | Permission | On its own | Read on its own | Provided by |\n| --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($resource['operations'] as $operation) {
          $document .= sprintf(
            "| `%s` | %s | %s | %s | %s | %s | %s | `%s` (`%s`) |\n",
            $operation['id'],
            self::cell($operation['label']),
            $operation['parameters'] === [] ? 'nothing' : self::codes($operation['parameters']),
            $operation['creates'] ? 'yes' : 'no',
            $operation['permission'] === NULL ? 'none' : '`' . $operation['permission'] . '`',
            self::standalone($resource, $operation),
            self::reads($operation),
            str_starts_with($operation['provider'], '#[Operation] on ') ? '#[Operation] on ' . self::short($operation['provider']) : self::short($operation['provider']),
            $operation['module'],
          );
        }
        $doors = [];
        foreach ($resource['operations'] as $operation) {
          if (($operation['doors'] ?? []) === []) {
            continue;
          }
          $reached = [];
          foreach ($operation['doors'] as $door => $what) {
            $reached[] = $door . ' `' . $what . '`';
          }
          $doors[] = '- `' . $operation['id'] . '` reaches ' . implode(', ', $reached) . '.';
        }
        if ($doors !== []) {
          $document .= "\n" . implode("\n", $doors) . "\n";
        }
      }
      foreach ($resource['alters'] as $alter) {
        $refines = array_map(
          static fn (array $refiner): string => '`' . $refiner['key'] . '`' . ($refiner['watches'] === [] ? '' : ' (watching ' . self::codes($refiner['watches']) . ')') . ($refiner['runtime'] ? ' at runtime' : ''),
          $alter['refines'],
        );
        $document .= "\n- Altered by `" . $alter['class'] . '` (`' . $alter['module'] . '`), '
          . ($alter['operations'] === [] ? 'in every operation' : 'in ' . self::codes($alter['operations']))
          . ($refines === [] ? '' : '; it refines ' . implode(', ', $refines))
          . (($alter['access'] ?? FALSE) ? '; it answers access' : '') . ".\n";
      }
      foreach ($resource['variants'] as $key => $variants) {
        $document .= "\n- Variants at `" . $key . '`: ';
        $filled = [];
        foreach ($variants as $value => $class) {
          $filled[] = '`' . $value . '` → `' . $class . '`';
        }
        $document .= implode(', ', $filled) . ".\n";
      }
    }
    if ($derivers !== []) {
      $document .= "\n## Derivers\n\n" . self::deriversIntro() . "\n";
      $document .= "| Base | Deriver | Module | Overridden by |\n| --- | --- | --- | --- |\n";
      foreach ($derivers as $deriver) {
        $document .= sprintf(
          "| `%s` | `%s` | `%s` | %s |\n",
          $deriver['base'],
          $deriver['class'],
          $deriver['module'],
          $deriver['overriddenBy'] === NULL ? 'nothing' : 'classes carrying `' . self::short($deriver['overriddenBy']) . '`' . ($deriver['overridden'] === [] ? ', none here' : ': ' . self::codes(array_map(self::short(...), array_keys($deriver['overridden'])))),
        );
      }
    }
    if ($groups !== []) {
      $document .= "\n## Read from a schema\n\n" . self::readIntro() . "\n";
      $document .= "| Read at | Structures | Module |\n| --- | --- | --- |\n";
      foreach ($groups as $at => $group) {
        $document .= sprintf("| `%s` | %d | `%s` |\n", $at, $group['count'], $group['module']);
      }
    }
    $document .= "\n" . self::refusals($refused);
    return $document;
  }

  /**
   * Lists the rows of the catalog's table: one per operation.
   *
   * What `drush surface:catalog` prints as its table and the catalog page
   * at /admin/config/development/surfaces shows, from the same call, so
   * the two list the same rows: a resource or structure with no
   * operation in one row, then one row where structures are read from a
   * schema, with how many.
   *
   * @param array $catalog
   *   What SiteCatalog::describe() answers.
   *
   * @return list<array{resource: string, kind: string, operation: string, operation_id: string|null, permission: string, needs: string, doors: array<string, string>|null, read: int|null}>
   *   Each row: the resource or structure, its kind, the operation and its
   *   kind, the operation's id, its permission, its parameters; the doors
   *   it reaches by name, NULL on a row with no operation; and how many
   *   structures are read there, on a row of those, NULL on any other.
   */
  public static function rows(array $catalog): array {
    $rows = [];
    // A row with no operation: no permission, parameters or doors.
    $none = ['operation' => 'none', 'operation_id' => NULL, 'permission' => '', 'needs' => '', 'doors' => NULL];
    foreach ($catalog as $entry) {
      if ($entry['read'] !== NULL) {
        continue;
      }
      if ($entry['operations'] === []) {
        $rows[] = ['resource' => $entry['id'], 'kind' => $entry['kind']] + $none + ['read' => NULL];
        continue;
      }
      foreach ($entry['operations'] as $operation) {
        $rows[] = [
          'resource' => $entry['id'],
          'kind' => $entry['kind'],
          'operation' => $operation['id'] . ' (' . $operation['kind'] . ')',
          'operation_id' => $operation['id'],
          'permission' => $operation['permission'] ?? 'none',
          'needs' => $operation['parameters'] === [] ? 'nothing' : implode(', ', $operation['parameters']),
          'doors' => $operation['doors'],
          'read' => NULL,
        ];
      }
    }
    foreach (self::readGroups($catalog) as $at => $group) {
      $rows[] = ['resource' => $at . '.*', 'kind' => 'structure'] + $none + ['read' => $group['count']];
    }
    return $rows;
  }

  /**
   * Says a row's doors as the table's text: their names, or why none.
   *
   * @param array $row
   *   A row, as rows() lists it.
   *
   * @return string
   *   The names, comma separated; "none" for an operation that reaches
   *   none; how many are read, on a row of those; empty with no operation.
   */
  public static function doorNames(array $row): string {
    return match (TRUE) {
      $row['read'] !== NULL => sprintf('%d read from a schema', $row['read']),
      $row['doors'] === NULL => '',
      $row['doors'] === [] => 'none',
      default => implode(', ', array_keys($row['doors'])),
    };
  }

  /**
   * Counts what the catalog lists.
   *
   * @param array $catalog
   *   What SiteCatalog::describe() answers.
   *
   * @return array{resources: int, structures: int, operations: int, read: int}
   *   The declared resources and structures, their operations, and the
   *   structures read from a schema.
   */
  public static function counts(array $catalog): array {
    $declared = array_filter($catalog, static fn (array $entry): bool => $entry['read'] === NULL);
    $kinds = array_count_values(array_column($declared, 'kind'));
    return [
      'resources' => $kinds['resource'] ?? 0,
      'structures' => $kinds['structure'] ?? 0,
      'operations' => array_sum(array_map(static fn (array $entry): int => count($entry['operations']), $declared)),
      'read' => count($catalog) - count($declared),
    ];
  }

  /**
   * Says what a resource or structure is called and is for, where it says.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param array $resource
   *   Its entry, as SiteCatalog::describe() or resource() gives it.
   *
   * @return string
   *   A list item for each of its label and its description it has, or
   *   nothing.
   */
  public static function names(array $resource): string {
    $names = '';
    if (($resource['label'] ?? NULL) !== NULL) {
      $names .= '- Label: ' . self::cell($resource['label']) . "\n";
    }
    if (($resource['description'] ?? NULL) !== NULL) {
      $names .= '- Description: ' . self::cell($resource['description']) . "\n";
    }
    return $names;
  }

  /**
   * Says what answers access to one resource, and of which kind.
   *
   * @param array{class: string|null, alters: string[], kind: string} $access
   *   Its `answers`, as SiteCatalog::resource() gives them.
   *
   * @return string
   *   One line.
   */
  public static function access(array $access): string {
    if ($access['kind'] === 'none') {
      return 'the operation\'s permission alone';
    }
    $who = array_filter([$access['class'], ...$access['alters']]);
    return implode(', ', $who) . ', ' . $access['kind'];
  }

  /**
   * Says what else is true of a key, one line each.
   *
   * @param array $key
   *   A key, as SiteCatalog::resource() lists it.
   *
   * @return string[]
   *   The notes.
   */
  public static function notes(array $key): array {
    return array_values(array_filter([
      $key['label'] === '' ? NULL : 'label: ' . $key['label'],
      ($key['widget'] ?? NULL) === NULL ? NULL : 'widget ' . $key['widget'],
      $key['locked'] ? 'locked' : NULL,
      $key['secret'] ? 'secret' : NULL,
      $key['computed'] === NULL ? NULL : 'computed from ' . implode(', ', $key['computed']['from']) . ' by ' . self::short($key['computed']['by']) . ', ' . $key['computed']['when'],
      $key['refiners'] === [] ? NULL : 'refined by ' . implode('; ', $key['refiners']),
      $key['rules'] === [] ? NULL : 'declared rules reading ' . implode(', ', $key['rules']),
      $key['watches'] === [] ? NULL : 'watches ' . implode(', ', $key['watches']),
      $key['runtime'] ? 'refined at runtime' : NULL,
      $key['contributor'] === NULL ? NULL : 'added by ' . $key['contributor'],
      $key['child'] === NULL ? NULL : 'child ' . $key['child'],
      $key['variants'] === NULL ? NULL : 'variants by ' . $key['variants']['by'] . ': ' . implode(', ', array_map(static fn (int|string $value, ?string $id): string => $value . ' ' . ($id ?? 'read'), array_keys($key['variants']['values']), $key['variants']['values'])),
      ($key['variants']['listed'] ?? TRUE) ? NULL : sprintf('%d variants, more than the site list limit: their keys are not listed here', count($key['variants']['values'])),
      $key['items'] === NULL ? NULL : 'items ' . $key['items'],
    ]));
  }

  /**
   * Encodes a value as JSON, as the catalog prints a default or a constraint.
   *
   * @param mixed $value
   *   The value.
   * @param bool $pretty
   *   Whether to print it over lines.
   *
   * @return string
   *   The JSON.
   */
  public static function json(mixed $value, bool $pretty = TRUE): string {
    return (string) json_encode($value, ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
  }

  /**
   * Groups the structures read from a schema by where they are read.
   *
   * A provider's variants are `<owner>.<key>.<value>`, one per value, and
   * a component's props may be thousands on a site: each group is the id
   * up to the value a provider lists (a segment holding `:` or `+`, a
   * site's own id), else less its last segment, with how many there are.
   *
   * @param array $catalog
   *   What SiteCatalog::describe() answers.
   *
   * @return array<string, array{count: int, module: string}>
   *   By where they are read, sorted.
   */
  public static function readGroups(array $catalog): array {
    $groups = [];
    foreach ($catalog as $resource) {
      if ($resource['read'] === NULL) {
        continue;
      }
      $segments = explode('.', $resource['id']);
      $kept = [];
      foreach ($segments as $segment) {
        // The value a provider lists is a site's id (`orion:image`), and
        // what is read under it is that value's.
        if (strpbrk($segment, ':+') !== FALSE) {
          break;
        }
        $kept[] = $segment;
      }
      $at = implode('.', count($kept) === count($segments) ? array_slice($kept, 0, -1) : $kept);
      $at = $at === '' ? $resource['id'] : $at;
      $groups[$at] ??= ['count' => 0, 'module' => $resource['module']];
      $groups[$at]['count']++;
    }
    ksort($groups);
    return $groups;
  }

  /**
   * Renders the refusals alone, as `--refused --format=markdown` prints them.
   *
   * @param array $refused
   *   What SiteCatalog::refused() answers.
   *
   * @return string
   *   The section.
   */
  public static function refusals(array $refused): string {
    $document = "## Refused\n\n";
    if ($refused === []) {
      return $document . "Nothing discovery found was refused: every operation of every resource and structure built.\n";
    }
    $document .= "What discovery or a build refused, and every route written wrong for the operation it serves, with the file the message points at.\n\n";
    $document .= "| Resource or structure | Operation | Message | File |\n| --- | --- | --- | --- |\n";
    foreach ($refused as $refusal) {
      $document .= sprintf(
        "| %s | %s | %s | %s |\n",
        $refusal['resource'] === NULL ? $refusal['stage'] : '`' . $refusal['resource'] . '`',
        $refusal['operation'] === NULL ? 'the whole' : '`' . $refusal['operation'] . '`',
        self::cell($refusal['message']),
        $refusal['file'] === NULL ? 'none' : '`' . $refusal['file'] . '`',
      );
    }
    return $document;
  }

  /**
   * The prose above the structures read from a schema.
   *
   * @return string
   *   The paragraph.
   */
  private static function readIntro(): string {
    return <<<'MARKDOWN'
A structure read from a schema document has no class: a variant a
provider lists (a component's props, each its own schema) or a def a
document refers to, built by the name `schema:<id>:<hash>`. Each is
registered when a build reads it, so these are what building every
operation here read, grouped by where they are read and counted;
`--resource=<name>` shows one, and `--format=json` lists every one.

MARKDOWN;
  }

  /**
   * The prose above the derivers.
   *
   * @return string
   *   The paragraph.
   */
  private static function deriversIntro(): string {
    return <<<'MARKDOWN'
A deriver makes resources or structures from site state at discovery,
one per bundle or per field, under the id `<base>:<derivative>`. Which
derivatives exist depends on the site (its bundles, their form displays
and fields): each one this site has is listed above, its class
`derived`, under the base it was derived from.

MARKDOWN;
  }

  /**
   * The prose above the catalog.
   *
   * @return string
   *   The introduction.
   */
  private static function intro(): string {
    return <<<'MARKDOWN'
# Catalog

Generated by `drush surface:catalog --format=markdown`; do not edit by
hand. This file is that output for a test site with the modules in this
repository installed, written by `ResourceCatalogTest` through the same
builder, and asserted on every run to match it.

Every resource and structure discovery finds on the site, from any
module: the `#[Resource]` and `#[Structure]` attributes, the
`#[Operation]` ones, on a class or on a method and what it needs, the
`#[Alters]` classes and the `#[Variant]` ones. Each entry says its kind;
a resource also names its storage, while a structure has none of its
own. A plugin that is its own structure, `#[UsesStructure]` with no
argument, is listed by its plugin class, under an id from its plugin
unless it carries `#[Structure]`, and is used by itself.

An operation that can be asked "on its own" belongs to a resource that
names storage and is no plugin's configuration, and every `%key`
placeholder in its permission can be supplied by one of its parameters.
Such an operation reaches the doors the installed modules open: a form
route a module declares, the HTTP path and the OpenAPI operation (the
base module), the tool `surface:<id>:<operation>` (the base module,
with `tool` installed) and the React page (`surface_examples_react`).
One that changes a thing that exists, neither creating nor read only, is
"read on its own" too, a read tool, `surface:<id>:<operation>:read`.
A structure a plugin uses is configured through the plugin's host. Every
operation is built for no account, and what did not build, with every
route written wrong for the operation it serves, is listed last, under
Refused. `docs/resources.md` says how each part works.


MARKDOWN;
  }

  /**
   * Says whether an operation can be asked on its own, and why not.
   *
   * @param array $resource
   *   The resource or structure, as the catalog describes it.
   * @param array $operation
   *   The operation, as the catalog describes it.
   *
   * @return string
   *   "yes", or "no" and the first reason.
   */
  private static function standalone(array $resource, array $operation): string {
    return match (TRUE) {
      $operation['standalone'] => 'yes',
      $resource['storage'] === NULL => 'no: no storage',
      $resource['plugins'] !== [] => 'no: a plugin\'s',
      default => 'no: nothing supplies ' . self::codes(array_map(static fn (string $key): string => '%' . $key, $operation['unresolvable'])),
    };
  }

  /**
   * Says whether what an operation changes can be read on its own, and why not.
   *
   * @param array $operation
   *   The operation, as the catalog describes it.
   *
   * @return string
   *   "yes", or "no" and the first reason.
   */
  private static function reads(array $operation): string {
    return match (TRUE) {
      $operation['reads'] => 'yes',
      !$operation['standalone'] => 'no: not on its own',
      $operation['creates'] => 'no: it creates',
      ($operation['kind'] ?? NULL) === OperationDefinition::DELETE => 'no: it deletes',
      default => 'no: it changes nothing',
    };
  }

  /**
   * Joins values as code spans.
   *
   * @param string[] $values
   *   The values.
   *
   * @return string
   *   The code spans, comma separated, or "none".
   */
  private static function codes(array $values): string {
    return $values === [] ? 'none' : implode(', ', array_map(static fn (string $value): string => '`' . $value . '`', $values));
  }

  /**
   * Writes text for a table cell: one line, its pipes escaped.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The cell.
   */
  private static function cell(string $text): string {
    return str_replace(['|', "\n"], ['\\|', ' '], $text);
  }

  /**
   * Shortens a class name, or a Class::method(), to its last segment.
   *
   * @param string $name
   *   The name.
   *
   * @return string
   *   The unqualified name.
   */
  public static function short(string $name): string {
    $position = strrpos($name, '\\');
    return $position === FALSE ? $name : substr($name, $position + 1);
  }

  /**
   * Makes the anchor a heading gets.
   *
   * @param string $id
   *   The heading text.
   *
   * @return string
   *   The anchor, as mkdocs writes it.
   */
  private static function anchor(string $id): string {
    return trim((string) preg_replace('/[^a-z0-9_-]+/', '', strtolower($id)), '-');
  }

}
