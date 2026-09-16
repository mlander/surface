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
    // Constraint-to-element mapping the adapter era lacked: the browser
    // enforces what the definition declares.
    $length = $this->constraint($definition, 'Length');
    if (isset($length['max']) && $element['#type'] !== 'textarea') {
      $element['#maxlength'] = (int) $length['max'];
    }
    // The first declared example shows what a valid value looks like
    // before the value is typed, rather than after validation fails.
    $examples = DefinitionMetadata::getExamples($definition);
    if ($examples !== [] && $element['#type'] !== 'textarea') {
      $element['#placeholder'] = (string) reset($examples);
    }
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
   * - **No `#placeholder`.** The string widget shows a definition's
   *   first example to say what a valid value looks like, and an example
   *   of an API token is either useless or a leak.
   * - **No `#required`.** Leaving the box empty is how a person says
   *   "the stored secret is unchanged", which is a legal submission and
   *   the usual one; a browser-enforced required attribute would make
   *   every unrelated edit demand the secret be retyped. A required
   *   secret is still required — validate() refuses a key that has never
   *   been set and was left blank — but it is refused by the surface,
   *   with the surface's own message, after accept() has had its say.
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
    $note = new TranslatableMarkup('Leave blank to keep the current value.');
    $element['#description'] = isset($element['#description'])
      ? new TranslatableMarkup('@description @note', [
        '@description' => $element['#description'],
        '@note' => $note,
      ])
      : $note;
    $length = $this->constraint($definition, 'Length');
    if (isset($length['max'])) {
      $element['#maxlength'] = (int) $length['max'];
    }
    return $element;
  }

}
