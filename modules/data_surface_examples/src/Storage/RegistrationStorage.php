<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Storage\SchemaViolations;

/**
 * Keeps one example's registration settings in its own config object.
 *
 * Each example names a subclass that says which object, so the examples never
 * write over each other. The values are stored as the resource accepted
 * them, key for key: a part with no storage of its own (the ticket, the
 * contact) is the map at its key, and another module's keys arrive
 * under third_party_settings, which the schema describes per module.
 *
 * The object always exists, from config/install, so there is nothing to
 * create and `creates` is never read.
 */
abstract class RegistrationStorage implements ResourceStorageInterface {

  /**
   * The config object this example's values live in.
   *
   * Public, so the panel under an example can name the object whose
   * config schema it shows.
   */
  public const CONFIG = '';

  /**
   * Constructs a registration storage.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, which holds the schema prepare() checks.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $data = $this->configFactory->get(static::CONFIG)->getRawData();
    unset($data['_core']);
    return $data;
  }

  /**
   * {@inheritdoc}
   *
   * Holds the values to the config object's schema, each violation filed
   * under the resource's key at the top of its path.
   */
  public function prepare(OperationContext $context, array $values): array {
    $keys = array_map('strval', array_keys($values));
    SchemaViolations::check($this->typedConfig, static::CONFIG, $values, array_combine($keys, $keys));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $config = $this->configFactory->getEditable(static::CONFIG);
    foreach ($prepared as $key => $value) {
      $config->set((string) $key, $value);
    }
    $config->save();
  }

}
