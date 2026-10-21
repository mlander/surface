<?php

declare(strict_types=1);

namespace Drupal\data_surface\Plugin\DataSurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * Text input for string definitions.
 *
 * The 'multiline' definition setting renders a textarea — core has no
 * multiline string data type (config schema's 'text' has no typed data
 * counterpart; see PLAN.md), and a setting is the core-native spelling
 * that avoids inventing one.
 *
 * A definition marked secret renders as a password element instead, and
 * secretElement() says what that element deliberately does not carry.
 */
#[DataSurfaceWidget(
  id: 'string',
  label: new TranslatableMarkup('String'),
  weight: 10,
)]
final class StringWidget extends DataSurfaceWidgetBase {

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return in_array($definition->getDataType(), ['string', 'email', 'uri'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    if (DefinitionMetadata::isSecret($definition)) {
      return $this->secretElement($definition);
    }
    $element = $this->baseElement($definition) + [
      '#type' => match (TRUE) {
        (bool) $definition->getSetting('multiline') => 'textarea',
        $definition->getDataType() === 'email' => 'email',
        $definition->getDataType() === 'uri' => 'url',
        default => 'textfield',
      },
      '#default_value' => $value,
    ];
    if ($element['#type'] !== 'textarea') {
      $element = $this->lengthHint($element, $definition);
    }
    // No placeholder from the examples: an empty field showing a value
    // reads as filled. A placeholder is declared, in the key's
    // arrangement, and the form builder draws it.
    // Decision: see docs/decisions.md#arrangement-is-declared-once.
    return $element;
  }

  /**
   * Builds the element for a secret, which carries no value at all.
   *
   * Three things this element does not have, each of them on purpose:
   *
   * - **No `#default_value`, and no value anywhere else.** A password
   *   element renders its default into the HTML like any other input, so
   *   a stored secret would be in the page source of every settings
   *   form. The form builder does not hand a secret's value to a widget
   *   either; both halves are deliberate, because one of them alone is a
   *   line somebody deletes as redundant.
   * - **No `#placeholder`.** Not even one the key's arrangement
   *   declares: a placeholder of an API token is either useless or a
   *   leak, and the form builder draws none on a password.
   * - **No `#required`.** Leaving the box empty is how a person says
   *   "the stored secret is unchanged", which is a legal submission and
   *   the usual one; a browser-enforced required attribute would make
   *   every unrelated edit demand the secret be retyped. A required
   *   secret is still required (validate() refuses a key that has never
   *   been set and was left blank), but it is refused by the data surface,
   *   with the data surface's own message, after accept() has had its say.
   *
   * The description says what the empty box means. It says so whether or
   * not a secret is stored, because nothing here knows: the widget is
   * handed no value, which is the point. "Leave blank to keep the
   * current value" reads correctly when there is one and harmlessly when
   * there is not, and the alternative — telling the builder to find out
   * — would mean reading the secret in order to say that it is never
   * read.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The secret definition.
   *
   * @return array
   *   The password element.
   */
  protected function secretElement(DataDefinitionInterface $definition): array {
    $element = $this->baseElement($definition) + ['#type' => 'password'];
    $element['#required'] = FALSE;
    $note = $this->t('Leave blank to keep the current value.');
    $element['#description'] = isset($element['#description'])
      ? $this->t('@description @note', [
        '@description' => $element['#description'],
        '@note' => $note,
      ])
      : $note;
    return $this->lengthHint($element, $definition);
  }

  /**
   * Puts a Length's maximum on the element as a hint for the browser.
   *
   * The `maxlength` attribute stops a browser at the maximum, and
   * `#maxlength` is cleared: core checks it before any form level
   * handler, a form keeps the first error per element, and its wording
   * ("cannot be longer than") would replace the rule's own message. The
   * element type's own default (128 for a text field) goes with it, so
   * no limit the definition does not declare is enforced on the form
   * alone.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition it draws.
   *
   * @return array
   *   The element, with the hint.
   */
  protected function lengthHint(array $element, DataDefinitionInterface $definition): array {
    $element['#maxlength'] = NULL;
    $length = $this->constraint($definition, 'Length');
    if (isset($length['max'])) {
      $element['#attributes']['maxlength'] = (int) $length['max'];
    }
    return $element;
  }

}
