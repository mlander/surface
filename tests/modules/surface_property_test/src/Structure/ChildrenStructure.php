<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Keys\Letters;
use PortableSchema\Builder\Schema;

/**
 * Children of every kind, with calls: ChildrenPropertiesStructure's twin.
 *
 * A child attached twice, wired, a collection with bounds, variants a
 * sibling chooses, and keys from the site, a value, a child and a
 * reference each.
 */
#[Structure('property_test.children')]
final class ChildrenStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('public', Schema::bool()
      ->title(t('Public'))
      ->default(FALSE));

    $inputs->addSchema('pricing', Schema::string()
      ->title(t('Pricing'))
      ->default('free')
      ->required());

    $inputs->attach('contact', ContactStructure::class, t('Contact'), reads: [
      'listed' => 'public',
      'anything' => 'pricing',
    ]);

    $inputs->attach(
      'backup',
      ContactStructure::class,
      t('Backup contact'),
      reads: ['anything' => 'pricing'],
      description: t('Asked when the contact is away.'),
    );

    $inputs->attachList('rows', EntryStructure::class, t('Rows'), min: 1, max: 3);

    $inputs->attachVariants('ticket', by: 'pricing', label: t('Ticket'));

    $inputs->addEach('on_sale', Schema::bool(), from: Letters::class, label: t('On sale'));

    $inputs->attachEach('limits', EntryStructure::class, from: Letters::class, label: t('Limits'), reads: ['selling' => 'on_sale']);

    $inputs->addEach(
      'roles',
      Schema::string()->check('drupal:SurfaceValidReference', ['entityTypeId' => 'user_role']),
      from: Letters::class,
      label: t('Roles'),
    );
  }

}
