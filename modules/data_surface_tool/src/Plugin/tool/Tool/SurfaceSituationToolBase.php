<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\SurfaceBuild\SurfaceDefinition;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_tool\SituationInputs;
use Drupal\tool\Tool\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the write and the read tool for one situation share.
 *
 * Both are derived from one situation of one surface and read which from
 * their derivative id, `<surface id>:<situation id>` and, for a read,
 * SurfaceSituationReadTool::SUFFIX after it. Both build the situation's
 * context from the parameters the same way, and both answer access the
 * same way: the situation's permission, then the surface's access class,
 * for that context.
 *
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\SurfaceSituationTool
 * @see \Drupal\data_surface_tool\Plugin\tool\Tool\SurfaceSituationReadTool
 */
abstract class SurfaceSituationToolBase extends ToolBase {

  /**
   * What follows `<surface id>:<situation id>` in the derivative id.
   */
  public const SUFFIX = '';

  /**
   * The build step.
   */
  protected SurfacesInterface $surfaces;

  /**
   * What discovery found.
   */
  protected SurfaceRegistry $surfaceRegistry;

  /**
   * What the tool takes and answers with.
   */
  protected SituationInputs $situationInputs;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->surfaces = $container->get('data_surface.surfaces');
    $instance->surfaceRegistry = $container->get('data_surface.surface_registry');
    $instance->situationInputs = $container->get('data_surface_tool.situation_inputs');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(array $values, AccountInterface $account, $return_as_object = FALSE): bool|AccessResultInterface {
    try {
      [$surface, , $context] = $this->situation($values);
      $result = $this->situationAccess($surface, $context, $account);
    }
    catch (\InvalidArgumentException $e) {
      $result = AccessResult::forbidden($e->getMessage());
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * Builds the situation this tool is for, from its parameters' values.
   *
   * @param array $values
   *   The tool's input values; the parameters are read by name.
   *
   * @return array{0: \Drupal\data_surface\SurfaceBuild\SurfaceDefinition, 1: \Drupal\data_surface\SurfaceBuild\SituationDefinition, 2: \Drupal\data_surface\Surface\SurfaceContext}
   *   The surface, the situation and the context it built.
   *
   * @throws \InvalidArgumentException
   *   When a parameter is missing or names nothing, which the Tool API
   *   reports as the caller's input to correct.
   */
  protected function situation(array $values): array {
    $derivative = (string) $this->getDerivativeId();
    if (static::SUFFIX !== '' && str_ends_with($derivative, static::SUFFIX)) {
      $derivative = substr($derivative, 0, -strlen(static::SUFFIX));
    }
    $separator = strrpos($derivative, ':');
    if ($separator === FALSE) {
      throw new \LogicException(sprintf('The %s tool is not one of the derived surface tools.', $this->getPluginId()));
    }
    $surface = $this->surfaceRegistry->getDefinition(substr($derivative, 0, $separator));
    $situation = $this->surfaceRegistry->getSituation($surface->name(), substr($derivative, $separator + 1));
    $arguments = [];
    foreach ($situation->parameters as $parameter) {
      if (($values[$parameter->name] ?? NULL) !== NULL) {
        $arguments[$parameter->name] = $values[$parameter->name];
      }
    }
    return [$surface, $situation, $this->surfaces->situation($surface->name(), $situation->id, $arguments)];
  }

  /**
   * Answers access for a situation's context, decisively.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceDefinition $surface
   *   The surface.
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed or forbidden, never neutral.
   */
  protected function situationAccess(SurfaceDefinition $surface, SurfaceContext $context, AccountInterface $account): AccessResultInterface {
    return DataSurfaceAccess::decisive(
      $this->surfaces->access($surface->name(), $context, $account),
      'The situation\'s permission and the surface\'s own access both have to allow this.',
    );
  }

}
