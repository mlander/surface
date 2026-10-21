<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\data_surface\DataSurfaceInterface;

/**
 * A storage that holds what it writes to a config schema.
 *
 * Whatever the resource advertises, the storage then refuses when the
 * config schema says less: a type the schema types otherwise, null where
 * the schema calls the key not nullable, a value its constraints refuse.
 * A storage implementing this says where the resource is wider than that
 * schema, and the build refuses such a resource, naming each key, so the
 * contract never advertises what storage will refuse. A schema generated
 * from the resource (drush data_surface:config-schema) is never narrower;
 * one the module did not generate, core's or an adopter's written by
 * hand, is held to it.
 *
 * @see \Drupal\data_surface\Storage\HeldToConfigSchemaTrait
 * @see docs/storage.md#a-schema-the-module-did-not-generate
 */
interface HeldToConfigSchemaInterface {

  /**
   * Names the config schema type the stored keys are held to.
   *
   * @return string
   *   A config object's name, or a pattern core's schema names, such as
   *   `node.type.*`.
   */
  public function configSchemaType(): string;

  /**
   * Says where a built resource is wider than the schema it is stored to.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The resource, as built and sealed.
   *
   * @return array<string, string>
   *   Why, by the dotted path of each key the schema says less for; empty
   *   when it says no less anywhere, or when no schema describes it.
   */
  public function widerThanSchema(DataSurfaceInterface $built): array;

}
