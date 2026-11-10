<?php

/**
 * @file
 * Post update functions for Surface API.
 */

declare(strict_types=1);

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Adds the limit up to which a reference key lists its targets.
 *
 * Superseded by surface_post_update_reference_list_limit(), which
 * adds it under its present name; kept so a site that ran it is not asked
 * again.
 */
function surface_post_update_reference_enum_limit(): void {
}

/**
 * Adds the limit up to which a list the site decides is spelled out.
 */
function surface_post_update_site_list_limit(): void {
  $config = \Drupal::configFactory()->getEditable('surface.settings');
  if ($config->get('site_list_limit') === NULL) {
    $config->set('site_list_limit', 20)->save();
  }
}

/**
 * Renames reference_enum_limit to reference_list_limit, keeping its value.
 */
function surface_post_update_reference_list_limit(): void {
  $config = \Drupal::configFactory()->getEditable('surface.settings');
  $raw = $config->getRawData();
  $limit = $raw['reference_list_limit'] ?? $raw['reference_enum_limit'] ?? 50;
  unset($raw['reference_enum_limit']);
  $raw['reference_list_limit'] = (int) $limit;
  // The keys in the order the schema lists them.
  $config->setData(['reference_list_limit' => $raw['reference_list_limit']] + $raw)->save();
}

/**
 * Adds the config object the site's defaults are set in, empty.
 */
function surface_post_update_site_defaults(): void {
  surface_install_missing_config('surface');
}

/**
 * Adds the components offered where a key chooses one: every one.
 */
function surface_post_update_components_offered(): void {
  $config = \Drupal::configFactory()->getEditable('surface.settings');
  if ($config->get('components_offered') === NULL) {
    $config->set('components_offered', [])->save();
  }
}

/**
 * Installs surface_http and surface_openapi, where they are not installed.
 *
 * The HTTP door and the OpenAPI document left this module for these two,
 * so a site that served the API before keeps serving it. Every role that
 * holds the documentation pages' permission is saved again, so it depends
 * on surface_openapi, the module that defines the permission now, and
 * loses the permission when that module is uninstalled.
 */
function surface_post_update_install_http_and_openapi(): ?TranslatableMarkup {
  $missing = array_values(array_filter(['surface_http', 'surface_openapi'], static fn (string $module): bool => !\Drupal::moduleHandler()->moduleExists($module)));
  if ($missing === []) {
    return NULL;
  }
  \Drupal::service('module_installer')->install($missing);
  $entity_type_manager = \Drupal::entityTypeManager();
  if (in_array('surface_openapi', $missing, TRUE) && $entity_type_manager->hasDefinition('user_role')) {
    foreach ($entity_type_manager->getStorage('user_role')->loadMultiple() as $role) {
      if (in_array('access surface api docs', $role->getPermissions(), TRUE)) {
        $role->save();
      }
    }
  }
  return t("Installed @modules. Export the site's configuration so core.extension lists them.", [
    '@modules' => implode(', ', $missing),
  ]);
}
