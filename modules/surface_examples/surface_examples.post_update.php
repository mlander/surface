<?php

/**
 * @file
 * Post update functions for Surface API - Examples.
 */

declare(strict_types=1);

use Drupal\surface_examples\Entity\EventVenue;
use Drupal\surface_examples\Slugify;

/**
 * Drops the language code from example 11's door settings.
 *
 * Their schema is generated from the resource now, which declares no
 * translatable value, so core's rule asks for no language code there.
 */
function surface_examples_post_update_doors_without_langcode(): void {
  $config = \Drupal::configFactory()->getEditable('surface_examples.doors');
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
function surface_examples_post_update_install_missing_examples(): void {
  surface_install_missing_config('surface_examples');
}

/**
 * Creates example 12's spotlight on a site that installed the module before.
 *
 * An object the site already has is left as it is.
 */
function surface_examples_post_update_install_spotlight(): void {
  surface_install_missing_config('surface_examples');
}

/**
 * Creates example 14's mailer and webhook on a site that had the module.
 *
 * An object the site already has is left as it is.
 */
function surface_examples_post_update_install_mailer_and_webhook(): void {
  surface_install_missing_config('surface_examples');
}

/**
 * Creates example 15's three venues on a site that had the module.
 *
 * A venue the site already has is left as it is.
 */
function surface_examples_post_update_install_venues(): void {
  surface_install_missing_config('surface_examples');
}

/**
 * Gives each of example 15's venues its slug, computed from its name.
 *
 * The slug is computed when a venue is added, so a venue saved before the
 * key existed holds none. Each is given what Slugify computes from its
 * name, the value an add would have stored; a venue whose slug another
 * venue holds already is left without one, and is refused by name at its
 * next edit.
 */
function surface_examples_post_update_venue_slugs(): void {
  $venues = \Drupal::entityTypeManager()->getStorage('event_venue')->loadMultiple();
  $held = [];
  foreach ($venues as $venue) {
    if ($venue instanceof EventVenue && $venue->slug() !== NULL) {
      $held[$venue->slug()] = TRUE;
    }
  }
  foreach ($venues as $venue) {
    if (!$venue instanceof EventVenue || $venue->slug() !== NULL) {
      continue;
    }
    $slug = Slugify::compute(['label' => $venue->label()]);
    if (!is_string($slug) || isset($held[$slug])) {
      continue;
    }
    $held[$slug] = TRUE;
    $venue->set('slug', $slug)->save();
  }
}
