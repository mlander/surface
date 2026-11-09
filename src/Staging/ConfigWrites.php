<?php

declare(strict_types=1);

namespace Drupal\surface\Staging;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\ChainedFastBackend;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records the config an apply writes, and resets it after a rollback.
 *
 * The `surface.config_writes` service. While StageStorage::replay() has
 * it switched on, every config save, delete and rename records the
 * config's name, whoever wrote it: a config object's storage, a config
 * entity's, a subclass that saves more, a cascade core runs on a delete,
 * a hook. A rollback undoes the config table's rows, and leaves what
 * config kept beside them in this process and in the cache bin; reset()
 * drops that for every name recorded:
 *
 * - the `cache.config` bin's item of each name, whose delete also moves
 *   the fast backend's last write time on, so no web server trusts an
 *   APCu copy of a rolled back value;
 * - the config factory's objects of each name and the cached storage's
 *   lists by prefix;
 * - the entity memory cache, every bundle and every field definition,
 *   which a write of a bundle entity rebuilt from rolled back rows;
 * - State's cache, which a field deleted during the apply wrote.
 *
 * Config objects other code already holds keep what they were handed.
 *
 * Decision: see
 * docs/decisions.md#config-is-staged-and-apply-resets-what-its-rollback-leaves-behind.
 *
 * @internal
 */
final class ConfigWrites implements EventSubscriberInterface {

  /**
   * Whether writes are recorded now.
   */
  protected bool $recording = FALSE;

  /**
   * The names written while recording, as keys.
   *
   * @var array<string, true>
   */
  protected array $names = [];

  /**
   * Constructs a ConfigWrites.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The `cache.config` bin.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface $entityMemoryCache
   *   The entity memory cache.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   The entity field manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   */
  public function __construct(
    protected readonly CacheBackendInterface $cache,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly MemoryCacheInterface $entityMemoryCache,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    protected readonly EntityFieldManagerInterface $fieldManager,
    protected readonly StateInterface $state,
  ) {}

  /**
   * The priority every event is heard at, before core's own listeners.
   *
   * Config::save() writes the storage and the cache before it dispatches
   * SAVE, and a listener can throw after that: the schema checker
   * (ConfigSchemaChecker, at 255) does on a test site. Heard first, the
   * name is recorded before any listener can throw.
   */
  public const PRIORITY = 1024;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => ['onWrite', self::PRIORITY],
      ConfigEvents::DELETE => ['onWrite', self::PRIORITY],
      ConfigEvents::RENAME => ['onRename', self::PRIORITY],
    ];
  }

  /**
   * Starts recording, with nothing recorded.
   */
  public function start(): void {
    $this->recording = TRUE;
    $this->names = [];
  }

  /**
   * Stops recording; what was recorded is kept for reset().
   */
  public function stop(): void {
    $this->recording = FALSE;
  }

  /**
   * Records the name of config saved or deleted.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The event.
   */
  public function onWrite(ConfigCrudEvent $event): void {
    if ($this->recording) {
      $this->names[$event->getConfig()->getName()] = TRUE;
    }
  }

  /**
   * Records both names of config renamed.
   *
   * @param \Drupal\Core\Config\ConfigRenameEvent $event
   *   The event.
   */
  public function onRename(ConfigRenameEvent $event): void {
    if ($this->recording) {
      $this->names[$event->getOldName()] = TRUE;
      $this->names[$event->getConfig()->getName()] = TRUE;
    }
  }

  /**
   * Gets the names recorded.
   *
   * @return string[]
   *   The names, in the order first written.
   */
  public function names(): array {
    return array_map('strval', array_keys($this->names));
  }

  /**
   * Drops what config kept beside the database for the names recorded.
   *
   * Called after the transaction was rolled back, or voided by a schema
   * change, and the transaction object released; with no name recorded,
   * nothing is done.
   */
  public function reset(): void {
    $names = $this->names();
    $this->names = [];
    if ($names === []) {
      return;
    }
    // The fast backend keeps the last write time it read in memory, and
    // marks the bin outdated only when that is a second old; forgotten,
    // the delete below writes a new one.
    if ($this->cache instanceof ChainedFastBackend) {
      $this->cache->reset();
    }
    $this->cache->deleteMultiple($names);
    foreach ($names as $name) {
      $this->configFactory->reset($name);
    }
    $this->entityMemoryCache->deleteAll();
    $this->bundleInfo->clearCachedBundles();
    $this->fieldManager->clearCachedFieldDefinitions();
    $this->state->resetCache();
  }

}
