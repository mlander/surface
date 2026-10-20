<?php

/**
 * @file
 * The renderer behind docs/catalog.md.
 *
 * Not autoloadable, like the comparison renderers beside it: it belongs
 * to the generator script, not to the module's runtime. Both
 * scripts/generate-catalog.php and ResourceCatalogTest include it, so
 * the document the script writes and the document the test asserts come
 * from one piece of code.
 *
 * @see scripts/generate-catalog.php
 * @see \Drupal\Tests\data_surface\Kernel\ResourceCatalogTest
 */

declare(strict_types=1);

/**
 * Renders the catalog of resources and structures as markdown.
 */
final class DataSurfaceCatalogDocument {

  /**
   * The environment variable that turns writing on.
   */
  public const WRITE_VARIABLE = 'DATA_SURFACE_WRITE_CATALOGUE';

  /**
   * Where the document lives, relative to the module root.
   */
  public const PATH = 'docs/catalog.md';

  /**
   * Renders the catalog.
   *
   * @param array $catalog
   *   What ResourceCatalog::describe() returns.
   * @param array $derivers
   *   What ResourceCatalog::derivers() returns.
   *
   * @return string
   *   The markdown.
   */
  public static function render(array $catalog, array $derivers = []): string {
    $document = self::intro();
    $document .= "| Resource or structure | Class | Identity | Operations |\n| --- | --- | --- | --- |\n";
    foreach ($catalog as $resource) {
      $document .= sprintf(
        "| [`%s`](#%s) | `%s` | %s | %s |\n",
        $resource['id'],
        self::anchor($resource['id']),
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
            $operation['label'],
            $operation['parameters'] === [] ? 'nothing' : self::codes($operation['parameters']),
            $operation['creates'] === NULL ? 'once given what it needs' : ($operation['creates'] ? 'yes' : 'no'),
            $operation['permission'] === NULL ? 'none' : '`' . $operation['permission'] . '`',
            self::standalone($resource, $operation),
            self::reads($operation),
            self::short($operation['provider']),
            $operation['module'],
          );
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
      foreach ($resource['derived'] as $key => $source) {
        $document .= "\n- Variants at `" . $key . '`, for every other value: derived from ' . $source . ".\n";
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
    return $document;
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
and fields), so this file, generated on a test site with none, lists
the derivers and not what they derive; the catalog service lists
every derivative a site has, with `class` empty and `deriver` naming
the base.

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

Generated by `scripts/generate-catalog.php`; do not edit by hand.
`ResourceCatalogTest` asserts on every run that this file still
matches what that script produces.

Every resource and structure the modules in this repository declare,
read from the static layer alone: the `#[Resource]` and `#[Structure]`
attributes, the `#[Operation]` methods and what each needs, the
`#[Alters]` classes and the `#[Variant]` ones. Each entry says its kind;
a resource also names its storage, while a structure has none of its
own. A plugin that is its own structure, `#[UsesStructure]` with no
argument, is listed by its plugin class, under an id from its plugin
unless it carries `#[Structure]`, and is used by itself. Nothing here
builds anything. An operation's "Creates" is the one fact that is not
static (it is on the context the operation returns), so it is known
only for an operation that needs nothing to start from.

An operation that can be asked "on its own" is also a tool,
`data_surface:<id>:<operation>`, when `data_surface_tool` is enabled:
it belongs to a resource that names storage and is no plugin's
configuration, and every `%key` placeholder in its permission can be
supplied by one of its parameters. A structure a plugin uses is
configured through the plugin's host. One that can be asked on its own
and changes a thing that exists is "read on its own" too, a read tool,
`data_surface:<id>:<operation>:read`: an operation that needs
nothing says so by not creating, and one that needs a subject by naming
one thing whole, with a single entity or with every identity key.
`docs/resources.md` says how each part works.


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
      $operation['creates'] === TRUE => 'no: it creates',
      default => 'no: what it needs leaves room for a new one',
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
   * Shortens a class name, or a Class::method(), to its last segment.
   *
   * @param string $name
   *   The name.
   *
   * @return string
   *   The unqualified name.
   */
  private static function short(string $name): string {
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
