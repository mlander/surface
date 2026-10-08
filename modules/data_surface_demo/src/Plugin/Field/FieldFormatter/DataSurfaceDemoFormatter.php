<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\Omitted;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_demo\Surface\DemoFormatterSurface;

/**
 * A field formatter whose settings form is generated from its surface.
 *
 * The plugin keeps its job, showing a value: formatValue(), and nothing
 * else. Its settings and what it emits are DemoFormatterSurface, named
 * with #[UsesSurface]; the formatter host builds it, renders its form,
 * validates it and answers the static defaults from it. No
 * defaultSettings, no settingsForm, no settingsSummary, and no render
 * array. The classic demo writes them out; see
 * modules/data_surface_demo_classic.
 *
 * @see \Drupal\data_surface_demo\Surface\DemoFormatterSurface
 * @see modules/data_surface_demo_classic/README.md
 */
#[FieldFormatter(
  id: 'data_surface_demo_string',
  label: new TranslatableMarkup('Data surface demo formatter'),
  field_types: ['string'],
)]
#[UsesSurface(DemoFormatterSurface::class)]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase {

  /**
   * The prefix every variant class carries.
   */
  public const VARIANT_CLASS_PREFIX = 'data-surface-variant-';

  /**
   * {@inheritdoc}
   */
  public function formatValue(FieldItemInterface $item, array $settings): array {
    $value = (string) ($item->getValue()['value'] ?? '');
    $value = match ($settings['casing'] ?? NULL) {
      'uppercase' => mb_strtoupper($value),
      'lowercase' => mb_strtolower($value),
      default => $value,
    };
    $variant = $settings['variant'] ?? NULL;
    return [
      'text' => (string) ($settings['prefix'] ?? '') . $value,
      // Not NULL and not the empty list: no variant means this formatter
      // has nothing to say about classes on this item.
      'classes' => is_string($variant) && $variant !== ''
        ? [self::VARIANT_CLASS_PREFIX . $variant]
        : Omitted::value(),
    ];
  }

}
