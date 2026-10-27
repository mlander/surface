<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples_references\Resource;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_examples_references\Storage\SponsorshipStorage;

/**
 * Example 16: a key that refers to an entity, three ways.
 *
 * A sponsor of the event has a stand at a venue, a contact among the
 * site's users and an announcement among its articles; each key is
 * declared by the data type string of what it refers to, and every door
 * sends and answers the id; the config object stores a content entity by
 * its uuid. The last three name an entity of any type: its entity type,
 * then its id, filtered by what kind of entity type it is and by bundle.
 */
#[Resource('event.sponsorship', storage: SponsorshipStorage::class)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the sponsorship'),
  description: new TranslatableMarkup("Sets the sponsor's venue, contact and announcement, and what the sponsor is featured beside, linked to and credited on."),
  permission: 'set event sponsorship',
)]
final class SponsorshipResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('venue', 'entity:event_venue', t('Venue'), default: 'riverside')
      ->setDescription(t("Where the sponsor's stand is."))
      ->setRequired(TRUE);
    $inputs->add('contact', 'entity:user', t('Contact'))
      ->setDescription(t('Who answers for the sponsor.'));
    $inputs->add('page', 'entity:node:article', t('Announcement'))
      ->setDescription(t('The article that announces the sponsor.'));
    // Of any type: an entity type, then an id of it.
    $inputs->add('thing', 'entity', t('Featured beside'))
      ->setDescription(t('Any content the sponsor is featured beside.'))
      ->addConstraint('DataSurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
    $inputs->add('setting', 'entity', t('Linked to'))
      ->setDescription(t('A venue or a role the sponsorship is linked to.'))
      ->addConstraint('DataSurfaceReferenceableType', [
        'interface' => ConfigEntityInterface::class,
        'targets' => ['event_venue' => [], 'user_role' => [], 'node' => []],
      ]);
    $inputs->add('credit', 'entity', t('Credited on'))
      ->setDescription(t('An article or a user the sponsor is credited on.'))
      ->addConstraint('DataSurfaceReferenceableType', [
        'targets' => ['node' => ['article'], 'user' => []],
      ]);
  }

}
