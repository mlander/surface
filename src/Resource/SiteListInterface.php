<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Lists the things a site has, for a key whose list the site decides.
 *
 * Named by class where a blueprint declares what it fills:
 *
 * - `addEach('forms', 'boolean', from: ProtectedForms::class)`, one key
 *   per thing, a value of the type or shape named;
 * - `attachEach('rows', RowStructure::class, from: ...)`, one key per
 *   thing, the structure named at each;
 * - `attachVariants('props', by: 'component', from: ComponentProps::class)`,
 *   one variant per thing, chosen by the deciding key's value: each entry
 *   names the JSON Schema of the structure it chooses, which SchemaReader
 *   reads into a variant, or the structure by class. The variants a class
 *   declares with #[Variant] stand beside them, and win for a value both
 *   name.
 *
 * Each reads what it needs of an entry and refuses what it does not read:
 * a key per thing takes an id, a label, and optionally a start and a lock;
 * a variant takes a value, a label, and a schema or a structure. Asked
 * once per build, as an options resolver is asked for a list: the
 * resource stays static, and what reads the site is this. It is built by
 * the class resolver, so it is a service named by its class (autowired),
 * a class implementing ContainerInjectionInterface, or one with no
 * constructor arguments, and may hold the services it reads.
 *
 * What it answers carries the cacheability of what it read. That reaches
 * the built resource, the form, both schema documents and the tool
 * listing, each of which is cached until one of its tags is invalidated;
 * a provider that reads State or configuration names it there. A list
 * that cannot be cached (max-age 0) is refused, and so is one that varies
 * by the account: what the site has is the same for every account, and
 * who may change or choose an entry is access.
 *
 * @see \Drupal\data_surface\Resource\SiteList
 * @see docs/resources.md#keys-that-come-from-the-site
 * @see docs/resources.md#variants-from-the-site
 */
interface SiteListInterface {

  /**
   * Lists the things, one entry each.
   *
   * @return \Drupal\data_surface\Resource\SiteList
   *   The entries, in order, with the cacheability of what they were read
   *   from.
   */
  public function list(): SiteList;

}
