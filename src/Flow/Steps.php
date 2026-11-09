<?php

declare(strict_types=1);

namespace Drupal\surface\Flow;

/**
 * Collects the steps a flow class declares.
 *
 * @internal
 */
final class Steps implements StepsInterface {

  /**
   * The steps, by id, in the order they were added.
   *
   * @var array<string, \Drupal\surface\Flow\Step>
   */
  protected array $steps = [];

  /**
   * {@inheritdoc}
   */
  public function step(string $id, string $resource, string $operation, array $arguments = []): StepInterface {
    if ($id === '' || $resource === '' || $operation === '') {
      throw new \InvalidArgumentException(sprintf('A flow\'s step names its id, resource and operation, each a non-empty string; step %d does not.', count($this->steps) + 1));
    }
    if ($arguments !== [] && array_is_list($arguments)) {
      throw new \InvalidArgumentException(sprintf('The step "%s" of a flow gives its arguments as a list: they are the operation\'s arguments by name, as [\'id\' => \'row\'].', $id));
    }
    if (isset($this->steps[$id])) {
      throw new \InvalidArgumentException(sprintf('A flow lists the step "%s" twice: each step has an id of its own.', $id));
    }
    return $this->steps[$id] = new Step($id, $resource, $operation, $arguments);
  }

  /**
   * Lists the steps declared.
   *
   * @return list<\Drupal\surface\Flow\Step>
   *   The steps, in order.
   */
  public function all(): array {
    return array_values($this->steps);
  }

}
