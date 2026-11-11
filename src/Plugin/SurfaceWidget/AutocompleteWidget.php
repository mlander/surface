<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Component\Utility\Tags;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Reference\References;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core's entity autocomplete, for a reference with too many to list.
 *
 * A key that refers to one entity type, the id with SurfaceValidReference,
 * or the id of a reference to any entity type once one is chosen, whose
 * candidates the options service does not list: there are more than the
 * site's limit. A key that asks for it by name, `autocomplete` (a
 * reference field whose form display draws it so), is drawn so however
 * many there are. The document names it `autocomplete`, and its node's
 * `drupal:SurfaceValidReference` check says which entity type and which
 * bundles it may name; core's `entity_autocomplete` element draws it, its
 * route asking the same selection handler with the same settings
 * (References::selectionSettings()), so it suggests what the key accepts.
 * The handler and its settings are the check's options where they carry
 * them (a key a resource declares), else the host's answer
 * (WidgetContext::selection(): a reference field's handler and settings,
 * which the document does not carry). The label of the entity it holds
 * is the host's answer too (WidgetContext::labelOf()), shown as core's
 * element shows a suggestion. Core's own check of the element is taken
 * off, as the number widget's is: the key's check refuses an id in its
 * own words, and the element is read back as the id it names,
 * "Riverside Hall (riverside)" as `riverside`.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
#[SurfaceWidget(
  id: 'autocomplete',
  label: new TranslatableMarkup('Autocomplete'),
  weight: -5,
)]
final class AutocompleteWidget extends SurfaceWidgetBase implements ContainerFactoryPluginInterface, DefinitionWidgetInterface {

  /**
   * The check a reference's node names its entity type in.
   */
  public const CHECK = 'drupal:SurfaceValidReference';

  /**
   * Constructs an AutocompleteWidget.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\surface\Reference\References $references
   *   The references service, which words the selection settings the
   *   element's route asks with.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly References $references,
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
      $container->get('surface.references'),
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
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return static::draws($definition)
      && ($definition->getSetting('widget') === 'autocomplete' || $choice->optionSet($definition) === NULL);
  }

  /**
   * {@inheritdoc}
   *
   * One value whose node names the entity type it refers to.
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::type($node) !== 'array' && static::target($node) !== NULL;
  }

  /**
   * Reads what a reference's node says it may name.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return array{entityTypeId: string, bundles: string[], handler: string, handlerSettings: array}|null
   *   The check's options, or NULL where the node names no entity type.
   */
  public static function target(Schema $node): ?array {
    $checks = Schemas::data($node->get(PortableKeywords::CHECK)) ?? [];
    $options = Schemas::data($checks[self::CHECK] ?? NULL);
    if ($options === NULL || !is_string($options['entityTypeId'] ?? NULL) || $options['entityTypeId'] === '') {
      return NULL;
    }
    return [
      'entityTypeId' => $options['entityTypeId'],
      'bundles' => array_values(array_filter((array) ($options['bundles'] ?? []), 'is_string')),
      'handler' => is_string($options['handler'] ?? NULL) ? $options['handler'] : '',
      'handlerSettings' => Schemas::data($options['handlerSettings'] ?? NULL) ?? [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $target = static::target($node);
    assert($target !== NULL);
    if ($target['handler'] === '') {
      $selection = $context->selection($context->pointer);
      if ($selection !== NULL) {
        $target['handler'] = $selection['handler'];
        $target['handlerSettings'] = $selection['handlerSettings'];
      }
    }
    $handler = $target['handler'];
    $label = is_int($value) || (is_string($value) && $value !== '') ? $context->labelOf($target['entityTypeId'], $value) : NULL;
    return $this->baseElement($node, $context) + [
      '#type' => 'entity_autocomplete',
      '#target_type' => $target['entityTypeId'],
      '#selection_handler' => $handler === '' ? 'default' : $handler,
      '#selection_settings' => $this->references->selectionSettings($target['entityTypeId'], $target['bundles'], $handler, $target['handlerSettings']),
      // Shown as core's element shows a suggestion, "Riverside Hall
      // (riverside)": the label the host gave, not an entity to load.
      '#default_value' => $label === NULL ? NULL : Tags::encode($label . ' (' . $value . ')'),
      '#process_default_value' => FALSE,
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
  public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed {
    $raw = $this->rawValue($element, $form_state);
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
