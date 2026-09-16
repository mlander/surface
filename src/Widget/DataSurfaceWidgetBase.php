<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;

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
   * Render key carrying the stale value an element stands in for.
   *
   * Written by the widget that renders a placeholder instead of a value
   * it cannot show — the options widget's sentinel option is the only
   * one today — and read back here, by every widget, because which
   * widget reads an element back is not the same question as which
   * widget built it. Extraction resolves widgets from the surface as
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
   * Maps a keep-stale submission back to the value it stands for.
   *
   * An element that could not render its stored value rendered a marker
   * in its place, and a control left alone submits what it was rendered
   * with — so the marker coming back means "keep", which is what leaving
   * a control alone has always meant. It is mapped back here rather than
   * left for the pipeline because the marker is a rendering device: no
   * value the pipeline handles is ever that string, and a payload that
   * sends it is sending a value its key does not have.
   *
   * The stash is the authority, not the marker. An element carrying no
   * stash never had a stale value, so the marker submitted into it is an
   * ordinary string and is passed along to be refused as one.
   *
   * @param array $element
   *   The element the value was submitted for.
   * @param mixed $value
   *   The raw submitted value.
   *
   * @return mixed
   *   The stashed value when the marker came back, the raw value
   *   otherwise.
   */
  protected static function unstash(array $element, mixed $value): mixed {
    return $value === DataSurfacePipelineInterface::KEEP_STALE && array_key_exists(self::STALE_KEY, $element)
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
