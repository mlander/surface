<?php

declare(strict_types=1);

namespace Drupal\surface_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_test\Structure\TestFormatterStructure;
use Drupal\surface\Plugin\Field\FieldFormatter\TypedFormatterBase;

/**
 * A formatter that adopts structures on a host that never heard of them.
 *
 * #[UsesStructure] and viewElements(). Field UI's missing
 * validate and submit hooks, and the static defaults it prunes against,
 * are the base class's problem.
 */
#[FieldFormatter(
  id: 'surface_test_formatter',
  label: new TranslatableMarkup('Data surface test formatter'),
  field_types: ['string'],
)]
#[UsesStructure(TestFormatterStructure::class)]
final class SurfaceTestFormatter extends TypedFormatterBase {

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
        '#attributes' => $variant ? ['class' => ['surface-variant-' . $variant]] : [],
        '#value' => $prefix . $value,
      ];
    }
    return $elements;
  }

}
