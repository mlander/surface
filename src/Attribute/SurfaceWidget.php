<?php

declare(strict_types=1);

namespace Drupal\surface\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a data surface widget plugin.
 *
 * Widgets map a data definition to a form element and back. Selection is
 * by applicability with weight as the tiebreaker: lower weights are
 * consulted first, so specialized widgets (select, fieldset) undercut the
 * generic per-type ones. A widget that claims keys is asked first for
 * the keys it claims, on every resource, and for no other key unless the
 * key asks for it by name.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SurfaceWidget extends Plugin {

  /**
   * Constructs a SurfaceWidget attribute.
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
   * @param list<array{type?: string, format?: string, shape?: string}> $claims
   *   (optional) The keys this widget serves on every resource, before
   *   the stock rule, unless a key asks for a widget with its `widget`
   *   setting. Each claim is a data type, `['type' => 'string']`; a data
   *   type and the key's `format` setting, `['type' => 'string', 'format'
   *   => 'color']`; or a shape id, `['shape' => 'event.license']`.
   * @param int $priority
   *   (optional) Which widget serves a key two widgets claim: the higher.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
    public readonly array $claims = [],
    public readonly int $priority = 0,
  ) {
  }

}
