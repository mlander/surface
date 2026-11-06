<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * A provider of variants whose list depends on what its owner binds.
 *
 * One class lists for several owners: the bundles of whichever entity
 * type a derived resource is for. The owner names it with the arguments
 * that say which list, as a derived resource's inputs are a class, a
 * method and the arguments bound to them:
 *
 * @code
 * $inputs->attachVariants('fields', by: 'bundle', from: BundleFields::class, arguments: ['node']);
 * @endcode
 *
 * The build asks listFor() with those arguments, under the same rules as
 * list(): cacheable, the same for every account, each entry a value, its
 * label and a schema or a structure. list() is what it lists bound to
 * nothing.
 *
 * Decision: see
 * docs/decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type.
 */
interface BoundSiteListInterface extends SiteListInterface {

  /**
   * Lists the things for the arguments an owner bound, one entry each.
   *
   * @param array $arguments
   *   What the owner bound. The shape is the provider's: a list read by
   *   position, or keys read by name.
   *
   * @return \Drupal\surface\Resource\SiteList
   *   The entries, in order, with the cacheability of what they were read
   *   from.
   */
  public function listFor(array $arguments): SiteList;

}
