<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Form\Document\DocumentDrawer;
use Drupal\surface\Form\Document\DocumentNodes;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\ValueState;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use Drupal\surface\Widget\WidgetHostInterface;
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
 * classes it constructs: the drawer draws the elements from the envelope
 * the data surface serves for the values (Document\DocumentDrawer), which
 * the builder emits with the widget hints; Extracting reads what was
 * posted back into the data surface's shape; and Refreshing refines the
 * form over AJAX, each rebuild drawn from a new envelope. The Form API
 * callbacks stay here under their own names, because form caches and
 * render arrays hold the class and the method. The AJAX callback and the
 * two #process callbacks call the static method of the same name on
 * Refreshing, and the container's #pre_render calls the drawer; the
 * collection buttons' #submit callbacks and preRenderRequired() keep
 * their bodies here.
 *
 * Decision: see
 * docs/decisions.md#the-form-builder-draws-extracts-and-refreshes.
 *
 * @see \Drupal\surface\Form\SurfaceFormBuilderInterface
 *   For the documentation of every method.
 */
class SurfaceFormBuilder implements SurfaceFormBuilderInterface, TrustedCallbackInterface {

  /**
   * The id the envelope a form is drawn from is emitted under.
   *
   * A form draws a data surface built in a host's context, which names no
   * served resource: the id names the document, never a def a client
   * fetches.
   */
  public const DOCUMENT_ID = 'surface.form';

  /**
   * Draws the served document as elements.
   */
  protected readonly DocumentDrawer $drawer;

  /**
   * Reads what a form posted, and flags what the pipeline refused.
   */
  protected readonly Extracting $extracting;

  /**
   * Refines the form over AJAX.
   */
  protected readonly Refreshing $refreshing;

  /**
   * Emits a deferred key's chosen document for the host's answers.
   */
  protected readonly NarrowsDocumentsInterface $narrowing;

  /**
   * How many drawn envelopes this request keeps for extraction.
   */
  protected const DRAWN_KEPT = 8;

  /**
   * The nodes of each envelope drawn in this request, by container id.
   *
   * Extraction in the request that drew the form reads them here; one
   * that reads a form from the form cache emits the envelope again.
   *
   * @var array<string, \Drupal\surface\Form\Document\DocumentNodes>
   */
  protected array $drawn = [];

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
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The contract emitter, which writes the envelope the form is drawn
   *   from.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the entity a reference holds.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository, which gives its label in the page's language.
   */
  public function __construct(
    protected readonly SurfaceWidgetManager $widgetManager,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly ElementInfoManagerInterface $elementInfo,
    protected readonly MessengerInterface $messenger,
    protected readonly SurfaceOptions $options,
    protected readonly ModuleHandlerInterface $moduleHandler,
    TranslationInterface $stringTranslation,
    protected readonly ContractEmitter $emitter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityRepositoryInterface $entityRepository,
  ) {
    $this->refreshing = new Refreshing($elementInfo);
    $this->drawer = new DocumentDrawer($widgetManager, $this->refreshing);
    $this->narrowing = new DocumentNarrowing($this, $emitter);
    $this->extracting = new Extracting($widgetManager, $pipeline, $messenger, $options, $moduleHandler, $stringTranslation, $this->nodesFor(...));
  }

  /**
   * {@inheritdoc}
   */
  public function buildSurfaceForm(SurfaceInterface $surface, array $values, FormStateInterface $form_state, string $wrapper_key = 'surface', array $keys = []): array {
    $container = $this->drawDocument($this->envelope($surface, $values), $form_state, $this->host($surface, $values), $wrapper_key, $keys);
    // What each element standing for a stale value stands for, which the
    // document leaves out: the host holds it, an orphan its standing
    // value, so a select left alone reads it back.
    $shown = $values;
    foreach ($values[self::STANDING_KEY] ?? [] as $dotted => $standing) {
      NestedArray::setValue($shown, explode('.', (string) $dotted), $standing, TRUE);
    }
    return static::stashed($container, $shown);
  }

  /**
   * Puts on each element marked stale the value it stands for.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param array $shown
   *   The values the host drew the form for, each orphan standing for its
   *   stored value.
   *
   * @return array
   *   The same, each stale element carrying its value.
   */
  protected static function stashed(array $element, array $shown): array {
    if (($element[SurfaceWidgetBase::STALE_KEY] ?? NULL) === TRUE && isset($element[self::PATH_KEY])) {
      $exists = FALSE;
      $held = NestedArray::getValue($shown, WidgetContext::parentsOf((string) $element[self::PATH_KEY]), $exists);
      if ($exists && ValueState::isConfigured($held)) {
        $element[SurfaceWidgetBase::STALE_KEY] = $held;
      }
    }
    foreach (DocumentDrawer::elementChildren($element) as $key) {
      $element[$key] = static::stashed($element[$key], $shown);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function drawDocument(ServedContract $contract, FormStateInterface $form_state, ?WidgetHostInterface $host = NULL, string $wrapper_key = 'surface', array $keys = []): array {
    $nodes = DocumentNodes::of($contract, $host);
    $container = $this->drawer->draw($contract, $form_state, $host, $wrapper_key, $keys, $nodes);
    $id = (string) ($container[Refreshing::WRAPPER_KEY] ?? '');
    unset($this->drawn[$id]);
    $this->drawn[$id] = $nodes;
    $this->drawn = array_slice($this->drawn, -self::DRAWN_KEPT, NULL, TRUE);
    return $container;
  }

  /**
   * Gets the nodes of the envelope a container was drawn from.
   *
   * Those this request drew for the container, when it drew it for the
   * values the container keeps; else the envelope emitted for those
   * values again, which is the one drawn: the form came from the form
   * cache, and the host built the same data surface.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface the container was drawn for.
   * @param array $container
   *   The container.
   *
   * @return \Drupal\surface\Form\Document\DocumentNodes
   *   The nodes.
   */
  protected function nodesFor(SurfaceInterface $surface, array $container): DocumentNodes {
    $values = is_array($container[self::VALUES_KEY] ?? NULL) ? $container[self::VALUES_KEY] : [];
    $kept = $this->drawn[(string) ($container[Refreshing::WRAPPER_KEY] ?? '')] ?? NULL;
    if ($kept !== NULL && $kept->values === $values) {
      return $kept;
    }
    return DocumentNodes::of($this->envelope($surface, $values), $this->host($surface, $values));
  }

  /**
   * {@inheritdoc}
   */
  public function envelope(SurfaceInterface $surface, array $values): ServedContract {
    return $this->emitter->emit($surface, $values, self::DOCUMENT_ID, widgets: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function host(SurfaceInterface $surface, array $values): WidgetHostInterface {
    return new FormWidgetHost($this->entityTypeManager, $this->entityRepository, $this->narrowing, $surface, $values);
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
  public function placeRefreshedBeside(array $form, string $container_key, string $key, array $element): array {
    return $this->refreshing->placeRefreshedBeside($form, $container_key, $key, $element);
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
    return DocumentDrawer::placeMounts(DocumentDrawer::gatherGroups($element));
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
