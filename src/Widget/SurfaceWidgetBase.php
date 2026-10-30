<?php

declare(strict_types=1);

namespace Drupal\surface\Widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Pipeline\ValueState;

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
abstract class SurfaceWidgetBase extends PluginBase implements SurfaceWidgetInterface {

  /**
   * Render key carrying the stored value a select could not show.
   *
   * Written by the options widget when a stored value is no longer among
   * the options: the select comes up on its empty option, and the value
   * travels here. Read back here, by every widget, because which widget
   * reads an element back is not the same question as which widget
   * built it. Extraction resolves widgets from the data surface as
   * advertised, since it has no values yet to refine with, while the
   * form was built from the data surface refined against what was stored: a
   * key that is a plain string until a refiner narrows it into a choice
   * is built by the options widget and read back by the string one. So
   * the stash belongs to the element, not to a widget, and reading it
   * back is shared ground.
   *
   * What it holds is a value and never anything else. The element is
   * serialized into the form cache and walked by every element alter
   * hook on the site, which is the same rule that keeps closures out of
   * a form array.
   */
  public const STALE_KEY = '#surface_stale';

  /**
   * The element types a value is typed into, which take a placeholder.
   */
  protected const PLACEHOLDER_TYPES = [
    'textfield',
    'textarea',
    'email',
    'url',
    'tel',
    'search',
    'number',
    'machine_name',
  ];

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
   * Draws a key's arrangement on the element a widget built for it.
   *
   * Every element, whichever widget built it: its weight among its
   * frame's keys, a prefix and a suffix beside the input, the help after
   * its description, a placeholder on an input a value is typed into
   * (never on a password), and the group it is drawn in, as a marker the
   * form builder reads (SurfaceFormBuilderInterface::GROUP_KEY).
   * Nothing here changes what the element posts or what is accepted.
   *
   * Decision: see docs/decisions.md#arrangement-is-declared-once.
   *
   * @param array $element
   *   The element the widget built.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   *
   * @return array
   *   The element, arranged.
   */
  public static function arranged(array $element, DataDefinitionInterface $definition): array {
    $arrangement = DefinitionMetadata::arrangementOf($definition);
    if ($arrangement === NULL) {
      return $element;
    }
    if ($arrangement->weight !== NULL) {
      $element['#weight'] = $arrangement->weight;
    }
    if ($arrangement->group !== NULL) {
      $element[SurfaceFormBuilderInterface::GROUP_KEY] = [
        'id' => $arrangement->group->id,
        'label' => $arrangement->group->label,
        'as' => $arrangement->group->as,
        'collapsed' => $arrangement->group->collapsed,
        'weight' => $arrangement->group->weight,
        'description' => $arrangement->group->description,
      ];
    }
    if (!empty($element[SurfaceFormBuilderInterface::PLACEHOLDER_KEY])) {
      // A key that does not apply has no element, only its place.
      return $element;
    }
    $type = $element['#type'] ?? NULL;
    if ($arrangement->placeholder !== NULL && (string) $arrangement->placeholder !== '' && in_array($type, self::PLACEHOLDER_TYPES, TRUE)) {
      $element['#placeholder'] = $arrangement->placeholder;
    }
    if ($arrangement->prefix !== NULL && (string) $arrangement->prefix !== '') {
      $element['#field_prefix'] = $arrangement->prefix;
    }
    if ($arrangement->suffix !== NULL && (string) $arrangement->suffix !== '') {
      $element['#field_suffix'] = $arrangement->suffix;
    }
    if ($arrangement->help !== NULL && (string) $arrangement->help !== '') {
      $element['#description'] = isset($element['#description'])
        ? t('@description @help', [
          '@description' => $element['#description'],
          '@help' => $arrangement->help,
        ])
        : $arrangement->help;
    }
    return $element;
  }

  /**
   * Draws a key the form does not draw: an item saying so, posting nothing.
   *
   * A placeholder, so extraction reads nothing from it and the key keeps
   * what it holds; the other doors set it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array
   *   The item.
   */
  public static function notDrawn(DataDefinitionInterface $definition): array {
    return [
      '#type' => 'item',
      '#title' => $definition->getLabel(),
      '#markup' => (string) t('This is not drawn on this form; what it holds is kept. Set it through the tool, over HTTP or on the React page.'),
      SurfaceFormBuilderInterface::PLACEHOLDER_KEY => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    return static::unstash($element, static::checked($element, $this->rawValue($element, $form_state, $fallback_parents)));
  }

  /**
   * Names the widget words a definition's `widget` setting may ask for.
   *
   * A definition asks for a stock widget by name, with core's own setting
   * spelling (`setSetting('widget', 'radios')`), and the manager refuses
   * a definition whose asked widget did not draw it, rather than draw it
   * as something it did not ask for. A widget answers to its plugin ID,
   * which for the stock ones is the word the served contract's
   * `x-surface.widget` says (`select`, `fieldset`, `checkbox`).
   *
   * Decision: see docs/decisions.md#a-widget-is-chosen-from-the-definition.
   *
   * @return string[]
   *   The words.
   */
  public function answersTo(): array {
    return [$this->getPluginId()];
  }

  /**
   * Reads checkboxes back as the list of the values checked.
   *
   * Checkboxes post every option, each with its value when checked and
   * the integer 0 when not (core uses the string '0' for a checked option
   * keyed 0, so the two never meet). Which widget reads an element back
   * is not the same question as which built it, as the stash says below:
   * a list a refiner narrowed is built from the refined definition and
   * read back through the advertised one, which may have drawn it as a
   * multiple select. So every widget reads a checkboxes element the same
   * way, and a list a payload sends, or a multiple select posts, passes
   * as it is.
   *
   * @param array $element
   *   The element the value was submitted for.
   * @param mixed $value
   *   The raw submitted value.
   *
   * @return mixed
   *   The values checked, in option order, for a checkboxes element; the
   *   raw value otherwise.
   */
  protected static function checked(array $element, mixed $value): mixed {
    if (($element['#type'] ?? NULL) !== 'checkboxes' || !is_array($value)) {
      return $value;
    }
    return array_values(array_filter($value, static fn (mixed $held): bool => $held !== 0 && $held !== NULL && $held !== FALSE));
  }

  /**
   * Maps an untouched stale select back to the value it stands for.
   *
   * An element that could not show its stored value came up empty and
   * carries the value in its stash, so empty coming back from it means
   * "left alone", and leaving a control alone means keep: only an
   * explicit new choice replaces a stored value. There is no way to tell
   * "left alone" from "chose the empty option" on such a select, since
   * the empty option is what was selected, and that is the point — it
   * never offered emptiness as a separate answer.
   *
   * The stash is the authority. An element carrying none had nothing
   * stale behind it, so empty from it is an ordinary empty answer.
   *
   * @param array $element
   *   The element the value was submitted for.
   * @param mixed $value
   *   The raw submitted value.
   *
   * @return mixed
   *   The stashed value when the element came back empty, the raw value
   *   otherwise.
   */
  protected static function unstash(array $element, mixed $value): mixed {
    return !ValueState::isConfigured($value) && array_key_exists(self::STALE_KEY, $element)
      ? $element[self::STALE_KEY]
      : $value;
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
   * the data surface as "not configured", which for a required key is a
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
