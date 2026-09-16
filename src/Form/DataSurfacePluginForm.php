<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Plugin\PluginFormBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceProviderInterface;

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
 * plugin rather than itself.
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
   * Gets the surface of the operation this form was built for.
   *
   * The operation argument is ignored: which operation this form serves
   * is settled when it is constructed, not when it is asked. The
   * subject is not, and is handed to the plugin unread — a form class
   * is named per operation, never per subject, so the half of the
   * coordinate that identifies a thing stays the caller's to name and
   * the plugin's to resolve.
   *
   * @param string $operation
   *   Unused; present to satisfy the provider signature.
   * @param string|null $subject
   *   The id of the thing the operation is about, passed to the plugin
   *   as it arrived; NULL when the plugin is its own subject, which is
   *   what a plugin behind this form ordinarily is.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The plugin's surface for this form's operation.
   */
  public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface {
    if (!$this->plugin instanceof DataSurfaceProviderInterface) {
      throw new \LogicException(sprintf(
        '%s requires a plugin implementing DataSurfaceProviderInterface; %s given.',
        static::class,
        get_debug_type($this->plugin),
      ));
    }
    return $this->plugin->getDataSurface($this->operation, $subject);
  }

  /**
   * Asks the plugin whether this form's operation may be run.
   *
   * The surface is the plugin's, so the answer is the plugin's too: a
   * form class serving a provider must not become a second, quieter
   * gate. The operation is this form's own, for the same reason
   * getDataSurface() ignores its argument, and the subject travels to
   * the plugin unread, for the same reason it does there.
   *
   * @param string $operation
   *   Unused; present to satisfy the provider signature.
   * @param string|null $subject
   *   The id of the thing the operation is about, passed to the plugin
   *   as it arrived.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to answer for, or NULL for the current user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The plugin's answer, or no opinion when the plugin is not a
   *   provider — which the surface build refuses in its own words.
   */
  public function surfaceAccess(string $operation = 'configure', ?string $subject = NULL, ?AccountInterface $account = NULL): AccessResultInterface {
    return $this->plugin instanceof DataSurfaceProviderInterface
      ? $this->plugin->surfaceAccess($this->operation, $subject, $account)
      : AccessResult::neutral();
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
