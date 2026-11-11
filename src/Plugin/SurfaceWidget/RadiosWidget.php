<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Widget\ChoiceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Radios for one value of a list of allowed values, when asked for.
 *
 * A single value with a list stays a select unless its definition asks
 * for radios, `setSetting('widget', 'radios')`: a select already shows
 * every choice on one click, while radios spend a line per choice on
 * every key, which is the page's layout and its author's to choose. The
 * document names the choice `radios`.
 *
 * Radios post one value, as a select does. "- None -" is a radio of its
 * own only where choosing nothing is an answer, as the document's
 * `emptyOption` says: an optional key that may be cleared. A required
 * key, or one its storage always holds, has no empty radio; nothing
 * checked is how it shows a key with no valid choice, a stored value no
 * longer offered among them, and the element is marked stale, so radios
 * left alone keep it, as a select does.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'radios',
  label: new TranslatableMarkup('Radios'),
  weight: -15,
)]
final class RadiosWidget extends ChoiceWidgetBase implements DefinitionWidgetInterface {

  /**
   * Answers whether one value of a list of allowed values is radios.
   *
   * The served contract's emitter asks the same, so its widget hint is
   * this widget's choice.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition, which offers a list.
   *
   * @return bool
   *   TRUE when it is one value and asks for radios.
   */
  public static function drawsOne(DataDefinitionInterface $definition): bool {
    return !$definition instanceof ListDataDefinitionInterface && $definition->getSetting('widget') === 'radios';
  }

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return static::drawsOne($definition) && $choice->optionSet($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * One value that offers a list; drawn as radios only where the
   * document's word says so, since the select is asked first.
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::type($node) !== 'array' && static::offers($node);
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#the-empty-option-rule.
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    [$options, $descriptions] = static::choices($node);
    $stale = $context->stale || !empty(static::extension($node)['stale']);
    $configured = ValueState::isConfigured($value);
    $chosen = !$stale && $configured && (is_int($value) || is_string($value)) && array_key_exists($value, $options);
    $said = static::options($node)['emptyOption'] ?? NULL;
    $said = is_object($said) ? (array) $said : $said;
    $holds = $context->required || !static::allowsNull($node);
    $none = is_array($said) && is_bool($said['show'] ?? NULL) ? $said['show'] : !$holds;
    $element = $this->baseElement($node, $context) + ['#type' => 'radios'];
    $element['#options'] = $none ? ['' => (string) (is_array($said) && is_string($said['label'] ?? NULL) ? $said['label'] : $this->t('- None -'))] + $options : $options;
    $element['#default_value'] = match (TRUE) {
      $chosen => $value,
      // Nothing stored and nothing to choose by default: choosing nothing
      // is this key's answer, and it is what the radios show.
      $none && !$configured && !$stale => '',
      default => NULL,
    };
    if ($stale) {
      // Stale: nothing is checked, and radios left alone keep what is
      // stored. Empty is "keep" here, not a refusal.
      $element[self::STALE_KEY] = TRUE;
      $element['#required'] = FALSE;
    }
    return static::describedOptions($element, $descriptions);
  }

}
