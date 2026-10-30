<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Reference\References;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core's entity autocomplete, for a reference with too many to list.
 *
 * A key that refers to one entity type, `entity:<type>[:<bundle>]` or a
 * bare `entity` key's id once its entity type is chosen, whose candidates
 * the options service does not list: there are more than the site's
 * limit. Core's `entity_autocomplete` element draws it, its route asking
 * the same selection handler with the same settings
 * (References::selectionSettings()), so it suggests what the key accepts.
 * Core's own check of the element is taken off, as the number widget's
 * is: the key's constraint refuses an id in its own words, and the
 * element is read back as the id it names, "Riverside Hall (riverside)"
 * as `riverside`.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
#[SurfaceWidget(
  id: 'autocomplete',
  label: new TranslatableMarkup('Autocomplete'),
  weight: -5,
)]
final class AutocompleteWidget extends SurfaceWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an AutocompleteWidget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which says whether the candidates are listed.
   * @param \Drupal\surface\Reference\References $references
   *   What reference keys are, and which entities they may name.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the entity a value names.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly SurfaceOptions $options,
    protected readonly References $references,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('surface.options'),
      $container->get('surface.references'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Answers whether a key refers to one entity type.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   TRUE for one value whose constraint names its entity type; what it
   *   is drawn as, a select or this, is whether its candidates are listed.
   */
  public static function draws(DataDefinitionInterface $definition): bool {
    return !$definition instanceof ListDataDefinitionInterface && References::target($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return static::draws($definition) && $this->options->resolve($definition) === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $target = References::target($definition);
    assert($target !== NULL);
    $entity = is_int($value) || (is_string($value) && $value !== '')
      ? $this->entityTypeManager->getStorage($target['entityType'])->load($value)
      : NULL;
    return $this->baseElement($definition) + [
      '#type' => 'entity_autocomplete',
      '#target_type' => $target['entityType'],
      '#selection_handler' => 'default',
      '#selection_settings' => $this->references->selectionSettings($target['entityType'], $target['bundles']),
      '#default_value' => $entity,
      // Core's element checks what was typed itself, before any form
      // level handler, and a form keeps the first error per element.
      // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
      '#element_validate' => [],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The text posted names the entity by its id in brackets, as core's
   * element writes a suggestion; an id typed alone is taken as it is, and
   * anything else is handed on as typed, for the key's rules to refuse.
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    if (!is_string($raw)) {
      return $raw;
    }
    $raw = trim($raw);
    if ($raw === '') {
      return NULL;
    }
    return EntityAutocomplete::extractEntityIdFromAutocompleteInput($raw) ?? $raw;
  }

}
