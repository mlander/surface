<?php

declare(strict_types=1);

namespace Drupal\surface_discovery_test\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Asks the resource registry while the action host is being read.
 *
 * What it saw is kept, so a test can say what a call made from inside the
 * cycle was answered.
 */
final class RegistryAskingActions extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The names the registry answered each time this deriver asked it.
   *
   * @var list<list<string>>
   */
  public static array $seen = [];

  /**
   * Constructs a RegistryAskingActions.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static($container->get('surface.resource_registry'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    static::$seen[] = array_keys($this->registry->getDefinitions());
    return $this->derivatives = [];
  }

}
