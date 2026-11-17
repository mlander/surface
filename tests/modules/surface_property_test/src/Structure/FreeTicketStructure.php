<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The free ticket, with calls: a variant of ChildrenStructure's ticket.
 */
#[Structure('property_test.ticket.free')]
#[Variant(of: ChildrenStructure::class, at: 'ticket', for: 'free', label: new TranslatableMarkup('Free'))]
final class FreeTicketStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('note', Schema::string()
      ->title(t('Note')));
  }

}
