<?php

declare(strict_types=1);

namespace Drupal\data_surface\Storage;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Creates the config objects a module ships that the site does not have.
 *
 * Core installs a module's config/install once, when the module is
 * installed. A file added in a later release never reaches a site that
 * installed the module before it, so a post-update hook calls this:
 *
 * @code
 * function my_module_post_update_new_settings(): void {
 *   data_surface_install_missing_config('my_module');
 * }
 * @endcode
 *
 * Only an object missing from active storage is created; an existing
 * object is left as the site has it. A simple config object is saved
 * through the config factory with the default config hash core's
 * installer stamps, and only when the extension its name starts with is
 * installed. A config entity goes through core's installer, which skips
 * one whose dependencies are not met and orders the rest by dependency.
 * Only the default collection is read: translations under
 * config/install/language are not created.
 */
final class MissingConfigInstaller {

  /**
   * Constructs a MissingConfigInstaller.
   *
   * @param \Drupal\Core\Config\StorageInterface $activeStorage
   *   The active config storage, which says what exists.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, which saves a simple config object.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, whose schema definitions are cleared
   *   first, so a schema shipped with the object describes it.
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The config manager, which says which names are config entities.
   * @param \Drupal\Core\Config\ConfigInstallerInterface $configInstaller
   *   Core's config installer, which creates the config entities.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, for the module's path.
   */
  public function __construct(
    protected readonly StorageInterface $activeStorage,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TypedConfigManagerInterface $typedConfig,
    protected readonly ConfigManagerInterface $configManager,
    protected readonly ConfigInstallerInterface $configInstaller,
    protected readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Creates every config object in a module's config/install that is missing.
   *
   * @param string $module
   *   The module's machine name.
   *
   * @return string[]
   *   The names of the config objects created: simple config first, then
   *   config entities.
   */
  public function install(string $module): array {
    $directory = $this->moduleList->getPath($module) . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY;
    if (!is_dir($directory)) {
      return [];
    }
    $shipped = new FileStorage($directory);
    $missing = array_values(array_diff($shipped->listAll(), $this->activeStorage->listAll()));
    if ($missing === []) {
      return [];
    }
    $this->typedConfig->clearCachedDefinitions();

    $extensions = $this->configFactory->get('core.extension');
    $installed = ['core' => 0] + (array) $extensions->get('module') + (array) $extensions->get('theme');
    $created = [];
    $entities = [];
    foreach ($shipped->readMultiple($missing) as $name => $data) {
      if ($this->configManager->getEntityTypeIdByName($name) !== NULL) {
        $entities[] = $name;
        continue;
      }
      // Core refuses to install simple config whose owner is not
      // installed; this leaves it out.
      if (!isset($installed[strstr($name, '.', TRUE)])) {
        continue;
      }
      $this->configFactory->getEditable($name)
        ->setData(['_core' => ['default_config_hash' => Crypt::hashBase64(serialize($data))]] + $data)
        ->save();
      $created[] = $name;
    }

    if ($entities !== []) {
      // Core's installer creates only the config entities among these that
      // do not exist and whose dependencies are met.
      $this->configInstaller->installOptionalConfig($shipped);
      $existing = array_flip($this->activeStorage->listAll());
      foreach ($entities as $name) {
        if (isset($existing[$name])) {
          $created[] = $name;
        }
      }
    }
    $this->configFactory->reset();
    return $created;
  }

}
