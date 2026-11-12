<?php

declare(strict_types=1);

namespace Drupal\surface\Form;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Reference\References;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Widget\WidgetHostInterface;

/**
 * What the form door answers that the served document does not say.
 *
 * Built for one drawing, from what the host form holds: the data surface
 * the envelope was emitted from and the values it was emitted for. The
 * label of an entity a reference holds is loaded here, as core's
 * autocomplete element shows it, the account's access to its label
 * asked; a reference key's selection handler and settings are read from
 * its definition at its place (Location::definitionAt(),
 * References::handlerOf()); and a deferred key's chosen document is the
 * narrowing service's (NarrowsDocumentsInterface::chosen()).
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 *
 * @internal
 */
final class FormWidgetHost implements WidgetHostInterface {

  use StringTranslationTrait;

  /**
   * Constructs a FormWidgetHost.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which loads the entity a reference names.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository, which gives its label in the page's language.
   * @param \Drupal\surface\Form\NarrowsDocumentsInterface $narrowing
   *   The narrowing, which emits a deferred key's chosen document.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface the envelope was emitted from.
   * @param array $values
   *   The values it was emitted for.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityRepositoryInterface $entityRepository,
    protected readonly NarrowsDocumentsInterface $narrowing,
    protected readonly SurfaceInterface $surface,
    protected readonly array $values,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function labelOf(string $entity_type_id, int|string $id): ?string {
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if ($entity === NULL) {
      return NULL;
    }
    // As core's element shows a label the account may not see.
    return $entity->access('view label')
      ? (string) $this->entityRepository->getTranslationFromContext($entity)->label()
      : (string) $this->t('- Restricted access -');
  }

  /**
   * {@inheritdoc}
   */
  public function selection(string $pointer): ?array {
    $values = $this->values;
    unset($values[SurfaceFormBuilderInterface::STANDING_KEY]);
    $definition = Location::definitionAt($this->surface, $values, $pointer)?->definition;
    if ($definition === NULL) {
      return NULL;
    }
    [$handler, $settings] = References::handlerOf($definition);
    return $handler === '' ? NULL : ['handler' => $handler, 'handlerSettings' => $settings];
  }

  /**
   * {@inheritdoc}
   */
  public function chosen(string $pointer, string|int|float|bool $value): ?ServedContract {
    $values = $this->values;
    unset($values[SurfaceFormBuilderInterface::STANDING_KEY]);
    return $this->narrowing->chosen($this->surface, $values, $pointer, $value)?->contract();
  }

}
