<?php

declare(strict_types=1);

namespace Drupal\data_surface\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a data surface widget plugin.
 *
 * Widgets map a data definition to a form element and back. Selection is
 * by applicability with weight as the tiebreaker: lower weights are
 * consulted first, so specialized widgets (options, map) undercut the
 * generic per-type ones.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DataSurfaceWidget extends Plugin {

  /**
   * Constructs a DataSurfaceWidget attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable label.
   * @param int $weight
   *   Selection order; lower is consulted first.
   * @param class-string|null $deriver
   *   (optional) The deriver class, as every core plugin attribute
   *   accepts one: a widget family generated from something the site
   *   already declares is derived rather than written out.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
