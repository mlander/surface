<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;

/**
 * A formatter that adopts surfaces on a host that never heard of them.
 *
 * Field UI has no validate or submit hook for settings and prunes what
 * it saves against a static defaults array; the base class and its trait
 * answer both from this class's attribute, so the formatter itself holds
 * its declaration, its refiner, and viewElements().
 */
#[FieldFormatter(
  id: 'data_surface_test_formatter',
  label: new TranslatableMarkup('Data surface test formatter'),
  field_types: ['string'],
)]
#[DataSurfaceAware(
  definitions: [
    'prefix' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Prefix'),
      'description' => new TranslatableMarkup('Text placed before each value.'),
      'required' => FALSE,
      'constraints' => ['Length' => ['max' => 10]],
    ]),
    'casing' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Casing'),
      'required' => FALSE,
      'default_value' => 'none',
      'constraints' => [
        'LabeledChoice' => [
          'choices' => ['none', 'uppercase', 'lowercase'],
          'labels' => [
            'none' => new TranslatableMarkup('As written'),
            'uppercase' => new TranslatableMarkup('Upper case'),
            'lowercase' => new TranslatableMarkup('Lower case'),
          ],
        ],
      ],
    ]),
    'variant' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Variant'),
      'description' => new TranslatableMarkup('Pick a casing other than none to see its variants.'),
      'required' => FALSE,
    ]),
  ],
  refinements: [
    'variant' => ['casing'],
  ],
)]
final class DataSurfaceTestFormatter extends DataSurfaceFormatterBase {

  /**
   * The variant choices each casing offers.
   *
   * A method rather than a class constant because the labels are
   * translatable markup, and PHP allows no object in a constant.
   *
   * @return array<string, array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>>
   *   Variant labels keyed by value, keyed by the casing offering them.
   */
  public static function variants(): array {
    return [
      'uppercase' => [
        'bold' => new TranslatableMarkup('Bold'),
        'strong' => new TranslatableMarkup('Strong'),
      ],
      'lowercase' => [
        'quiet' => new TranslatableMarkup('Quiet'),
        'muted' => new TranslatableMarkup('Muted'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $variants = static::variants();
    if ($name !== 'variant' || !isset($variants[$values['casing']])) {
      return $definition;
    }
    $offered = $variants[$values['casing']];
    $definition->addConstraint('LabeledChoice', [
      'choices' => array_keys($offered),
      'labels' => $offered,
    ]);
    return $definition;
  }

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
