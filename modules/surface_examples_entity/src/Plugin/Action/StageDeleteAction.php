<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stages the delete of each selected node, for the confirm page to apply.
 *
 * The content list's bulk form hands it the rows it may run on. The list
 * has one row per translation and hands each row's translation, so the
 * rows are read as nodes first: two rows of one node, or one row twice,
 * are one node and one delete. It asks each node's delete access through
 * the entity type's resource, `entity.content:node`, and leaves a refused
 * one out with a message, as the bulk form leaves out one the action's
 * access() refuses. It then opens a stage as the current user, through
 * the pipeline, as any door opens one, labeled with the count of the
 * nodes allowed; stages one delete per node; and keeps the stage's id and
 * the staged ids in the private temp store, one selection per account, as
 * core's delete action keeps its own. The bulk form then sends the
 * account to the confirm page, which applies the stage. With no node
 * allowed, no stage is opened. A delete the stage refuses when it is
 * staged also leaves its node out with a message, so the label may count
 * more nodes than the confirm page asks about.
 *
 * It stages rather than deletes: the confirm page lists what the stage
 * holds, the apply checks every delete again (its access, and that the
 * node did not change since it was staged) and makes them all in one
 * transaction or none, and nothing is removed before the account
 * confirms.
 *
 * A limit of the example: a translation's row stages the removal of the
 * whole node with every translation, since that is what the storage
 * deletes (docs/entities.md#the-storage), where core's own "Delete
 * content" removes the one translation.
 *
 * Decision: see
 * docs/decisions.md#a-destructive-bulk-operation-stages-then-confirms-over-the-stage.
 */
#[Action(
  id: 'surface_entity_stage_delete',
  label: new TranslatableMarkup('Delete content through a stage'),
  type: 'node',
  confirm_form_route_name: 'surface_examples_entity.stage_delete_confirm',
)]
final class StageDeleteAction extends EntityActionBase {

  /**
   * The resource each delete is staged through: one for every bundle.
   */
  private const RESOURCE = 'entity.content:node';

  /**
   * The private temp store collection the selection is kept in.
   */
  public const COLLECTION = 'surface_examples_entity';

  /**
   * The key of the selection in that collection: one per account.
   */
  public const KEY = 'stage_delete';

  /**
   * Constructs a StageDeleteAction.
   *
   * @param array $configuration
   *   The plugin's configuration, empty.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step: operations, their access, builds and storages.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which opens the stage and stages each delete.
   * @param \Drupal\surface\Staging\StageStore $stages
   *   The stages, which name the stage a staged write is handed.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private temp store, which keeps the selection for the confirm
   *   page.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account the bulk form runs as, which owns the stage.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    protected readonly ResourcesInterface $resources,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly StageStore $stages,
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('surface.resources'),
      $container->get('surface.pipeline'),
      $container->get('surface.stages'),
      $container->get('tempstore.private'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    // One delete per node: the content list has a row per translation.
    $nodes = [];
    foreach ($entities as $entity) {
      if ($entity instanceof EntityInterface) {
        $nodes[$entity->id()] ??= $entity;
      }
    }
    $allowed = [];
    foreach ($nodes as $node) {
      $answer = $this->allowedDelete($node);
      if ($answer !== NULL) {
        $allowed[] = [$node, ...$answer];
      }
    }
    $stage = $allowed === [] ? NULL : $this->open($this->formatPlural(count($allowed), 'Delete 1 item', 'Delete @count items'));
    if ($stage === NULL) {
      $store->delete(self::KEY);
      return;
    }
    $ids = [];
    foreach ($allowed as [$node, $context, $access]) {
      if ($this->stageDelete($stage, $node, $context, $access)) {
        $ids[] = $node->id();
      }
    }
    if ($ids === []) {
      // Nothing to confirm: the empty stage expires as any does.
      $store->delete(self::KEY);
      return;
    }
    $store->set(self::KEY, ['stage' => $stage->id, 'ids' => $ids]);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(?object $object = NULL): void {
    $this->executeMultiple([$object]);
  }

  /**
   * {@inheritdoc}
   *
   * The node's own delete access, as core's delete action asks it.
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    return $object->access('delete', $account, $return_as_object);
  }

  /**
   * Opens a stage owned by the current user, through the pipeline.
   *
   * @param \Stringable|string $label
   *   The stage's label.
   *
   * @return \Drupal\surface\Staging\Stage|null
   *   The stage, or NULL when it was refused, which a message says.
   */
  protected function open(\Stringable|string $label): ?Stage {
    $context = $this->resources->operation(StageResource::ID, 'create');
    $access = $this->resources->access(StageResource::ID, $context, $this->currentUser);
    $built = $this->resources->build(StageResource::ID, $context);
    $storage = $this->resources->storage(StageResource::ID, $context, $built, $this->currentUser);
    $result = $this->pipeline->submit($built, ['label' => (string) $label], $storage, access: $access);
    // The id is no value sent: the storage reports it once the stage is
    // open, and the pipeline reads it.
    $id = $this->pipeline->outputs($built, $storage, $result)['id'] ?? NULL;
    if (!$result->committed || !is_string($id)) {
      foreach ($result->violations as $violation) {
        $this->messenger()->addError($this->t('No stage could be opened, so nothing was staged for deletion: @reason', [
          '@reason' => ResultDocument::message($violation, FALSE),
        ]));
      }
      return NULL;
    }
    // The stage's own access, asked before it is handed on, as every door
    // asks it.
    $update = $this->resources->operation(StageResource::ID, 'update', ['id' => $id]);
    if (!$this->resources->access(StageResource::ID, $update, $this->currentUser)->isAllowed()) {
      $this->messenger()->addError($this->t('The stage just opened may not be written into, so nothing was staged for deletion.'));
      return NULL;
    }
    return $this->stages->named($id);
  }

  /**
   * Asks the resource whether the current user may delete one node.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The node.
   *
   * @return array{0: \Drupal\surface\Resource\OperationContext, 1: \Drupal\Core\Access\AccessResultInterface}|null
   *   The delete's context and its access answer, or NULL when the node
   *   may not be staged for deletion, which a message says.
   */
  protected function allowedDelete(EntityInterface $entity): ?array {
    try {
      $context = $this->resources->operation(self::RESOURCE, 'delete', ['id' => $entity->id()]);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($this->t('%label was not staged for deletion: @reason', [
        '%label' => $entity->label() ?? $entity->id(),
        '@reason' => $e->getMessage(),
      ]));
      return NULL;
    }
    $access = $this->resources->access(self::RESOURCE, $context, $this->currentUser);
    if (!$access->isAllowed()) {
      $this->messenger()->addError($this->t('%label was not staged for deletion: you may not delete it.', [
        '%label' => $entity->label() ?? $entity->id(),
      ]));
      return NULL;
    }
    return [$context, $access];
  }

  /**
   * Stages one node's delete, as a door does, its access asked already.
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The node.
   * @param \Drupal\surface\Resource\OperationContext $context
   *   The delete's context.
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   The delete's access answer, which allowed it.
   *
   * @return bool
   *   Whether the stage holds its delete; when not, a message says why.
   */
  protected function stageDelete(Stage $stage, EntityInterface $entity, OperationContext $context, AccessResultInterface $access): bool {
    $built = $this->resources->build(self::RESOURCE, $context);
    $storage = $this->resources->storage(self::RESOURCE, $context, $built, $this->currentUser, $stage->withArguments(['id' => $entity->id()]));
    $result = $this->pipeline->submit($built, [], $storage, access: $access);
    if ($result->committed) {
      return TRUE;
    }
    foreach ($result->violations as $violation) {
      $this->messenger()->addError($this->t('%label was not staged for deletion: @reason The stage keeps the others.', [
        '%label' => $entity->label() ?? $entity->id(),
        '@reason' => ResultDocument::message($violation, FALSE),
      ]));
    }
    return FALSE;
  }

}
