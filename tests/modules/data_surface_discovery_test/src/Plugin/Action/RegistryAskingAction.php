<?php

declare(strict_types=1);

namespace Drupal\data_surface_discovery_test\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface_discovery_test\Plugin\Derivative\RegistryAskingActions;

/**
 * An action whose deriver asks the resource registry, and derives none.
 *
 * The eca_tool module derives an action per tool, and the tool deriver
 * asks the registry, which asks the plugin hosts, the action host among
 * them, for the plugins that are their own structure: a cycle discovery
 * has to answer the same whichever end is asked first.
 */
#[Action(
  id: 'data_surface_discovery_test_registry_asking',
  label: new TranslatableMarkup('Registry asking (discovery test)'),
  deriver: RegistryAskingActions::class,
)]
final class RegistryAskingAction extends ActionBase {

  /**
   * {@inheritdoc}
   *
   * @param mixed $object
   *   Unused: no action is derived.
   */
  public function execute(mixed $object = NULL): void {
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return FALSE;
  }

}
