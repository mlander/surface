<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\ValueState;

/**
 * Common ground for data surface widgets.
 *
 * Extraction is shared and deliberately dumb: it hands back the raw
 * submitted value in the definition's shape. Coercion belongs to the
 * pipeline's accept(), which the form path calls once the whole tree is
 * collected, so a generated form and a JSON payload are coerced by the
 * same code. A widget overrides extractValue() only when the submitted
 * tree needs walking, as the map widget's does.
 */
abstract class DataSurfaceWidgetBase extends PluginBase implements DataSurfaceWidgetInterface {

  /**
   * Render key carrying the stored value a select could not show.
   *
   * Written by the options widget when a stored value is no longer among
   * the options: the select comes up on its empty option, and the value
   * travels here. Read back here, by every widget, because which widget
   * reads an element back is not the same question as which widget
   * built it. Extraction resolves widgets from the surface as
   * advertised, since it has no values yet to refine with, while the
   * form was built from the surface refined against what was stored: a
   * key that is a plain string until a refiner narrows it into a choice
   * is built by the options widget and read back by the string one. So
   * the stash belongs to the element, not to a widget, and reading it
   * back is shared ground.
   *
   * What it holds is a value and never anything else. The element is
   * serialized into the form cache and walked by every element alter
   * hook on the site, which is the same rule that keeps closures out of
   * a form array.
   */
  public const STALE_KEY = '#data_surface_stale';

  /**
   * Builds the properties every element derives from its definition.
   *
   * @return array
   *   The common element properties.
   */
  protected function baseElement(DataDefinitionInterface $definition): array {
    $element = [
      '#title' => $definition->getLabel(),
      '#required' => $definition->isRequired(),
    ];
    if ($definition->getDescription() !== NULL) {
      $element['#description'] = $definition->getDescription();
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    return static::unstash($element, $this->rawValue($element, $form_state, $fallback_parents));
  }

  /**
   * Maps an untouched stale select back to the value it stands for.
   *
   * An element that could not show its stored value came up empty and
   * carries the value in its stash, so empty coming back from it means
   * "left alone", and leaving a control alone means keep: only an
   * explicit new choice replaces a stored value. There is no way to tell
   * "left alone" from "chose the empty option" on such a select, since
   * the empty option is what was selected, and that is the point — it
   * never offered emptiness as a separate answer.
   *
   * The stash is the authority. An element carrying none had nothing
   * stale behind it, so empty from it is an ordinary empty answer.
   *
   * @param array $element
   *   The element the value was submitted for.
   * @param mixed $value
   *   The raw submitted value.
   *
   * @return mixed
   *   The stashed value when the element came back empty, the raw value
   *   otherwise.
   */
  protected static function unstash(array $element, mixed $value): mixed {
    return !ValueState::isConfigured($value) && array_key_exists(self::STALE_KEY, $element)
      ? $element[self::STALE_KEY]
      : $value;
  }

  /**
   * Reads the raw submitted value for an element.
   *
   * Two coordinate frames meet here, and getting it wrong is silent.
   * Form API assigns #parents from the root of the complete form, so
   * they are absolute; a subform state reads values relative to the
   * fragment it wraps. A host that hands its plugin a subform state —
   * a block's settings are the common case, built with
   * SubformState::createForSubform() — would therefore have every value
   * looked up one level too deep, come back NULL, and be reported by
   * the surface as "not configured", which for a required key is a
   * spurious violation and for an optional one is a stored value
   * replaced by nothing. So an absolute path is read against the
   * complete form state it is absolute in.
   *
   * An element with no #parents has not been processed at all
   * (programmatic use, kernel tests, hosts that validate before
   * processing). There is no absolute path to use, so the value is read
   * where the caller put it: relative to the state handed over.
   */
  protected function rawValue(array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    if (!isset($element['#parents'])) {
      return $form_state->getValue($fallback_parents);
    }
    $state = $form_state instanceof SubformStateInterface
      ? $form_state->getCompleteFormState()
      : $form_state;
    return $state->getValue($element['#parents']);
  }

  /**
   * Reads a constraint's options from a definition.
   */
  protected function constraint(DataDefinitionInterface $definition, string $name): ?array {
    $constraint = $definition->getConstraints()[$name] ?? NULL;
    return is_array($constraint) ? $constraint : NULL;
  }

}
