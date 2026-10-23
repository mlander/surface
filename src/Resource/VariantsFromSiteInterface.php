<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Lists the variants a site has at one key, each with its JSON Schema.
 *
 * Named by class where a blueprint attaches the variants,
 * `attachVariants('props', by: 'component', from: ComponentProps::class)`,
 * and asked once per build, as a keys provider is: the resource stays
 * static, and what reads the site is this. Each entry is one value of the
 * deciding key, its label, and the schema of the structure that value
 * chooses, which SchemaReader reads into a variant; the variants a class
 * declares with #[Variant] stand beside them, and win for a value both
 * name. It is built by the class resolver, so it is a service named by
 * its class (autowired), a class implementing ContainerInjectionInterface,
 * or one with no constructor arguments.
 *
 * What it answers carries the cacheability of what it read, which
 * reaches the built resource, the form, both schema documents and the
 * tool listing. A list that cannot be cached (max-age 0) is refused, and
 * so is one that varies by the account: which variants there are is the
 * site's, and who may choose one is access.
 *
 * @see \Drupal\data_surface\Resource\VariantList
 * @see docs/resources.md#variants-from-the-site
 */
interface VariantsFromSiteInterface {

  /**
   * Lists the variants, one per value of the deciding key.
   *
   * @return \Drupal\data_surface\Resource\VariantList
   *   The entries, in order, with the cacheability of what they were read
   *   from.
   */
  public function variants(): VariantList;

}
