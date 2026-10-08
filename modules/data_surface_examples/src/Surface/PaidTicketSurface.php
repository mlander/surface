<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * The ticket, when registration is paid: a price, and its currency.
 *
 * Fills example 3's ticket slot for `paid`. No target: example 3 stores it
 * under its ticket key.
 */
#[Surface('registration.step3.ticket.paid')]
#[SurfaceVariant(of: RegistrationStep3Surface::class, key: 'ticket', value: 'paid')]
final class PaidTicketSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('price', 'float', t('Price'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0.01]);
    $inputs->add('currency', 'string', t('Currency'), default: 'EUR')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['EUR', 'GBP', 'USD']]);
  }

}
