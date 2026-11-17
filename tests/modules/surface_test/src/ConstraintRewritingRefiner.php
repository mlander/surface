<?php

declare(strict_types=1);

namespace Drupal\surface_test;

use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Refinement\ChoiceSet;
use Drupal\surface\SurfaceRefinerInterface;
use PortableSchema\Builder\Schema;

/**
 * A refiner that answers whatever it was constructed to answer.
 *
 * One class standing in for every well-behaved and badly behaved
 * refiner the applier of answers has an opinion about, so the cases can
 * be written as a table instead of as a dozen classes that differ by one
 * line.
 *
 * Before refiners answered with schemas, it wrote its constraints over
 * the key's definition, removing one given NULL options and turning the
 * required flag off when told; no answer says either, so neither is
 * left. Values given for the key's list constraint are answered as
 * `options()`, the list in its own order; any other constraint as its
 * check.
 */
final class ConstraintRewritingRefiner implements SurfaceRefinerInterface {

  /**
   * Constructs a ConstraintRewritingRefiner.
   *
   * @param array $constraints
   *   Constraints to answer with, keyed by plugin ID.
   * @param bool $required
   *   Whether to answer that the key is required.
   * @param string|null $type
   *   A JSON type to answer with, or NULL for none.
   */
  public function __construct(
    protected readonly array $constraints = [],
    protected readonly bool $required = FALSE,
    protected readonly ?string $type = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function refine(string $name, Schema $key, array $values): array {
    $answer = $this->type === NULL ? Schema::any() : Schema::any()->with('type', $this->type);
    $list = ChoiceSet::of($key)?->constraint;
    $checks = [];
    foreach ($this->constraints as $constraint => $options) {
      if ($constraint === $list && array_key_exists('choices', $options)) {
        $offered = ChoiceSet::fromOptions($constraint, $options)->values;
        $answer = $answer->with('oneOf', array_map(static fn (mixed $value): Schema => Schema::const($value), $offered));
        continue;
      }
      $checks[PortableKeywords::PROVIDER . $constraint] = $options;
    }
    if ($checks !== []) {
      $answer = $answer->with(PortableKeywords::CHECK, $checks);
    }
    return [$this->required ? $answer->required() : $answer];
  }

}
