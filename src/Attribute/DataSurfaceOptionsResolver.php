<?php

declare(strict_types=1);

namespace Drupal\data_surface\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a data surface options resolver plugin.
 *
 * A resolver knows how to read one constraint as a list of allowed
 * values with their labels. It is the only place that knows how, so
 * widgets, schema emission and any later consumer share one answer.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DataSurfaceOptionsResolver extends Plugin {

  /**
   * Constructs a DataSurfaceOptionsResolver attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable label.
   * @param string $constraint
   *   The ID of the validation constraint plugin this resolver reads.
   * @param class-string|null $deriver
   *   (optional) The deriver class, as every core plugin attribute
   *   accepts one: one resolver per constraint in a family is derived
   *   rather than written out.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $constraint,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
