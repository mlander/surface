<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a key's property: the widget it asks for, its `widget` setting.
 *
 * The spelling of setSetting('widget', ...), checked at the build like
 * every widget a key asks for.
 *
 * @code
 * #[Widget('time')]
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Widget {

  /**
   * Constructs a Widget attribute.
   *
   * @param string $widget
   *   The widget's word.
   */
  public function __construct(public readonly string $widget) {}

}
