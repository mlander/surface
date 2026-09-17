<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_classic\Plugin\Block;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The demo block, written the way blocks were written before surfaces.
 *
 * Behaviorally identical to `data_surface_demo`: the same six settings,
 * the same defaults, the same entity-type-to-bundle-to-field narrowing
 * over AJAX, the same stored configuration and the same rendered list.
 * Everything the other block gets from one declaration is written out
 * here — defaults, form, AJAX, validation, storage, labels and the
 * config schema beside them.
 *
 * This is not a straw man. It is the shortest correct version of the
 * classic approach, and it is meant to be read as good code; the
 * comparison is only worth having if it is.
 *
 * @see \Drupal\data_surface_demo\Plugin\Block\DataSurfaceDemoBlock
 * @see modules/data_surface_demo_classic/README.md
 */
#[Block(
  id: 'data_surface_demo_classic',
  admin_label: new TranslatableMarkup('Data surface demo (classic)'),
)]
final class ClassicDemoBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The id of the element the AJAX rebuild replaces.
   */
  protected const WRAPPER_ID = 'data-surface-demo-classic-settings';

  /**
   * Constructs a ClassicDemoBlock.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which names the content entity types.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service, which names the chosen type's bundles.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, which names the chosen bundle's fields.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'headline' => 'Featured content',
      'entity_type' => 'user',
      'bundle' => NULL,
      'field' => NULL,
      'limit' => 10,
      'show_summary' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $configuration = $this->getConfiguration();
    // During an AJAX rebuild the submitted values are what the dependent
    // lists have to be built from; on a first build there are none.
    $entity_type_id = (string) $form_state->getValue('entity_type', $configuration['entity_type']);
    $bundle = (string) $form_state->getValue('bundle', $configuration['bundle'] ?? '');

    $form['#prefix'] = '<div id="' . static::WRAPPER_ID . '">';
    $form['#suffix'] = '</div>';

    $form['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Headline'),
      '#description' => $this->t('Shown above the featured content.'),
      '#default_value' => $configuration['headline'],
      '#required' => TRUE,
      '#maxlength' => 50,
      '#placeholder' => $this->t('Quarterly report'),
    ];

    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#description' => $this->t('The type of content to feature.'),
      '#default_value' => $entity_type_id,
      '#required' => TRUE,
      '#options' => $this->entityTypeOptions(),
      '#ajax' => [
        'callback' => [static::class, 'refreshSettings'],
        'wrapper' => static::WRAPPER_ID,
      ],
    ];

    $bundle_options = $this->bundleOptions($entity_type_id);
    $form['bundle'] = $bundle_options === []
      ? [
        '#type' => 'textfield',
        '#title' => $this->t('Bundle'),
        '#description' => $this->t('Choose an entity type to see its bundles.'),
        '#default_value' => $configuration['bundle'],
      ]
      : [
        '#type' => 'select',
        '#title' => $this->t('Bundle'),
        '#description' => $this->t('A @entity_type bundle.', ['@entity_type' => $entity_type_id]),
        '#default_value' => $bundle,
        '#options' => $bundle_options,
        '#empty_option' => $this->t('- None -'),
        '#ajax' => [
          'callback' => [static::class, 'refreshSettings'],
          'wrapper' => static::WRAPPER_ID,
        ],
      ];

    $field_options = $this->fieldOptions($entity_type_id, $bundle);
    $form['field'] = $field_options === []
      ? [
        '#type' => 'textfield',
        '#title' => $this->t('Highlight field'),
        '#description' => $this->t('Choose a bundle to pick from its fields.'),
        '#default_value' => $configuration['field'],
      ]
      : [
        '#type' => 'select',
        '#title' => $this->t('Highlight field'),
        '#description' => $this->t('A field on @entity_type @bundle.', [
          '@entity_type' => $entity_type_id,
          '@bundle' => $bundle,
        ]),
        '#default_value' => $configuration['field'],
        '#options' => $field_options,
        '#empty_option' => $this->t('- None -'),
      ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of items'),
      '#description' => $this->t('How many items to feature.'),
      '#default_value' => $configuration['limit'],
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 50,
    ];

    $form['show_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show summaries'),
      '#description' => $this->t('Whether item summaries render.'),
      '#default_value' => $configuration['show_summary'],
    ];

    return $form;
  }

  /**
   * AJAX callback: replaces the settings subform with a rebuilt one.
   *
   * @param array $form
   *   The whole form, already rebuilt.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The settings subform, which is the parent of whatever changed.
   */
  public static function refreshSettings(array $form, FormStateInterface $form_state): array {
    $parents = $form_state->getTriggeringElement()['#array_parents'] ?? [];
    return NestedArray::getValue($form, array_slice($parents, 0, -1)) ?? $form;
  }

  /**
   * {@inheritdoc}
   *
   * Only what the elements cannot check themselves. `#required` is the
   * form builder's, `#min` and `#max` are validated by the number
   * element and `#options` by the select, but `#maxlength` on a text
   * field is a browser attribute and nothing enforces it on the way in.
   */
  public function blockValidate($form, FormStateInterface $form_state): void {
    $headline = (string) $form_state->getValue('headline');
    if (mb_strlen($headline) > 50) {
      $form_state->setError($form['headline'], $this->t('Headline cannot be longer than 50 characters.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['headline'] = (string) $form_state->getValue('headline');
    $this->configuration['entity_type'] = (string) $form_state->getValue('entity_type');
    // An unchosen select submits the empty string, and the stored shape
    // is a nullable string, so the two notations are reconciled here.
    $this->configuration['bundle'] = $this->storedChoice($form_state->getValue('bundle'));
    $this->configuration['field'] = $this->storedChoice($form_state->getValue('field'));
    $this->configuration['limit'] = (int) $form_state->getValue('limit');
    $this->configuration['show_summary'] = (bool) $form_state->getValue('show_summary');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $configuration = $this->getConfiguration();
    $items = [];
    foreach ($this->settingLabels() as $name => $label) {
      $items[] = $this->t('@label: @value', [
        '@label' => $label,
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
   * The label each setting is shown under, in the order they are shown.
   *
   * A second copy of what the form elements already say, because a form
   * element is not readable from anywhere but a form.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The labels, keyed by setting name.
   */
  protected function settingLabels(): array {
    return [
      'headline' => $this->t('Headline'),
      'entity_type' => $this->t('Entity type'),
      'bundle' => $this->t('Bundle'),
      'field' => $this->t('Highlight field'),
      'limit' => $this->t('Number of items'),
      'show_summary' => $this->t('Show summaries'),
    ];
  }

  /**
   * Lists the content entity types, keyed by id.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   The labels, keyed by entity type id.
   */
  protected function entityTypeOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->entityClassImplements(ContentEntityInterface::class)) {
        $options[$id] = $definition->getLabel();
      }
    }
    return $options;
  }

  /**
   * Lists one entity type's bundles, keyed by id.
   *
   * @param string $entity_type_id
   *   The chosen entity type.
   *
   * @return array<string, string>
   *   The labels, keyed by bundle id.
   */
  protected function bundleOptions(string $entity_type_id): array {
    $options = [];
    foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
      $options[$bundle] = $info['label'] ?? $bundle;
    }
    return $options;
  }

  /**
   * Lists one bundle's fields, keyed by name.
   *
   * @param string $entity_type_id
   *   The chosen entity type.
   * @param string $bundle
   *   The chosen bundle, or the empty string when none is chosen.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   The labels, keyed by field name; empty while no bundle is chosen.
   */
  protected function fieldOptions(string $entity_type_id, string $bundle): array {
    if ($entity_type_id === '' || $bundle === '') {
      return [];
    }
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $name => $definition) {
      $options[$name] = $definition->getLabel();
    }
    return $options;
  }

  /**
   * Reads a submitted choice as the value the schema stores.
   *
   * @param mixed $value
   *   The submitted value.
   *
   * @return string|null
   *   The choice, or NULL when nothing was chosen.
   */
  protected function storedChoice(mixed $value): ?string {
    $value = is_scalar($value) ? (string) $value : '';
    return $value === '' ? NULL : $value;
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
