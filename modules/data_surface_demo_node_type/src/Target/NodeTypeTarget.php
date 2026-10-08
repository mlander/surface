<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Target;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * Where a content type's values live: the entity, and its base fields.
 *
 * Loads by the identity the context knows, so the context never carries
 * an entity: the machine name. Creates when the context creates, and
 * updates the content type the identity names otherwise.
 *
 * Two destinations, written in the order core's own content type form
 * writes them, because the second belongs to the first:
 *
 * - The node type config entity, for the keys it owns, and for the
 *   third party settings another module mounted on the surface, which
 *   arrive here under third_party_settings.<module> in the shape they
 *   are stored in — a contributor's storage shape has already been
 *   applied by the time they arrive — and are written with
 *   setThirdPartySetting(), where core keeps them.
 * - Base field overrides on the bundle, for the title label and the three
 *   workflow defaults, which are not stored on the node type at all.
 *   Compared before writing, as core's form does, so a value that did not
 *   move writes no override.
 */
final class NodeTypeTarget implements SurfaceTargetInterface {

  /**
   * The entity type whose bundles this target writes.
   */
  protected const ENTITY_TYPE_ID = 'node';

  /**
   * The surface key the mounted settings arrive under.
   */
  protected const THIRD_PARTY = 'third_party_settings';

  /**
   * The base fields whose per bundle default is a surface key.
   */
  protected const WORKFLOW = ['status', 'promote', 'sticky'];

  /**
   * Constructs a NodeTypeTarget.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, autowired, which answers what the node
   *   base fields say for a bundle.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Every key, for a content type the context names; nothing for one it
   * does not, which a creating context never does, so a new content type
   * starts from what the surface declares.
   */
  public function load(SurfaceContext $context): array {
    $type = $this->type($context);
    if ($type === NULL) {
      return [];
    }
    $fields = $this->entityFieldManager->getFieldDefinitions(self::ENTITY_TYPE_ID, (string) $type->id());
    $values = [
      'name' => $type->label(),
      'type' => $type->id(),
      'description' => $type->getDescription(),
      'title_label' => (string) $fields['title']->getLabel(),
      'preview_mode' => self::previewModeOf($type),
      'help' => $type->getHelp(),
    ];
    foreach (self::WORKFLOW as $name) {
      $values[$name] = (bool) ($fields[$name]->getDefaultValueLiteral()[0]['value'] ?? FALSE);
    }
    $values['new_revision'] = $type->shouldCreateNewRevision();
    $values['display_submitted'] = $type->displaySubmitted();
    foreach ($type->getThirdPartyProviders() as $provider) {
      $values[self::THIRD_PARTY][$provider] = $type->getThirdPartySettings($provider);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(SurfaceContext $context, array $values): void {
    $type = $this->type($context);
    if ($type === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a content type that does not exist.', $context->operation));
      }
      $type = $this->entityTypeManager->getStorage('node_type')->create(['type' => $values['type']]);
    }
    // Plain properties, named as they are stored. Preview mode is set as
    // the integer the schema stores: its setter wants the enum and
    // deprecates the integer, and the LabeledChoice has already refused
    // anything else.
    foreach (['name', 'description', 'help', 'preview_mode'] as $key) {
      if (array_key_exists($key, $values)) {
        $type->set($key, $values[$key]);
      }
    }
    if (array_key_exists('new_revision', $values)) {
      $type->setNewRevision((bool) $values['new_revision']);
    }
    if (array_key_exists('display_submitted', $values)) {
      $type->setDisplaySubmitted((bool) $values['display_submitted']);
    }
    foreach (is_array($values[self::THIRD_PARTY] ?? NULL) ? $values[self::THIRD_PARTY] : [] as $provider => $settings) {
      foreach (is_array($settings) ? $settings : [] as $key => $setting) {
        $type->setThirdPartySetting((string) $provider, (string) $key, $setting);
      }
    }
    $type->save();
    $this->writeOverrides((string) $type->id(), $values);
  }

  /**
   * Writes the base field overrides that moved, as core's form does.
   *
   * @param string $bundle
   *   The content type, saved.
   * @param array $values
   *   The accepted values.
   */
  protected function writeOverrides(string $bundle, array $values): void {
    // A content type just created has no field definitions cached for it
    // yet, and one that was cached before it existed is stale.
    $this->entityFieldManager->clearCachedFieldDefinitions();
    $fields = $this->entityFieldManager->getFieldDefinitions(self::ENTITY_TYPE_ID, $bundle);
    $moved = FALSE;
    if (array_key_exists('title_label', $values) && (string) $fields['title']->getLabel() !== (string) $values['title_label']) {
      $fields['title']->getConfig($bundle)->setLabel((string) $values['title_label'])->save();
      $moved = TRUE;
    }
    foreach (self::WORKFLOW as $name) {
      if (!array_key_exists($name, $values)) {
        continue;
      }
      $value = (bool) $values[$name];
      if ((bool) ($fields[$name]->getDefaultValueLiteral()[0]['value'] ?? FALSE) !== $value) {
        $fields[$name]->getConfig($bundle)->setDefaultValue($value)->save();
        $moved = TRUE;
      }
    }
    if ($moved) {
      $this->entityFieldManager->clearCachedFieldDefinitions();
    }
  }

  /**
   * Loads the content type the context's identity names.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return \Drupal\node\NodeTypeInterface|null
   *   The content type, or NULL when the context names none that exists.
   */
  protected function type(SurfaceContext $context): ?NodeTypeInterface {
    $id = $context->known['type'] ?? NULL;
    if (!is_string($id) || $id === '') {
      return NULL;
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($id);
    return $type instanceof NodeTypeInterface ? $type : NULL;
  }

  /**
   * Reads a content type's preview mode as the integer it is stored as.
   *
   * NodeTypeInterface still only comments the $returnAsInt parameter in,
   * so the entity class is the one that can be asked for the enum without
   * the deprecated integer return; anything else implementing the
   * interface is read from the stored property instead.
   *
   * @param \Drupal\node\NodeTypeInterface $type
   *   The content type.
   *
   * @return int
   *   The preview mode.
   */
  protected static function previewModeOf(NodeTypeInterface $type): int {
    return $type instanceof NodeType
      ? $type->getPreviewMode(FALSE)->value
      : NodePreviewMode::from((int) $type->get('preview_mode'))->value;
  }

}
