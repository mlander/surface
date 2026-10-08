<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Pipeline\DataSurfaceTargetInterface;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface\Target\PluginConfigurationTarget;

/**
 * A reusable plugin form generated entirely from the plugin's surface.
 *
 * The answer to ADOPTION.md group D, where a host resolves a form class
 * per operation through plugin_form.factory: one surface per operation
 * on the plugin, and this one class serving all of them. A plugin listing
 * it needs no form code at all — the trait-based path stays for hosts
 * without the factory, but a factory-aware host gets the cleaner split:
 * the surface on the plugin, the form logic here, once.
 *
 * All three bodies come from the plugin form trait; this class only says
 * where the surface and the configuration array come from, which is the
 * plugin rather than itself. The plugin names its surface with
 * #[UsesSurface] — any configurable plugin, with no base class of this
 * module's — and has that surface built here, in this form's operation,
 * with its configuration array as the target: the plugin keeps rendering
 * and writes no form.
 *
 * Declaring it, with the operation it serves:
 * @code
 * // The class name alone serves the 'configure' operation.
 * #[Block(id: 'example', forms: ['configure' => DataSurfacePluginForm::class])]
 *
 * // For any other operation, name a service instead: the class resolver
 * // returns a container service when the form entry is a service ID, so
 * // the operation reaches the constructor.
 * #[Block(id: 'example', forms: ['settings_tray' => 'example.tray_form'])]
 * @endcode
 * @code
 * services:
 *   example.tray_form:
 *     class: Drupal\data_surface\Form\DataSurfacePluginForm
 *     arguments: ['settings_tray']
 * @endcode
 *
 * Caveat for hosts whose operation covers more than the plugin's own
 * values — a block's 'configure' also carries label and visibility —
 * declare this class under a dedicated operation, or compose it from the
 * host's form rather than replacing it. A block adopting surfaces for
 * its own settings wants DataSurfaceBlockBase instead.
 */
class DataSurfacePluginForm extends PluginFormBase {

  use DataSurfacePluginFormTrait;

  /**
   * Constructs a DataSurfacePluginForm.
   *
   * @param string $operation
   *   The host operation this form serves, which is the operation the
   *   plugin's surface is asked for.
   */
  public function __construct(
    protected readonly string $operation = 'configure',
  ) {
  }

  /**
   * Builds the surface the plugin names, in this form's operation.
   *
   * Which operation this form serves is settled when it is constructed,
   * not when it is asked; it is the context's operation, a host verb.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The plugin's surface for this form's operation.
   *
   * @throws \LogicException
   *   When the plugin names no surface.
   */
  public function getDataSurface(): DataSurfaceInterface {
    return $this->surfaces()->build($this->pluginSurface(), $this->surfaceContext($this->operation));
  }

  /**
   * Asks the plugin's surface whether this form's operation may be run.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   * @param string $operation
   *   Unused: this form's own operation is asked about.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The surface's answer, or no opinion when the plugin names no
   *   surface — which the surface build refuses in its own words.
   */
  public function surfaceAccess(?AccountInterface $account = NULL, string $operation = 'configure'): AccessResultInterface {
    $surface = $this->pluginDefinitionSurface();
    return $surface === NULL
      ? AccessResult::neutral()
      : $this->surfaces()->access($surface, $this->surfaceContext($this->operation), $this->surfaceAccount($account));
  }

  /**
   * Gets where the plugin's values are stored: its configuration array.
   *
   * @return \Drupal\data_surface\Pipeline\DataSurfaceTargetInterface
   *   The plugin configuration target.
   *
   * @throws \LogicException
   *   When the plugin names no surface at all, which is the same refusal
   *   getDataSurface() makes.
   */
  public function getDataSurfaceTarget(): DataSurfaceTargetInterface {
    $this->pluginSurface();
    return new PluginConfigurationTarget($this->surfaceConfigurable());
  }

  /**
   * Gets the surface the plugin's definition names, or refuses.
   *
   * @return class-string
   *   The surface class.
   *
   * @throws \LogicException
   *   When the plugin names no surface.
   */
  protected function pluginSurface(): string {
    return $this->pluginDefinitionSurface() ?? throw new \LogicException(sprintf(
      '%s requires a plugin naming its surface with #[UsesSurface]; %s names none.',
      static::class,
      get_debug_type($this->plugin),
    ));
  }

  /**
   * Reads the surface #[UsesSurface] names, from the plugin's definition.
   *
   * @return class-string|null
   *   The surface class, or NULL when the definition names none.
   */
  protected function pluginDefinitionSurface(): ?string {
    $definition = $this->plugin->getPluginDefinition();
    $surface = is_array($definition) ? ($definition[UsesSurface::DEFINITION_KEY] ?? NULL) : NULL;
    return is_string($surface) ? $surface : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function surfaceConfigurable(): ConfigurableInterface {
    if (!$this->plugin instanceof ConfigurableInterface) {
      throw new \LogicException(sprintf(
        '%s requires a plugin implementing ConfigurableInterface; %s given.',
        static::class,
        get_debug_type($this->plugin),
      ));
    }
    return $this->plugin;
  }

}
