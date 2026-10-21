<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * Lists the things a site has, so a resource can ask one key per thing.
 *
 * Named by class where a blueprint declares the keys, `addEach('forms',
 * 'boolean', from: ProtectedForms::class)` or `attachEach('rows',
 * RowStructure::class, from: ...)`, and asked once per build, as an
 * options resolver is asked for a list: the resource stays static, and
 * what reads the site is this. It is built by the class resolver, so it
 * is a service named by its class (autowired), a class implementing
 * ContainerInjectionInterface, or one with no constructor arguments, and
 * may hold the services it reads.
 *
 * What it answers carries the cacheability of what it read. That reaches
 * the built resource, the form, both schema documents and the tool
 * listing, each of which is cached until one of its tags is invalidated;
 * a provider that reads State or configuration names it there. A list
 * that cannot be cached (max-age 0) is refused, and so is one that varies
 * by the account: which keys there are is the site's, the same for every
 * account, and who may change one is access.
 *
 * @see \Drupal\data_surface\Resource\KeyedList
 * @see docs/resources.md#keys-that-come-from-the-site
 */
interface KeysFromSiteInterface {

  /**
   * Lists the things, one key each.
   *
   * @return \Drupal\data_surface\Resource\KeyedList
   *   The entries, in order, with the cacheability of what they were read
   *   from.
   */
  public function keys(): KeyedList;

}
