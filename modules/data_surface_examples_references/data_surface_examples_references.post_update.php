<?php

/**
 * @file
 * Post update functions for the references example.
 */

declare(strict_types=1);

use Drupal\data_surface_examples_references\Resource\SponsorshipResource;

/**
 * Stores the sponsorship's references to content by uuid.
 *
 * The contact, the announcement and a bare key's content entity were
 * stored as ids; each that names an entity is stored as its uuid.
 */
function data_surface_examples_references_post_update_references_by_uuid(): string {
  $changed = data_surface_store_references_by_uuid(SponsorshipResource::class, 'configure');
  return $changed
    ? (string) t('The sponsorship stores its references to content by uuid.')
    : (string) t('The sponsorship held no reference to content by id.');
}
