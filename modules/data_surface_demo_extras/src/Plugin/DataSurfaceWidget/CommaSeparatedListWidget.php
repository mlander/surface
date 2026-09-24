<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_extras\Plugin\DataSurfaceWidget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceWidget;
use Drupal\data_surface\Widget\DataSurfaceWidgetBase;

/**
 * One text field for a list of strings, items separated by commas.
 *
 * The stock widgets render a list only as a multiple select, so a list
 * of free strings has no element, and a definition no widget claims is
 * refused when the form is built. This module mounts one on the content
 * type surface, so it brings the element with it, and claims only the
 * lists that ask for it through the setting below: a third party module
 * deciding how every list of strings on the site is drawn would be
 * overreach.
 *
 * Reading the text back is a change of notation and nothing else. A
 * comma and the spaces around it separate items; nothing is lowercased,
 * nothing is dropped as a duplicate, because that would be this widget
 * deciding what a tag means. The surface says what a tag may be and the
 * pipeline holds every caller, this form included, to it.
 */
#[DataSurfaceWidget(
  id: 'data_surface_demo_extras_comma_separated',
  label: new TranslatableMarkup('Comma-separated list'),
  weight: 0,
)]
final class CommaSeparatedListWidget extends DataSurfaceWidgetBase {

  /**
   * The definition setting that asks for this widget.
   */
  public const SETTING = 'data_surface_demo_extras_comma_separated';

  /**
   * {@inheritdoc}
   */
  public function isApplicable(DataDefinitionInterface $definition): bool {
    return $definition instanceof ListDataDefinitionInterface
      && $definition->getSetting(self::SETTING) === TRUE
      && $definition->getItemDefinition()->getDataType() === 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(DataDefinitionInterface $definition, mixed $value): array {
    return $this->baseElement($definition) + [
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
  public function extractValue(DataDefinitionInterface $definition, array $element, FormStateInterface $form_state, array $fallback_parents): mixed {
    $raw = $this->rawValue($element, $form_state, $fallback_parents);
    if (!is_string($raw)) {
      return $raw;
    }
    $raw = trim($raw);
    return $raw === '' ? [] : preg_split('/\s*,\s*/', $raw);
  }

}
