<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A block whose entire settings form is generated from its surface.
 *
 * One declaration, one refiner, and build(). No defaultConfiguration,
 * no blockForm, no blockValidate, no blockSubmit. The classic demo
 * writes all four out; see modules/data_surface_demo_classic.
 *
 * @see modules/data_surface_demo_classic/README.md
 */
#[Block(
  id: 'data_surface_demo',
  admin_label: new TranslatableMarkup('Data surface demo'),
)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a DataSurfaceDemoBlock.
   *
   * Promoted, not assigned: BlockPluginTrait::__construct() applies the
   * defaults through the surface, which refines through these two, so a
   * property assigned after parent::__construct() would be unset by then.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service, which names the chosen type's bundles.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, which names the chosen bundle's fields.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
    );
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
    // 'user', not 'node'. The base class applies the declared defaults
    // through the pipeline while the plugin is being constructed, so a
    // default its own constraint refuses makes the block impossible to
    // construct — and 'node' is refused on a site without the node
    // module.
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
    return match ($name) {
      'bundle' => $this->refineBundle($definition, $values),
      'field' => $this->refineField($definition, $values),
      default => $definition,
    };
  }

  /**
   * Narrows the bundle to the bundles of the chosen entity type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The advertised definition.
   * @param array $values
   *   The values this key refines against.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The narrowed definition.
   */
  protected function refineBundle(DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $entity_type_id = (string) $values['entity_type'];
    $choices = [];
    foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
      $choices[$bundle] = $info['label'] ?? $bundle;
    }
    if ($choices === []) {
      return $definition;
    }
    $definition->addConstraint('LabeledChoice', ['choices' => $choices]);
    if ($definition instanceof DataDefinition) {
      $definition->setDescription($this->t('A @entity_type bundle.', [
        '@entity_type' => $entity_type_id,
      ]));
    }
    return $definition;
  }

  /**
   * Narrows the field to the fields on the chosen bundle.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The advertised definition.
   * @param array $values
   *   The values this key refines against.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The narrowed definition, left open while no bundle is chosen.
   */
  protected function refineField(DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    $choices = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      (string) $values['entity_type'],
      (string) $values['bundle'],
    );
    foreach ($field_definitions as $field_name => $field_definition) {
      $choices[$field_name] = $field_definition->getLabel();
    }
    if ($choices === []) {
      return $definition;
    }
    $definition->addConstraint('LabeledChoice', ['choices' => $choices]);
    if ($definition instanceof DataDefinition) {
      $definition->setDescription($this->t('A field on @entity_type @bundle.', [
        '@entity_type' => $values['entity_type'],
        '@bundle' => $values['bundle'],
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
      $items[] = $this->t('@label: @value', [
        '@label' => $definition->getLabel() ?? $name,
        '@value' => $this->describeValue($configuration[$name] ?? NULL),
      ]);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $configuration['headline'] ?? '',
      '#items' => $items,
    ];
  }

  /**
   * Says what one stored value is, in words a visitor can read.
   *
   * @param mixed $value
   *   The stored value.
   *
   * @return string|\Stringable
   *   The value as text, still unescaped: it goes into a placeholder,
   *   which is what escapes it.
   */
  protected function describeValue(mixed $value): string|\Stringable {
    if ($value === NULL) {
      return $this->t('not configured');
    }
    if (is_bool($value)) {
      return $value ? $this->t('yes') : $this->t('no');
    }
    if (is_array($value)) {
      return $this->formatPlural(count($value), '1 value', '@count values');
    }
    return (string) $value;
  }

}
