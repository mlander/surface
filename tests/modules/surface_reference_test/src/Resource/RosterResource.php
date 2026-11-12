<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_reference_test\Storage\RosterStorage;
use Drupal\surface_reference_test\Structure\AuthorStructure;

/**
 * One structure that refers to an account, attached at two keys and listed.
 *
 * Its def is shared by every site, so a site's lookup link, whose pointer
 * is the site's, is written at the site and never in the def; the list's
 * items are not reached by the lookup and carry none. `GET` serves
 * `configure` at the path, so `review` has no lookup link. It is kept in
 * state.
 *
 * @see docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links
 */
#[Resource('reference_test.roster', storage: RosterStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the roster'),
  permission: 'administer site configuration',
)]
final class RosterResource implements ResourceInterface {

  /**
   * Reviews the roster: an update `GET` does not serve at the path.
   */
  #[Operation('review',
    label: new TranslatableMarkup('Review the roster'),
    permission: 'administer site configuration',
  )]
  public static function review(): OperationContext {
    return new OperationContext();
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->attach('organizer', AuthorStructure::class, 'Organizer');
    $inputs->attach('speaker', AuthorStructure::class, 'Speaker');
    $inputs->attachList('helpers', AuthorStructure::class, 'Helpers', max: 3);
  }

}
