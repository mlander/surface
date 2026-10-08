<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Target;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\Target\SchemaViolations;

/**
 * Keeps one step's registration settings in its own config object.
 *
 * Each step names a subclass that says which object, so the steps never
 * write over each other. The values are stored as the surface accepted
 * them, key for key: a part with no target of its own (the ticket, the
 * contact) is the map at its key, and another module's keys arrive
 * under third_party_settings, which the schema describes per module.
 *
 * The object always exists, from config/install, so there is nothing to
 * create and `creates` is never read.
 */
abstract class RegistrationTarget implements SurfaceTargetInterface {

  /**
   * The config object this step's values live in.
   */
  protected const CONFIG = '';

  /**
   * Constructs a registration target.
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
  public function load(SurfaceContext $context): array {
    $data = $this->configFactory->get(static::CONFIG)->getRawData();
    unset($data['_core']);
    return $data;
  }

  /**
   * {@inheritdoc}
   *
   * Holds the values to the config object's schema, each violation filed
   * under the surface key at the top of its path.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    $keys = array_map('strval', array_keys($values));
    SchemaViolations::check($this->typedConfig, static::CONFIG, $values, array_combine($keys, $keys));
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $config = $this->configFactory->getEditable(static::CONFIG);
    foreach ($prepared as $key => $value) {
      $config->set((string) $key, $value);
    }
    $config->save();
  }

}
