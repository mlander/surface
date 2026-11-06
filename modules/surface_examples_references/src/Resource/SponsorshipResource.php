<?php

declare(strict_types=1);

namespace Drupal\surface_examples_references\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_examples_references\Storage\SponsorshipStorage;
use Drupal\surface_examples_references\Structure\CreditStructure;
use Drupal\surface_examples_references\Structure\SettingStructure;
use Drupal\surface_examples_references\Structure\ThingStructure;

/**
 * Example 16: a key that refers to an entity, three ways.
 *
 * A sponsor of the event has a stand at a venue, a contact among the
 * site's users and an announcement among its articles; each key is the
 * id's type with the SurfaceValidReference constraint naming the entity
 * type it refers to, and every door sends and answers the id; the config
 * object stores a content entity by its uuid. The last three name an
 * entity of any type: a structure of its entity type, then its id,
 * filtered by what kind of entity type it is and by bundle.
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
    // A venue keeps its id as a string, a user and a node as an integer.
    $inputs->add('venue', 'string', t('Venue'), default: 'riverside')
      ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'event_venue'])
      ->setDescription(t("Where the sponsor's stand is."))
      ->setRequired(TRUE);

    $inputs->add('contact', 'integer', t('Contact'))
      ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'user'])
      ->setDescription(t('Who answers for the sponsor.'));

    $inputs->add('page', 'integer', t('Announcement'))
      ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'node', 'bundles' => ['article']])
      ->setDescription(t('The article that announces the sponsor.'));

    // Of any type: an entity type, then an id of it.
    $inputs->attach('thing', ThingStructure::class, t('Featured beside'))
      ->setDescription(t('Any content the sponsor is featured beside.'));

    $inputs->attach('setting', SettingStructure::class, t('Linked to'))
      ->setDescription(t('A venue or a role the sponsorship is linked to.'));

    $inputs->attach('credit', CreditStructure::class, t('Credited on'))
      ->setDescription(t('An article or a user the sponsor is credited on.'));
  }

}
