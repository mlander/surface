<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Text input for a string.
 *
 * The document's word says which input: `text` a text field, `textarea`
 * a text area (a definition's `multiline` setting), `email` an email
 * input; a string of `format: uri` is a URL input. Its size and rows are
 * the hints `x-portable-ui.options.size` and `rows`, presentation alone.
 *
 * A node marked `writeOnly` is a secret, and renders as a password
 * element instead: secretElement() says what that element deliberately
 * does not carry.
 */
#[SurfaceWidget(
  id: 'string',
  label: new TranslatableMarkup('String'),
  weight: 10,
)]
final class StringWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * The presentation settings a definition may give this widget.
   *
   * Written into the document as `x-portable-ui.options` of the same
   * names, which buildElement() reads.
   */
  public const SETTINGS = ['size', 'rows'];

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return in_array($definition->getDataType(), ['string', 'email', 'uri', 'duration_iso8601'], TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * A string, but a date and time, which the date and time widget draws.
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::types($node) === ['string'] && $node->get('format') !== 'date-time';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    if ($node->get('writeOnly') === TRUE) {
      return $this->secretElement($node, $context);
    }
    $word = static::ui($node)['widget'] ?? NULL;
    $element = $this->baseElement($node, $context) + [
      '#type' => match (TRUE) {
        $word === 'textarea' => 'textarea',
        $word === 'email', $node->get('format') === 'email' => 'email',
        $node->get('format') === 'uri' => 'url',
        default => 'textfield',
      },
      '#default_value' => $value,
    ];
    if ($element['#type'] !== 'textarea') {
      $element = $this->lengthHint($element, $node);
    }
    // How big the input is drawn: presentation alone, which a form
    // display's component hands over (FieldItemDerivation).
    $options = static::options($node);
    $size = $options['size'] ?? NULL;
    if ($element['#type'] !== 'textarea' && is_int($size) && $size > 0) {
      $element['#size'] = $size;
    }
    $rows = $options['rows'] ?? NULL;
    if ($element['#type'] === 'textarea' && is_int($rows) && $rows > 0) {
      $element['#rows'] = $rows;
    }
    // No placeholder from the examples: an empty field showing a value
    // reads as filled. A placeholder is a hint the drawer draws.
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
   *   form. The document carries no value for a secret either; both
   *   halves are deliberate.
   * - **No `#placeholder`.** Not even one the key's arrangement
   *   declares: a placeholder of an API token is either useless or a
   *   leak, and the drawer draws none on a password.
   * - **No `#required`.** Leaving the box empty is how a person says
   *   "the stored secret is unchanged", which is a legal submission and
   *   the usual one. A required secret is still required: the pipeline
   *   refuses a key that has never been set and was left blank.
   *
   * The description says what the empty box means, whether or not a
   * secret is stored, because nothing here knows.
   *
   * @param \PortableSchema\Builder\Schema $node
   *   The secret's node.
   * @param \Drupal\surface\Widget\WidgetContext $context
   *   Where it is drawn.
   *
   * @return array
   *   The password element.
   */
  protected function secretElement(Schema $node, WidgetContext $context): array {
    $element = $this->baseElement($node, $context) + ['#type' => 'password'];
    $element['#required'] = FALSE;
    $note = (string) $this->t('Leave blank to keep the current value.');
    $element['#description'] = isset($element['#description']) ? $element['#description'] . ' ' . $note : $note;
    return $this->lengthHint($element, $node);
  }

  /**
   * Puts the node's `maxLength` on the element as a hint for the browser.
   *
   * The `maxlength` attribute stops a browser at the maximum, and
   * `#maxlength` is cleared: core checks it before any form level
   * handler, a form keeps the first error per element, and its wording
   * ("cannot be longer than") would replace the rule's own message. The
   * element type's own default (128 for a text field) goes with it.
   *
   * Decision: see docs/decisions.md#an-element-hints-and-the-rule-speaks.
   *
   * @param array $element
   *   The element.
   * @param \PortableSchema\Builder\Schema $node
   *   The node it draws.
   *
   * @return array
   *   The element, with the hint.
   */
  protected function lengthHint(array $element, Schema $node): array {
    $element['#maxlength'] = NULL;
    $max = $node->get('maxLength');
    if (is_int($max)) {
      $element['#attributes']['maxlength'] = $max;
    }
    return $element;
  }

}
