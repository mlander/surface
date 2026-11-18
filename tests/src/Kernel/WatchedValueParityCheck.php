<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\SchemaFacts;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Refinement\WatchedValueAnswers;
use Drupal\surface\Refinement\WatchedValueCheck;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use PortableSchema\Builder\Schema;

/**
 * Asks every watched-value check twice: as kept, and with nothing kept.
 *
 * Decorates `surface.watched_value_check` in WatchedValueAnswersParityTest.
 * The kept answer is what the data surface is handed; the other is asked
 * of a check whose WatchedValueAnswers keeps nothing, as every check was
 * asked before anything was kept. Each pair that differs is recorded.
 */
final class WatchedValueParityCheck implements WatchedValueCheckInterface {

  use DependencySerializationTrait;

  /**
   * What was asked, counted.
   *
   * @var array<string, int>
   */
  public static array $counts = [];

  /**
   * Each check whose two answers differ.
   *
   * @var array<int, array>
   */
  public static array $differences = [];

  /**
   * Constructs a WatchedValueParityCheck.
   *
   * @param \Drupal\surface\Refinement\WatchedValueCheckInterface $inner
   *   The module's check, keeping what it may.
   * @param \Drupal\surface\Refinement\WatchedValueAnswers $answers
   *   What the module's check keeps.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, for the check that keeps nothing.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter, for the check that keeps nothing.
   */
  public function __construct(
    protected WatchedValueCheckInterface $inner,
    protected WatchedValueAnswers $answers,
    protected SurfacePipelineInterface $pipeline,
    protected ContractEmitter $emitter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function admits(Schema $schema, mixed $value): bool {
    $kept = self::keptAnswer($this->answers, $schema, $value);
    $fresh = new WatchedValueCheck($this->pipeline, new WatchedValueAnswers($this->emitter, 0, 0));
    $said = [];
    foreach (['kept' => $this->inner, 'asked' => $fresh] as $way => $check) {
      try {
        $said[$way] = $check->admits($schema, $value);
      }
      catch (\Throwable $thrown) {
        $said[$way] = get_class($thrown) . ': ' . $thrown->getMessage();
      }
    }
    self::count('checks');
    self::count($kept === NULL ? 'asked of the validator' : 'answered from kept');
    if ($said['asked'] === FALSE) {
      self::count('refused');
      if ($kept === FALSE) {
        self::count('refused, answered from kept');
      }
    }
    if ($value === TRUE && SchemaFacts::dataType($schema) === 'string') {
      self::count($said['asked'] === TRUE ? 'TRUE at a string key, admitted' : 'TRUE at a string key, refused');
    }
    if ($said['kept'] !== $said['asked'] || ($kept !== NULL && $kept !== $said['asked'])) {
      self::$differences[] = ['schema' => $schema->toDocument(), 'value' => $value, 'kept answer' => $kept] + $said;
    }
    if (is_string($said['kept'])) {
      throw new \RuntimeException($said['kept']);
    }
    return $said['kept'];
  }

  /**
   * Resets what was recorded.
   */
  public static function reset(): void {
    self::$counts = [];
    self::$differences = [];
  }

  /**
   * Counts one thing.
   *
   * @param string $what
   *   What.
   */
  protected static function count(string $what): void {
    self::$counts[$what] = (self::$counts[$what] ?? 0) + 1;
  }

  /**
   * Reads the answer kept for a schema and a value, before it is asked.
   *
   * @param \Drupal\surface\Refinement\WatchedValueAnswers $answers
   *   The answers kept.
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param mixed $value
   *   The value, as accepted.
   *
   * @return bool|null
   *   The kept answer; NULL where none is kept.
   */
  protected static function keptAnswer(WatchedValueAnswers $answers, Schema $schema, mixed $value): ?bool {
    $hashed = (new \ReflectionMethod(WatchedValueAnswers::class, 'hashOf'))->invoke($answers, $schema);
    if ($hashed === FALSE) {
      return NULL;
    }
    $written = (new \ReflectionMethod(WatchedValueCheck::class, 'written'))->invoke(NULL, $schema, $value);
    $hash = (new \ReflectionMethod(WatchedValueAnswers::class, 'valueHash'))->invoke(NULL, $written);
    $kept = (new \ReflectionProperty(WatchedValueAnswers::class, 'answers'))->getValue($answers);
    return $hash === NULL ? NULL : $kept[$hashed[0] . ':' . $hash] ?? NULL;
  }

}
