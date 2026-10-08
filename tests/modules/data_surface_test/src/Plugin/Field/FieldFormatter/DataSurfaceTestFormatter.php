<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Surface\TestFormatterSurface;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;

/**
 * A formatter that adopts surfaces on a host that never heard of them.
 *
 * #[UsesSurface] and viewElements(). Field UI's missing
 * validate and submit hooks, and the static defaults it prunes against,
 * are the base class's problem.
 */
#[FieldFormatter(
  id: 'data_surface_test_formatter',
  label: new TranslatableMarkup('Data surface test formatter'),
  field_types: ['string'],
)]
#[UsesSurface(TestFormatterSurface::class)]
final class DataSurfaceTestFormatter extends DataSurfaceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    $prefix = (string) ($this->getSetting('prefix') ?? '');
    $casing = $this->getSetting('casing');
    $variant = $this->getSetting('variant');
    foreach ($items as $delta => $item) {
      $value = (string) ($item->getValue()['value'] ?? '');
      $value = match ($casing) {
        'uppercase' => mb_strtoupper($value),
        'lowercase' => mb_strtolower($value),
        default => $value,
      };
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => $variant ? ['class' => ['data-surface-variant-' . $variant]] : [],
        '#value' => $prefix . $value,
      ];
    }
    return $elements;
  }

}
