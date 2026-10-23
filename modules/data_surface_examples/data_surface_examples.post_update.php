<?php

/**
 * @file
 * Post update functions for Data Surface - Examples.
 */

declare(strict_types=1);

/**
 * Drops the language code from example 11's door settings.
 *
 * Their schema is generated from the resource now, which declares no
 * translatable value, so core's rule asks for no language code there.
 */
function data_surface_examples_post_update_doors_without_langcode(): void {
  $config = \Drupal::configFactory()->getEditable('data_surface_examples.doors');
  if (!$config->isNew() && $config->get('langcode') !== NULL) {
    $config->clear('langcode')->save(TRUE);
  }
}

/**
 * Creates the settings of the examples added since the module was installed.
 *
 * A site that installed the module before examples 7 to 11 existed has
 * none of their config objects. An object the site already has is left as
 * it is.
 */
function data_surface_examples_post_update_install_missing_examples(): void {
  data_surface_install_missing_config('data_surface_examples');
}

/**
 * Creates example 12's spotlight on a site that installed the module before.
 *
 * An object the site already has is left as it is.
 */
function data_surface_examples_post_update_install_spotlight(): void {
  data_surface_install_missing_config('data_surface_examples');
}

/**
 * Creates example 14's mailer and webhook on a site that had the module.
 *
 * An object the site already has is left as it is.
 */
function data_surface_examples_post_update_install_mailer_and_webhook(): void {
  data_surface_install_missing_config('data_surface_examples');
}
