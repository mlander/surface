<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\ViolationSet;

/**
 * Generates, extracts, and validates Form API elements from a surface.
 *
 * The refinement-aware layer: the surface is refined against current
 * values before elements build, and every definition that others refine
 * against is wired to rebuild the surface container via AJAX — the
 * rendered form and the refined definitions stay in lockstep by
 * construction.
 *
 * The form is not a value path of its own. Widgets collect the raw
 * submitted tree and the pipeline's accept() does the rest — coercion,
 * merging, locking, refusing undeclared keys — so this builder produces
 * exactly what a JSON payload carrying the same strings produces.
 */
interface DataSurfaceFormBuilderInterface {

  /**
   * Builds a container of form elements for a surface.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param array $values
   *   Current values keyed by surface key (stored values overlaid with
   *   any user input so far).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the containing form.
   * @param string $wrapper_key
   *   A stable identifier for the AJAX wrapper, unique within the page.
   *
   * @return array
   *   A container render array with one widget-built element per
   *   definition, keyed by surface key.
   */
  public function buildSurfaceForm(DataSurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'data-surface'): array;

  /**
   * Names the in-progress input a rebuild has just invalidated.
   *
   * The in-form half of the two-case rule. When the form's own edit of a
   * dependency orphans what a dependent was holding, the dependent's
   * input is transient — nobody submitted it, and it is no longer an
   * answer to the question now being asked — so it is discarded and the
   * key falls back: to its stored value when the narrowed definition
   * still offers that, to the stale placeholder when a stored value
   * exists and is no longer offered, and otherwise to nothing chosen at
   * all. Nothing is flagged and nothing is warned about, because nothing
   * was submitted.
   *
   * The out-of-form half is the stale model and is untouched here: only
   * input is ever named, never a stored value, which clears on a real
   * submit and at no other time.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface, as advertised.
   * @param array $stored
   *   What the host stores for the surface's keys.
   * @param array $input
   *   The in-progress input an AJAX refinement rebuild collected, keyed
   *   by surface key.
   *
   * @return string[]
   *   The surface keys whose input is to be dropped. A chain settles in
   *   one call: discarding a dependency's input invalidates whatever
   *   refines against it, however many links deep.
   *
   * @see docs/forms.md
   */
  public function discardedRefinementInput(DataSurfaceInterface $surface, array $stored, array $input): array;

  /**
   * Merges a surface container into a host's own form element.
   *
   * For hosts whose protocol hands over a form fragment the surface has
   * to live inside rather than beside — a block's settings, a
   * formatter's settings — so the surface's keys land at the value paths
   * the host stores them at.
   *
   * The rule: the surface fills in what the host left unsaid and
   * overwrites nothing the host said itself. #type, #tree, #attributes
   * and #process stay the host's where the host declares them; the
   * surface's children and its container marker are added. When the host
   * already names its element, that id becomes the AJAX wrapper.
   *
   * @param array $container
   *   The container built by buildSurfaceForm().
   * @param array $form
   *   The host's form fragment.
   *
   * @return array
   *   The host's fragment with the surface merged into it.
   */
  public function mergeSurfaceContainer(array $container, array $form): array;

  /**
   * Extracts surface values from a built container via the widgets.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param array $container
   *   The container built by buildSurfaceForm() (or an ancestor).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying submitted values.
   * @param array $current
   *   What the surface's keys already hold, in surface shape, read from
   *   wherever the host stores them. Not an optimization: a form is
   *   always a partial statement about a surface, because a key whose
   *   element was never rendered — access denied, removed by an alter,
   *   or below a container the host did not build — says nothing about
   *   its value, and without the stored values to fall back on accept()
   *   would replace every one of them with its declared default. A host
   *   that leaves this out is telling the surface that storage is empty.
   *
   * @return array
   *   The accepted values keyed by surface key, in each definition's
   *   native type.
   */
  public function extractSurfaceValues(DataSurfaceInterface $surface, array $container, FormStateInterface $form_state, array $current = []): array;

  /**
   * Validates extracted values against the refined surface.
   *
   * Violations are flagged on the elements they belong to before this
   * answers, so a host with nothing else to do may ignore the return.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The surface.
   * @param array $values
   *   The extracted values.
   * @param array $container
   *   The container the elements were built into.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to flag violations on.
   * @param array $current
   *   The stored values, in surface shape — the same ones handed to
   *   extractSurfaceValues(). Only what a key already holds can be
   *   stale, so a host that does not pass them gets a stale value
   *   refused as an ordinary violation, which is the bug this exists
   *   to close rather than a safe default.
   *
   * @return bool
   *   TRUE when the values are valid. Stale references do not make them
   *   invalid: they are warned about and saved.
   */
  public function validateSurfaceForm(DataSurfaceInterface $surface, array $values, array $container, FormStateInterface $form_state, array $current = []): bool;

  /**
   * Attaches surface violations to the exact elements they belong to.
   *
   * A violation's message reaches setError() as the object the
   * constraint built, never as text: Form API takes a Stringable and
   * renders it when the error is printed, so a message with placeholders
   * is escaped once, by whoever prints it.
   *
   * Stale references are not violations and are not flagged as ones:
   * they become a warning through the messenger, naming the key and the
   * value being kept. The element itself says so too, but it says it
   * where it is built — a select carries the sentinel option, the note
   * and the class from buildSurfaceForm() — because a form that fails
   * validation is rebuilt from scratch and anything written onto an
   * element here would not survive to be rendered.
   *
   * @param \Drupal\data_surface\Pipeline\ViolationSet $errors
   *   The violations, as the pipeline reports them.
   * @param array $container
   *   The container the elements were built into.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state to flag violations on.
   */
  public function flagSurfaceErrors(ViolationSet $errors, array $container, FormStateInterface $form_state): void;

  /**
   * AJAX callback: returns the surface container of the triggering element.
   *
   * Static because Form API stores an #ajax callback and calls it with
   * no object to call it on.
   *
   * @param array $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the rebuild.
   *
   * @return array
   *   The surface container to replace, or the whole form when no
   *   container is found.
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): array;

  /**
   * Locates the surface container inside whatever a host handed over.
   *
   * Host protocols are inconsistent about what reaches validate and
   * submit callbacks; the wrapper marker makes the container findable
   * either way, at any nesting depth.
   *
   * Missing is fatal rather than fallible. A fallback to the whole form
   * reads as working — extraction finds none of the surface's keys,
   * accept() is handed nothing, and every stored value quietly becomes
   * its default — so the one case where something is genuinely wrong is
   * the one case that must not be survivable.
   *
   * @param array $form
   *   The form or form fragment to search.
   *
   * @return array
   *   The container.
   *
   * @throws \LogicException
   *   When nothing in the tree carries the container marker.
   */
  public static function findSurfaceContainer(array $form): array;

  /**
   * Element #process callback: scopes the surface's AJAX validation.
   *
   * Attached to the container by buildSurfaceForm(). Every #ajax the
   * builder attached is limited to the container's own value path, so
   * touching a dependency validates the surface and never the host form
   * around it, and leaves the surface's submitted values — and only
   * those — readable on the rebuild.
   *
   * It has to be a #process callback on the container rather than a
   * property written at build time, because a container does not know
   * its own #parents until Form API assigns them, and it has to be the
   * container's rather than each child's, because by the time a child is
   * processed Form API has already taken its copy of the triggering
   * element.
   *
   * @param array $element
   *   The container element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The processed container.
   */
  public static function processSurfaceContainer(array &$element, FormStateInterface $form_state, array &$complete_form): array;

}
