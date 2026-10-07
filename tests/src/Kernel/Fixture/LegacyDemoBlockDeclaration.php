<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel\Fixture;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DataSurfaceDeclarationInterface;
use Drupal\data_surface\DataSurfaceRefinerInterface;
use Drupal\data_surface\DefinitionMetadata;

/**
 * The demo block's surface as the old spelling declared it.
 *
 * Kept verbatim from DataSurfaceDemoBlock before it moved to
 * #[UsesSurface], so the new spelling can be held to what the old one
 * produced. Deleted with the old spelling, in step 5 of the rework.
 */
final class LegacyDemoBlockDeclaration implements DataSurfaceDeclarationInterface, DataSurfaceRefinerInterface {

  /**
   * Constructs the legacy declaration's refiner.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager.
   */
  public function __construct(
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $headline = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Headline'))
      ->setDescription(new TranslatableMarkup('Shown above the featured content.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $builder->setDefinition('headline', $headline);
    $builder->setDefault('headline', 'Featured content');

    $builder->setDefinition('entity_type', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Entity type'))
      ->setDescription(new TranslatableMarkup('The type of content to feature.'))
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]));
    $builder->setDefault('entity_type', 'user');

    $builder->setDefinition('bundle', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Bundle'))
      ->setDescription(new TranslatableMarkup('Choose an entity type to see its bundles.')));
    $builder->addRefinement('bundle', ['entity_type']);

    $builder->setDefinition('field', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Highlight field'))
      ->setDescription(new TranslatableMarkup('Choose a bundle to pick from its fields.')));
    $builder->addRefinement('field', ['entity_type', 'bundle']);

    $builder->setDefinition('limit', DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Number of items'))
      ->setDescription(new TranslatableMarkup('How many items to feature.'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]));
    $builder->setDefault('limit', 10);

    $builder->setDefinition('show_summary', DataDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Show summaries'))
      ->setDescription(new TranslatableMarkup('Whether item summaries render.')));
    $builder->setDefault('show_summary', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $choices = [];
    if ($name === 'bundle') {
      foreach ($this->bundleInfo->getBundleInfo((string) $values['entity_type']) as $bundle => $info) {
        $choices[$bundle] = $info['label'] ?? $bundle;
      }
      $description = new TranslatableMarkup('A @entity_type bundle.', ['@entity_type' => $values['entity_type']]);
    }
    elseif ($name === 'field') {
      foreach ($this->entityFieldManager->getFieldDefinitions((string) $values['entity_type'], (string) $values['bundle']) as $field_name => $field) {
        $choices[$field_name] = $field->getLabel();
      }
      $description = new TranslatableMarkup('A field on @entity_type @bundle.', [
        '@entity_type' => $values['entity_type'],
        '@bundle' => $values['bundle'],
      ]);
    }
    if ($choices === [] || !isset($description)) {
      return $definition;
    }
    $definition->addConstraint('LabeledChoice', ['choices' => $choices]);
    if ($definition instanceof DataDefinition) {
      $definition->setDescription($description);
    }
    return $definition;
  }

}
