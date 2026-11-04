<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * The paid ticket, with calls: a variant of ChildrenStructure's ticket.
 */
#[Structure('property_test.ticket.paid')]
#[Variant(of: ChildrenStructure::class, at: 'ticket', for: 'paid', label: new TranslatableMarkup('Paid'))]
final class PaidTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('price', 'float', t('Price'))
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 0]);
  }

}
