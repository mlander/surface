<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Form\CompiledFlow;
use Drupal\surface\Form\ContractPanel;
use Drupal\surface\Reference\References;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\KeyPaths;
use Drupal\surface\ResourceBuild\ResourceDefinition;
use Drupal\surface\Drush\Generators\Resource\ResourceCode;
use Drupal\surface\Storage\ConfigSchemaGenerator;
use Drupal\surface\Storage\EncryptedStorageLayout;
use Drupal\surface\Storage\FlatVariantsLayout;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\InputDefinitions;
use Drupal\surface\Tool\OperationInputs;

/**
 * Records what the doors and the readers beside them make of a surface.
 *
 * One record per row, by the row's name, `<form> | <kind> | <case>`, for
 * the surface as built (`plain`) and each other form of it:
 * - `door`: the tool door's whole trees, as the Tool API objects hold
 *   them (class, type, label, description, required, default,
 *   constraints, settings, at every depth): fromSurface() alone, with
 *   what is stored and narrowing, withLookups(), withDependencies(),
 *   withStored(), shapeOf(), withoutRequired(), outputFromInput(),
 *   secrets(), outputsFromSurface(), OperationInputs::values() for an
 *   update and a create and stored(); the encrypted layout's secret keys;
 *   the config schema the generator writes, alone, with keys always held
 *   and what it is installed with, installed with nothing for every key,
 *   and with every key kept otherwise, and
 *   each key held to that schema; ResourceCode's output; the flow's
 *   placing of the keys, whole and grouped.
 * - `values`: for what is stored and for each case, the surface refined
 *   by it: its tool tree and secrets, the encrypted layout's secret keys,
 *   withoutNonScalars() of the shape, KeyPaths::pathsOf() and heldOn() of
 *   the values as given, as text and as JSON, the encrypted (fixed codec)
 *   and flat layouts' toStorage() and fromStorage(), the panel's rows and
 *   the formatter's summary.
 * - `key`: for each key, fromDefinition(), outputFromDefinition(),
 *   fromVariants() for variants, the builder side of KeyPaths::held() for
 *   18 raw values in both verbs, and the key held to 12 schemas written
 *   by hand, strict and not.
 * - `path`: for each path into the surface (one level into each child, a
 *   collection's item, each variant and each mount, and four into maps
 *   and lists), KeyPaths::reachedOn() in both verbs, heldOn() of the 18
 *   raw values in both verbs, and References::definitionAt() for no
 *   entity type and for three, with target(), handlerOf(), storedByUuid()
 *   and the data type of what it gives.
 * - `nodes`: References::nodeAt() at each path, with targetIn(),
 *   handlerIn() and the node's data type, which readers older than slice
 *   31b do not have.
 *
 * A definition's tree holds its array (toArray()) with its top-level keys
 * sorted, and a `type` key left out where it holds what getDataType()
 * gives without it (typeWithout()). Every array below the top keeps its
 * order. A definition derived from a node differs from the one its author
 * built only there: the order of its array's top-level keys, and a
 * `type: list` key on a list (4,669 door rows measured, 2026-11-15).
 * Neither is a fact of the definition.
 *
 * What was thrown is recorded as its class and message. Nothing here
 * writes: no storage commits and no fixture is touched.
 */
final class DoorCorpusRecorder {

  /**
   * The values heldOn() and held() are handed at each path.
   */
  protected const RAWS = [
    // cspell:ignore SECRETRAW
    'abc',
    '5',
    '1.5',
    'true',
    '0',
    '',
    '2026-01-01',
    '2026-01-01T10:00:00',
    '2026-01-01T10:00:00+02:00',
    '["a","b"]',
    '{"a":1}',
    ['a', 'b'],
    ['a' => 1],
    5,
    1.5,
    TRUE,
    NULL,
    'SECRETRAW',
  ];

  /**
   * The schemas each key is held to, by name.
   */
  protected const SCHEMAS = [
    'string' => ['type' => 'string'],
    'string nullable' => ['type' => 'string', 'nullable' => TRUE],
    'integer' => ['type' => 'integer'],
    'integer nullable' => ['type' => 'integer', 'nullable' => TRUE],
    'boolean' => ['type' => 'boolean'],
    'float' => ['type' => 'float', 'nullable' => TRUE],
    'ignore' => ['type' => 'ignore'],
    'mapping' => ['type' => 'mapping', 'mapping' => []],
    'sequence string' => ['type' => 'sequence', 'sequence' => ['type' => 'string']],
    'sequence mapping' => ['type' => 'sequence', 'sequence' => ['type' => 'mapping', 'mapping' => []]],
    'constrained' => [
      'type' => 'string',
      'nullable' => TRUE,
      'constraints' => [
        'NotBlank' => [],
        'Length' => ['max' => 3],
        'Choice' => ['choices' => ['a', 'x']],
        'Range' => ['min' => 0, 'max' => 1],
        'Count' => ['max' => 1],
        'Email' => [],
      ],
    ],
    'loose' => [
      'type' => 'string',
      'nullable' => TRUE,
      'constraints' => [
        'Length' => ['max' => 99],
        'Range' => ['min' => -100, 'max' => 100],
        'Count' => ['max' => 99],
      ],
    ],
  ];

  /**
   * Where a heldOn() and a reachedOn() refusal says the value came from.
   */
  protected const WHERE = [
    'resource' => 'hand',
    'at' => 'q.',
    'source' => 'query',
    'noun' => 'value',
    'verb' => 'given',
  ];

  /**
   * The resource the config schema and the tool values are written for.
   */
  protected readonly ResourceDefinition $owner;

  /**
   * Constructs a recorder.
   *
   * @param \Drupal\surface\Tool\InputDefinitions $inputDefinitions
   *   The tool door's converter.
   * @param \Drupal\surface\Tool\OperationInputs $operationInputs
   *   The tool door's per-run inputs.
   * @param \Drupal\surface\ResourceBuild\KeyPaths $keyPaths
   *   The key paths.
   * @param \Drupal\surface\Reference\References $references
   *   The references.
   * @param \Drupal\surface\Storage\ConfigSchemaGenerator $configSchema
   *   The config schema writer.
   * @param \Drupal\surface\Form\ContractPanel $panel
   *   The panel.
   * @param \Drupal\Tests\surface\HandCorpus\HeldToSchemaProbe $probe
   *   What holds a key to a schema.
   */
  public function __construct(
    protected readonly InputDefinitions $inputDefinitions,
    protected readonly OperationInputs $operationInputs,
    protected readonly KeyPaths $keyPaths,
    protected readonly References $references,
    protected readonly ConfigSchemaGenerator $configSchema,
    protected readonly ContractPanel $panel,
    protected readonly HeldToSchemaProbe $probe,
  ) {
    $this->owner = new ResourceDefinition(NULL, 'hand.corpus', 'surface', [], NULL, NULL, []);
  }

  /**
   * Records every row of one surface.
   *
   * @param string $name
   *   One of HandSurfaces::NAMES or DoorSurfaces::NAMES.
   *
   * @return \Generator<string, mixed>
   *   Each row's record, by the row's name.
   */
  public function rows(string $name): \Generator {
    [$surface, $stored, $cases, $alternates] = in_array($name, HandSurfaces::NAMES, TRUE) ? HandSurfaces::build($name) : DoorSurfaces::build($name);
    $forms = ['plain' => [$surface, $stored]];
    foreach ($alternates as $form => [$each, $held]) {
      $forms[$form] = [$each, $held];
    }
    foreach ($forms as $form => [$each, $held]) {
      yield from $this->form((string) $form, $each, $held, $cases);
    }
  }

  /**
   * Records one form of a surface.
   *
   * @param string $form
   *   The form's name.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $stored
   *   What storage holds.
   * @param array<string, array<string, mixed>> $cases
   *   The inputs, by case.
   *
   * @return \Generator<string, mixed>
   *   Each row's record, by the row's name.
   */
  protected function form(string $form, SurfaceInterface $surface, array $stored, array $cases): \Generator {
    $defs = $this->inputDefinitions;
    $values = ['stored' => $stored] + $cases;
    $map = static::attempt(fn (): mixed => $defs->fromSurface($surface, 'L', 'D'));
    $door = [
      'fromSurface' => static::tree($map),
      'fromSurface stored' => static::attempt(fn (): mixed => static::tree($defs->fromSurface($surface, 'L', 'D', stored: $stored))),
      'fromSurface narrowing' => static::attempt(fn (): mixed => static::tree($defs->fromSurface($surface, 'L', 'D', narrowing: 'surface:x:update'))),
    ];
    if (is_object($map)) {
      $door += [
        'withLookups' => static::attempt(fn (): mixed => static::tree($defs->withLookups(clone $map, $surface, '/lookup/x'))),
        'withDependencies' => static::attempt(fn (): mixed => static::tree($defs->withDependencies(clone $map, $surface))),
        'withStored' => static::attempt(fn (): mixed => static::tree($defs->withStored($defs->fromSurface($surface, 'L', 'D'), $surface, $stored))),
        'shapeOf' => static::attempt(fn (): mixed => static::tree(InputDefinitions::shapeOf($map))),
        'withoutRequired' => static::attempt(fn (): mixed => static::tree($defs->withoutRequired($defs->fromSurface($surface, 'L', 'D')))),
        'outputFromInput' => static::attempt(fn (): mixed => static::tree($defs->outputFromInput($map))),
      ];
    }
    $storage = new HandStorage($stored);
    $door += [
      'secrets' => static::attempt(fn (): mixed => static::plain($defs->secrets($surface))),
      'outputs' => static::attempt(fn (): mixed => array_map(static fn (mixed $output): mixed => static::tree($output), $defs->outputsFromSurface($surface))),
      'values update' => static::attempt(fn (): mixed => static::tree($this->operationInputs->values($this->owner, $surface, new OperationContext('update', FALSE), $storage))),
      'values create' => static::attempt(fn (): mixed => static::tree($this->operationInputs->values($this->owner, $surface, new OperationContext('create', TRUE), $storage))),
      'stored' => static::attempt(fn (): mixed => static::plain($this->operationInputs->stored($this->owner, $surface, new OperationContext('update', FALSE), $storage))),
      'secretKeys' => static::attempt(fn (): mixed => EncryptedStorageLayout::secretKeys($surface)),
    ];
    $keys = array_keys($surface->getDefinitions()->toArray());
    $kept_otherwise = ['type' => 'string', 'label' => 'T'];
    $schema = static::attempt(fn (): mixed => $this->configSchema->describeBuilt($surface, $this->owner, 'hand.settings', [], [], []));
    $door += [
      'config schema' => static::plain($schema),
      'config schema held+install' => static::attempt(fn (): mixed => static::plain($this->configSchema->describeBuilt($surface, $this->owner, 'hand.settings', array_slice($keys, 0, 6), ['hand.settings' => ['label' => 'Hand']], $stored))),
      'config schema installed empty' => static::attempt(fn (): mixed => static::plain($this->configSchema->describeBuilt($surface, $this->owner, 'hand.settings', [], [], array_fill_keys($keys, NULL)))),
      'config schema translated' => static::attempt(fn (): mixed => static::plain($this->configSchema->describeBuilt($surface, $this->owner, 'hand.settings', [], [], [], array_fill_keys($keys, $kept_otherwise)))),
    ];
    if (is_array($schema)) {
      $door['wider generated'] = static::attempt(function () use ($surface, $schema): mixed {
        $wider = [];
        foreach ($surface->getDefinitions()->entries() as $name => $entry) {
          $wider[$name] = $this->probe->wider($entry, $schema['entries'][0]['schema']['mapping'][$name] ?? NULL, (string) $name, TRUE);
        }
        return static::plain($wider);
      });
    }
    $door['ResourceCode'] = static::attempt(fn (): mixed => static::plain((new ResourceCode('hand', 'hand'))->write($surface)));
    $placed = new \ReflectionMethod(CompiledFlow::class, 'placed');
    $first = (string) array_key_first($surface->getDefinitions()->toArray());
    $placings = [
      'whole' => [1 => [NULL, NULL]],
      'grouped' => [1 => ['g1', NULL], 2 => [NULL, [$first]], 3 => ['g2', NULL]],
    ];
    foreach ($placings as $steps_name => $steps) {
      $door["flow placed $steps_name"] = static::attempt(fn (): mixed => static::plain($placed->invoke(NULL, $surface, $steps)));
    }
    foreach ($door as $case => $record) {
      yield "$form | door | $case" => $record;
    }

    foreach ($values as $case => $value) {
      yield "$form | values | $case" => $this->valuesRecord($surface, $stored, $value, is_object($map) ? $map : NULL);
    }
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      $record = [
        'fromDefinition' => static::attempt(fn (): mixed => static::tree($defs->fromDefinition($entry->definition()))),
        'outputFromDefinition' => static::attempt(fn (): mixed => static::tree($defs->outputFromDefinition($entry->definition()))),
      ];
      if ($entry->variants !== NULL) {
        $record['fromVariants'] = static::attempt(fn (): mixed => static::tree($defs->fromVariants($entry->variants, $entry->schema())));
      }
      $held = [];
      foreach (self::RAWS as $i => $raw) {
        $held[$i] = [
          static::attempt(fn (): mixed => static::plain($this->keyPaths->held(clone $entry->definition(), $raw, self::WHERE, (string) $name))),
          static::attempt(fn (): mixed => static::plain($this->keyPaths->held(clone $entry->definition(), $raw, ['verb' => 'fixes'] + self::WHERE, (string) $name))),
        ];
      }
      $record['held builder'] = $held;
      $wider = [];
      foreach (self::SCHEMAS as $schema_name => $schema) {
        foreach ([TRUE, FALSE] as $strict) {
          $wider["$schema_name " . (int) $strict] = static::attempt(fn (): mixed => $this->probe->wider($entry, $schema, (string) $name, $strict));
        }
      }
      $record['wider'] = static::plain($wider);
      yield "$form | key | $name" => $record;
    }
    $types = [NULL, 'node', 'user', 'taxonomy_term'];
    foreach (static::paths($surface) as $path) {
      $raws = [];
      foreach (self::RAWS as $i => $raw) {
        $raws[$i] = [
          static::attempt(fn (): mixed => static::plain($this->keyPaths->heldOn($surface, [$path => $raw], self::WHERE))),
          static::attempt(fn (): mixed => static::plain($this->keyPaths->heldOn($surface, [$path => $raw], ['verb' => 'fixes'] + self::WHERE))),
        ];
      }
      $at = [];
      foreach ($types as $type) {
        $at[var_export($type, TRUE)] = static::attempt(function () use ($surface, $path, $type): mixed {
          $definition = $this->references->definitionAt($surface, explode('.', $path), $type);
          return $definition === NULL ? NULL : static::plain([
            'tree' => static::tree($definition),
            'target' => References::target($definition),
            'handler' => static::attempt(fn (): mixed => References::handlerOf($definition)),
            'uuid' => References::storedByUuid($definition),
            'type' => $definition->getDataType(),
          ]);
        });
      }
      yield "$form | path | $path" => [
        'reachedOn' => static::attempt(fn (): mixed => ($reached = $this->keyPaths->reachedOn($surface, $path, self::WHERE)) === NULL ? NULL : static::tree($reached)),
        'reachedOn fixes' => static::attempt(fn (): mixed => ($reached = $this->keyPaths->reachedOn($surface, $path, ['verb' => 'fixes'] + self::WHERE, ['id'])) === NULL ? NULL : static::tree($reached)),
        'heldOn raws' => $raws,
        'definitionAt' => $at,
      ];
      $nodes = [];
      foreach ($types as $type) {
        $nodes[var_export($type, TRUE)] = static::attempt(function () use ($surface, $path, $type): mixed {
          $node = $this->references->nodeAt($surface, explode('.', $path), $type);
          return $node === NULL ? NULL : static::plain([
            'target' => References::targetIn($node),
            'handler' => static::attempt(fn (): mixed => References::handlerIn($node)),
            'type' => NodeFacts::dataType($node),
          ]);
        });
      }
      yield "$form | nodes | $path" => $nodes;
    }
  }

  /**
   * Records what the readers make of the surface refined by one value set.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $stored
   *   What storage holds.
   * @param array<string, mixed> $value
   *   The values.
   * @param \Drupal\tool\TypedData\MapInputDefinition|null $map
   *   The tool door's tree of the surface, or NULL when it threw.
   *
   * @return array<string, mixed>
   *   The record, by reader.
   */
  protected function valuesRecord(SurfaceInterface $surface, array $stored, array $value, mixed $map): array {
    $defs = $this->inputDefinitions;
    $record = [];
    if ($map !== NULL) {
      $record['withoutNonScalars'] = static::attempt(fn (): mixed => static::plain(InputDefinitions::withoutNonScalars(InputDefinitions::shapeOf($map), $value, '')));
    }
    $record['fromSurface refined'] = static::attempt(fn (): mixed => static::tree($defs->fromSurface($surface->refine($value), 'L', 'D', stored: $stored)));
    $record['secrets refined'] = static::attempt(fn (): mixed => static::plain($defs->secrets($surface->refine($value))));
    $record['secretKeys refined'] = static::attempt(fn (): mixed => EncryptedStorageLayout::secretKeys($surface->refine($value)));
    $paths = static::attempt(fn (): mixed => KeyPaths::pathsOf($surface, $value));
    $record['pathsOf'] = static::plain($paths);
    if (is_array($paths)) {
      $strings = array_map(static fn (mixed $one): mixed => is_int($one) || is_float($one) ? (string) $one : (is_bool($one) ? ($one ? '1' : '0') : $one), $paths);
      $record['heldOn'] = static::attempt(fn (): mixed => static::plain($this->keyPaths->heldOn($surface, $paths, self::WHERE)));
      $record['heldOn strings'] = static::attempt(fn (): mixed => static::plain($this->keyPaths->heldOn($surface, $strings, self::WHERE)));
      $record['heldOn json'] = static::attempt(fn (): mixed => static::plain($this->keyPaths->heldOn($surface, array_map(static fn (mixed $one): mixed => is_array($one) ? json_encode($one) : $one, $paths), self::WHERE)));
    }
    $record['encrypted'] = static::attempt(function () use ($surface, $value): mixed {
      $layout = EncryptedStorageLayout::forSurface($surface, new FixedCodec());
      if ($layout === NULL) {
        return NULL;
      }
      $to = $layout->toStorage($value);
      return static::plain(['to' => $to, 'from' => $layout->fromStorage($to)]);
    });
    $record['flat'] = static::attempt(function () use ($surface, $value): mixed {
      $layout = FlatVariantsLayout::forSurface($surface);
      if ($layout === NULL) {
        return NULL;
      }
      $to = $layout->toStorage($value);
      return static::plain(['to' => $to, 'from' => $layout->fromStorage($to), 'schema' => $layout->storedSchema()]);
    });
    $record['panel'] = static::attempt(function () use ($surface, $value): mixed {
      $refined = $surface->refine($value);
      $rows = [];
      $entry_rows = new \ReflectionMethod($this->panel, 'entryRows');
      foreach ($refined->getDefinitions()->entries() as $name => $entry) {
        $declared = $surface->getDefinitions()->entry((string) $name);
        $arguments = [&$rows, (string) $name, $entry, $declared?->definition(), $refined, $value, $declared];
        $entry_rows->invokeArgs($this->panel, $arguments);
      }
      return static::plain($rows);
    });
    $record['formatter'] = static::attempt(fn (): mixed => static::plain((new HandFormatter($surface, $value))->settingsSummary()));
    return $record;
  }

  /**
   * Names the paths into a surface the path records are made at.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, or a child of it.
   * @param string $prefix
   *   The path the frame's keys sit under, with its trailing dot.
   * @param int $level
   *   How many frames down.
   *
   * @return list<string>
   *   The paths, with two no key has at the top.
   */
  protected static function paths(SurfaceInterface $surface, string $prefix = '', int $level = 0): array {
    $paths = $prefix === '' ? ['nope', 'nope.deeper'] : [];
    foreach ($surface->getDefinitions()->entries() as $name => $entry) {
      if ($level > 2) {
        break;
      }
      if ($entry->attachment !== NULL) {
        $paths = array_merge($paths, static::paths($entry->attachment->child, "$prefix$name.", $level + 1));
      }
      if ($entry->list !== NULL) {
        $paths = array_merge($paths, static::paths($entry->list->child(), "$prefix$name.0.", $level + 1));
      }
      if ($entry->variants !== NULL) {
        foreach ($entry->variants->variants as $variant) {
          $paths = array_merge($paths, static::paths($variant->child, "$prefix$name.", $level + 1));
        }
      }
      foreach ($entry->mounted as $module => $mounted) {
        $paths = array_merge($paths, static::paths($mounted->child, "$prefix$name.$module.", $level + 1));
      }
    }
    $walk = static function (DataDefinitionInterface $definition, string $at, int $depth) use (&$walk, &$paths): void {
      $paths[] = $at;
      if ($depth > 3) {
        return;
      }
      if ($definition instanceof ListDataDefinitionInterface) {
        $paths[] = "$at.0";
        $walk($definition->getItemDefinition(), "$at.0", $depth + 1);
        return;
      }
      if ($definition instanceof ComplexDataDefinitionInterface) {
        foreach ($definition->getPropertyDefinitions() as $name => $property) {
          $walk($property, "$at.$name", $depth + 1);
        }
        $paths[] = "$at.nope";
      }
    };
    foreach ($surface->getDefinitions() as $name => $definition) {
      $walk($definition, $prefix . $name, 0);
    }
    return array_values(array_unique($paths));
  }

  /**
   * Writes a definition's whole tree as plain data.
   *
   * Class, type, label, description, required, default, locked,
   * examples, constraints, settings and its array where the class has
   * them, then its properties and its item, at every depth.
   *
   * @param mixed $definition
   *   A definition, or anything else, written as plain().
   * @param int $depth
   *   How deep the walk is.
   *
   * @return mixed
   *   The tree.
   */
  public static function tree(mixed $definition, int $depth = 0): mixed {
    if ($depth > 40) {
      return '@deeper';
    }
    if (!$definition instanceof DataDefinitionInterface) {
      return static::plain($definition, $depth + 1);
    }
    $tree = [
      'class' => get_class($definition),
      'type' => $definition->getDataType(),
      'label' => static::plain($definition->getLabel(), $depth + 1),
      'description' => static::plain($definition->getDescription(), $depth + 1),
      'required' => $definition->isRequired(),
    ];
    foreach (['getDefaultValue', 'isLocked', 'getExamples', 'isMultiple', 'isConvertedFromMultiple'] as $method) {
      if (method_exists($definition, $method)) {
        $tree[$method] = static::attempt(fn (): mixed => static::plain($definition->$method(), $depth + 1));
      }
    }
    $tree['constraints'] = static::attempt(fn (): mixed => static::plain($definition->getConstraints(), $depth + 1));
    $tree['settings'] = static::attempt(fn (): mixed => static::plain($definition->getSettings(), $depth + 1));
    $tree['array'] = static::attempt(function () use ($definition, $depth): mixed {
      $array = method_exists($definition, 'toArray') ? $definition->toArray() : [];
      unset($array['class']);
      if (array_key_exists('type', $array) && $array['type'] === static::typeWithout($definition)) {
        unset($array['type']);
      }
      // The top-level keys alone: an array below them keeps its order.
      ksort($array);
      return static::plain($array, $depth + 1);
    });
    if ($definition instanceof ComplexDataDefinitionInterface) {
      $tree['properties'] = [];
      foreach ($definition->getPropertyDefinitions() as $name => $property) {
        $tree['properties'][$name] = static::tree($property, $depth + 1);
      }
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $tree['item'] = static::tree($definition->getItemDefinition(), $depth + 1);
    }
    return $tree;
  }

  /**
   * Gets the data type a definition gives with no `type` key in its array.
   *
   * A `type` key that holds this says nothing the definition does not say
   * without it: a list is `list` whatever its array holds, and any other
   * definition core makes is `any` with no type. So the key is left out
   * of the tree where it holds this, and kept where it holds anything
   * else. A definition that holds its array elsewhere keeps the key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return string|null
   *   The data type, or NULL when it cannot be asked.
   */
  protected static function typeWithout(DataDefinitionInterface $definition): ?string {
    if (!property_exists($definition, 'definition')) {
      return NULL;
    }
    $bare = clone $definition;
    $member = new \ReflectionProperty($bare, 'definition');
    $array = $member->getValue($bare);
    if (!is_array($array)) {
      return NULL;
    }
    unset($array['type']);
    $member->setValue($bare, $array);
    return $bare->getDataType();
  }

  /**
   * Writes a value as plain data: a definition as its tree, text as text.
   *
   * @param mixed $value
   *   The value.
   * @param int $depth
   *   How deep the walk is.
   *
   * @return mixed
   *   The plain data; any other object is its class.
   */
  public static function plain(mixed $value, int $depth = 0): mixed {
    if ($depth > 60) {
      return '@deeper';
    }
    if ($value instanceof \Closure) {
      return '@closure';
    }
    if ($value instanceof DataDefinitionInterface) {
      return static::tree($value, $depth + 1);
    }
    if ($value instanceof \Stringable) {
      return ['@text' => (string) $value];
    }
    if (is_object($value)) {
      return ['@class' => get_class($value)];
    }
    if (is_array($value)) {
      $plain = [];
      foreach ($value as $key => $member) {
        $plain[$key] = static::plain($member, $depth + 1);
      }
      return $plain;
    }
    if (is_float($value) && !is_finite($value)) {
      return 'float ' . var_export($value, TRUE);
    }
    return $value;
  }

  /**
   * Runs a reader, recording what it answers or what it threw.
   *
   * @param \Closure(): mixed $reader
   *   The reader.
   *
   * @return mixed
   *   What it answers, or the class and message of what it threw, with no
   *   file path or line and no test database prefix.
   */
  public static function attempt(\Closure $reader): mixed {
    try {
      return $reader();
    }
    catch (\Throwable $thrown) {
      $patterns = ['#, called in \S+ on line \d+#', '# in \S+\.php(:\d+| on line \d+)#', '#\btest\d+#'];
      $message = (string) preg_replace($patterns, ['', '', 'test'], $thrown->getMessage());
      return ['thrown' => get_class($thrown), 'message' => $message];
    }
  }

}
