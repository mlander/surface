<?php

declare(strict_types=1);

namespace Drupal\data_surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Maps a data definition to a form element and back.
 *
 * The API is deliberately value-based: plain values in, a render element
 * out, and plain values back from extraction. No typed-data objects are
 * threaded through the form path and no subform states exist anywhere —
 * the coordinate-frame landmines of adapter-era extraction cannot occur.
 * A definition's element IS its input (or a container of named children
 * for complex definitions); there is no wrapper nesting, so submitted
 * value trees are already in the definition's clean shape.
 *
 * Widgets own the definition-to-element fidelity the adapter era lacked:
 * defaults populate at every depth, constraint options map onto element
 * properties (Length to #maxlength, Range to #min/#max), and optional
 * selects offer an empty choice. Widgets do not coerce: extraction
 * hands back the raw submitted value in the definition's shape and the
 * pipeline's accept() casts it, which is what keeps a generated form
 * and a payload from drifting apart.
 */
interface DataSurfaceWidgetInterface {

  /**
   * Returns whether this widget can serve a definition.
   *
   * An instance method, and deliberately so: a widget may have to ask a
   * collaborator before it can answer — the options widget asks the
   * options service whether the definition's constraints name a list
   * that is not empty — and a static answer would have to reach the
   * container to do it. The manager instantiates the candidates in
   * weight order and asks each, so a widget answers with everything
   * create() gave it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The (possibly refined) definition.
   *
   * @return bool
   *   TRUE when this widget can build an element for the definition.
   */
  public function isApplicable(DataDefinitionInterface $definition): bool;

  /**
   * Builds the form element for a definition.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The (possibly refined) definition.
   * @param mixed $value
   *   The current value, or NULL.
   *
   * @return array
   *   A render element: an input element, or a container whose children
   *   are keyed by property name for complex definitions.
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array;

  /**
   * Extracts the submitted value for a definition's element.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition the element was built from.
   * @param array $element
   *   The processed element (carries #parents), or the unprocessed
   *   structure in programmatic use.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying submitted values.
   * @param array $fallback_parents
   *   The value path to read when the element has no #parents (an
   *   unprocessed form in programmatic/kernel use).
   *
   * @return mixed
   *   The raw submitted value in the definition's shape: a scalar for a
   *   primitive definition, an array keyed by property name for a
   *   complex one. Coercion is the pipeline's job, not the widget's.
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed;

}
