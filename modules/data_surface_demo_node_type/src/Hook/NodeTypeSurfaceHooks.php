<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider;
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
   * The provider is where it is spelled, because the provider is what
   * answers the access question for every caller; this constant stays as
   * the name existing code already reads.
   */
  public const PERMISSION = NodeTypeSurfaceProvider::PERMISSION;

  /**
   * Constructs a NodeTypeSurfaceHooks object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, which t() would otherwise reach
   *   for through the container on every call.
   * @param \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider $provider
   *   The surface provider, which is the one place this module's access
   *   answer is written down.
   */
  public function __construct(
    TranslationInterface $string_translation,
    protected readonly NodeTypeSurfaceProvider $provider,
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
   * in the one place the question is answered: the provider's access
   * answer for the edit operation with this content type as its
   * subject, which is the entity's own say over it ANDed with this
   * module's permission. So the link is never offered where it would be
   * refused, and it cannot drift from the route or from the form's own
   * write. The answer's cacheability travels with the listing.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity, CacheableMetadata $cacheability): array {
    if (!$entity instanceof NodeTypeInterface) {
      return [];
    }
    // The pair the route the link points at is addressed by: the verb,
    // then the content type it is about.
    $access = $this->provider->surfaceAccess(
      NodeTypeSurfaceProvider::OPERATION_EDIT,
      (string) $entity->id(),
    );
    $cacheability->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [];
    }
    return [
      'surface_edit' => [
        'title' => $this->t('Edit (surface)'),
        'url' => Url::fromRoute('data_surface_demo_node_type.edit', ['node_type' => $entity->id()]),
        'weight' => 30,
      ],
    ];
  }

}
