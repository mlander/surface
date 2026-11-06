<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Keys\Letters;

/**
 * Children of every kind, with calls: ChildrenPropertiesStructure's twin.
 *
 * A child attached twice, wired, a collection with bounds, variants a
 * sibling chooses, and keys from the site, a value and a child each.
 */
#[Structure('property_test.children')]
final class ChildrenStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('public', 'boolean', t('Public'), default: FALSE);

    $inputs->add('pricing', 'string', t('Pricing'), default: 'free')
      ->setRequired(TRUE);

    $inputs->attach('contact', ContactStructure::class, t('Contact'), reads: [
      'listed' => 'public',
      'anything' => 'pricing',
    ]);

    $inputs->attach('backup', ContactStructure::class, t('Backup contact'), reads: ['anything' => 'pricing'])
      ->setDescription(t('Asked when the contact is away.'));

    $inputs->attachList('rows', EntryStructure::class, t('Rows'), min: 1, max: 3);

    $inputs->attachVariants('ticket', by: 'pricing', label: t('Ticket'));

    $inputs->addEach('on_sale', 'boolean', from: Letters::class, label: t('On sale'));

    $inputs->attachEach('limits', EntryStructure::class, from: Letters::class, label: t('Limits'), reads: ['selling' => 'on_sale']);
  }

}
