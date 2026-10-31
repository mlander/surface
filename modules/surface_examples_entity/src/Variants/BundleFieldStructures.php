<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Variants;

use Drupal\surface\Resource\BoundSiteListInterface;
use Drupal\surface\Resource\SiteList;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Drupal\surface_examples_entity\Resource\ContentEntityResources;

/**
 * One variant per bundle of an entity type: the bundle's fields.
 *
 * What an entity type's resource, `entity.content:<entity type>`, holds at
 * `fields`, chosen by its `bundle`: every bundle of the entity type with a
 * stored default form display, under its label, each the structure the
 * deriver derives for its fields,
 * `entity.content:<entity type>.<bundle>.fields`. Bound to the entity
 * type by the resource that names it; bound to nothing, it lists nothing.
 *
 * Which bundles there are is the same for every account; which of them an
 * account may create is the bundle key's answer (SurfaceCreatableBundle),
 * so the list carries what decides the bundles and nothing of the
 * account. Past the site list limit the full schema names the bundles and
 * leaves the variants deferred.
 *
 * Decision: see
 * docs/decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type.
 */
final class BundleFieldStructures implements BoundSiteListInterface {

  /**
   * Constructs the provider.
   *
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   Which bundles have a form display.
   */
  public function __construct(
    protected readonly FieldItemDerivation $derivation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->addCacheTags($this->derivation->listCacheTags());
  }

  /**
   * {@inheritdoc}
   *
   * Its one argument is the entity type id.
   */
  public function listFor(array $arguments): SiteList {
    $entity_type_id = (string) ($arguments[0] ?? '');
    $list = $this->list();
    foreach ($this->derivation->bundles()[$entity_type_id] ?? [] as $bundle => $label) {
      $list->add((string) $bundle, $label, structure: ContentEntityResources::BASE . ':' . $entity_type_id . '.' . $bundle . ContentEntityResources::FIELDS_SUFFIX);
    }
    return $list;
  }

}
