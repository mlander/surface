<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\TypedData\DataDefinitionInterface;

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
    return $this->rawValue($element, $form_state, $fallback_parents);
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
