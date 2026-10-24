<?php

declare(strict_types=1);

namespace Drupal\data_surface_tool;

use Drupal\Component\Plugin\Discovery\DiscoveryInterface;
use Drupal\data_surface\ResourceBuild\CatalogDoorsInterface;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceDefinition;
use Drupal\data_surface\ResourceBuild\SiteCatalog;

/**
 * The tool and the read tool derived for an operation.
 *
 * Read off the Tool API's own definitions, which the deriver wrote for no
 * account, so the catalog lists the tools that exist, not the ones the
 * rule says should.
 */
final class ToolDoors implements CatalogDoorsInterface {

  /**
   * Constructs a ToolDoors.
   *
   * @param \Drupal\Component\Plugin\Discovery\DiscoveryInterface $tools
   *   The tool plugin manager.
   */
  public function __construct(
    protected readonly DiscoveryInterface $tools,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function doors(ResourceDefinition $resource, OperationDefinition $operation, bool $standalone, bool $reads): array {
    $id = 'data_surface:' . $resource->id . ':' . $operation->id;
    return array_filter([
      SiteCatalog::TOOL => $this->tools->hasDefinition($id) ? $id : NULL,
      SiteCatalog::READ_TOOL => $this->tools->hasDefinition($id . ':read') ? $id . ':read' : NULL,
    ]);
  }

}
