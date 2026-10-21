<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Gallery;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Keys\GalleryRooms;
use Drupal\data_surface_resource_test\Storage\GalleryStorage;
use Drupal\data_surface_resource_test\Structure\Gallery\PlaqueStructure;
use Drupal\data_surface_resource_test\Structure\Gallery\RoomStructure;
use Drupal\data_surface_resource_test\Structure\Gallery\WallStructure;

/**
 * A gallery: access answered by alters, in every frame a thing is built in.
 *
 * Nothing here names an access class but the wall's: every other answer
 * comes from an alter in src/Alter, of the gallery itself (when it is
 * curated), of the plaque it mixes in, of the wall it attaches twice, of
 * the oil finish one of its variants is, and of the room at every
 * entry of its keys from the site. Each alter is asked after the owner's
 * own answer, the stricter standing.
 *
 * @see docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands
 */
#[Resource('resource_test.gallery', storage: GalleryStorage::class)]
final class GalleryResource implements ResourceInterface {

  /**
   * Configures the gallery, which always exists.
   */
  #[Operation('configure', label: 'Configure the gallery', permission: 'keep a gallery')]
  public static function configure(): OperationContext {
    return new OperationContext('configure');
  }

  /**
   * Curates the gallery: the same keys, where the gallery's alter answers.
   */
  #[Operation('curate', label: 'Curate the gallery', permission: 'keep a gallery')]
  public static function curate(): OperationContext {
    return new OperationContext('curate');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'))
      ->setRequired(TRUE);
    $inputs->mixIn(PlaqueStructure::class);
    $inputs->attach('north', WallStructure::class)
      ->setLabel(t('North wall'));
    $inputs->attach('south', WallStructure::class)
      ->setLabel(t('South wall'));
    $inputs->add('medium', 'string', t('Medium'), default: 'oil')
      ->setRequired(TRUE);
    $inputs->attachVariants('finish', by: 'medium')
      ->setLabel(t('Finish'));
    $inputs->attachEach('rooms', RoomStructure::class, from: GalleryRooms::class)
      ->setLabel(t('Rooms'));
  }

}
