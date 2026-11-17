<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Refinement;

use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\filter\FilterFormatRepositoryInterface;
use Drupal\surface\Refinement\ChoiceSet;
use PortableSchema\Builder\Schema;

/**
 * Narrows a text field's formats to those the account may use.
 *
 * The item structure lists every format the field allows, whoever asks:
 * that is what the full schema can say. Which of them this account may
 * use is a fact about the account, so it is a runtime refiner, never run
 * ahead of time; the contract marks the key `live`, and the pipeline
 * holds a submission to it. Core's text format element answers the same
 * question the same way.
 *
 * Hole: see docs/entities.md#holes.
 */
final class TextFormatRefiner {

  /**
   * Keeps the formats the account may use.
   *
   * @param \PortableSchema\Builder\Schema $format
   *   The format key's schema, with the field's formats as its list.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the operation runs as, handed in.
   *
   * @return \PortableSchema\Builder\Schema
   *   The formats it may use, of the key's.
   */
  #[RefinesInput('format', runtime: TRUE)]
  public static function formatsTheAccountMayUse(Schema $format, AccountInterface $account): Schema {
    if (ChoiceSet::of($format) === NULL) {
      return Schema::any();
    }
    return Schema::enum(array_map('strval', array_keys(static::usable($account))));
  }

  /**
   * Lists the formats an account may use.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array<string, \Drupal\filter\FilterFormatInterface>
   *   The formats, by id.
   */
  protected static function usable(AccountInterface $account): array {
    // The repository is core's from 11.4, where filter_formats() is
    // deprecated; before, the function is all there is.
    if (interface_exists(FilterFormatRepositoryInterface::class) && \Drupal::hasService(FilterFormatRepositoryInterface::class)) {
      return \Drupal::service(FilterFormatRepositoryInterface::class)->getFormatsForAccount($account);
    }
    return function_exists('filter_formats') ? filter_formats($account) : [];
  }

}
