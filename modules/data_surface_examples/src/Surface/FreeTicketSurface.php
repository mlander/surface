<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The ticket, when registration is free: a note, and nothing to pay.
 *
 * Fills example 3's ticket slot for `free`. No target: example 3 stores it
 * under its ticket key.
 */
#[Surface('registration.step3.ticket.free')]
#[SurfaceVariant(of: RegistrationStep3Surface::class, key: 'ticket', value: 'free')]
final class FreeTicketSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'))
      ->setDescription(t('Shown beside the register button, for example "Donations welcome".'));
  }

}
