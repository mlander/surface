<?php

declare(strict_types=1);

namespace Drupal\data_surface;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rules for reading a provider's access answer.
 *
 * A provider answers per operation with core's AccessResultInterface,
 * which is a tri-state rather than a boolean, and the third state is the
 * whole point: a provider that has nothing to say about an operation
 * says so, and saying so must not close a door its host had opened.
 *
 * - **Forbidden** blocks. Nothing later in the run happens, whatever the
 *   host thinks.
 * - **Neutral** is no opinion. The host's own gate stands exactly as it
 *   stood before the provider was asked, and only the answer's
 *   cacheability travels.
 * - **Allowed** is an affirmative grant that does *not* bypass the
 *   host's gate. A provider may agree that an operation is legal; it may
 *   not hand out a permission the host refused.
 *
 * Those three sentences are one line of code, gate(), and every host
 * that combines its own answer with a provider's calls it rather than
 * writing andIf() by hand — which would turn a neutral provider into a
 * refusal, because allowed AND neutral is neutral and neutral is not
 * allowed.
 *
 * @see \Drupal\data_surface\DataSurfaceProviderInterface::surfaceAccess()
 * @see \Drupal\data_surface\Pipeline\DataSurfacePipelineInterface::submit()
 * @see docs/pipeline.md
 */
final class DataSurfaceAccess {

  /**
   * Combines a host's own access answer with a provider's.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $host
   *   What the host decided on its own: a route requirement, an entity
   *   access answer, a permission.
   * @param \Drupal\Core\Access\AccessResultInterface $provider
   *   What the provider answered for the operation.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The host's answer when the provider expressed no opinion, and the
   *   two ANDed otherwise. Either way the provider's cacheability is
   *   part of the answer, so a caller that caches the decision is
   *   invalidated when either half changes.
   */
  public static function gate(AccessResultInterface $host, AccessResultInterface $provider): AccessResultInterface {
    if (!$provider->isNeutral()) {
      return $host->andIf($provider);
    }
    if ($host instanceof RefinableCacheableDependencyInterface) {
      // No opinion, so the host's answer stands. Its cacheability still
      // grows, because "this provider had nothing to say" is itself a
      // conclusion that can stop being true.
      $host->addCacheableDependency($provider);
    }
    return $host;
  }

  /**
   * Turns an answer with no opinion into an explicit refusal.
   *
   * For the provider that *owns* an operation, and only for that one.
   * Neutral is how a provider says the question is not its business, and
   * a provider that has made the requirements its business has no third
   * state to offer: either it grants the operation or it refuses it.
   *
   * The distinction matters because of how core's helpers answer.
   * AccessResult::allowedIfHasPermission() and an entity access handler
   * both say *neutral* when the answer is no, since another checker
   * might still allow it — which is right for a hook and wrong for the
   * one place the rule is written down. A route treats that neutral as a
   * refusal, because a route requires allowed; the pipeline's gate does
   * not, because neutral there means "nothing to say". So a provider
   * mirroring its routes says so out loud, and the two agree.
   *
   * Whatever the answer already carried is kept: its cacheability
   * travels, and a refusal that already had a reason keeps that reason
   * rather than being restated in generic terms.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The assembled answer.
   * @param string|null $reason
   *   Why the operation is refused, when the answer carries no reason of
   *   its own.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer when it allows, and a forbidden answer otherwise.
   */
  public static function decisive(AccessResultInterface $access, ?string $reason = NULL): AccessResultInterface {
    if ($access->isAllowed() || $access->isForbidden()) {
      return $access;
    }
    $refusal = AccessResult::forbidden($reason);
    return $refusal->addCacheableDependency($access);
  }

  /**
   * Reads a refusal as a message object, for a violation or a log line.
   *
   * Core states a reason as a plain string, and a violation carries an
   * unrendered message object, so the reason is placed in one rather
   * than concatenated into text that something else would have to
   * escape. A result carrying no reason still says what happened.
   *
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The access answer.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   */
  public static function message(AccessResultInterface $access): TranslatableMarkup {
    $reason = $access instanceof AccessResultReasonInterface ? $access->getReason() : NULL;
    return $reason === NULL || (string) $reason === ''
      ? new TranslatableMarkup('Access refused.')
      : new TranslatableMarkup('Access refused: @reason', ['@reason' => $reason]);
  }

}
