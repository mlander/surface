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
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A block whose entire settings form is generated from its surface.
 *
 * What is left of a configurable block once the base class carries the
 * pipeline: the attribute declaring what it accepts, one refiner method
 * for the settings that depend on another, and build(). There is no
 * defaultConfiguration, no blockForm, no blockValidate and no
 * blockSubmit — compare the config_surface original, which still spelled
 * all four out.
 *
 * Every option list is declared, never repeated. The entity type is a
 * PluginExists constraint naming the manager and the interface, which
 * the options resolver reads as a select of content entity types; the
 * bundle and the field are refined into labeled choices from live site
 * state. The refinement map is static — entity type narrows bundle,
 * bundle narrows field — so it belongs in the attribute with the
 * definitions, and the surface is fully attribute-declared even though
 * resolving it consults the site.
 */
#[Block(
  id: 'data_surface_demo',
  admin_label: new TranslatableMarkup('Data surface demo'),
)]
#[DataSurfaceAware(
  definitions: [
    'headline' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Headline'),
      'description' => new TranslatableMarkup('Shown above the featured content.'),
      'required' => TRUE,
      'default_value' => 'Featured content',
      'examples' => ['Quarterly report'],
      'constraints' => ['Length' => ['max' => 50]],
    ]),
    'entity_type' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Entity type'),
      'description' => new TranslatableMarkup('The type of content to feature.'),
      'required' => TRUE,
      // 'user', not 'node'. A declared default has to satisfy the key's
      // own constraints, because the base class applies the defaults
      // through the pipeline while the plugin is being constructed: a
      // default of 'node' made this block impossible to construct on any site
      // without the node module, since its own PluginExists constraint
      // refused the value before the block existed. The user entity type
      // is a content entity type every Drupal site has, so the default
      // is valid wherever the block can be installed at all. The demo
      // loses nothing: user has exactly one bundle and node has several,
      // so choosing a different entity type still narrows the bundle
      // list to something visibly different, which is the chain the
      // JavaScript test walks.
      'default_value' => 'user',
      // One declaration doing both jobs: the constraint refuses a plugin
      // that is not a content entity type, and the options resolver
      // reads the same constraint as the select's choices.
      'constraints' => [
        'PluginExists' => [
          'manager' => 'entity_type.manager',
          'interface' => ContentEntityInterface::class,
        ],
      ],
    ]),
    'bundle' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Bundle'),
      'description' => new TranslatableMarkup('Choose an entity type to see its bundles.'),
      'required' => FALSE,
    ]),
    'field' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Highlight field'),
      'description' => new TranslatableMarkup('Choose a bundle to pick from its fields.'),
      'required' => FALSE,
    ]),
    'limit' => new DataDefinition([
      'type' => 'integer',
      'label' => new TranslatableMarkup('Number of items'),
      'description' => new TranslatableMarkup('How many items to feature.'),
      'required' => TRUE,
      'default_value' => 10,
      'constraints' => ['Range' => ['min' => 1, 'max' => 50]],
    ]),
    'show_summary' => new DataDefinition([
      'type' => 'boolean',
      'label' => new TranslatableMarkup('Show summaries'),
      'description' => new TranslatableMarkup('Whether item summaries render.'),
      'required' => FALSE,
      'default_value' => TRUE,
    ]),
  ],
  refinements: [
    'bundle' => ['entity_type'],
    // A target refined against an optional dependency: while bundle is
    // empty this stays an open text field; once a bundle is chosen the
    // field list narrows to that bundle's fields.
    'field' => ['entity_type', 'bundle'],
  ],
)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a DataSurfaceDemoBlock.
   *
   * Promoted properties are assigned before the constructor body runs,
   * which is what this block depends on: BlockPluginTrait::__construct()
   * applies the default configuration, that consults the surface, and
   * the surface refines through the collaborators below. A property
   * assigned after parent::__construct() would still be unset by then.
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
   *
   * Narrowing is replacing the open definition with a smaller one, and
   * the labels travel with the values they belong to, so the refined
   * definition is self-contained: the select, the validator and any
   * other consumer read the one list.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name === 'bundle') {
      $entity_type_id = (string) $values['entity_type'];
      $choices = [];
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        $choices[$bundle] = $info['label'] ?? $bundle;
      }
      if ($choices === []) {
        return $definition;
      }
      $definition->addConstraint('LabeledChoice', [
        'choices' => array_keys($choices),
        'labels' => $choices,
      ]);
      if ($definition instanceof DataDefinition) {
        $definition->setDescription(new TranslatableMarkup('A @entity_type bundle.', [
          '@entity_type' => $entity_type_id,
        ]));
      }
    }
    if ($name === 'field') {
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
      $definition->addConstraint('LabeledChoice', [
        'choices' => array_keys($choices),
        'labels' => $choices,
      ]);
      if ($definition instanceof DataDefinition) {
        $definition->setDescription(new TranslatableMarkup('A field on @entity_type @bundle.', [
          '@entity_type' => $values['entity_type'],
          '@bundle' => $values['bundle'],
        ]));
      }
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
      // One translatable line per key, built from placeholders rather
      // than concatenation, so the label and the stored value are both
      // escaped by the placeholder mechanism and a translator sees a
      // whole sentence. The value is described in words; a PHP literal
      // dump is debugging output, not something a visitor reads.
      $items[] = new TranslatableMarkup('@label: @value', [
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
   *   The value as text, still unescaped: it is placed in a placeholder,
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
