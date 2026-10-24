<?php

/**
 * @file
 * Post update functions for Data Surface.
 */

declare(strict_types=1);

/**
 * Adds the limit up to which a reference key lists its targets.
 *
 * Superseded by data_surface_post_update_reference_list_limit(), which
 * adds it under its present name; kept so a site that ran it is not asked
 * again.
 */
function data_surface_post_update_reference_enum_limit(): void {
}

/**
 * Adds the limit up to which a list the site decides is spelled out.
 */
function data_surface_post_update_site_list_limit(): void {
  $config = \Drupal::configFactory()->getEditable('data_surface.settings');
  if ($config->get('site_list_limit') === NULL) {
    $config->set('site_list_limit', 20)->save();
  }
}

/**
 * Renames reference_enum_limit to reference_list_limit, keeping its value.
 */
function data_surface_post_update_reference_list_limit(): void {
  $config = \Drupal::configFactory()->getEditable('data_surface.settings');
  $raw = $config->getRawData();
  $limit = $raw['reference_list_limit'] ?? $raw['reference_enum_limit'] ?? 50;
  unset($raw['reference_enum_limit']);
  $raw['reference_list_limit'] = (int) $limit;
  // The keys in the order the schema lists them.
  $config->setData(['reference_list_limit' => $raw['reference_list_limit']] + $raw)->save();
}
