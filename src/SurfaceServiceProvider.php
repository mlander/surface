<?php

declare(strict_types=1);

namespace Drupal\surface;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\surface\ResourceBuild\ResourceCollectorPass;
use Drupal\surface\Tool\InputDefinitions;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Tool\ToolDefinitionsInvalidator;
use Drupal\surface\Tool\ToolDoors;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds the pass that discovers resources and structures in every module.
 *
 * And registers the Tool API door's services when the tool module is
 * installed. The module does not depend on the tool module: its tool
 * plugins are found only by the tool module's plugin manager, and these
 * services take that manager or build the Tool API's input and output
 * definitions, so without the tool module they would name a service or a
 * class the site does not have.
 */
final class SurfaceServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $container->addCompilerPass(new ResourceCollectorPass());

    $modules = $container->getParameter('container.modules');
    if (is_array($modules) && isset($modules['tool'])) {
      $this->registerToolDoor($container);
    }
  }

  /**
   * Registers the services of the Tool API door.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container being built, on a site with the tool module.
   */
  protected function registerToolDoor(ContainerBuilder $container): void {
    // A resource's keys as the Tool API's input and output definitions.
    $container->register('surface.input_definitions', InputDefinitions::class)
      ->addArgument(new Reference('surface.options'))
      ->addArgument(new Reference('string_translation'))
      ->addArgument(new Reference('surface.references'))
      ->addArgument(new Reference('surface.site_list_budget'));

    // What a tool generated for one operation of one resource takes and
    // answers with; the deriver writes it statically, the tool refines it.
    $container->register('surface.operation_inputs', OperationInputs::class)
      ->addArgument(new Reference('surface.resources'))
      ->addArgument(new Reference('surface.operation_arguments'))
      ->addArgument(new Reference('surface.input_definitions'))
      ->addArgument(new Reference('string_translation'))
      ->addArgument(new Reference('module_handler'))
      ->addArgument(new Reference('surface_react.served_paths', ContainerInterface::IGNORE_ON_INVALID_REFERENCE));

    // The derived tools are kept by the Tool API until the next cache
    // rebuild; this clears them when what they were derived from changes.
    $container->register('surface.tool_definitions_invalidator', ToolDefinitionsInvalidator::class)
      ->addArgument(new Reference('service_container'))
      ->addTag('cache_tags_invalidator');

    // The tool, the read tool and the narrowing tool an operation reaches,
    // for the site catalog.
    $container->register('surface.tool_doors', ToolDoors::class)
      ->addArgument(new Reference('plugin.manager.tool'))
      ->addTag('surface.catalog_doors', ['priority' => 20]);
  }

}
