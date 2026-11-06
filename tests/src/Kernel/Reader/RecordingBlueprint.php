<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel\Reader;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resource\ArrangementGroup;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\Compute;

/**
 * A blueprint that writes down the verbs a reader calls, and checks none.
 *
 * What a reader declares, read without a build: each key's verb, its
 * definition, and what it names (a structure, a provider and its
 * arguments, a map's own keys).
 */
final class RecordingBlueprint implements BlueprintInterface {

  /**
   * Each key declared, in order.
   *
   * @var array<string, array{verb: string, definition: \Drupal\Core\TypedData\DataDefinitionInterface, child?: string, by?: string, from?: string|null, arguments?: array, default?: mixed, map?: \Drupal\Tests\surface\Kernel\Reader\RecordingBlueprint}>
   */
  public array $keys = [];

  /**
   * Each mixInVariants() call, in order, after the key it follows.
   *
   * @var list<array{by: string, from: string|null, arguments: array, after: string|null}>
   */
  public array $mixedInVariants = [];

  /**
   * Records a key.
   *
   * @param string $key
   *   The key.
   * @param string $verb
   *   The verb.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   What it declared.
   * @param array $more
   *   What else it named.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function record(string $key, string $verb, DataDefinitionInterface $definition, array $more = []): DataDefinitionInterface {
    if (isset($this->keys[$key])) {
      throw new \LogicException(sprintf('"%s" is declared twice.', $key));
    }
    $this->keys[$key] = ['verb' => $verb, 'definition' => $definition] + $more;
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function attachVariants(string $key, string $by, ?string $from = NULL, array $arguments = []): MapDataDefinition {
    $map = MapDataDefinition::create();
    $this->record($key, 'attachVariants', $map, ['by' => $by, 'from' => $from, 'arguments' => $arguments]);
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function addEach(string $key, ?string $type, string $from, mixed $default = NULL, ?string $to = NULL, array $bundles = []): MapDataDefinition {
    $map = MapDataDefinition::create();
    $this->record($key, 'addEach', $map, ['from' => $from]);
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function attachEach(string $key, string $structure, string $from, array $reads = []): MapDataDefinition {
    $map = MapDataDefinition::create();
    $this->record($key, 'attachEach', $map, ['child' => $structure, 'from' => $from]);
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function reads(string $port, string $type, bool $required = FALSE): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function compute(string $key, string $type, string|\Stringable $label, array $from, string $using, string $when = Compute::ON_CREATE): DataDefinition {
    $definition = DataDefinition::create($type)->setLabel($label);
    $this->record($key, 'compute', $definition);
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function add(string $key, string $type, string|\Stringable $label, mixed $default = NULL): DataDefinition {
    $definition = DataDefinition::create($type)->setLabel($label);
    $this->record($key, 'add', $definition, ['default' => $default]);
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function addMap(string $key, string|\Stringable $label, callable $define): MapDataDefinition {
    $map = MapDataDefinition::create()->setLabel($label);
    $inner = new self();
    $define($inner);
    $this->record($key, 'addMap', $map, ['map' => $inner]);
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function addList(string $key, string|DataDefinitionInterface $item, string|\Stringable $label, mixed $default = NULL): ListDataDefinition {
    $list = new ListDataDefinition([], is_string($item) ? DataDefinition::create($item) : $item);
    $list->setLabel($label);
    $this->record($key, 'addList', $list);
    return $list;
  }

  /**
   * {@inheritdoc}
   */
  public function addDefinition(string $key, DataDefinitionInterface $definition, mixed $default = NULL): DataDefinitionInterface {
    return $this->record($key, 'addDefinition', $definition, ['default' => $default]);
  }

  /**
   * {@inheritdoc}
   */
  public function describe(string $key, string|\Stringable|null $label = NULL, string|\Stringable|null $description = NULL, ?string $after = NULL, ?ArrangementGroup $group = NULL, ?int $weight = NULL, string|\Stringable|null $placeholder = NULL, string|\Stringable|null $prefix = NULL, string|\Stringable|null $suffix = NULL, string|\Stringable|null $help = NULL): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function attach(string $key, string $structure, array $reads = []): MapDataDefinition {
    $map = MapDataDefinition::create();
    $this->record($key, 'attach', $map, ['child' => $structure]);
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function attachList(string $key, string $structure, ?int $min = NULL, ?int $max = NULL): ListDataDefinition {
    $list = new ListDataDefinition([], MapDataDefinition::create());
    $this->record($key, 'attachList', $list, ['child' => $structure]);
    return $list;
  }

  /**
   * {@inheritdoc}
   */
  public function mixIn(string $structure, array $reads = []): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function mixInVariants(string $by, ?string $from = NULL, array $arguments = []): static {
    $this->mixedInVariants[] = [
      'by' => $by,
      'from' => $from,
      'arguments' => $arguments,
      'after' => array_key_last($this->keys),
    ];
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function extendChoices(string $key, array $choices): static {
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function narrow(string $key): Rule {
    throw new \LogicException('A reader declares no rule.');
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(string $key, array $from, string $using): static {
    return $this;
  }

  /**
   * Walks every key, a map's keys under its own.
   *
   * @param string $at
   *   The path this blueprint's keys are under.
   *
   * @return \Generator<string, array>
   *   Each key's record, by dotted path.
   */
  public function walk(string $at = ''): \Generator {
    foreach ($this->keys as $key => $record) {
      $path = $at === '' ? $key : $at . '.' . $key;
      yield $path => $record;
      if (isset($record['map'])) {
        yield from $record['map']->walk($path);
      }
    }
  }

}
