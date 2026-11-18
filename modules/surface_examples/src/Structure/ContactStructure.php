<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Who to contact about the event: a part example 3 always has.
 *
 * Attached by example 3 with attach(). It is its own structure, so it is
 * validated in its own frame, and an alter could name it alone. No
 * storage: example 3 stores it under its contact key.
 */
#[Structure('registration.contact')]
final class ContactStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('email', Schema::string()->format('email')
      ->title(t('Email'))
      ->required());

    $inputs->add('phone', Schema::string()
      ->title(t('Phone')));
  }

}
