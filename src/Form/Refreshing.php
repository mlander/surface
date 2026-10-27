<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AnnounceCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Collection;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Refines a form over AJAX: the triggers, the wrappers and the response.
 *
 * Each refinement dependency is wired to the rebuild (wireRefinement()).
 * The container's #process limits each trigger's validation to its own
 * value and wraps every element the rebuild may replace
 * (processSurfaceContainer()). The AJAX callback answers the request with
 * one replace command per element the trigger moved, the container's
 * markers put back as the rebuild left them, and a sentence saying what
 * changed (refreshSurface(), changesSaid()). A host's own element takes
 * the container in (mergeSurfaceContainer()), and the container takes an
 * element every rebuild replaces (placeRefreshed()).
 *
 * The Form API callbacks stay on DataSurfaceFormBuilder under their own
 * names, because form caches and render arrays hold those names. Its
 * refreshSurface(), processSurfaceContainer() and processOptionTrigger()
 * call the static method of the same name here.
 *
 * Decision: see
 * docs/decisions.md#the-form-builder-draws-extracts-and-refreshes.
 *
 * @internal
 *
 * @see \Drupal\data_surface\Form\DataSurfaceFormBuilder
 */
final class Refreshing {

  /**
   * Render key marking the data surface container the AJAX path rebuilds.
   */
  public const WRAPPER_KEY = '#data_surface_wrapper';

  /**
   * Render key on an element the AJAX callback may replace: its path.
   *
   * Dotted, below the container. The container's #process turns it into
   * a wrapper and REFRESH_ID_KEY once the container's id is final.
   */
  public const REFRESH_KEY = '#data_surface_refresh';

  /**
   * Render key on the container listing the keys every rebuild replaces.
   *
   * Filled by placeRefreshed().
   */
  protected const REFRESHED_KEY = '#data_surface_refreshed';

  /**
   * Where a request keeps the options its triggers were rendered with.
   *
   * Temporary form state: read off the form the request arrived with,
   * which is the page the person sees, before any rebuild, so the AJAX
   * callback can tell a trigger whose options the rebuild changed.
   */
  protected const RENDERED_OPTIONS = 'data_surface_rendered_options';

  /**
   * Where a request keeps what each element it may replace was drawn as.
   *
   * Temporary form state, read off the form the request arrived with as
   * RENDERED_OPTIONS is, so the AJAX callback can say what the rebuild
   * changed: keyed by the container's place in the form, then by each
   * replaceable element's dotted path, each as drawing() reads it.
   */
  protected const DRAWN_AS = 'data_surface_drawn_as';

  /**
   * Element types that hold children rather than a value of their own.
   *
   * A refinement dependency rendered as one of these gets no #ajax: see
   * attachRefinementAjax() for why.
   */
  public const GROUPING_TYPES = ['details', 'fieldset', 'container'];

  /**
   * Element types whose options are each an element that triggers.
   */
  protected const OPTION_GROUP_TYPES = ['radios', 'checkboxes'];

  /**
   * Element types whose change event fires when they lose focus.
   *
   * Core's own list, the types it marks to refocus-blur in
   * RenderElementBase::preRenderAjaxForm(). A trigger of one of these
   * also gets disable-refocus: see attachRefinementAjax() for why.
   */
  protected const BLUR_CHANGE_TYPES = ['password', 'textfield', 'number', 'tel', 'textarea', 'machine_name'];

  /**
   * Constructs the refinement over AJAX.
   *
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $elementInfo
   *   The element info manager. The container and an option trigger carry
   *   a #process callback of their own, and an explicit #process replaces
   *   whatever the element type declares instead of adding to it, so the
   *   type's own callbacks are read from here and kept.
   */
  public function __construct(
    protected readonly ElementInfoManagerInterface $elementInfo,
  ) {
  }

  /**
   * Merges a data surface container into a host's own form element.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::mergeSurfaceContainer()
   */
  public function mergeSurfaceContainer(array $container, array $form): array {
    // The rule, in one line: the data surface fills in what the host left
    // unsaid and overwrites nothing the host said itself. A host form that
    // already declares #type, #tree, #attributes or #process is describing
    // its own element, and the data surface is a guest inside it; taking
    // those keys over is how a fieldset became a container and a host's own
    // wrapper id disappeared. Only the data surface's own children and the
    // marker are the data surface's to place.
    $merged = $form;
    if (isset($form['#attributes']['id']) && $form['#attributes']['id'] !== $container[self::WRAPPER_KEY]) {
      // The host named its element before the data surface arrived, so that
      // id is the wrapper the rebuild replaces and the generated one is
      // discarded, including in the #ajax already attached to children.
      $container = static::retargetWrapper($container, $form['#attributes']['id']);
    }
    foreach ($container as $key => $value) {
      if (!is_string($key) || !str_starts_with($key, '#')) {
        // The data surface's keys are the data surface's own, so they win
        // over a host key of the same name; its render keys, below, do not.
        $merged[$key] = $value;
        continue;
      }
      // Anything the host has not said itself, the data surface says.
      $merged[$key] ??= $value;
    }
    // Attributes merge rather than replace, so a host that set a class
    // keeps it and the data surface adds only what is missing. The type,
    // the tree flag and the rest were settled by the loop above.
    $merged['#attributes'] = ($form['#attributes'] ?? []) + $container['#attributes'];
    $merged['#process'] = isset($form['#process'])
      ? array_merge($form['#process'], [[DataSurfaceFormBuilder::class, 'processSurfaceContainer']])
      : $this->surfaceProcess($merged['#type'] ?? NULL);
    $merged['#pre_render'] = isset($form['#pre_render'])
      ? array_merge($form['#pre_render'], [[DataSurfaceFormBuilder::class, 'preRenderSurfaceContainer']])
      : $this->surfacePreRender($merged['#type'] ?? NULL);
    return $merged;
  }

  /**
   * Places an element in the container that every refinement replaces.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::placeRefreshed()
   */
  public function placeRefreshed(array $container, string $key, array $element): array {
    $element[self::REFRESH_KEY] = $key;
    $container[$key] = $element;
    $container[self::REFRESHED_KEY] = array_values(array_unique([...($container[self::REFRESHED_KEY] ?? []), $key]));
    return $container;
  }

  /**
   * AJAX callback: replaces what the triggering element's change moved.
   *
   * What is replaced is read off the trigger, which carries the
   * dependent closure it was built with: the dependency edges are fixed
   * when a data surface is sealed and no refinement changes them, so the
   * closure the previous build computed is the closure of this one. Each
   * element is then taken from the rebuilt container, because that is
   * the one built against the new answer.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::refreshSurface()
   */
  public static function refreshSurface(array &$form, FormStateInterface $form_state): AjaxResponse|array {
    $trigger = $form_state->getTriggeringElement() ?? [];
    $container = static::containerOf($form, $trigger['#array_parents'] ?? []);
    $replaces = $trigger[DataSurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'] ?? NULL;
    if ($container === NULL || !is_array($replaces)) {
      return $container ?? $form;
    }
    $own = implode('.', $trigger[DataSurfaceFormBuilderInterface::TRIGGER_KEY]['path'] ?? []);
    $wrapper_id = (string) $container[self::WRAPPER_KEY];
    $own_parents = explode('.', $own);
    $touched = NestedArray::getValue($container, $own_parents);
    $replace_own = FALSE;
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    if (is_array($touched)) {
      $touched = Extracting::withTriggerErrors($touched, $form_state, static::containerKey($container), $own);
      NestedArray::setValue($container, $own_parents, $touched);
      // Decision: see docs/decisions.md#an-error-on-the-trigger-renders-inline.
      $rendered = $state->getTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($container), $own]);
      $replace_own = !empty($touched[DataSurfaceFormBuilderInterface::INLINE_ERROR_KEY])
        || ($state->getUserInput()[DataSurfaceFormBuilderInterface::INVALID_INPUT] ?? NULL) === '1'
        || ($rendered !== NULL && $rendered !== static::optionsSignature($touched));
    }
    $elements = [];
    $drawn = [];
    foreach ([...$replaces, ...($container[self::REFRESHED_KEY] ?? []), ...($replace_own ? [$own] : [])] as $dotted) {
      if ($dotted === $own && !$replace_own) {
        // Not the element that was touched, unless its own value was
        // refused: it already shows what the person chose, and redrawing
        // it is what made the change look like a reload of the field
        // rather than of what it changed.
        continue;
      }
      $exists = FALSE;
      $element = NestedArray::getValue($container, explode('.', (string) $dotted), $exists);
      if (!$exists || !is_array($element) || !isset($element[DataSurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
        // Taken out by a host or a cosmetic layer, or never wrapped:
        // there is no telling where it went, so the container goes
        // whole, which is coarser and never wrong.
        return $container;
      }
      // A member of a group is not rendered on its own, and a replaced
      // element is rendered on its own; core's own AJAX response builder
      // does the same to the element a callback returns.
      unset($element['#group']);
      if ($dotted !== $own && in_array($dotted, $replaces, TRUE)) {
        // What the change moved; a panel every rebuild redraws is not.
        $drawn[(string) $dotted] = $element;
      }
      // Rendered without the container, so a group or a fieldset placed
      // inside it is drawn here, as the container's pre-render would have.
      $elements[$element[DataSurfaceFormBuilderInterface::REFRESH_ID_KEY]] = Drawing::placeMounts(Drawing::gatherGroups($element));
    }
    $response = new AjaxResponse();
    foreach ($elements as $id => $element) {
      $response->addCommand(new ReplaceCommand('#' . $id, $element));
    }
    // Said once, politely, for whoever cannot see what moved: the error on
    // the trigger, then what the rebuild changed.
    $said = static::changesSaid(
      $state->getTemporaryValue([self::DRAWN_AS, static::containerKey($container)]) ?? [],
      $drawn,
      is_array($touched) && !empty($touched[DataSurfaceFormBuilderInterface::INLINE_ERROR_KEY]) ? $touched : NULL,
    );
    if ($said !== '') {
      $response->addCommand(new AnnounceCommand($said));
    }
    // The stale marker names stale selects across the whole container,
    // and whether it exists at all depends on the rebuild, so it is not
    // replaced but taken out and put back as the rebuild left it. A
    // marker left naming a select that now holds a real choice would
    // read that select back as its stored value on the next request.
    $response->addCommand(new RemoveCommand('#' . static::reservedId($wrapper_id, 'stale')));
    if (isset($container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY])) {
      $response->addCommand(new AppendCommand('#' . $wrapper_id, $container[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]));
    }
    // The keys drawn as nothing, likewise: one the rebuild drew must not
    // be read as left out by the next submission.
    $response->addCommand(new RemoveCommand('#' . static::reservedId($wrapper_id, 'absent')));
    if (isset($container[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY])) {
      $response->addCommand(new AppendCommand('#' . $wrapper_id, $container[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY]));
    }
    // What the render-array path printed into the replaced container,
    // printed in the same place, so nothing the request said is held
    // over to the next page: a stale value kept, say. An error on the
    // trigger is not among them; it is printed under the trigger. The
    // previous request's are taken away first, as replacing the
    // container used to.
    $messages = static::reservedId($wrapper_id, 'messages');
    $response->addCommand(new RemoveCommand('#' . $messages));
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    if (\Drupal::messenger()->all() !== []) {
      $response->addCommand(new PrependCommand('#' . $wrapper_id, [
        '#type' => 'container',
        '#attributes' => ['id' => $messages],
        'messages' => ['#type' => 'status_messages'],
      ]));
    }
    return $response;
  }

  /**
   * Says what a rebuild changed, in whole sentences, for an announcement.
   *
   * A sighted person sees a field come or go and a limit move; nobody
   * else is told. Each replaced element is compared with what it was
   * drawn as on the page the request came from: whether it applies,
   * whether it is required, the range it allows, the choices it offers,
   * and, for a group, the fields it holds. The trigger's own refusal,
   * named for it, comes first.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array<string, array> $before
   *   What each replaceable element was drawn as, by dotted path, from
   *   drawnAs().
   * @param array<string, array> $after
   *   The replaced elements as rebuilt, by dotted path, the trigger left
   *   out.
   * @param array|null $refused
   *   The trigger, when it carries an error printed under it.
   *
   * @return string
   *   The sentences, or an empty string when nothing is said.
   */
  protected static function changesSaid(array $before, array $after, ?array $refused): string {
    $sentences = [];
    $errors = $refused['#errors'] ?? NULL;
    foreach (is_array($errors) ? ($errors['#items'] ?? []) : [$errors] as $message) {
      if ($refused !== NULL && (is_string($message) || $message instanceof \Stringable) && (string) $message !== '') {
        $sentences[] = Drawing::named($refused, $message);
      }
    }
    foreach ($after as $dotted => $element) {
      $was = $before[$dotted] ?? NULL;
      if ($was === NULL) {
        continue;
      }
      $now = Drawing::drawing($element);
      $label = $now['label'] ?? $was['label'];
      if ($label === NULL || trim(strip_tags((string) $label)) === '') {
        continue;
      }
      if ($was['applies'] !== $now['applies']) {
        $sentences[] = $now['applies']
          ? t('@label now applies.', ['@label' => $label])
          : t('@label no longer applies.', ['@label' => $label]);
        continue;
      }
      if (!$now['applies']) {
        continue;
      }
      $said = count($sentences);
      if ($was['required'] !== $now['required']) {
        $sentences[] = $now['required']
          ? t('@label is now required.', ['@label' => $label])
          : t('@label is no longer required.', ['@label' => $label]);
      }
      if ([$was['min'], $was['max']] !== [$now['min'], $now['max']]) {
        // Only the bounds there are: a placeholder is never handed NULL.
        $range = array_filter(['@label' => $label, '@min' => $now['min'], '@max' => $now['max']], static fn ($bound): bool => $bound !== NULL);
        $sentences[] = match (TRUE) {
          isset($range['@min'], $range['@max']) => t('@label now allows @min to @max.', $range),
          isset($range['@min']) => t('@label now allows @min or more.', $range),
          isset($range['@max']) => t('@label now allows up to @max.', $range),
          default => t('@label now allows any number.', $range),
        };
      }
      if ($was['options'] !== $now['options']) {
        $sentences[] = t('The choices for @label have changed.', ['@label' => $label]);
      }
      if (count($sentences) === $said && $was['children'] !== $now['children']) {
        $sentences[] = t('@label was updated.', ['@label' => $label]);
      }
    }
    return implode(' ', array_map('strval', $sentences));
  }

  /**
   * Finds the data surface container around an element of a built form.
   *
   * The walk reads the form and never writes to it: a walk by reference
   * would create every key it looked for.
   *
   * @param array $form
   *   The form.
   * @param array $array_parents
   *   The element's #array_parents.
   *
   * @return array|null
   *   The nearest container above the element, or NULL when none is.
   */
  protected static function containerOf(array $form, array $array_parents): ?array {
    $parents = $array_parents;
    while ($parents !== []) {
      array_pop($parents);
      $candidate = $form;
      foreach ($parents as $parent) {
        if (!is_array($candidate) || !array_key_exists($parent, $candidate)) {
          continue 2;
        }
        $candidate = $candidate[$parent];
      }
      if (isset($candidate[self::WRAPPER_KEY])) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Element #process callback: scopes AJAX validation, places wrappers.
   *
   * @see \Drupal\data_surface\Form\DataSurfaceFormBuilderInterface::processSurfaceContainer()
   */
  public static function processSurfaceContainer(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    // The container knows its own #parents by now — Form API assigns
    // them before it runs an element's #process — and its children do
    // not yet know they triggered anything, because the triggering
    // element is captured while each child is built, which happens after
    // this. That ordering is the only moment the limit can be written:
    // Form API keeps a copy of the triggering element, so anything added
    // to a child later is never seen.
    //
    // The limit is the triggering element and nothing else, not the
    // container around it. Touching a select is not a submission of the
    // data surface: the person has said one thing, about one key, and every
    // other key is still mid-edit. Scoped to the container, the rebuild
    // validated the whole data surface, so a dependent holding a value the
    // new choice had just orphaned was flagged as a wrong answer to a
    // question nobody asked, and, because Form API skips the rebuild
    // outright once anything has errored, the container came back
    // refined against the old choice as well. Both halves of the bug
    // were that one line.
    //
    // The data surface's real validation is unaffected: it runs on submit,
    // through the host's validate stage, where every key has been
    // answered on purpose.
    //
    // At any depth: an attached child's own dependency sits inside the
    // child's details, and is as much a trigger as a top level key.
    $wrapper_id = $element[self::WRAPPER_KEY] ?? NULL;
    if (is_string($wrapper_id)) {
      // Each list of free values the list widget drew, at any depth: the
      // widget cannot know where it sits, and this is the first moment
      // anything does.
      Drawing::wireRows($element, [], $wrapper_id);
    }
    // Before any child is processed, so before Form API checks one.
    foreach (Drawing::elementChildren($element) as $key) {
      $element[$key] = static::requiredHints($element[$key], \Drupal::service('element_info'));
    }
    static::limitAjax($element, $element['#parents'] ?? [], !empty($element['#tree']));
    // The wrappers the AJAX callback replaces by. Placed here rather than
    // at build time because the container's id is only final once a host
    // has merged it into its own element, and every wrapper id is derived
    // from it.
    if (is_string($wrapper_id)) {
      $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
      if ($state->getTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($element)]) === NULL) {
        // The first build of a request is the form the page was rendered
        // from; a rebuild later in the same request is not.
        $state->setTemporaryValue([self::RENDERED_OPTIONS, static::containerKey($element)], static::triggerOptions($element));
        $state->setTemporaryValue([self::DRAWN_AS, static::containerKey($element)], Drawing::drawnAs($element));
      }
      static::wrapRefreshed($element, $wrapper_id);
      if (isset($element[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY]) && !isset($element[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
        $element[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY] = static::wrapped($element[DataSurfaceFormBuilderInterface::STALE_MARKER_KEY], static::reservedId($wrapper_id, 'stale'));
      }
      if (isset($element[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY]) && !isset($element[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
        $element[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY] = static::wrapped($element[DataSurfaceFormBuilderInterface::ABSENT_MARKER_KEY], static::reservedId($wrapper_id, 'absent'));
      }
    }
    return $element;
  }

  /**
   * Makes the #required of every value element below one a hint only.
   *
   * A widget draws a required key with #required, which paints the
   * browser's `required` attribute and the label's marker, and which Form
   * API also checks, before any form level handler: a form keeps the
   * first error per element, so its "Name field is required." replaced
   * the data surface's own, "Name is required when Email is set." So the
   * check is taken away and the drawing kept: #required is cleared on
   * each element that holds a value, REQUIRED_KEY says it was set, and
   * preRenderRequired(), first in the element's #pre_render list, puts
   * it back when the element is drawn, on the page and in an AJAX
   * response alike. A details or a fieldset holds no value and is never
   * checked, so it keeps its #required as it is.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Render\ElementInfoManagerInterface $element_info
   *   The element info manager, for each type's own #pre_render list,
   *   which an explicit one replaces.
   *
   * @return array
   *   The element, and every element below it, with #required a hint.
   */
  protected static function requiredHints(array $element, ElementInfoManagerInterface $element_info): array {
    $type = $element['#type'] ?? NULL;
    if (!empty($element['#required']) && is_string($type) && !empty($element_info->getInfo($type)['#input'])) {
      $element['#required'] = FALSE;
      $element[DataSurfaceFormBuilderInterface::REQUIRED_KEY] = TRUE;
      $element['#pre_render'] = [
        [DataSurfaceFormBuilder::class, 'preRenderRequired'],
        ...($element['#pre_render'] ?? $element_info->getInfo($type)['#pre_render'] ?? []),
      ];
    }
    foreach (Drawing::elementChildren($element) as $key) {
      $element[$key] = static::requiredHints($element[$key], $element_info);
    }
    return $element;
  }

  /**
   * Limits every #ajax below an element to that element's own value.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param array $parents
   *   The element's #parents.
   * @param bool $tree
   *   The element's #tree.
   */
  protected static function limitAjax(array &$element, array $parents, bool $tree): void {
    foreach (Drawing::elementChildren($element) as $key) {
      // The child's own value path, computed the way Form API is about
      // to compute it: a child inherits its parent's #tree, and is
      // nested under its parent only when both are trees; anything else
      // is top level.
      $child_tree = (bool) ($element[$key]['#tree'] ?? $tree);
      $child_parents = $element[$key]['#parents'] ?? ($child_tree && $tree ? [...$parents, $key] : [$key]);
      if (isset($element[$key]['#ajax']) && !isset($element[$key]['#limit_validation_errors'])) {
        $element[$key]['#limit_validation_errors'] = [$child_parents];
      }
      static::limitAjax($element[$key], $child_parents, $child_tree);
    }
  }

  /**
   * Gives every element the AJAX callback may replace its wrapper.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param string $wrapper_id
   *   The container's id.
   */
  protected static function wrapRefreshed(array &$element, string $wrapper_id): void {
    foreach (Drawing::elementChildren($element) as $key) {
      if (isset($element[$key][self::REFRESH_KEY]) && !isset($element[$key][DataSurfaceFormBuilderInterface::REFRESH_ID_KEY])) {
        $element[$key] = static::wrapped($element[$key], static::refreshId($wrapper_id, (string) $element[$key][self::REFRESH_KEY]));
      }
      static::wrapRefreshed($element[$key], $wrapper_id);
    }
  }

  /**
   * Wraps an element in a div the AJAX callback can replace by id.
   *
   * A prefix and a suffix rather than a theme wrapper: they sit outside
   * everything the element renders, its own form element wrapper and
   * description included, so the replacement carries all of it, and they
   * do not touch the element's own attributes, which are the input's.
   *
   * @param array $element
   *   The element.
   * @param string $id
   *   The wrapper's id.
   *
   * @return array
   *   The element, wrapped, and carrying the id.
   */
  protected static function wrapped(array $element, string $id): array {
    $element[DataSurfaceFormBuilderInterface::REFRESH_ID_KEY] = $id;
    $element['#prefix'] = '<div id="' . Html::escape($id) . '">' . ($element['#prefix'] ?? '');
    $element['#suffix'] = ($element['#suffix'] ?? '') . '</div>';
    return $element;
  }

  /**
   * Derives the wrapper id of an element from its container's.
   *
   * Unique wherever the container's id is, because no two elements of
   * one container share a path, and the same on every rebuild, because
   * the container's is.
   *
   * @param string $wrapper_id
   *   The container's id.
   * @param string $dotted
   *   The element's dotted path below the container.
   *
   * @return string
   *   The id.
   */
  protected static function refreshId(string $wrapper_id, string $dotted): string {
    return $wrapper_id . '--' . implode('--', array_map([Html::class, 'getId'], explode('.', $dotted)));
  }

  /**
   * Derives the id of one of the container's own wrappers.
   *
   * With an underscore, which Html::getId() never leaves in a key's part
   * of a refreshId(), so no data surface key can collide with it.
   *
   * @param string $wrapper_id
   *   The container's id.
   * @param string $name
   *   What the wrapper holds.
   *
   * @return string
   *   The id.
   */
  protected static function reservedId(string $wrapper_id, string $name): string {
    return $wrapper_id . '__' . $name;
  }

  /**
   * Reads which element of this container triggered a refinement request.
   *
   * @param array $container
   *   The data surface container, processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string|null
   *   The trigger's dotted path below the container, or NULL when the
   *   request is not a refinement of this container: a submission, or a
   *   trigger in another container on the same form.
   */
  public static function triggerPath(array $container, FormStateInterface $form_state): ?string {
    $state = $form_state instanceof SubformStateInterface ? $form_state->getCompleteFormState() : $form_state;
    $triggering = $state->getTriggeringElement() ?? [];
    $path = $triggering[DataSurfaceFormBuilderInterface::TRIGGER_KEY]['path'] ?? NULL;
    if (!is_array($path) || $path === []) {
      return NULL;
    }
    $element = NestedArray::getValue($container, $path);
    $parents = $triggering['#array_parents'] ?? NULL;
    if (is_array($parents) && in_array($element['#type'] ?? NULL, self::OPTION_GROUP_TYPES, TRUE)) {
      // One radio or checkbox of the group triggered.
      array_pop($parents);
    }
    return is_array($element) && isset($element['#array_parents']) && $element['#array_parents'] === $parents
      ? implode('.', $path)
      : NULL;
  }

  /**
   * Names a container by its place in the form.
   *
   * What a request holds for a container is keyed by this rather than
   * by its id: a rebuild that posted no id generates a new one, and the
   * place is the same in every build.
   *
   * @param array $container
   *   The container, processed far enough to know its #array_parents.
   *
   * @return string
   *   The key.
   */
  public static function containerKey(array $container): string {
    return implode('][', $container['#array_parents'] ?? []);
  }

  /**
   * Lists what every trigger with options below an element offers.
   *
   * @param array $element
   *   The container, or an element inside it.
   *
   * @return array<string, string>
   *   Each trigger's options signature, keyed by its dotted path.
   */
  protected static function triggerOptions(array $element): array {
    $found = [];
    foreach (Drawing::elementChildren($element) as $key) {
      $child = $element[$key];
      $path = $child[DataSurfaceFormBuilderInterface::TRIGGER_KEY]['path'] ?? NULL;
      $signature = is_array($path) ? static::optionsSignature($child) : NULL;
      if ($signature !== NULL) {
        $found[implode('.', $path)] = $signature;
      }
      $found += static::triggerOptions($child);
    }
    return $found;
  }

  /**
   * Says which options an element offers, the empty one included.
   *
   * The same before and after Form API processes a select, which turns
   * its #empty_option into an option keyed by the empty string.
   *
   * @param array $element
   *   The element.
   *
   * @return string|null
   *   The signature, or NULL for an element with no options.
   */
  public static function optionsSignature(array $element): ?string {
    if (!isset($element['#options']) || !is_array($element['#options'])) {
      return NULL;
    }
    $keys = array_map('strval', array_keys(OptGroup::flattenOptions($element['#options'])));
    $empty = isset($element['#empty_option']) || in_array('', $keys, TRUE);
    return serialize([array_values(array_diff($keys, [''])), $empty]);
  }

  /**
   * Wires a refinement dependency to rebuild the data surface over AJAX.
   *
   * A widget that renders a grouping element — the map widget's details
   * — is skipped on purpose. A details is not an input: it emits no
   * change event, so an #ajax on it never fires, which is worse than
   * nothing because the form looks wired and is not. Attaching to each
   * leaf inside it instead was considered and rejected: a refiner
   * depends on the whole value of a key, so a rebuild fired by one leaf
   * would refine against a half-filled map, and the leaves of a map are
   * exactly where a person types rather than chooses. A map dependency
   * is therefore declared and left unwired; the data surface still refines
   * when the form is submitted, or when a scalar dependency is touched.
   *
   * A trigger whose change fires on blur, a number or a text input, is
   * marked disable-refocus. Its rebuild starts when the person leaves the
   * field, and core puts focus back on the trigger after the response
   * unless something else with a selector holds it: a click on blank page
   * space leaves the body focused, so focus snapped back to the field the
   * person had just left, and a refused value made that a loop. Focus now
   * stays where the person put it, and core's refocus-blur, which it sets
   * on these types, still carries it to the field they tabbed to. A
   * select or a checkbox changes while focused and keeps core's refocus,
   * because it is disabled during the request and would otherwise lose
   * focus to the body.
   *
   * The wrapper the #ajax names is the container's. The callback answers
   * with commands that name their own targets, so the wrapper is only
   * used when it falls back to returning the container; and the id is
   * sent back with every request, so the rebuild takes the same one.
   *
   * @param array $element
   *   The element built for the dependency.
   * @param string $wrapper_id
   *   The DOM id of the container.
   * @param string[] $path
   *   The element's path below the container.
   * @param string[] $replaces
   *   The dotted paths of the elements depending on it, transitively.
   *
   * @return array
   *   The element, wired or left as it was.
   */
  protected function attachRefinementAjax(array $element, string $wrapper_id, array $path, array $replaces): array {
    if (!isset($element['#type']) || in_array($element['#type'], self::GROUPING_TYPES, TRUE) || $element['#type'] === 'item') {
      // A value drawn fixed cannot change, so nothing is rebuilt on it.
      return $element;
    }
    $element['#ajax'] = [
      'callback' => [DataSurfaceFormBuilder::class, 'refreshSurface'],
      'wrapper' => $wrapper_id,
      // Core posts what is under 'submit' with the request, beside the
      // trigger's name, which it puts there itself.
      'submit' => [DataSurfaceFormBuilderInterface::WRAPPER_INPUT => $wrapper_id],
    ];
    if (in_array($element['#type'], self::BLUR_CHANGE_TYPES, TRUE)) {
      // Focus stays where the person put it; core's refocus-blur still
      // carries it to the field they tabbed to.
      $element['#ajax']['disable-refocus'] = TRUE;
    }
    $element[DataSurfaceFormBuilderInterface::TRIGGER_KEY] = ['path' => $path, 'replaces' => $replaces];
    // A wrapper of its own as well: a trigger is replaced when its own
    // value was refused, so the error is printed under it.
    $element[self::REFRESH_KEY] ??= implode('.', $path);
    if (in_array($element['#type'], self::OPTION_GROUP_TYPES, TRUE)) {
      // Each radio or checkbox is what triggers, and Form API copies the
      // #ajax of the group onto it and nothing else of ours.
      $declared = $element['#process'] ?? $this->elementInfo->getInfo($element['#type'])['#process'] ?? [];
      $element['#process'] = array_merge($declared, [[DataSurfaceFormBuilder::class, 'processOptionTrigger']]);
    }
    return $element;
  }

  /**
   * Gives each radio or checkbox of a trigger what the group carries.
   *
   * Which of the group's own triggered is what Form API records, and it
   * runs a trigger's #limit_validation_errors and our path from it: the
   * path the rebuild finds the container by and replaces what depends on
   * it from.
   *
   * @param array $element
   *   The radios or checkboxes, processed into their options.
   *
   * @return array
   *   The same, each option carrying the group's trigger.
   */
  public static function processOptionTrigger(array $element): array {
    foreach (Drawing::elementChildren($element) as $key) {
      foreach ([DataSurfaceFormBuilderInterface::TRIGGER_KEY, '#limit_validation_errors'] as $property) {
        if (isset($element[$property]) && is_array($element[$key])) {
          $element[$key][$property] = $element[$property];
        }
      }
    }
    return $element;
  }

  /**
   * Marks what a rebuild may replace and wires what triggers one.
   *
   * One frame at a time, the data surface's own and then each child's, under
   * the names each frame gives its keys: a child's refiners run in the
   * child's frame, so its dependencies and its targets are the child's
   * keys, and what one of them moves is inside that child and nowhere
   * else.
   *
   * @param array $element
   *   The container, or the element an attached child or a variant is
   *   rendered as.
   * @param \Drupal\data_surface\DataSurfaceInterface $surface
   *   The data surface or built child the element renders.
   * @param array $values
   *   The values the frame is rendered with.
   * @param string[] $path
   *   The frame's path below the container.
   * @param string $wrapper_id
   *   The container's id.
   *
   * @return array
   *   The element, marked and wired.
   */
  public function wireRefinement(array $element, DataSurfaceInterface $surface, array $values, array $path, string $wrapper_id): array {
    $definitions = $surface->getDefinitions();
    // One property at a time inside the mount: a key an alter refines
    // there is a target of its own, and a key it watches there a trigger.
    $refinements = $definitions->refinementPaths();
    foreach (array_keys($refinements) as $target) {
      $parents = explode('.', (string) $target);
      if (count($parents) > 1 && is_array(NestedArray::getValue($element, $parents))) {
        NestedArray::setValue($element, [...$parents, self::REFRESH_KEY], implode('.', [...$path, ...$parents]));
      }
    }
    foreach ($definitions->entries() as $name => $entry) {
      $name = (string) $name;
      if (!isset($element[$name]) || !is_array($element[$name])) {
        continue;
      }
      $at = [...$path, $name];
      if (isset($refinements[$name])) {
        // A target (a key with variants is one too, of its deciding key)
        // has a wrapper of its own, which is what the rebuild replaces.
        $element[$name][self::REFRESH_KEY] = implode('.', $at);
      }
      if ($entry->list !== NULL) {
        // Each item is a frame of its own: what refines inside an item is
        // wired inside it, and replaces what it moves there and nothing
        // else.
        $child = $entry->list->child();
        $held = Collection::items($values[$name] ?? NULL);
        foreach (Drawing::elementChildren($element[$name]) as $delta) {
          if (is_int($delta) && is_array($element[$name][$delta])) {
            $item = $held[$delta] ?? [];
            $element[$name][$delta] = $this->wireRefinement(
              $element[$name][$delta],
              $child,
              array_replace($child->getDefaultValues(), is_array($item) ? $item : []),
              [...$at, (string) $delta],
              $wrapper_id,
            );
          }
        }
        continue;
      }
      if (!$entry->isNested() || !empty($element[$name][DataSurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
        continue;
      }
      $child = $entry->attachment?->child;
      $held = $values[$name] ?? NULL;
      if ($entry->variants !== NULL) {
        // The variant the key was rendered as, from what it was rendered
        // with: its own defaults when what is held was another's.
        $chosen = $entry->variants->chosen($values[$entry->variants->by] ?? $surface->getDefault($entry->variants->by));
        $child = $chosen === NULL ? NULL : $entry->variants->variant($chosen)->child;
        $held = $chosen !== NULL ? $entry->variants->held($chosen, $held) : NULL;
      }
      if ($child !== NULL) {
        $element[$name] = $this->wireRefinement(
          $element[$name],
          $child,
          array_replace($child->getDefaultValues(), is_array($held) ? $held : []),
          $at,
          $wrapper_id,
        );
      }
    }
    foreach ($definitions->refinementDependencies() as $dependency) {
      $parents = explode('.', $dependency);
      $trigger = NestedArray::getValue($element, $parents);
      if (!is_array($trigger)) {
        continue;
      }
      NestedArray::setValue($element, $parents, $this->attachRefinementAjax(
        $trigger,
        $wrapper_id,
        [...$path, ...$parents],
        array_map(
          static fn (string $target): string => implode('.', [...$path, $target]),
          static::dependentsOf($dependency, $refinements),
        ),
      ));
    }
    return $element;
  }

  /**
   * Lists the keys depending on one key, directly or through others.
   *
   * The closure the discard cascade walks: a target of the key, then a
   * target of that target, until nothing new is reached. A change to the
   * key may move every one of them and nothing else.
   *
   * @param string $key
   *   The key that changed.
   * @param array<string, string[]> $refinements
   *   The frame's refinement map, one mounted key at a time: target =>
   *   the keys it refines against.
   *
   * @return string[]
   *   The dependents, in declaration order; never the key itself.
   */
  protected static function dependentsOf(string $key, array $refinements): array {
    $reached = [];
    $queue = [$key];
    while ($queue !== []) {
      $moved = array_shift($queue);
      foreach ($refinements as $target => $dependencies) {
        $target = (string) $target;
        if ($target !== $key && !isset($reached[$target]) && in_array($moved, $dependencies, TRUE)) {
          $reached[$target] = TRUE;
          $queue[] = $target;
        }
      }
    }
    return array_values(array_filter(
      array_map('strval', array_keys($refinements)),
      static fn (string $target): bool => isset($reached[$target]),
    ));
  }

  /**
   * Builds the #process list for the container, keeping the type's own.
   *
   * An explicit #process replaces the element type's default list rather
   * than adding to it, so the type's callbacks are read and kept — a
   * container that lost processGroup would break every #group inside it.
   *
   * @param string|null $type
   *   The effective element type of the container, or NULL when it has
   *   none, in which case the type declares no callbacks to keep.
   *
   * @return array
   *   The #process list.
   */
  public function surfaceProcess(?string $type): array {
    $declared = $type === NULL ? [] : ($this->elementInfo->getInfo($type)['#process'] ?? []);
    return array_merge($declared, [[DataSurfaceFormBuilder::class, 'processSurfaceContainer']]);
  }

  /**
   * Builds the #pre_render list for the container, keeping the type's own.
   *
   * The same rule as surfaceProcess(), for the same reason: an explicit
   * #pre_render replaces the element type's list, and a container that
   * lost preRenderGroup would draw no #group inside it.
   *
   * @param string|null $type
   *   The effective element type of the container, or NULL when it has
   *   none.
   *
   * @return array
   *   The #pre_render list.
   */
  public function surfacePreRender(?string $type): array {
    $declared = $type === NULL ? [] : ($this->elementInfo->getInfo($type)['#pre_render'] ?? []);
    return array_merge($declared, [[DataSurfaceFormBuilder::class, 'preRenderSurfaceContainer']]);
  }

  /**
   * Points a built container's AJAX at a different wrapper id.
   *
   * @param array $container
   *   The container built by buildSurfaceForm().
   * @param string $wrapper_id
   *   The id to point at.
   *
   * @return array
   *   The container, re-pointed.
   */
  protected static function retargetWrapper(array $container, string $wrapper_id): array {
    $container[self::WRAPPER_KEY] = $wrapper_id;
    $container['#attributes']['id'] = $wrapper_id;
    return static::retargetAjax($container, $wrapper_id);
  }

  /**
   * Points every refinement #ajax below an element at a container id.
   *
   * At any depth, because an attached child's own dependency is wired
   * too, and the id the request sends back with it as well as the
   * wrapper, so the rebuild takes the id the page holds.
   *
   * @param array $element
   *   The element whose children are walked.
   * @param string $wrapper_id
   *   The id to point at.
   *
   * @return array
   *   The element, re-pointed.
   */
  protected static function retargetAjax(array $element, string $wrapper_id): array {
    foreach (Drawing::elementChildren($element) as $key) {
      if (isset($element[$key]['#ajax']['wrapper'])) {
        $element[$key]['#ajax']['wrapper'] = $wrapper_id;
      }
      if (isset($element[$key]['#ajax']['submit'][DataSurfaceFormBuilderInterface::WRAPPER_INPUT])) {
        $element[$key]['#ajax']['submit'][DataSurfaceFormBuilderInterface::WRAPPER_INPUT] = $wrapper_id;
      }
      $element[$key] = static::retargetAjax($element[$key], $wrapper_id);
    }
    return $element;
  }

  /**
   * Lists the dotted paths of the keys drawn as nothing, not applying.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param string $prefix
   *   The path so far.
   *
   * @return string[]
   *   The paths, relative to the container.
   */
  public static function ruledOutPaths(array $element, string $prefix = ''): array {
    $paths = [];
    foreach (Drawing::elementChildren($element) as $key) {
      if (!is_array($element[$key])) {
        continue;
      }
      $path = $prefix . $key;
      if (!empty($element[$key][DataSurfaceFormBuilderInterface::RULED_OUT_KEY])) {
        $paths[] = $path;
        continue;
      }
      $paths = array_merge($paths, static::ruledOutPaths($element[$key], $path . '.'));
    }
    return $paths;
  }

  /**
   * Lists the dotted paths of the elements standing for a stale value.
   *
   * @param array $element
   *   The container, or an element inside it.
   * @param string $prefix
   *   The path so far.
   *
   * @return string[]
   *   The paths, relative to the container.
   */
  public static function stalePaths(array $element, string $prefix = ''): array {
    $paths = [];
    foreach (Drawing::elementChildren($element) as $key) {
      $path = $prefix . $key;
      if (array_key_exists(DataSurfaceWidgetBase::STALE_KEY, $element[$key])) {
        $paths[] = $path;
        continue;
      }
      $paths = array_merge($paths, static::stalePaths($element[$key], $path . '.'));
    }
    return $paths;
  }

  /**
   * Walks a form for the marker, without a depth limit.
   *
   * @param array $form
   *   The form or form fragment to search.
   *
   * @return array|null
   *   The container, or NULL when nothing in the tree carries the marker.
   */
  public static function searchSurfaceContainer(array $form): ?array {
    if (isset($form[self::WRAPPER_KEY])) {
      return $form;
    }
    foreach (Drawing::elementChildren($form) as $key) {
      if (($found = static::searchSurfaceContainer($form[$key])) !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

}
