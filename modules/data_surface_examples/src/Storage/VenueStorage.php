<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;
use Drupal\data_surface\Storage\ConfigEntityStorage;
use Drupal\data_surface_examples\Entity\EventVenue;
use Drupal\data_surface_examples\Resource\VenueResource;

/**
 * Example 15: a venue lives on its config entity, and so do all of them.
 *
 * Add, edit and remove are ConfigEntityStorage's, by the venue's machine
 * name, with two rules of its own: a slug is held once across the venues,
 * since a link names a venue by it, and so is a room id, since example 2
 * offers a room by it. The two operations over
 * the venues together are read and written here:
 *
 * - **list** loads nothing, since a query has nothing stored, and its
 *   prepare() reads the venues, lighter first, as its answer: those the
 *   filter matches and the account may read, a page of them, and how
 *   many there are. An account may read a venue when the resource's
 *   access answer for it, asked in the listing's own context knowing the
 *   venue, is allowed: the listing's permission, then the access class.
 * - **reorder** loads every venue's id and weight, and its prepare()
 *   refuses an id that names no venue or that is named twice, and
 *   rehearses each named venue with its new weight, held to its config
 *   schema; commit() saves them, all in that one write.
 *
 * Decision: see
 * docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
 */
final class VenueStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  public const ENTITY_TYPE_ID = 'event_venue';

  /**
   * {@inheritdoc}
   */
  public const PROPERTIES = ['id', 'label', 'slug', 'capacity', 'address', 'rooms', 'weight', 'status'];

  /**
   * {@inheritdoc}
   */
  public const ALWAYS_HELD = ['rooms', 'weight', 'status'];

  /**
   * The operation that lists the venues.
   */
  public const LIST = 'list';

  /**
   * The operation that reorders them.
   */
  public const REORDER = 'reorder';

  /**
   * Constructs a VenueStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, autowired.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, autowired, which answers access for each venue.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The account a listing is answered for, autowired.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
    protected readonly ResourcesInterface $resources,
    protected readonly AccountProxyInterface $currentUser,
  ) {
    parent::__construct($entityTypeManager, $typedConfig, $string_translation);
  }

  /**
   * {@inheritdoc}
   *
   * Nothing for a listing; every venue's id and weight for a reorder.
   */
  public function load(OperationContext $context): array {
    return match ($context->id) {
      self::LIST => [],
      self::REORDER => [
        'venues' => array_values(array_map(static fn (EventVenue $venue): array => [
          'id' => (string) $venue->id(),
          'weight' => $venue->weight(),
        ], $this->venues())),
      ],
      default => parent::load($context),
    };
  }

  /**
   * {@inheritdoc}
   *
   * A listing's answer, a reorder's rehearsal, or a venue's, its slug
   * and its rooms held to what no other venue has.
   */
  public function prepare(OperationContext $context, array $values): array {
    return match ($context->id) {
      self::LIST => $this->listing($context, $values),
      self::REORDER => $this->reordering($values),
      default => $this->venue($context, $values),
    };
  }

  /**
   * {@inheritdoc}
   *
   * A reorder saves each venue it rehearsed with its new weight.
   */
  public function commit(OperationContext $context, array $prepared): void {
    if ($context->id !== self::REORDER) {
      parent::commit($context, $prepared);
      return;
    }
    $storage = $this->storage();
    foreach ($prepared['weights'] ?? [] as $id => $weight) {
      $venue = $storage->load((string) $id);
      if ($venue instanceof EventVenue) {
        $venue->set('weight', (int) $weight)->save();
      }
    }
  }

  /**
   * Reads a page of the venues the account may read.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The listing's context.
   * @param array $values
   *   The accepted offset, limit and status.
   *
   * @return array{items: list<array{id: string, label: string, capacity: int, weight: int}>, total: int}
   *   The page and how many venues matched.
   */
  protected function listing(OperationContext $context, array $values): array {
    $status = $values['status'] ?? NULL;
    $matched = [];
    foreach ($this->venues() as $venue) {
      if (is_bool($status) && $venue->status() !== $status) {
        continue;
      }
      // Decision: see
      // docs/decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection.
      if (!$this->resources->access(VenueResource::class, $context->withKnown(['id' => (string) $venue->id()]), $this->currentUser)->isAllowed()) {
        continue;
      }
      $matched[] = [
        'id' => (string) $venue->id(),
        'label' => (string) $venue->label(),
        'capacity' => (int) $venue->get('capacity'),
        'weight' => $venue->weight(),
      ];
    }
    return [
      'items' => array_slice($matched, (int) ($values['offset'] ?? 0), (int) ($values['limit'] ?? 20)),
      'total' => count($matched),
    ];
  }

  /**
   * Rehearses a reorder: each venue named, once, with its new weight.
   *
   * @param array $values
   *   The accepted list of venues and weights.
   *
   * @return array{weights: array<string, int>}
   *   The weight each venue named takes, by its machine name.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When an id names no venue or is named twice, or a venue's config
   *   schema refuses its new weight.
   */
  protected function reordering(array $values): array {
    $venues = $this->venues();
    $refused = [];
    $weights = [];
    $first = [];
    foreach (array_values(is_array($values['venues'] ?? NULL) ? $values['venues'] : []) as $delta => $item) {
      $id = is_array($item) && is_string($item['id'] ?? NULL) ? $item['id'] : '';
      if (!isset($venues[$id])) {
        $refused[] = new Violation('venues', $delta . '.id', $this->t('No venue is named @id.', ['@id' => $id]), Violation::NOT_FOUND);
        continue;
      }
      if (isset($first[$id])) {
        $refused[] = new Violation('venues', $delta . '.id', $this->t('The @id venue is named already, at position @first: a reorder names each venue once.', [
          '@id' => $id,
          '@first' => $first[$id],
        ]), Violation::TAKEN);
        continue;
      }
      $first[$id] = $delta;
      $copy = $this->copy($venues[$id], []);
      $copy->set('weight', (int) $item['weight']);
      foreach ($this->validate($copy, $copy->toArray()) as $violation) {
        $refused[] = new Violation('venues', $delta . '.weight', $violation->message, $violation->code);
      }
      $weights[$id] = (int) $item['weight'];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    return ['weights' => $weights];
  }

  /**
   * Rehearses one venue, its slug and rooms held to what no other venue has.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The create or the update.
   * @param array $values
   *   The accepted values.
   *
   * @return array
   *   The venue's exported array.
   *
   * @throws \Drupal\data_surface\Pipeline\StorageViolationsException
   *   When another venue holds the slug, a room id is held twice, here or
   *   by another venue, or as ConfigEntityStorage refuses.
   */
  protected function venue(OperationContext $context, array $values): array {
    $own = $context->known['id'] ?? $values['id'] ?? NULL;
    $elsewhere = [];
    foreach ($this->venues() as $id => $venue) {
      if ($id !== $own) {
        foreach ($venue->rooms() as $room) {
          $elsewhere[(string) $room['id']] = (string) $id;
        }
      }
    }
    $refused = [];
    $slug = $values['slug'] ?? NULL;
    foreach ($this->venues() as $id => $venue) {
      if ($id !== $own && is_string($slug) && $venue->slug() === $slug) {
        // A rule no schema can state, said by the storage that knows the
        // other venues; the pipeline names the key the slug is computed
        // from after it.
        $refused[] = new Violation('slug', '', $this->t('The slug @slug is held already, by @venue: a slug is held once across the venues.', [
          '@slug' => $slug,
          '@venue' => $id,
        ]), Violation::TAKEN);
      }
    }
    $seen = [];
    foreach (array_values(is_array($values['rooms'] ?? NULL) ? $values['rooms'] : []) as $delta => $room) {
      $id = is_array($room) && is_string($room['id'] ?? NULL) ? $room['id'] : NULL;
      if ($id === NULL) {
        continue;
      }
      if (isset($elsewhere[$id]) || isset($seen[$id])) {
        $refused[] = new Violation('rooms', $delta . '.id', $this->t('The room id @id is held already, by @venue: a room id is held once across the venues.', [
          '@id' => $id,
          '@venue' => $elsewhere[$id] ?? $own,
        ]), Violation::TAKEN);
      }
      $seen[$id] = TRUE;
    }
    try {
      $record = parent::prepare($context, $values);
    }
    catch (StorageViolationsException $e) {
      $refused = [...iterator_to_array($e->getViolations(), FALSE), ...$refused];
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    assert(isset($record));
    return $record;
  }

  /**
   * Loads every venue, lighter first, then by name.
   *
   * @return array<string, \Drupal\data_surface_examples\Entity\EventVenue>
   *   The venues, by machine name.
   */
  protected function venues(): array {
    $venues = array_filter($this->storage()->loadMultiple(), static fn ($venue): bool => $venue instanceof EventVenue);
    uasort($venues, [EventVenue::class, 'sort']);
    return $venues;
  }

}
