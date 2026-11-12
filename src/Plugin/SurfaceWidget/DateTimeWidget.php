<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\SurfaceWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\DefinitionWidgetInterface;
use Drupal\surface\Contract\WidgetChoice;
use Drupal\surface\Attribute\SurfaceWidget;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetContext;
use PortableSchema\Builder\Schema;

/**
 * Core's date and time element for an ISO 8601 date and time.
 *
 * A `datetime_iso8601` key holds a string, which every other door sends
 * and answers. The element is core's `datetime`, a date input and a time
 * input in the site's time zone, which holds a DrupalDateTime; it is
 * drawn from the stored string and read back as one, ISO 8601 with its
 * offset, which accept() lays out as the key's constraints say.
 */
#[SurfaceWidget(
  id: 'datetime',
  label: new TranslatableMarkup('Date and time'),
  weight: 10,
)]
final class DateTimeWidget extends SurfaceWidgetBase implements DefinitionWidgetInterface {

  /**
   * {@inheritdoc}
   */
  public function drawsDefinition(DataDefinitionInterface $definition, WidgetChoice $choice): bool {
    return $definition->getDataType() === 'datetime_iso8601';
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(Schema $node, WidgetContext $context): bool {
    return static::types($node) === ['string'] && $node->get('format') === 'date-time';
  }

  /**
   * {@inheritdoc}
   */
  public function buildElement(Schema $node, mixed $value, WidgetContext $context): array {
    $date = NULL;
    if (is_string($value) && $value !== '') {
      try {
        $date = new DrupalDateTime($value);
        $date = $date->hasErrors() ? NULL : $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
      }
      catch (\Exception) {
        $date = NULL;
      }
    }
    return $this->baseElement($node, $context) + [
      '#type' => 'datetime',
      '#default_value' => $date,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The element's value is a DrupalDateTime once core has read it, an
   * array of what was typed before; either is read back as the ISO 8601
   * string it spells. Nothing typed sent nothing, and what spells no date
   * is passed on as typed, for the key's constraints to refuse.
   */
  public function extractValue(array $element, FormStateInterface $form_state, Schema $node): mixed {
    $raw = $this->rawValue($element, $form_state);
    if (is_array($raw)) {
      $raw = ($raw['object'] ?? NULL) instanceof \DateTimeInterface || ($raw['object'] ?? NULL) instanceof DrupalDateTime
        ? $raw['object']
        : trim(implode(' ', array_filter([$raw['date'] ?? NULL, $raw['time'] ?? NULL], 'is_string')));
    }
    if ($raw instanceof DrupalDateTime || $raw instanceof \DateTimeInterface) {
      return $raw->format('c');
    }
    if (!is_string($raw) || trim($raw) === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($raw))->format('c');
    }
    catch (\Exception) {
      return $raw;
    }
  }

}
