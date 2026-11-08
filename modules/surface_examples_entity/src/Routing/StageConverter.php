<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Routing;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Resource\StageResource;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Staging\Stage;
use Drupal\surface\Staging\StageStoreInterface;
use Symfony\Component\Routing\Route;

/**
 * Converts a stage's id in a path to the stage, for its owner alone.
 *
 * A route names it as a parameter of type `surface_stage`. The id is read
 * through the stages (`surface.stages`), and the stage's own `update`
 * access is asked for the current user, which only the owner holds: an
 * id that names no stage, or another account's, converts to nothing, so
 * the page is not found and says nothing of whose the stage is.
 */
final class StageConverter implements ParamConverterInterface {

  /**
   * The parameter type this converter answers.
   */
  public const TYPE = 'surface_stage';

  /**
   * Constructs a StageConverter.
   *
   * @param \Drupal\surface\Staging\StageStoreInterface $stages
   *   The stages.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, which answers the stage's access.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected readonly StageStoreInterface $stages,
    protected readonly ResourcesInterface $resources,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults): ?Stage {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    try {
      $stage = $this->stages->named($value);
      $context = $this->resources->operation(StageResource::ID, 'update', ['id' => $value]);
    }
    catch (NamesNothingException) {
      return NULL;
    }
    return $this->resources->access(StageResource::ID, $context, $this->currentUser)->isAllowed() ? $stage : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    return ($definition['type'] ?? NULL) === self::TYPE;
  }

}
