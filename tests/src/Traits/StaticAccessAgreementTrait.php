<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Traits;

use Drupal\data_surface\Computed;
use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\Contract\SchemaResolver;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * Holds what the full schema states about access to what the build applies.
 *
 * The full schema is emitted from a data surface built for the account
 * alone (ResourcesInterface::buildStatic()): each static access answer is
 * applied there, a key it locked `readOnly`, a key it left out absent, and
 * each runtime answer is set aside, its key marked `x-surface.live`, as
 * a key a runtime refiner narrows is. That is only true if a static
 * answer is what its cacheability says, decided by the account alone.
 * So, for each sampled account, this emits the full schema once and then,
 * for each situation (a stored value changed, a State flag set: what a
 * runtime answer reads), builds the resource with every answer applied
 * (build()) and asserts that every key the full schema states without the
 * runtime mark is exactly as the build has it: there when it is there,
 * locked when it is locked, inside every child too. A key marked runtime
 * may be anything. An answer whose cacheability left out what it read
 * answers differently in some situation and fails here.
 *
 * The full schema itself is first held to the build it was emitted from,
 * keyword for keyword where access is concerned: `readOnly` exactly where
 * a key is locked, the runtime mark exactly where it was set aside, no
 * property the build left out.
 *
 * @see docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability
 */
trait StaticAccessAgreementTrait {

  /**
   * Asserts the full schema's static access marks hold at runtime.
   *
   * @param string $resource
   *   The resource, by id or class.
   * @param string $operation
   *   The operation, which needs no argument.
   * @param array<string, \Drupal\Core\Session\AccountInterface> $accounts
   *   The accounts to sample, by label.
   * @param array<string, callable(): void> $situations
   *   What a runtime answer reads, set before each build, by label; each
   *   is run for each account, in order.
   * @param array $arguments
   *   The operation's arguments.
   *
   * @return int
   *   How many keys were compared, so a caller can assert the check
   *   reached something.
   */
  protected function assertStaticAccessAgrees(string $resource, string $operation, array $accounts, array $situations, array $arguments = []): int {
    $resources = \Drupal::service('data_surface.resources');
    assert($resources instanceof ResourcesInterface);
    $emitter = \Drupal::service('data_surface.contract_emitter');
    assert($emitter instanceof ContractEmitter);
    $compared = 0;
    foreach ($accounts as $who => $account) {
      \Drupal::currentUser()->setAccount($account);
      $context = $resources->operation($resource, $operation, $arguments);
      $alone = $resources->buildStatic($resource, $context);
      $full = json_decode((string) json_encode($emitter->emitFull($alone, $resource, $operation)->document['schema']), TRUE);
      $compared += $this->assertSchemaStatesAccess($alone, SchemaResolver::inline($full), $who);
      foreach ($situations as $situation => $set) {
        $set();
        $built = $resources->build($resource, $context);
        $compared += $this->assertStaticMarksHold($alone, $built, sprintf('%s, %s', $who, $situation));
      }
    }
    return $compared;
  }

  /**
   * Asserts a full schema states access as the data surface it came from.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $alone
   *   The data surface built for the account alone.
   * @param array $schema
   *   The full schema's frame for it, inline.
   * @param string $label
   *   Where, for messages.
   * @param bool $frozen
   *   Whether the frame is inside a child locked whole, where every key is
   *   read only.
   *
   * @return int
   *   How many keys were compared.
   */
  protected function assertSchemaStatesAccess(DataSurfaceInterface $alone, array $schema, string $label, bool $frozen = FALSE): int {
    $compared = 0;
    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    $this->assertSame($alone->getDefinitions()->names(), array_keys($properties), sprintf('%s: the full schema states every key the account may see and no other.', $label));
    foreach ($alone->getDefinitions()->entries() as $name => $entry) {
      $property = is_array($properties[$name]) ? $properties[$name] : [];
      $locked = $entry->locked || $frozen;
      // A computed key is read only too: nobody types it. One computed
      // unless sent is typed, or computed when left out.
      // Decision: see
      // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
      $this->assertSame($locked || Computed::of($entry->definition)?->takesSent() === FALSE, ($property['readOnly'] ?? FALSE) === TRUE, sprintf('%s: "%s" is read only exactly where it is locked or computed.', $label, $name));
      $this->assertSame($entry->runtimeAccess || $entry->isRefinedAtRuntime(), ($property['x-surface']['live'] ?? FALSE) === TRUE, sprintf('%s: "%s" is marked live exactly where its answer was set aside or a runtime refiner narrows it.', $label, $name));
      $compared++;
      if ($entry->attachment !== NULL) {
        $compared += $this->assertSchemaStatesAccess($entry->attachment->child, $property, sprintf('%s.%s', $label, $name), $locked);
      }
    }
    return $compared;
  }

  /**
   * Asserts every key not marked runtime is as the build applied it.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $alone
   *   The data surface built for the account alone.
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The same, built with every answer applied.
   * @param string $label
   *   Where, for messages.
   *
   * @return int
   *   How many keys were compared.
   */
  protected function assertStaticMarksHold(DataSurfaceInterface $alone, DataSurfaceInterface $built, string $label): int {
    $compared = 0;
    $at_runtime = $built->getDefinitions();
    foreach ($alone->getDefinitions()->entries() as $name => $entry) {
      $now = $at_runtime->entry((string) $name);
      if ($entry->runtimeAccess) {
        continue;
      }
      $this->assertNotNull($now, sprintf('%s: "%s" is stated without the runtime mark, and the build left it out.', $label, $name));
      $this->assertSame($entry->locked, $now->locked, sprintf('%s: "%s" is stated %s, and the build has it %s.', $label, $name, $entry->locked ? 'locked' : 'open', $now->locked ? 'locked' : 'open'));
      $compared++;
      if ($entry->attachment !== NULL && $now->attachment !== NULL) {
        $compared += $this->assertStaticMarksHold($entry->attachment->child, $now->attachment->child, sprintf('%s.%s', $label, $name));
      }
    }
    foreach ($at_runtime->names() as $name) {
      $this->assertNotNull($alone->getDefinitions()->entry($name), sprintf('%s: the full schema leaves "%s" out, and the build has it.', $label, $name));
    }
    return $compared;
  }

}
