<?php

declare(strict_types=1);

namespace Drupal\surface_test\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_test\Plugin\Field\FieldType\SurfaceGatedItem;

/**
 * The gated field type's own answer: no, while the state flag is set.
 *
 * The refusal is switched by state rather than by a permission, because
 * a permission is exactly what the host gate already asks and the point
 * of the fixture is an answer the host gate does not have. Otherwise no
 * opinion: the field config entity, or the operation's permission, is
 * the gate.
 */
final class GatedFieldSettingsAccess implements OperationAccessInterface {

  /**
   * Constructs a GatedFieldSettingsAccess.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service, autowired.
   */
  public function __construct(
    protected readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function access(OperationContext $context, AccountInterface $account): AccessResultInterface {
    return $this->state->get(SurfaceGatedItem::REFUSE_STATE_KEY, FALSE)
      ? AccessResult::forbidden('The gated test field type does not allow its settings to be configured.')->setCacheMaxAge(0)
      : AccessResult::neutral()->setCacheMaxAge(0);
  }

}
