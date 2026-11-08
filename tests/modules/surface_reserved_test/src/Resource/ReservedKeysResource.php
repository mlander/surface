<?php

declare(strict_types=1);

namespace Drupal\surface_reserved_test\Resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Staging\StagedStorage;
use Drupal\surface\Storage\StageStorage;
use Drupal\surface_reserved_test\Storage\ReservedKeysStorage;

/**
 * A resource whose operations know a key the stage reserves.
 *
 * One operation knows the position key of a staged create, one the
 * lifetime key of a new stage. Resources::operation() refuses each, since
 * the stage's storage reads those keys and no owner's context may set
 * them.
 */
#[Resource('reserved_test.thing', storage: ReservedKeysStorage::class)]
final class ReservedKeysResource implements ResourceInterface {

  /**
   * Knows the key that tells a thing a stage creates.
   */
  #[Operation('position', label: new TranslatableMarkup('Know the position'))]
  public static function position(): OperationContext {
    return new OperationContext(known: [StagedStorage::POSITION => 1]);
  }

  /**
   * Knows the key that names a new stage's lifetime.
   */
  #[Operation('keep', label: new TranslatableMarkup('Know the lifetime'))]
  public static function keep(): OperationContext {
    return new OperationContext(known: [StageStorage::KEEP => 'short']);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('name', 'string', t('Name'), default: '');
  }

}
