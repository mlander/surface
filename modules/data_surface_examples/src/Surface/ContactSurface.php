<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Who to contact about the event: a part example 3 always has.
 *
 * Attached by example 3 with attach(). It is its own surface, so it is
 * validated in its own frame, and an alter could name it alone. No
 * target: example 3 stores it under its contact key.
 */
#[Surface('registration.contact')]
final class ContactSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('email', 'email', new TranslatableMarkup('Email'))
      ->setRequired(TRUE);
    $inputs->add('phone', 'string', new TranslatableMarkup('Phone'));
  }

}
