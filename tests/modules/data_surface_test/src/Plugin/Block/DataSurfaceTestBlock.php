<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;

/**
 * A block that adopts surfaces and says nothing else about its settings.
 *
 * What a block costs once the base class carries the pipeline: the
 * attribute declaring what it accepts, one refiner method for the
 * setting that depends on another, and build(). There is no
 * defaultConfiguration, no blockForm, no blockValidate and no
 * blockSubmit, which is the whole claim of the adoption layer.
 */
#[Block(
  id: 'data_surface_test_block',
  admin_label: new TranslatableMarkup('Data surface test block'),
  forms: ['alternate' => 'data_surface_test.alternate_form'],
)]
#[DataSurfaceAware(
  definitions: [
    'headline' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Headline'),
      'description' => new TranslatableMarkup('Shown above the items.'),
      'required' => TRUE,
      'default_value' => 'Featured',
      'examples' => ['Quarterly report'],
      'constraints' => ['Length' => ['max' => 20]],
    ]),
    'limit' => new DataDefinition([
      'type' => 'integer',
      'label' => new TranslatableMarkup('Number of items'),
      'required' => FALSE,
      'default_value' => 10,
      'constraints' => ['Range' => ['min' => 1, 'max' => 50]],
    ]),
    'show_summary' => new DataDefinition([
      'type' => 'boolean',
      'label' => new TranslatableMarkup('Show summaries'),
      'required' => FALSE,
      'default_value' => TRUE,
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
final class DataSurfaceTestBlock extends DataSurfaceBlockBase {

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
    if ($definition instanceof DataDefinition) {
      $definition->setDescription(new TranslatableMarkup('A @casing variant.', [
        '@casing' => $values['casing'],
      ]));
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $items = [];
    foreach ($this->getDataSurface()->getDefinitions() as $name => $definition) {
      $items[] = $definition->getLabel() . ': ' . var_export($configuration[$name] ?? NULL, TRUE);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $configuration['headline'] ?? '',
      '#items' => $items,
    ];
  }

}
