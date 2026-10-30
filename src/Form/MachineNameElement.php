<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\MachineName;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Plugin\Validation\Constraint\UniqueConstraintValidator;

/**
 * Draws a machine name computed from a title as core's machine name element.
 *
 * A key of the config schema type `machine_name`, computed unless sent
 * from a key beside it: the text field its widget drew becomes core's
 * `machine_name` element, its `#machine_name` source the source key's
 * element, so the browser suggests the name while the title is typed, as
 * core's own forms do, and a person may edit it. What the browser
 * suggests is what MachineName computes for a call that leaves the key
 * out.
 *
 * Its `exists` callback asks the storage the question SurfaceUnique
 * asks (UniqueConstraintValidator::taken()). The element's own validation
 * is left off: it would refuse a taken name in core's words before the
 * data surface refuses it in its own, and a form keeps the first error
 * per element, so the constraint's sentence, the one the tool and HTTP
 * answer, is the one the form shows.
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
   * Where the element keeps the uniqueness constraint's options.
   */
  public const UNIQUE_KEY = '#surface_unique';

  /**
   * Turns a key's text field into a machine name element.
   *
   * @param array $element
   *   The element the key's widget drew.
   * @param string $source
   *   The key it is computed from, at the same level.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The machine name element.
   */
  public static function draw(array $element, string $source, DataDefinitionInterface $definition): array {
    $element['#type'] = 'machine_name';
    $element['#machine_name'] = [
      'exists' => [self::class, 'exists'],
      'label' => $definition->getLabel(),
    ];
    $element[self::SOURCE_KEY] = $source;
    $unique = $definition->getConstraints()['SurfaceUnique'] ?? NULL;
    if (is_array($unique)) {
      $element[self::UNIQUE_KEY] = [
        'storage' => (string) ($unique['storage'] ?? ''),
        'key' => (string) ($unique['key'] ?? ''),
      ];
    }
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

  /**
   * Answers whether a thing already holds the machine name.
   *
   * The element's `exists` callback: the storage's own answer, as the
   * SurfaceUnique constraint asks it.
   *
   * @param mixed $value
   *   The machine name.
   * @param array $element
   *   The element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when a thing holds it.
   */
  public static function exists(mixed $value, array $element, FormStateInterface $form_state): bool {
    $unique = $element[self::UNIQUE_KEY] ?? NULL;
    if (!is_array($unique) || !is_scalar($value) || (string) $value === '') {
      return FALSE;
    }
    return \Drupal::classResolver(UniqueConstraintValidator::class)->taken((string) $unique['storage'], (string) $unique['key'], (string) $value);
  }

}
