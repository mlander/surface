<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;

/**
 * A block that adopts surfaces and says nothing else about its settings.
 *
 * What a block costs once the base class carries the pipeline: one
 * method declaring what it accepts, one refiner method for the
 * setting that depends on another, and build(). There is no
 * defaultConfiguration, no blockForm, no blockValidate and no
 * blockSubmit, which is the whole claim of the adoption layer.
 */
#[Block(
  id: 'data_surface_test_block',
  admin_label: new TranslatableMarkup('Data surface test block'),
  forms: ['alternate' => 'data_surface_test.alternate_form'],
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
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $headline = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Headline'))
      ->setDescription(new TranslatableMarkup('Shown above the items.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 20]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $builder->setDefinition('headline', $headline);
    $builder->setDefault('headline', 'Featured');

    $builder->setDefinition('limit', DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Number of items'))
      ->setRequired(FALSE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]));
    $builder->setDefault('limit', 10);

    $builder->setDefinition('show_summary', DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show summaries'))
      ->setRequired(FALSE));
    $builder->setDefault('show_summary', TRUE);

    $builder->setDefinition('casing', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Casing'))
      ->setRequired(FALSE)
      ->addConstraint('LabeledChoice', [
        'choices' => ['none', 'uppercase', 'lowercase'],
        'labels' => [
          'none' => new TranslatableMarkup('As written'),
          'uppercase' => new TranslatableMarkup('Upper case'),
          'lowercase' => new TranslatableMarkup('Lower case'),
        ],
      ]));
    $builder->setDefault('casing', 'none');

    $builder->setDefinition('variant', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Variant'))
      ->setDescription(new TranslatableMarkup('Pick a casing other than none to see its variants.'))
      ->setRequired(FALSE));
    $builder->addRefinement('variant', ['casing']);
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
