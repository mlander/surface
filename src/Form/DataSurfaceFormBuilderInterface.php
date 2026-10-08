<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Core\Ajax\AjaxResponse;
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
   * Key of the hidden input naming the selects that stand for a stale value.
   *
   * A select whose stored value is no longer offered comes up on its
   * empty option, which submits the same empty string as an empty
   * answer. Its element knows the difference, through its stash, but
   * the build a submission is processed against is made from what was
   * submitted, before any element exists. So the container posts, beside
   * the selects, the dotted paths of the ones standing for a stored
   * value, and a host's overlay reads an empty answer at one of those
   * paths as "keep what is stored" rather than as "clear it". It can
   * only ever name a value that is already stored, so a forged path
   * keeps a value the caller could have sent anyway.
   *
   * In the reserved "@" namespace, which no definition is named in.
   */
  public const STALE_MARKER_KEY = '@stale';

  /**
   * Render key on a wired refinement dependency: where it sits, what moves.
   *
   * An array with 'path', the element's own path below the container as
   * a list of keys, and 'replaces', the dotted paths of every element
   * that depends on it, transitively, in declaration order. Plain arrays,
   * so it rides the form cache. The path is what lets a host find the
   * container in the input from a trigger at any depth — an attached
   * child's own dependency sits two levels down, not one — and the list
   * is what the AJAX callback replaces.
   */
  public const TRIGGER_KEY = '#data_surface_trigger';

  /**
   * Render key holding the DOM id of an element's own AJAX wrapper.
   *
   * Set on every refinement target, every slot and every element placed
   * with placeRefreshed() when the container is processed: derived from
   * the container's id and the element's path, so it is unique wherever
   * the container's is and stays the same on every rebuild.
   */
  public const REFRESH_ID_KEY = '#data_surface_refresh_id';

  /**
   * The request key a refinement trigger posts its container's id under.
   *
   * Html::getUniqueId() answers an AJAX request with a random suffix, so
   * the id a rebuild would generate is never the one the browser holds,
   * and the elements a partial rebuild leaves in place keep the old one.
   * The trigger therefore sends the id it was rendered with, and the
   * rebuild takes it back, so every wrapper id the commands name is one
   * the page has.
   */
  public const WRAPPER_INPUT = '_data_surface_wrapper';

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
   * still offers that, to the empty option standing for the stored
   * value when one exists and is no longer offered, and otherwise to
   * nothing chosen at all. Nothing is flagged and nothing is warned
   * about, because nothing was submitted.
   *
   * The out-of-form half is the stale model and is untouched here: only
   * input is ever named, never a stored value, which changes on a real
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
   *   The surface keys whose input is to be dropped, and, inside an
   *   attached child or a chosen slot variant, the dotted paths of the
   *   child's own keys its own refiners orphaned. A chain settles in one
   *   call: discarding a dependency's input invalidates whatever refines
   *   against it, however many links deep.
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
   * Places an element in the container that every refinement replaces.
   *
   * For something shown inside the surface that describes it as the
   * answers stand — the situation form's panel — and so has to follow
   * every rebuild, whichever key triggered it. It gets a wrapper of its
   * own, like a refinement target's, and refreshSurface() replaces it
   * beside the dependents. It is not a surface key: extraction reads the
   * surface's own keys only.
   *
   * @param array $container
   *   The container built by buildSurfaceForm().
   * @param string $key
   *   The key to place the element at; never a surface key.
   * @param array $element
   *   The element.
   *
   * @return array
   *   The container, holding the element.
   */
  public function placeRefreshed(array $container, string $key, array $element): array;

  /**
   * AJAX callback: replaces what the triggering element's change moved.
   *
   * One replace command per element that depends on the trigger,
   * transitively — the closure the discard cascade walks, each found in
   * the rebuilt container by its path and replaced by the wrapper id it
   * was rendered with — plus every element placed with
   * placeRefreshed(), the stale marker removed and put back as the
   * rebuild left it, and the messages the request produced. The
   * triggering element itself is never among them: what the person just
   * touched stays where it is, focused, and only what it changed is
   * redrawn. A slot whose deciding key moved is replaced whole, by its
   * wrapper.
   *
   * When a dependent cannot be found in the rebuilt container — a host
   * or a cosmetic layer took it out — the whole container is returned
   * instead, which the browser puts in place of the wrapper the trigger
   * names: the old behavior, and never wrong, only coarser.
   *
   * Static because Form API stores an #ajax callback and calls it with
   * no object to call it on.
   *
   * @param array $form
   *   The rebuilt form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state of the rebuild.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|array
   *   The commands, or, as the fallback, the surface container to
   *   replace (the whole form when no container is found).
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): AjaxResponse|array;

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
   * Element #process callback: scopes AJAX validation, places wrappers.
   *
   * Attached to the container by buildSurfaceForm(). Every #ajax inside
   * the container, at any depth, is limited to that element's own value
   * path, so touching a dependency judges that one answer and nothing
   * else — not the host form around the surface, and not the dependent
   * the change has just orphaned. And every element the AJAX callback
   * may replace gets its wrapper, with an id derived from the
   * container's, which is only final once a host has merged the
   * container into its own element.
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
