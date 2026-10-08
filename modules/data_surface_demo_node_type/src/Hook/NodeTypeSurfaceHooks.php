<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface\SurfaceBuild\SurfacesInterface;
use Drupal\data_surface_demo_node_type\Surface\NodeTypeSurface;
use Drupal\node\NodeTypeInterface;

/**
 * Hook implementations for the node type surface demo.
 *
 * A class rather than a .module file: core 11.3 discovers hooks from
 * #[Hook] attributes and registers the class as an autowired service, so
 * the implementation holds its services instead of reaching for the
 * container, and a module with no procedural code ships no procedural
 * file.
 */
final class NodeTypeSurfaceHooks {

  use StringTranslationTrait;

  /**
   * The permission opening this module's surface-driven way in.
   *
   * The surface's situations are where it is spelled, because the
   * situation is what answers the access question for every caller; this
   * constant stays as the name existing code already reads.
   */
  public const PERMISSION = NodeTypeSurface::PERMISSION;

  /**
   * Constructs a NodeTypeSurfaceHooks object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which t() would otherwise reach
   *   for through the container on every call.
   * @param \Drupal\data_surface\SurfaceBuild\SurfacesInterface $surfaces
   *   The build step, which answers access for a situation of the
   *   content type surface: the one place this module's access answer is
   *   written down.
   */
  public function __construct(
    TranslationInterface $string_translation,
    protected readonly SurfacesInterface $surfaces,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_entity_operation().
   *
   * Puts the surface-driven edit form beside core's in the content type
   * listing's operations, so the two are one click apart to compare.
   *
   * The link asks exactly what the route it points at asks, and asks it
   * in the one place the question is answered: the content type
   * surface's access for its edit situation with this content type, which
   * is this module's permission and then the entity's own say over it.
   * So the link is never offered where it would be refused, and it cannot
   * drift from the route or from the form's own write. The answer's
   * cacheability travels with the listing.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, CacheableMetadata $cacheability): array {
    if (!$entity instanceof NodeTypeInterface) {
      return [];
    }
    // The situation the route the link points at is served by.
    $access = $this->surfaces->access(
      NodeTypeSurface::class,
      $this->surfaces->situation(NodeTypeSurface::class, 'edit', [$entity]),
    );
    $cacheability->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [];
    }
    return [
      'surface_edit' => [
        'title' => $this->t('Edit (surface)'),
        'url' => Url::fromRoute('data_surface_demo_node_type.edit', ['type' => $entity->id()]),
        'weight' => 30,
      ],
    ];
  }

}
