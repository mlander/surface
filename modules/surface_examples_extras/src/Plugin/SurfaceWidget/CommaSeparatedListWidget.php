<?php

declare(strict_types=1);

namespace Drupal\surface_examples_extras\Plugin\SurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Contract\SchemaWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\KeyFacts;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * One text field for a list of strings, items separated by commas.
 *
 * The stock widgets render a list only as a multiple select, so a list
 * of free strings has no element, and a definition no widget claims is
 * refused when the form is built. This module mounts one on the content
 * type resource, so it brings the element with it, and claims only the
 * lists that ask for it through the setting below: a third party module
 * deciding how every list of strings on the site is drawn would be
 * overreach.
 *
 * Reading the text back is a change of notation and nothing else. A
 * comma and the spaces around it separate items; nothing is lowercased,
 * nothing is dropped as a duplicate, because that would be this widget
 * deciding what a tag means. The resource says what a tag may be and the
 * pipeline holds every caller, this form included, to it.
 */
#[SurfaceWidget(
  id: 'surface_examples_extras_comma_separated',
  label: new TranslatableMarkup('Comma-separated list'),
  weight: 0,
)]
final class CommaSeparatedListWidget extends SurfaceWidgetBase implements SchemaWidgetInterface {

  /**
   * The definition setting that asks for this widget.
   */
  public const SETTING = 'surface_examples_extras_comma_separated';

  /**
   * {@inheritdoc}
   */
  public function drawsSchema(Schema $schema, WidgetChoice $choice): bool {
    $item = KeyFacts::item($schema);
    return $item !== NULL
      && KeyFacts::setting($schema, self::SETTING) === TRUE
      && KeyFacts::type($item) === 'portable:string';
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $schema, WidgetContext $context): bool {
    $items = $schema->get('items');
    return static::type($schema) === 'array' && $items instanceof Schema && !$items->isBoolean() && static::types($items) === ['string'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $schema, mixed $value, WidgetContext $context): array {
    return $this->baseElement($schema, $context) + [
      '#type' => 'textfield',
      '#default_value' => is_array($value) ? implode(', ', array_map('strval', $value)) : '',
      '#maxlength' => 1024,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * An empty field is the empty list, which is an answer: the field was
   * rendered, and a person who cleared it meant no items.
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $schema): mixed {
    $raw = $this->rawValue($element, $form_state);
    if (!is_string($raw)) {
      return $raw;
    }
    $raw = trim($raw);
    return $raw === '' ? [] : preg_split('/\s*,\s*/', $raw);
  }

}
