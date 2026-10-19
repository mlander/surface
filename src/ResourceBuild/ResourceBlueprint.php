<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Port;
use Drupal\data_surface\Resource\BlueprintInterface;

/**
 * The owner's blueprint: keys land at the top of the data surface.
 *
 * An input goes through setDefinition() and its default through
 * setDefault(); an output goes through setOutputDefinition(), and a
 * default given for one is written onto it so that the engine refuses it
 * in its own words rather than this class guessing at them.
 *
 * @internal
 */
final class ResourceBlueprint extends BlueprintAdapterBase implements BlueprintInterface {

  /**
   * The slots declared through this blueprint, keyed by key.
   *
   * @var array<string, string>
   */
  protected array $slots = [];

  /**
   * The ports declared through this blueprint, by name.
   *
   * @var array<string, \Drupal\data_surface\Port>
   */
  protected array $ports = [];

  /**
   * {@inheritdoc}
   */
  public function attachBy(string $key, string $by): MapDataDefinition {
    $shell = $this->reserveChild('attachBy', $key);
    $this->slots[$key] = $by;
    return $shell;
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#a-port-is-a-live-sibling-value.
   */
  public function reads(string $port, string $type, bool $required = FALSE): static {
    if ($this->outputs) {
      throw new \LogicException(sprintf('reads() was asked to read "%s" into outputs. An output is emitted, never refined, so it has nothing to read.', $port));
    }
    if (isset($this->ports[$port]) || in_array($port, $this->declared(), TRUE) || $this->isDeclared($port)) {
      throw new \LogicException(sprintf('"%s" is already declared in this blueprint, as %s, so it cannot be read as a port too: a port has a name of its own.', $port, isset($this->ports[$port]) ? 'a port' : 'a key'));
    }
    try {
      $this->typedDataManager->getDefinition($type);
    }
    catch (PluginNotFoundException $e) {
      throw new \InvalidArgumentException(sprintf('The "%s" port reads a "%s", which is no typed data type.', $port, $type), 0, $e);
    }
    $this->ports[$port] = new Port($port, $type, $required);
    $this->builder->addPort($this->ports[$port]);
    return $this;
  }

  /**
   * Gets the ports declared through this blueprint.
   *
   * @return array<string, \Drupal\data_surface\Port>
   *   The ports, by name, in declaration order.
   */
  public function ports(): array {
    return $this->ports;
  }

  /**
   * {@inheritdoc}
   */
  protected function assertNoPort(string $key): void {
    if (isset($this->ports[$key])) {
      throw new \LogicException(sprintf('"%s" is read as a port by this blueprint, so it cannot also be a key: a port is the container\'s key, read under a name of the structure\'s own.', $key));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency(CacheableDependencyInterface $dependency): static {
    $this->builder->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * Gets the slots declared through this blueprint.
   *
   * @return array<string, string>
   *   The deciding key of each slot, keyed by key, in declaration order.
   *   Discovery's #[Variant] classes fill them.
   */
  public function slots(): array {
    return $this->slots;
  }

  /**
   * {@inheritdoc}
   */
  public function declared(): array {
    return array_merge(parent::declared(), array_keys($this->slots));
  }

  /**
   * {@inheritdoc}
   */
  protected function declare(string $key, DataDefinitionInterface $definition, mixed $default): void {
    if ($this->outputs) {
      if ($default !== NULL) {
        DefinitionMetadata::setDefaultValue($definition, $default);
      }
      $this->builder->setOutputDefinition($key, $definition);
      return;
    }
    $this->builder->setDefinition($key, $definition);
    if ($default !== NULL) {
      $this->builder->setDefault($key, $default);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function isDeclared(string $key): bool {
    return $this->outputs
      ? $this->builder->getOutputDefinition($key) !== NULL
      : $this->builder->getDefinition($key) !== NULL;
  }

}
