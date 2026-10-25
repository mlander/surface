<?php

declare(strict_types=1);

namespace Drupal\data_surface\ResourceBuild;

use Drupal\Core\Session\AccountInterface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Resource\OperationContext;

/**
 * Where the variants at one key sit, and the build they were held in.
 *
 * Shared by every variant the build held unsettled at the key
 * (VariantToSettle), so a build that stores three hundred of them stores
 * this once: the frames around them, the key, and the build: for no
 * account, for the account alone or with every answer, the account it
 * was for, and whether it is held to its config schema.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 *
 * @internal
 */
final class VariantsToSettle {

  /**
   * Constructs a VariantsToSettle.
   *
   * @param \Drupal\data_surface\ResourceBuild\Resources|null $resources
   *   The build step that builds them; NULL when it is not the container's,
   *   so what it holds is built before it is stored.
   * @param array $ancestry
   *   The frames around them, outermost first, the parent's last, each as
   *   Resources::buildResource() takes it; a storage a frame names is
   *   kept by its class, and found again when a variant is built.
   * @param string $key
   *   The key they sit at.
   * @param bool $alone
   *   Whether the build sets a runtime answer aside.
   * @param bool $declaring
   *   Whether it sets every answer aside, for no account.
   * @param bool $holdsToSchema
   *   Whether it is held to its config schema.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account it was for; NULL for no account.
   */
  public function __construct(
    private ?Resources $resources,
    public readonly array $ancestry,
    public readonly string $key,
    public readonly bool $alone,
    public readonly bool $declaring,
    public readonly bool $holdsToSchema,
    public readonly ?AccountInterface $account,
  ) {
  }

  /**
   * The contexts the variants are invoked in, one object for each that differs.
   *
   * @var \Drupal\data_surface\Resource\OperationContext[]
   */
  private array $contexts = [];

  /**
   * Gives one object for every context that says the same.
   *
   * So the variants at a key invoked alike share their context, and a
   * build storing them stores it once.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   A variant's context.
   *
   * @return \Drupal\data_surface\Resource\OperationContext
   *   The first one equal to it.
   */
  public function shared(OperationContext $context): OperationContext {
    // Most often one: every variant at the key invoked in its parent's.
    foreach ($this->contexts as $kept) {
      if ($kept == $context) {
        return $kept;
      }
    }
    return $this->contexts[] = $context;
  }

  /**
   * Builds one of the variants.
   *
   * @param string $name
   *   The variant, by name.
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context it is invoked in.
   * @param string|null $locked
   *   Why its access locked it whole, or NULL.
   * @param bool $read
   *   Whether it is a structure read from a schema.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The sealed variant.
   */
  public function settle(string $name, OperationContext $context, ?string $locked, bool $read): DataSurfaceInterface {
    $resources = $this->resources ?? throw new \LogicException(sprintf('The variant %s at "%s" was stored as what builds it and woke with no build step to build it.', $name, $this->key));
    return $resources->settleVariant($this, $name, $context, $locked, $read);
  }

  /**
   * Answers whether the variants may be stored as what builds them.
   *
   * @return bool
   *   TRUE for variants held for no account by the container's build step,
   *   held to their config schema: what builds them is all there is to
   *   store.
   */
  public function storesWhatBuilds(): bool {
    return $this->declaring && $this->holdsToSchema && $this->resources !== NULL && $this->resources === static::buildStep();
  }

  /**
   * Stores the frames and the build, not the build step, a service.
   *
   * @return array
   *   What the constructor takes, less the build step.
   */
  public function __serialize(): array {
    $ancestry = $this->ancestry;
    foreach ($ancestry as $level => $frame) {
      if (is_array($frame['held'] ?? NULL)) {
        unset($ancestry[$level]['held']['storage']);
      }
    }
    return [
      'ancestry' => $ancestry,
      'key' => $this->key,
      'alone' => $this->alone,
      'declaring' => $this->declaring,
      'holdsToSchema' => $this->holdsToSchema,
      'account' => $this->account,
    ];
  }

  /**
   * Restores the frames and the build, with the container's build step.
   *
   * @param array $data
   *   What __serialize() stored.
   */
  public function __unserialize(array $data): void {
    $this->resources = static::buildStep();
    $this->ancestry = $data['ancestry'];
    $this->key = $data['key'];
    $this->alone = $data['alone'];
    $this->declaring = $data['declaring'];
    $this->holdsToSchema = $data['holdsToSchema'];
    $this->account = $data['account'];
  }

  /**
   * Gets the container's build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\Resources|null
   *   The build step, or NULL with no container.
   */
  protected static function buildStep(): ?Resources {
    $resources = \Drupal::hasService('data_surface.resources') ? \Drupal::service('data_surface.resources') : NULL;
    return $resources instanceof Resources ? $resources : NULL;
  }

}
