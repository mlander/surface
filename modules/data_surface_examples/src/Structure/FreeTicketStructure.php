<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface_examples\Resource\RegistrationStep3Resource;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\Attribute\Variant;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * The ticket, when registration is free: a note, and nothing to pay.
 *
 * Example 3's ticket variant for `free`. No storage: example 3 stores it
 * under its ticket key.
 */
#[Structure('registration.step3.ticket.free')]
#[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'free')]
final class FreeTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('Shown beside the register button, for example "Donations welcome".'));
  }

}
