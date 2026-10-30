<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

/**
 * A provider of variants whose every variant is a structure its code names.
 *
 * The site still decides which are offered: list() is asked at every
 * build, as for any provider, and may list fewer (one a module that is off
 * cannot back). What it may ever list is named here, statically, by the
 * value that chooses each, so what describes storage ahead of time
 * describes all of them: the config schema written from the owner gives
 * each its own type, as it gives a #[Variant] class, where the variants of
 * an open provider are an open map. Each structure is named once, here:
 * an entry of list() names a value and its label, and the build reads the
 * structure from structures(). It refuses an entry these do not name, and
 * one that names a schema or a structure of its own.
 *
 * @see \Drupal\surface\Storage\ConfigSchemaGenerator
 * @see docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter
 */
interface ClosedSiteListInterface extends SiteListInterface {

  /**
   * Names every structure the provider may list.
   *
   * @return array<string, class-string<\Drupal\surface\Resource\StructureInterface>>
   *   Each structure's class, by the value of the deciding key that
   *   chooses it, in the order they are offered.
   */
  public static function structures(): array;

}
