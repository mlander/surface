<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Widget\ChoiceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Select list for a key whose node offers a list of values.
 *
 * The node's `oneOf` is what it offers, each entry's `const` an option
 * and its `title` the option's label; a list's `items` say it for one
 * item, and the list is drawn as a multiple select (`options.multiple`)
 * when the checkboxes widget leaves it: a list of more options than a
 * column of checkboxes shows, or one that asks for a select. A single
 * value is a select unless it asks for radios; one that may hold only
 * one value is drawn as that value (`fixed`).
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'select',
  label: new TranslatableMarkup('Select'),
  weight: -10,
)]
final class OptionsWidget extends ChoiceWidgetBase implements DefinitionWidgetInterface {

  /**
   * How many rows a multiple select shows at most.
   */
  protected const MAXIMUM_SIZE = 8;

  /**
   * {@inheritdoc}
   *
   * An empty set is a list too: a refiner that narrowed it to nothing, or
   * a resolver that found nothing, still says the value is one of a list,
   * and the list offers none. So the key is a select offering its empty
   * option alone, and the constraint refuses any value.
   *
   * Decision: see docs/decisions.md#a-key-that-does-not-apply.
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return $choice->optionSet($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * A node that offers values, its own or its items', and the one value a
   * key may hold, which its node states as `const` beside the label it
   * is offered under (`x-surface.fixed`).
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::offers(static::offering($node)) || static::isFixed($node);
  }

  /**
   * {@inheritdoc}
   *
   * One rule decides whether a single select shows its empty option, and
   * the document states its answer for these values
   * (`x-portable-ui.options.emptyOption`, `{show, label}`): the empty
   * option is there whenever no valid choice is selected, and a select
   * that may be cleared keeps it besides. A value not among the choices
   * comes up on the empty option, selected.
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    if (static::isFixed($node)) {
      return static::fixed($this->baseElement($node, $context), $node);
    }
    [$options] = static::choices(static::offering($node));
    $element = $this->baseElement($node, $context) + [
      '#type' => 'select',
      '#options' => $options,
    ];
    if (static::type($node) === 'array') {
      // A list offers every value at once and needs no empty choice:
      // choosing nothing is the empty list. Never #required, as the
      // checkboxes are not: a required list must hold a value, and the
      // empty list is one.
      $element['#default_value'] = is_array($value) ? $value : [];
      $element['#multiple'] = TRUE;
      $element['#size'] = min(max(count($options), 2), self::MAXIMUM_SIZE);
      $element['#required'] = FALSE;
      return $element;
    }
    return $this->singleSelect($element, $node, $options, $value, $context);
  }

  /**
   * Answers whether a definition is drawn as the one value it may hold.
   *
   * A deciding key whose variants offer one value, or a key its
   * operation's context fixed to one, which may not hold nothing,
   * holding that value or nothing yet: there is nothing to choose, so it
   * is drawn as the value, not as a select of one. The emitter asks it,
   * and writes the word `fixed`.
   *
   * Decision: see
   * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param \Drupal\surface\Options\OptionSet $set
   *   The values it offers.
   * @param mixed $value
   *   What it holds, or its default.
   *
   * @return bool
   *   TRUE when it is drawn fixed.
   */
  public static function drawsFixed(DataDefinitionInterface $definition, OptionSet $set, mixed $value): bool {
    return !$definition instanceof ListDataDefinitionInterface
      && (DefinitionMetadata::isOneVariant($definition) || DefinitionMetadata::isFixed($definition))
      && DefinitionMetadata::holdsValue($definition)
      && count($set->options) === 1
      && (!ValueState::isConfigured($value) || $set->allows($value));
  }

  /**
   * Answers whether a node is the one value a key may hold.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   *
   * @return bool
   *   TRUE for a `const` beside the label `x-surface.fixed` gives it.
   */
  protected static function isFixed(Schema $node): bool {
    return $node->has('const') && is_string(static::extension($node)['fixed'] ?? NULL);
  }

  /**
   * Draws the one value a key may hold, with its label, and posts it.
   *
   * @param array $element
   *   The element so far.
   * @param \PortableSchema\Builder\Schema $node
   *   The node: its `const`, and its label in `x-surface.fixed`.
   *
   * @return array
   *   An item showing the value's label, whose value is that value.
   */
  protected static function fixed(array $element, Schema $node): array {
    return [
      '#type' => 'item',
      '#plain_text' => (string) static::extension($node)['fixed'],
      '#value' => $node->get('const'),
      // Nothing to answer, so nothing to mark as required.
      '#required' => FALSE,
    ] + $element;
  }

  /**
   * Applies the empty option rule to a single select.
   *
   * A stored value the list does not offer is never put back into it:
   * offering it would let somebody re-save a reference to something that
   * does not exist. Nor is the select left without a selection, which a
   * browser answers by picking the first real option. The empty option
   * is selected instead, worded as the document says ("- Keep @value (no
   * longer offered) -"), and the element is marked stale, so a save that
   * leaves the select alone keeps what is stored: only an explicit new
   * choice replaces it.
   *
   * @param array $element
   *   The select element so far.
   * @param \PortableSchema\Builder\Schema $node
   *   The node it draws.
   * @param array<int|string, string> $options
   *   The values it offers.
   * @param mixed $value
   *   The value shown: NULL for a stale one, which the document leaves out.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn.
   *
   * @return array
   *   The element, with its default value and, when the rule calls for
   *   one, its empty option.
   */
  protected function singleSelect(array $element, Schema $node, array $options, mixed $value, WidgetContext $context): array {
    // Decision: see docs/decisions.md#the-empty-option-rule.
    $stale = $context->stale || !empty(static::extension($node)['stale']);
    $chosen = !$stale && ValueState::isConfigured($value) && (is_int($value) || is_string($value)) && array_key_exists($value, $options);
    // Inside a collection's item the document's answer is the shared
    // items' schema's, for an item holding nothing: an item holding a
    // value is answered for its own.
    $empty = static::emptyOption($node, $context->required, $chosen, $context->item && ValueState::isConfigured($value));
    if (!$empty['show']) {
      // A valid choice is selected and emptiness is not an answer: no
      // empty option. Core adds none either, since a default is set.
      $element['#default_value'] = $value;
      return $element;
    }
    $element['#default_value'] = $chosen ? $value : '';
    $element['#empty_value'] = '';
    $element['#empty_option'] = $empty['label'];
    if ($stale) {
      // Stale: an untouched select stands for what is stored. It still
      // posts the empty string, never a value the key does not take.
      $element[self::STALE_KEY] = TRUE;
      if ($context->required) {
        // Empty is not a refusal here, it is "keep": so no `required`
        // attribute, which would have the browser refuse the option that
        // keeps it. The marker on the label and aria-required stay, so
        // what a screen reader says agrees with what the eye sees.
        // Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
        $element['#required'] = FALSE;
        $element['#label_attributes']['class'] = ['js-form-required', 'form-required'];
        $element['#attributes']['aria-required'] = 'true';
      }
    }
    return $element;
  }

  /**
   * Reads a single select's empty option: React's `emptyOption()`.
   *
   * The document's answer for these values where it gives one; where it
   * gives none, shown whenever no valid choice is selected or the key may
   * be cleared, "- Select -" on a key that holds a value and "- None -" on
   * one that may be cleared. Inside a collection's item holding a value of
   * its own, the document's answer is the shared items' schema's, given for
   * an item holding nothing: whether it shows is read from the item's own
   * value by the same rule, and only its label is the document's.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The node.
   * @param bool $required
   *   Whether its owner requires it.
   * @param bool $chosen
   *   Whether a valid choice is selected.
   * @param bool $own
   *   Whether it is drawn inside a collection's item, for a value the item
   *   holds.
   *
   * @return array{show: bool, label: string}
   *   The empty option.
   */
  public static function emptyOption(Schema $node, bool $required, bool $chosen, bool $own = FALSE): array {
    $said = static::options($node)['emptyOption'] ?? NULL;
    $said = is_array($said) ? $said : (is_object($said) ? (array) $said : NULL);
    $holds = $required || !static::allowsNull($node);
    if ($said !== NULL && is_bool($said['show'] ?? NULL) && is_string($said['label'] ?? NULL)) {
      return ['show' => ($own ? !$holds : $said['show']) || !$chosen, 'label' => $said['label']];
    }
    return [
      'show' => !($chosen && $holds),
      'label' => (string) ($holds ? t('- Select -') : t('- None -')),
    ];
  }

  /**
   * Words the empty option of a select standing for a stale value.
   *
   * @param mixed $value
   *   The stored value no longer offered.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "- Keep @value (no longer offered) -".
   *
   * @see docs/decisions.md#the-empty-option-rule
   */
  public static function keepLabel(mixed $value): TranslatableMarkup {
    return t('- Keep @value (no longer offered) -', ['@value' => is_scalar($value) ? (string) $value : (string) json_encode($value)]);
  }

}
