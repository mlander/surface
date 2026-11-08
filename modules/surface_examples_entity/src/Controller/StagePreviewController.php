<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Pipeline\StorageViolationsException;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface_examples_entity\Storage\ContentEntityStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Previews one node as a stage holds it, the way core previews a node.
 *
 * `/surface-entity/stage/{surface_stage}/preview/{node_id}`: the node a
 * stage writes, rendered in its full view mode as it would be once the
 * stage is applied, and nothing saved. The node is the stored one, or a
 * new one for a local id (`-1`), with the staged values set on it by the
 * content entity storage's preview() and marked `in_preview`, so core's
 * node view builder caches nothing of it and draws no links, as node
 * preview does; the render cache keys are dropped whatever view builder
 * the site sets for nodes. What the node's own validation would refuse is
 * listed above it. A node the stage removes is a page saying so.
 *
 * Which resource the stage writes the node through is read from the
 * stage's `changes`, run through the pipeline: a node the stage holds no
 * write of is not found.
 *
 * Access follows core's NodePreviewAccessCheck: the stage's own access,
 * which only its owner holds (the converter asks it too), and the access
 * of the write the stage holds, asked through the resource the stage
 * writes the node through: the node's delete access for a staged delete,
 * the bundle's create access for a staged create, else the node's update
 * access.
 */
final class StagePreviewController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The resource of every node; a bundle's is this, a dot, and the bundle.
   */
  protected const NODE = 'entity.content:node';

  /**
   * Constructs a StagePreviewController.
   *
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which runs the stage's `changes`.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, for the view builder.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   *   What gives the content entity storage.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected readonly ResourcesInterface $resources,
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ClassResolverInterface $classResolver,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static(
      $container->get('surface.resources'),
      $container->get('surface.pipeline'),
      $container->get('entity_type.manager'),
      $container->get('class_resolver'),
      $container->get('current_user'),
    );
    $instance->setStringTranslation($container->get('string_translation'));
    return $instance;
  }

  /**
   * Checks access to the preview.
   *
   * @param \Drupal\surface\Staging\Stage $surface_stage
   *   The stage.
   * @param string $node_id
   *   The node's id, or a local id the stage gave a create.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The stage's access AND the node's for the write's kind; never
   *   cached. A node the stage holds no write of, or one deleted since it
   *   was staged, is let through for the page to answer.
   */
  public function access(Stage $surface_stage, string $node_id, AccountInterface $account): AccessResultInterface {
    try {
      $staged = $this->resources->access(StageResource::ID, $this->resources->operation(StageResource::ID, 'update', ['id' => $surface_stage->id]), $account);
    }
    catch (NamesNothingException) {
      return AccessResult::forbidden()->setCacheMaxAge(0);
    }
    if (!$staged->isAllowed()) {
      return AccessResult::forbidden()->addCacheableDependency($staged)->setCacheMaxAge(0);
    }
    $item = $this->item($surface_stage, (int) $node_id, $account);
    if ($item === NULL) {
      return $staged;
    }
    // The access asked is the write's own: a staged delete asks the
    // node's delete access, a staged create the bundle's create access,
    // any other write the node's update access.
    try {
      $context = match ($item['kind']) {
        'create' => $this->resources->operation($item['resource'], 'create'),
        'delete' => $this->resources->operation($item['resource'], 'delete', ['id' => (int) $node_id], $surface_stage->withArguments(['id' => (int) $node_id])),
        default => $this->resources->operation($item['resource'], 'update', ['id' => (int) $node_id], $surface_stage->withArguments(['id' => (int) $node_id])),
      };
    }
    catch (NamesNothingException) {
      // The node was deleted since it was staged: the page answers, the
      // removal page for a staged delete, else not found.
      return $staged;
    }
    // The stage's answer is never cached, nor is what it is ANDed with.
    return $staged->andIf($this->resources->access($item['resource'], $context, $account));
  }

  /**
   * Renders the node as the stage holds it.
   *
   * @param \Drupal\surface\Staging\Stage $surface_stage
   *   The stage.
   * @param string $node_id
   *   The node's id, or a local id the stage gave a create.
   *
   * @return array
   *   A render array.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the stage holds no write of the node.
   */
  public function view(Stage $surface_stage, string $node_id): array {
    $id = (int) $node_id;
    $item = $this->item($surface_stage, $id, $this->currentUser);
    if ($item === NULL) {
      throw new NotFoundHttpException(sprintf('The stage %s holds no write of node %s.', $surface_stage->id, $node_id));
    }
    $stage = $surface_stage->label ?? $surface_stage->id;
    if ($item['kind'] === 'delete') {
      $node = $this->entityTypeManager->getStorage('node')->load($id);
      $label = $node === NULL ? $node_id : (string) $node->label();
      return [
        '#title' => $this->t('Preview: @title (staged in @stage)', ['@title' => $label, '@stage' => $stage]),
        'removed' => [
          '#markup' => '<p>' . $this->t('%title is removed when the stage is applied; until then it stays as it is stored.', ['%title' => $label]) . '</p>',
        ],
        '#cache' => ['max-age' => 0],
      ];
    }
    $through = $surface_stage->withArguments(['id' => $id]);
    $resource = $item['resource'];
    try {
      $context = $this->resources->operation($resource, 'update', ['id' => $id], $through);
    }
    catch (NamesNothingException $e) {
      throw new NotFoundHttpException($e->getMessage(), $e);
    }
    $built = $this->resources->build($resource, $context);
    $values = $this->resources->storage($resource, $context, $built, $this->currentUser, $through)->load($built);
    $storage = $this->classResolver->getInstanceFromDefinition(ContentEntityStorage::class);
    try {
      [$entity, $refused] = $storage->preview($context, $values, $this->currentUser);
    }
    catch (StorageViolationsException $e) {
      throw new NotFoundHttpException(sprintf('The stage %s holds a write of node %s that cannot be built: %s', $surface_stage->id, $node_id, $e->getMessage()), $e);
    }
    $build = [
      '#title' => $this->t('Preview: @title (staged in @stage)', [
        '@title' => (string) $entity->label(),
        '@stage' => $stage,
      ]),
      '#cache' => ['max-age' => 0],
    ];
    if ($refused !== []) {
      $build['refused'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Apply would refuse these staged values:'),
        '#items' => array_map(static fn (Violation $violation): string => ($violation->path === '' ? '' : $violation->path . ': ') . $violation->message, $refused),
        '#attributes' => ['class' => ['messages', 'messages--warning']],
      ];
    }
    // As NodePreviewController: the view mode the node template reads to
    // treat the preview as the node's own page, its title the page's.
    // @phpstan-ignore assign.propertyType
    $entity->preview_view_mode = 'full';
    $view = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId())->view($entity, 'full');
    // Core's node view builder caches nothing of a node in preview; a
    // builder that replaces it may not ask (Canvas's adds its render cache
    // keys back), and a preview cached under the node's keys would be
    // served to whoever views the node. So none is kept, whoever builds.
    unset($view['#cache']['keys']);
    $view['#cache']['max-age'] = 0;
    $build['entity'] = $view;
    return $build;
  }

  /**
   * Finds the write a stage holds of a node, by its id or local id.
   *
   * @param \Drupal\surface\Staging\Stage $stage
   *   The stage.
   * @param int $id
   *   The node's id, or a local id.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the stage's `changes` is run for.
   *
   * @return array{resource: string, kind: string}|null
   *   The resource the node is written through and the write's kind, or
   *   NULL when the stage holds no write of it, or may not be read.
   */
  protected function item(Stage $stage, int $id, AccountInterface $account): ?array {
    try {
      $context = $this->resources->operation(StageResource::ID, 'changes', ['id' => $stage->id]);
    }
    catch (NamesNothingException) {
      return NULL;
    }
    $access = $this->resources->access(StageResource::ID, $context, $account);
    if (!$access->isAllowed()) {
      return NULL;
    }
    $built = $this->resources->build(StageResource::ID, $context);
    $storage = $this->resources->storage(StageResource::ID, $context, $built, $account);
    $result = $this->pipeline->submit($built, [], $storage, access: $access);
    if (!$result->isValid()) {
      return NULL;
    }
    foreach ($this->pipeline->outputs($built, $storage, $result)['items'] ?? [] as $item) {
      $resource = (string) $item['resource'];
      if ($resource !== self::NODE && !str_starts_with($resource, self::NODE . '.')) {
        continue;
      }
      $parameters = array_column((array) $item['parameters'], 'value', 'name');
      if (($parameters['id'] ?? NULL) === (string) $id) {
        return ['resource' => $resource, 'kind' => (string) $item['kind']];
      }
    }
    return NULL;
  }

}
