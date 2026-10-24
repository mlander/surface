<?php

/**
 * @file
 * Post update functions for Data Surface.
 */

declare(strict_types=1);

/**
 * Adds the limit up to which a reference key lists its targets.
 */
function data_surface_post_update_reference_enum_limit(): void {
  $config = \Drupal::configFactory()->getEditable('data_surface.settings');
  if ($config->get('reference_enum_limit') === NULL) {
    $config->set('reference_enum_limit', 50)->save();
  }
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
