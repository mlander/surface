<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Contract\NodeFacts;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Widget\ChoiceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Checkboxes for a list of allowed values that a column of them shows.
 *
 * A list whose item names a list of values is drawn as checkboxes while
 * it offers at most MAXIMUM_OPTIONS values, and as a multiple select (the
 * options widget) above that, where a column of checkboxes stops being
 * something a person scans. The definition overrides the count with its
 * `widget` setting: `checkboxes` at any length, `select` at any length.
 * The document names the choice `checkboxes`, and the node's `items`
 * offer the values.
 *
 * Checkboxes post every option, each with its value or 0; the base class
 * reads that back as the list of the values checked, so all unchecked is
 * the empty list, which clears the list, and an element left alone posts
 * what it showed, which keeps it.
 *
 * Never #required, as the boolean widget's checkbox is not: a required
 * list must hold a value, and the empty list is one.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'checkboxes',
  label: new TranslatableMarkup('Checkboxes'),
  weight: -15,
)]
final class CheckboxesWidget extends ChoiceWidgetBase implements SchemaWidgetInterface {

  /**
   * The most values a list offers and is still drawn as checkboxes.
   *
   * Twelve: a year of months is a column a person scans; a list of every
   * country is not.
   */
  public const MAXIMUM_OPTIONS = 12;

  /**
   * Answers whether a list of allowed values is drawn as checkboxes.
   *
   * The served contract's emitter asks the same, so its widget hint is
   * this widget's choice.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The key's schema.
   * @param \Drupal\surface\Options\OptionSet $set
   *   The values one of its items may be.
   *
   * @return bool
   *   TRUE for a list that asks for checkboxes, or asks for nothing and
   *   offers at most MAXIMUM_OPTIONS values.
   */
  public static function drawsList(Schema $schema, OptionSet $set): bool {
    if (!DefinitionSchema::isList($schema)) {
      return FALSE;
    }
    $asked = NodeFacts::setting($schema, 'widget');
    return $asked === 'checkboxes' || ($asked === NULL && count($set->options) <= self::MAXIMUM_OPTIONS);
  }

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    $set = DefinitionSchema::isList($schema) ? $choice->optionSet($schema) : NULL;
    return $set !== NULL && static::drawsList($schema, $set);
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::type($node) === 'array' && static::offers(static::offering($node));
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    [$options, $descriptions] = static::choices(static::offering($node));
    $element = $this->baseElement($node, $context) + [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#default_value' => array_values(array_filter(is_array($value) ? $value : [], 'is_scalar')),
    ];
    $element['#required'] = FALSE;
    return static::describedOptions($element, $descriptions);
  }

}
