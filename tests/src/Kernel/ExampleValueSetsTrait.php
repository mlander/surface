<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;

/**
 * Builds the value sets and places a data surface is asked at.
 *
 * For a test that asks every place of every shipped example under the
 * values that choose each variant and hold an item in each list: the
 * value sets (valueSets()), the places they hold (places()), and a walk of
 * a served document's schemas (walk()).
 */
trait ExampleValueSetsTrait {

  /**
   * Builds the value sets a data surface's places are asked at.
   *
   * The defaults; the defaults with one item in each list and collection
   * that holds none (an item of a collection is its child's defaults,
   * with one item in each of its own lists); and, read from the served
   * document, each choice of each key with variants and each other value
   * a key some refiner or conditional watches may take (each option of
   * its list, TRUE and FALSE, its bounds, 1, NULL), each laid over the
   * defaults as the form lays an edit over what is stored
   * (SurfaceFormBuilderInterface::refinementOverlay()).
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, array>
   *   The values, by a name for the set.
   */
  protected function valueSets(SurfaceInterface $surface): array {
    $defaults = $surface->getDefaultValues();
    $sets = ['defaults' => $defaults, 'one item each' => static::withItems($surface, $defaults)];
    $builder = $this->container->get('surface.form_builder');
    $document = json_decode((string) json_encode($builder->envelope($surface, $defaults)->document['schema']), TRUE);
    $schemas = [];
    $sources = [];
    foreach (static::walk($document, $document) as [$path, $schema]) {
      $schemas[implode('/', $path)] ??= $schema;
      $frame = array_slice($path, 0, -1);
      foreach ((array) ($schema['x-surface']['dependsOn'] ?? []) as $watched) {
        if (is_string($watched)) {
          $sources[implode('/', [...$frame, $watched])] = [...$frame, $watched];
        }
      }
      if (is_string($schema['x-surface']['by'] ?? NULL)) {
        $sources[implode('/', [...$frame, $schema['x-surface']['by']])] = [...$frame, $schema['x-surface']['by']];
      }
    }
    foreach ($sources as $key => $path) {
      if (!is_array($schemas[$key] ?? NULL)) {
        continue;
      }
      $current = NestedArray::getValue($defaults, $path);
      $alternatives = static::alternatives($schemas[$key], $current);
      foreach ($schemas as $at => $schema) {
        // A key with variants that this key decides: each of its choices.
        $deciding = [...array_slice(explode('/', (string) $at), 0, -1), $schema['x-surface']['by'] ?? NULL];
        if (is_string($schema['x-surface']['by'] ?? NULL) && implode('/', $deciding) === $key) {
          foreach (array_keys((array) ($schema['x-surface']['variants'] ?? [])) as $variant) {
            if ((string) $variant !== $current) {
              $alternatives[] = (string) $variant;
            }
          }
        }
      }
      foreach (array_unique($alternatives, SORT_REGULAR) as $alternative) {
        $input = $defaults;
        NestedArray::setValue($input, $path, $alternative, TRUE);
        $values = $builder->refinementOverlay($surface, $defaults, $input);
        unset($values[SurfaceFormBuilderInterface::STANDING_KEY]);
        $sets[$key . '=' . json_encode($alternative)] = $values;
      }
    }
    return $sets;
  }

  /**
   * Gives values one item in each list and collection that holds none.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   *
   * @return array
   *   The values, with the items.
   */
  protected static function withItems(SurfaceInterface $surface, array $values): array {
    foreach ($surface->entries()->entries() as $name => $entry) {
      $held = $values[$name] ?? NULL;
      if (is_array($held) && $held !== []) {
        continue;
      }
      if ($entry->list !== NULL) {
        $child = $entry->list->child();
        $values[$name] = [static::withItems($child, $child->getDefaultValues())];
      }
      elseif (!$entry->isNested() && $entry->definition() instanceof ListDataDefinitionInterface) {
        $item = $entry->definition()->getItemDefinition();
        $values[$name] = [
          match ($item->getDataType()) {
            'integer' => 1,
            'float' => 1.5,
            'boolean' => TRUE,
            default => $item instanceof ComplexDataDefinitionInterface ? [] : 'a',
          },
        ];
      }
    }
    return $values;
  }

  /**
   * Walks a served document's schemas, through its defs and branches.
   *
   * @param array $document
   *   The document's schema.
   * @param mixed $schema
   *   The schema walked from.
   * @param array $path
   *   Its path: names, and 0 for an item.
   * @param int $depth
   *   How deep the walk is.
   *
   * @return \Generator<int, array{0: array, 1: array}>
   *   Each schema's path and the schema, its def's keywords merged in.
   */
  protected static function walk(array $document, mixed $schema, array $path = [], int $depth = 0): \Generator {
    for ($hops = 0; is_array($schema) && is_string($schema['$ref'] ?? NULL) && str_starts_with($schema['$ref'], '#/$defs/') && $hops < 20; $hops++) {
      $def = $document['$defs'][substr($schema['$ref'], 8)] ?? NULL;
      unset($schema['$ref']);
      $schema = is_array($def) ? $schema + $def : $schema;
    }
    if (!is_array($schema) || $depth > 8) {
      return;
    }
    yield [$path, $schema];
    foreach ($schema['properties'] ?? [] as $name => $property) {
      yield from static::walk($document, $property, [...$path, (string) $name], $depth + 1);
    }
    if (is_array($schema['items'] ?? NULL)) {
      yield from static::walk($document, $schema['items'], [...$path, 0], $depth + 1);
    }
    foreach (['anyOf', 'oneOf'] as $keyword) {
      foreach ($schema[$keyword] ?? [] as $branch) {
        if (is_array($branch) && (isset($branch['$ref']) || isset($branch['properties']))) {
          foreach (static::walk($document, $branch, $path, $depth + 1) as [$below, $inside]) {
            if ($below !== $path) {
              yield [$below, $inside];
            }
          }
        }
      }
    }
  }

  /**
   * Lists the other values a watched key may take.
   *
   * @param array $schema
   *   The key's schema.
   * @param mixed $current
   *   What it holds.
   *
   * @return array
   *   At most six values: each option of its list, TRUE and FALSE for a
   *   boolean, its bounds and 1 for a number, NULL where it takes null.
   */
  protected static function alternatives(array $schema, mixed $current): array {
    $alternatives = [];
    foreach ($schema['oneOf'] ?? [] as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        $alternatives[] = $branch['const'];
      }
    }
    foreach ($schema['items']['oneOf'] ?? [] as $branch) {
      if (is_array($branch) && array_key_exists('const', $branch)) {
        $alternatives[] = [$branch['const']];
      }
    }
    $type = (array) ($schema['type'] ?? []);
    if (in_array('boolean', $type, TRUE)) {
      array_push($alternatives, TRUE, FALSE);
    }
    if (array_intersect(['integer', 'number'], $type) !== []) {
      foreach (['minimum', 'maximum'] as $bound) {
        if (isset($schema[$bound])) {
          $alternatives[] = $schema[$bound];
        }
      }
      $alternatives[] = 1;
    }
    if (in_array('null', $type, TRUE)) {
      $alternatives[] = NULL;
    }
    $alternatives = array_filter($alternatives, static fn (mixed $alternative): bool => $alternative !== $current);
    return array_slice(array_values(array_unique($alternatives, SORT_REGULAR)), 0, 6);
  }

  /**
   * Lists the places of a data surface's values a check may be asked at.
   *
   * Every pointer the values hold; each key, each property and item below
   * a plain key down to the values given, each item of a collection and
   * each key of an attached child; and a name nothing declares beside
   * each frame.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function places(SurfaceInterface $surface, array $values): array {
    $held = [];
    static::held($values, '', $held);
    return array_values(array_unique([...static::pointers($surface, $values), ...$held]));
  }

  /**
   * Lists every pointer a value holds.
   *
   * @param mixed $value
   *   The value.
   * @param string $pointer
   *   Its pointer.
   * @param string[] $pointers
   *   The pointers so far; gains these.
   */
  protected static function held(mixed $value, string $pointer, array &$pointers): void {
    $pointers[] = $pointer;
    foreach (is_array($value) ? $value : [] as $key => $inside) {
      static::held($inside, Location::append($pointer, (string) $key), $pointers);
    }
  }

  /**
   * Lists the places of a data surface's declared keys.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   Its values.
   * @param string $pointer
   *   The pointer of the frame.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function pointers(SurfaceInterface $surface, array $values, string $pointer = ''): array {
    $pointers = [$pointer, Location::append($pointer, 'nothing_declares_this')];
    foreach ($surface->entries()->entries() as $name => $entry) {
      $at = Location::append($pointer, (string) $name);
      $value = $values[$name] ?? NULL;
      if ($entry->list !== NULL) {
        $pointers[] = $at;
        foreach (is_array($value) ? array_values($value) : [] as $delta => $item) {
          $inside = static::pointers($entry->list->child(), is_array($item) ? $item : [], Location::append($at, $delta));
          $pointers = [...$pointers, ...$inside];
        }
        continue;
      }
      $pointers = [...$pointers, ...static::below($entry->definition(), $value, $at)];
    }
    return $pointers;
  }

  /**
   * Lists the places below a plain key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param mixed $value
   *   Its value.
   * @param string $pointer
   *   Its pointer.
   *
   * @return string[]
   *   The pointers.
   */
  protected static function below(DataDefinitionInterface $definition, mixed $value, string $pointer): array {
    $pointers = [$pointer];
    if ($definition instanceof ListDataDefinitionInterface) {
      $items = is_array($value) ? array_values($value) : [NULL];
      foreach ($items as $delta => $item) {
        $inside = static::below($definition->getItemDefinition(), $item, Location::append($pointer, $delta));
        $pointers = [...$pointers, ...$inside];
      }
    }
    elseif ($definition instanceof ComplexDataDefinitionInterface) {
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $inside = static::below($property, is_array($value) ? $value[$name] ?? NULL : NULL, Location::append($pointer, (string) $name));
        $pointers = [...$pointers, ...$inside];
      }
    }
    return $pointers;
  }

}
