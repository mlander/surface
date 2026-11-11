<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

/**
 * A plain class with public properties: no #[Document], no shape.
 *
 * Outside discovery: SchemaAttributesTest names it in #[Schema(items:)],
 * where it is read in place, and in #[Of], where it is refused.
 */
final class PlainItem {

  /**
   * The street.
   */
  public string $street;

  /**
   * The city.
   */
  public ?string $city = NULL;

}
