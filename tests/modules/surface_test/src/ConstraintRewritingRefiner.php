<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\NodeDefinition;
use Drupal\surface\Refinement\AnswerFromDefinition;
use Drupal\surface\Refinement\Narrowing;
use Drupal\surface\Surface;
use Drupal\surface\SurfaceRefinerInterface;
use PortableSchema\Builder\Schema;

/**
 * A refiner that does whatever it was constructed to do.
 *
 * One class standing in for every well-behaved and badly behaved
 * refiner the narrowing check has an opinion about, so the cases can be
 * written as a table instead of as a dozen classes that differ by one
 * line.
 *
 * It rewrites a copy of the key's definition, held to the narrowing check
 * as an old-shape #[RefinesInput] method is, and what it made is read as
 * an answer (AnswerFromDefinition).
 */
final class ConstraintRewritingRefiner implements SurfaceRefinerInterface {

  /**
   * Constructs a ConstraintRewritingRefiner.
   *
   * @param array $constraints
   *   Constraints to write onto the definition, keyed by plugin ID. An
   *   entry whose options are NULL removes that constraint.
   * @param bool|null $required
   *   Whether to set the required flag, or NULL to leave it.
   * @param string|null $dataType
   *   A data type to answer with a fresh definition of, or NULL to
   *   refine the one handed over.
   */
  public function __construct(
    protected readonly array $constraints = [],
    protected readonly ?bool $required = NULL,
    protected readonly ?string $dataType = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values, string $who): array {
    $definition = NodeDefinition::of($key);
    $refined = $this->rewritten(Surface::deepClone($definition));
    Narrowing::assertNarrows($name, $who, $definition, $refined);
    Surface::carryMetadata($definition, $refined);
    return [AnswerFromDefinition::between($key, $refined, $definition)];
  }

  /**
   * Rewrites a copy of the key's definition as constructed.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The copy.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   What the refiner makes of it.
   */
  protected function rewritten(DataDefinitionInterface $definition): DataDefinitionInterface {
    if ($this->dataType !== NULL) {
      return DataDefinition::create($this->dataType);
    }
    if (!$definition instanceof DataDefinition) {
      return $definition;
    }
    $constraints = $definition->getConstraints();
    foreach ($this->constraints as $constraint => $options) {
      if ($options === NULL) {
        unset($constraints[$constraint]);
        continue;
      }
      $constraints[$constraint] = $options;
    }
    $definition->setConstraints($constraints);
    if ($this->required !== NULL) {
      $definition->setRequired($this->required);
    }
    return $definition;
  }

}
