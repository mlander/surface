<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Widget\ChoiceWidgetBase;

/**
 * Radios for one value of a list of allowed values, when asked for.
 *
 * A single value with a list stays a select unless its definition asks
 * for radios, `setSetting('widget', 'radios')`: a select already shows
 * every choice on one click and has none of a multiple select's faults,
 * while radios spend a line per choice on every key, which is the page's
 * layout and its author's to choose (pathauto's settings draw radios for
 * one key and selects for thirty-three rows beside it). It also keeps an
 * element from changing kind when a refiner narrows the list.
 *
 * Radios post one value, as a select does. "- None -" is a radio of its
 * own only where choosing nothing is an answer: an optional key that may
 * be cleared. A required key, or one its storage always holds, has no
 * empty radio; nothing checked is how it shows a key with no valid
 * choice, a stored value no longer offered among them, and the stash
 * keeps that value when the radios are left alone, as a select's does.
 *
 * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
 */
#[SurfaceWidget(
  id: 'radios',
  label: new TranslatableMarkup('Radios'),
  weight: -15,
)]
final class RadiosWidget extends ChoiceWidgetBase {

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
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return static::drawsOne($definition) && $this->optionSet($definition) !== NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Decision: see docs/decisions.md#the-empty-option-rule.
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    $set = $this->optionSet($definition);
    $configured = ValueState::isConfigured($value);
    $chosen = $configured && $set !== NULL && $set->allows($value);
    $holds = DefinitionMetadata::holdsValue($definition);
    $options = $set === NULL ? [] : $set->options;
    $element = $this->baseElement($definition) + ['#type' => 'radios'];
    $element['#options'] = $holds ? $options : ['' => $this->t('- None -')] + $options;
    $element['#default_value'] = match (TRUE) {
      $chosen => $value,
      // Nothing stored and nothing to choose by default: choosing nothing
      // is this key's answer, and it is what the radios show.
      !$holds && !$configured => '',
      default => NULL,
    };
    if ($configured && !$chosen) {
      // Stale: nothing is checked, and the value travels on the element,
      // so radios left alone keep it.
      $element[self::STALE_KEY] = $value;
      // Empty is "keep" here, not a refusal.
      $element['#required'] = FALSE;
    }
    if ($set !== NULL) {
      $element = static::describedOptions($element, $set);
    }
    return static::cachedBy($element, $set);
  }

}
