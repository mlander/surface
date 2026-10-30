<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\ViolationSet;
use Drupal\surface\Collection;
use Drupal\surface\Widget\SurfaceWidgetManager;

/**
 * Generates, extracts, and validates Form API elements from a data surface.
 *
 * Compared to the adapter-era proof of concept this layer carries no
 * masks: widgets own defaults, constraint mapping and empty options
 * natively, elements are not wrapper-nested so submitted trees arrive in
 * the definition's clean shape, and extraction reads by #parents with no
 * subform states anywhere.
 *
 * The service is the entry point, and the work is done by three internal
 * classes it constructs: Drawing draws the elements, Extracting reads
 * what was posted back into the data surface's shape, and Refreshing
 * refines the form over AJAX. The Form API callbacks stay here under
 * their own names, because form caches and render arrays hold the class
 * and the method. The AJAX callback and the two #process callbacks call
 * the static method of the same name on Refreshing, and the container's
 * #pre_render calls Drawing; the collection buttons' #submit callbacks
 * and preRenderRequired() keep their bodies here.
 *
 * Decision: see
 * docs/decisions.md#the-form-builder-draws-extracts-and-refreshes.
 *
 * @see \Drupal\surface\Form\SurfaceFormBuilderInterface
 *   For the documentation of every method.
 */
class SurfaceFormBuilder implements SurfaceFormBuilderInterface, TrustedCallbackInterface {

  /**
   * Draws a data surface as elements.
   */
  protected readonly Drawing $drawing;

  /**
   * Reads what a form posted, and flags what the pipeline refused.
   */
  protected readonly Extracting $extracting;

  /**
   * Refines the form over AJAX.
   */
  protected readonly Refreshing $refreshing;

  /**
   * Constructs a SurfaceFormBuilder.
   *
   * @param \Drupal\surface\Widget\SurfaceWidgetManager $widgetManager
   *   The widget manager, which maps definitions to elements.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which accepts and validates what the form collects.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The element info manager. The container carries a #process
   *   callback of its own, and an explicit #process replaces whatever
   *   the element type declares instead of adding to it, so the type's
   *   own callbacks are read from here and kept.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger, which carries the one thing a data surface has to say
   *   that is not an error and not part of an element: that a stored
   *   value went stale and is being kept.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which answers the one question the discard
   *   rule turns on: whether the refined definition still offers the
   *   value an in-progress edit left behind.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, which says whether Inline Form Errors prints
   *   an error beside its element, so the message need not name it.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service, which words a value of the wrong
   *   shape the form refuses.
   */
  public function __construct(
    protected readonly SurfaceWidgetManager $widgetManager,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly ElementInfoManagerInterface $elementInfo,
    protected readonly MessengerInterface $messenger,
    protected readonly SurfaceOptions $options,
    protected readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $stringTranslation,
  ) {
    $this->refreshing = new Refreshing($elementInfo);
    $this->drawing = new Drawing($widgetManager, $this->refreshing);
    $this->extracting = new Extracting($widgetManager, $pipeline, $messenger, $options, $moduleHandler, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function buildSurfaceForm(SurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'surface'): array {
    return $this->drawing->buildSurfaceForm($surface, $values, $form_state, $wrapper_key);
  }

  /**
   * Adds an empty item to a collection: its "Add another" button's submit.
   *
   * The posted items are what the rebuild draws, so one more, holding
   * nothing, is put at their end, and the form is rebuilt from them.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function addListItem(array &$form, FormStateInterface $form_state): void {
    static::rewriteListInput($form_state, static function (array $items): array {
      $items[] = [];
      return $items;
    }, 1);
  }

  /**
   * Takes one item out of a collection: its "Remove" button's submit.
   *
   * The items after it move up one: delta is position.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function removeListItem(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $parents = $trigger['#parents'] ?? [];
    $delta = (int) ($parents[count($parents) - 2] ?? -1);
    static::rewriteListInput($form_state, static function (array $items) use ($delta): array {
      unset($items[$delta]);
      return array_values($items);
    }, 2);
  }

  /**
   * Rewrites the posted items of the collection a button belongs to.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param callable $rewrite
   *   Takes the posted items, in order, and returns the new ones.
   * @param int $depth
   *   How far below the list the button sits.
   */
  protected static function rewriteListInput(FormStateInterface $form_state, callable $rewrite, int $depth): void {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $trigger = $state->getTriggeringElement() ?? [];
    $parents = array_slice($trigger['#parents'] ?? [], 0, -$depth);
    if ($parents === []) {
      return;
    }
    $input = $state->getUserInput();
    NestedArray::setValue($input, $parents, $rewrite(Collection::items(NestedArray::getValue($input, $parents))), TRUE);
    $state->setUserInput($input);
    $state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function discardedRefinementInput(SurfaceInterface $surface, array $stored, array $input): array {
    return $this->extracting->discardedRefinementInput($surface, $stored, $input);
  }

  /**
   * {@inheritdoc}
   */
  public function refinementOverlay(SurfaceInterface $surface, array $stored, array $input): array {
    return $this->extracting->refinementOverlay($surface, $stored, $input);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeSurfaceContainer(array $container, array $form): array {
    return $this->refreshing->mergeSurfaceContainer($container, $form);
  }

  /**
   * {@inheritdoc}
   */
  public function placeRefreshed(array $container, string $key, array $element): array {
    return $this->refreshing->placeRefreshed($container, $key, $element);
  }

  /**
   * {@inheritdoc}
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): AjaxResponse|array {
    return Refreshing::refreshSurface($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function processSurfaceContainer(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    return Refreshing::processSurfaceContainer($element, $form_state, $complete_form);
  }

  /**
   * Draws an element required that Form API did not check as required.
   *
   * @param array $element
   *   The element.
   *
   * @return array
   *   The element, #required as its widget set it.
   */
  public static function preRenderRequired(array $element): array {
    $element['#required'] = !empty($element[self::REQUIRED_KEY]);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderSurfaceContainer', 'preRenderRequired'];
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderSurfaceContainer(array $element): array {
    return Drawing::placeMounts(Drawing::gatherGroups($element));
  }

  /**
   * {@inheritdoc}
   */
  public static function findSurfaceContainer(array $form): array {
    $found = Refreshing::searchSurfaceContainer($form);
    if ($found === NULL) {
      throw new \LogicException(sprintf(
        'No data surface container was found: nothing in the given form carries the "%s" marker. The container built by buildSurfaceForm() must reach extraction intact, because a data surface whose container cannot be found collects no values at all and every stored value is replaced by its default.',
        Refreshing::WRAPPER_KEY,
      ));
    }
    return $found;
  }

  /**
   * {@inheritdoc}
   */
  public function extractSurfaceValues(SurfaceInterface $surface, array $container, FormStateInterface $form_state, array $current = []): array {
    return $this->extracting->extractSurfaceValues($surface, $container, $form_state, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function validateSurfaceForm(SurfaceInterface $surface, array $values, array $container, FormStateInterface $form_state, array $current = []): bool {
    return $this->extracting->validateSurfaceForm($surface, $values, $container, $form_state, $current);
  }

  /**
   * {@inheritdoc}
   */
  public function flagSurfaceErrors(ViolationSet $errors, array $container, FormStateInterface $form_state): void {
    $this->extracting->flagSurfaceErrors($errors, $container, $form_state);
  }

  /**
   * Gives each radio or checkbox of a trigger what the group carries.
   *
   * @param array $element
   *   The radios or checkboxes, processed into their options.
   *
   * @return array
   *   The same, each option carrying the group's trigger.
   *
   * @see \Drupal\surface\Form\Refreshing::processOptionTrigger()
   */
  public static function processOptionTrigger(array $element): array {
    return Refreshing::processOptionTrigger($element);
  }

  /**
   * Starts afresh each variant whose deciding key moved since it was drawn.
   *
   * @see \Drupal\surface\Form\Extracting::switchedVariants()
   */
  public static function switchedVariants(SurfaceInterface $surface, ?array $form, array $path, array $input, array $stored): array {
    return Extracting::switchedVariants($surface, $form, $path, $input, $stored);
  }

  /**
   * Reads the keys a container posted as drawn as nothing.
   *
   * @param array|null $input
   *   The raw input, or NULL for a form state that holds none, as a
   *   host's form built in code may.
   * @param string[] $parents
   *   The container's input path.
   *
   * @return string[]
   *   Dotted paths, relative to the container.
   */
  public static function markedAbsent(?array $input, array $parents): array {
    $marker = $input === NULL ? NULL : NestedArray::getValue($input, [...$parents, self::ABSENT_MARKER_KEY]);
    return is_string($marker) && $marker !== '' ? explode(' ', $marker) : [];
  }

}
