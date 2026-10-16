<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Refinement;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\filter\FilterFormatRepositoryInterface;

/**
 * Narrows a text field's formats to those the account may use.
 *
 * The item surface lists every format the field allows, whoever asks:
 * that is what the full schema can say. Which of them this account may
 * use is a fact about the account, so it is a runtime refiner, never run
 * ahead of time; the contract marks the key `runtime`, and the pipeline
 * holds a submission to it. Core's text format element answers the same
 * question the same way.
 *
 * Hole: see docs/entities.md#holes.
 */
final class TextFormatRefiner {

  /**
   * Keeps the formats the current account may use.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $format
   *   The format key, with the field's formats as a LabeledChoice.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The key, its choices narrowed.
   */
  #[RefinesInput('format', runtime: TRUE)]
  public static function formatsTheAccountMayUse(DataDefinitionInterface $format): DataDefinitionInterface {
    $constraints = $format->getConstraints();
    if (!isset($constraints['LabeledChoice']['choices'])) {
      return $format;
    }
    $usable = array_map('strval', array_keys(static::usable()));
    $choice = $constraints['LabeledChoice'];
    $choice['choices'] = array_values(array_filter($choice['choices'], static fn ($id): bool => in_array((string) $id, $usable, TRUE)));
    $choice['labels'] = array_intersect_key($choice['labels'] ?? [], array_flip($choice['choices']));
    $format->addConstraint('LabeledChoice', $choice);
    return $format;
  }

  /**
   * Lists the formats the current account may use.
   *
   * @return array<string, \Drupal\filter\FilterFormatInterface>
   *   The formats, by id.
   */
  protected static function usable(): array {
    $account = \Drupal::currentUser();
    // The repository is core's from 11.4, where filter_formats() is
    // deprecated; before, the function is all there is.
    if (interface_exists(FilterFormatRepositoryInterface::class) && \Drupal::hasService(FilterFormatRepositoryInterface::class)) {
      return \Drupal::service(FilterFormatRepositoryInterface::class)->getFormatsForAccount($account);
    }
    return function_exists('filter_formats') ? filter_formats($account) : [];
  }

}
