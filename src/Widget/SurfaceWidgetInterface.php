<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use PortableSchema\Builder\Schema;

/**
 * Maps a node of the served document to a form element and back.
 *
 * A widget reads one node of the document the form is drawn from: the
 * schema of one key, with its `$ref`s followed and its compositions
 * merged (SchemaResolver::inline()), the hints the emitter wrote under
 * `x-portable-ui`, and Surface's markers under `x-surface`. It reads no
 * definition and asks no live collaborator: what it needs that the
 * document does not say, the label of the entity a reference names, it
 * asks the context the host provides.
 *
 * Widgets do not validate and do not coerce: extraction hands back the
 * raw submitted value in the node's shape, and the pipeline's accept()
 * casts it, which keeps a generated form and a payload from drifting
 * apart.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
interface SurfaceWidgetInterface {

  /**
   * Returns whether this widget draws a node.
   *
   * Asked of a node whose `x-portable-ui.widget` names no widget, or names
   * one that cannot draw it: the widgets are asked in weight order and
   * the first that says yes draws it. The answer is read off the
   * document alone (`type`, `format`, `oneOf`, `items`, a check, a hint),
   * never from a live collaborator.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's node, inline.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where the node is drawn.
   *
   * @return bool
   *   TRUE when this widget can build an element for the node.
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool;

  /**
   * Builds the form element for a node.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The key's node, inline.
   * @param mixed $value
   *   The value shown, from the envelope's `values`, or NULL.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where the node is drawn: whether its owner requires it, whether its
   *   value is stale, and the host's answers.
   *
   * @return array
   *   A render element: an input element, or a container whose children
   *   are keyed by property name for an object.
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array;

  /**
   * Extracts the submitted value of an element this widget built.
   *
   * @param array $element
   *   The processed element (carries #parents), or the unprocessed
   *   structure in programmatic use, whose #surface_path says where its
   *   value is posted.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying submitted values.
   * @param \PortableSchema\Builder\Schema $node
   *   The node the element was built from.
   *
   * @return mixed
   *   The raw submitted value in the node's shape: a scalar for a single
   *   value, an array keyed by property name for an object. Coercion is
   *   the pipeline's job, not the widget's.
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed;

}
