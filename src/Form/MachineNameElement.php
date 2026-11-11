<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\MachineName;

/**
 * Draws a machine name computed from a title as core's machine name element.
 *
 * A string computed unless sent from a key beside it, whose `pattern` is
 * a machine name's (PATTERN): the text field its widget drew becomes
 * core's `machine_name` element, its `#machine_name` source the source
 * key's element, so the browser suggests the name while the title is
 * typed, as core's own forms do, and a person may edit it. What the
 * browser suggests is what MachineName computes for a call that leaves
 * the key out.
 *
 * The element has no `exists` callback and its own validation is left
 * off: a taken name is refused by the key's own check (SurfaceUnique) in
 * the pipeline, at the element, as every door refuses it, in the words
 * the tool and HTTP answer with. Core's element would refuse it first in
 * its own words, and a form keeps the first error per element.
 *
 * On an update the key is the identity the operation knows, locked: it
 * is drawn by its widget, disabled with its value, and never reaches
 * here.
 *
 * Decision: see docs/decisions.md#a-machine-name-is-a-computed-identity.
 *
 * @internal
 */
final class MachineNameElement {

  /**
   * Where the element keeps the source key's name until it is processed.
   */
  public const SOURCE_KEY = '#surface_machine_name_source';

  /**
   * The pattern a machine name's node states.
   */
  public const PATTERN = '^[a-z0-9_]+$';

  /**
   * Turns a key's text field into a machine name element.
   *
   * @param array $element
   *   The element the key's widget drew.
   * @param string $source
   *   The key it is computed from, at the same level.
   * @param string|null $label
   *   The key's title, which core's element names the suggestion by.
   *
   * @return array
   *   The machine name element.
   */
  public static function draw(array $element, string $source, ?string $label): array {
    $element['#type'] = 'machine_name';
    $element['#machine_name'] = [
      'label' => $label,
    ];
    $element[self::SOURCE_KEY] = $source;
    // Core's process callbacks, with the source found first; no element
    // validation, so the data surface's refusal is the one shown.
    $element['#process'] = [
      [self::class, 'process'],
      [MachineName::class, 'processAutocomplete'],
      [MachineName::class, 'processAjaxForm'],
    ];
    $element['#element_validate'] = [];
    return $element;
  }

  /**
   * Points the element at its source's element, then lets core process it.
   *
   * The source is found by the element's own place in the form: the key
   * beside it.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The element, processed as core processes a machine name.
   */
  public static function process(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $parents = $element['#array_parents'];
    array_pop($parents);
    $parents[] = (string) $element[self::SOURCE_KEY];
    $element['#machine_name']['source'] = $parents;
    return MachineName::processMachineName($element, $form_state, $complete_form);
  }

}
