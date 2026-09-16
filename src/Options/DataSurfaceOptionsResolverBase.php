<?php

declare(strict_types=1);

namespace Drupal\data_surface\Options;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Validation\ConstraintManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Common ground for data surface options resolvers.
 *
 * Applicability is answered from the declared constraint plugin ID: the
 * resolver serves that constraint's class and anything extending it, so
 * a constraint that refines another is read by the same resolver unless
 * one of its own is declared. A resolver that needs more than the class
 * to decide narrows this further.
 */
abstract class DataSurfaceOptionsResolverBase extends PluginBase implements DataSurfaceOptionsResolverInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a DataSurfaceOptionsResolverBase.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Validation\ConstraintManager $constraintManager
   *   The constraint plugin manager, which names the class behind the
   *   constraint plugin ID this resolver declares.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly ConstraintManager $constraintManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('validation.constraint'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Constraint $constraint): bool {
    $class = $this->constraintManager->getDefinition($this->pluginDefinition['constraint'])['class'];
    return $constraint instanceof $class;
  }

}
