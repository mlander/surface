<?php

declare(strict_types=1);

namespace Drupal\surface\Refinement;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\Emission;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Surface;
use PortableSchema\Builder\Schema;

/**
 * What the watched-value check computed in this request, kept.
 *
 * The check holds a value to its key's schema as the one output of a data
 * surface (Surface::holding()), by the dialect's validator. This keeps two
 * results of that computation for the rest of the request, by a hash of
 * the schema, and asks the validator again for anything else:
 *
 * - The holding data surface, so its outputs document is built once
 *   (OutputsDocument keeps it for the surface). Kept only when the
 *   document's cacheability names no cache context and no maximum age,
 *   since an option list read live, or for one account, may change inside
 *   the request.
 * - The answer, by the hash of the value as the check writes it
 *   (WatchedValueCheck::written()), for a schema whose every check is one
 *   PURE_CHECKS names, with no `callback`: such a check reads only the
 *   value and its own options. A schema that names any other check (an
 *   entity that must exist, an extension that must be installed) is asked
 *   every time, as is a value that holds an object, or a schema that
 *   holds one other than a plain object or a markup.
 *
 * Both are dropped on every cache tag invalidation, where the validator
 * drops the documents it emitted (forget(),
 * ResourceRegistryInvalidator). At most SCHEMAS holding data surfaces and
 * ANSWERS answers are kept, the oldest let go first.
 *
 * Nothing here decides whether a value fits: a kept answer is what the
 * validator answered for the same schema and the same written value.
 *
 * Decision: see
 * docs/decisions.md#a-watched-value-is-asked-once-per-request.
 *
 * @internal
 */
final class WatchedValueAnswers {

  use DependencySerializationTrait {
    __sleep as private sleepServices;
    __wakeup as private wakeServices;
  }

  /**
   * The most holding data surfaces kept.
   */
  public const SCHEMAS = 64;

  /**
   * The most answers kept.
   */
  public const ANSWERS = 1024;

  /**
   * The checks whose answer reads only the value and the check's options.
   *
   * Each is Symfony's Choice validator over the choices the options list;
   * a `callback` option, which reads anything, makes it impure.
   */
  public const PURE_CHECKS = ['drupal:Choice', 'drupal:LabeledChoice'];

  /**
   * The hash of each schema seen, FALSE for one that cannot be hashed.
   *
   * @var \WeakMap<\PortableSchema\Builder\Schema, array{0: string, 1: bool}|false>
   */
  protected \WeakMap $hashes;

  /**
   * The holding data surfaces, by the schema's hash.
   *
   * @var array<string, array{0: \Drupal\surface\SurfaceInterface|null, 1: bool}>
   *   The data surface, NULL for one whose document may change inside the
   *   request, and whether its answers may be kept.
   */
  protected array $holding = [];

  /**
   * The answers, by the schema's hash and the written value's hash.
   *
   * @var array<string, bool>
   */
  protected array $answers = [];

  /**
   * Constructs a WatchedValueAnswers.
   *
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter, whose writer of the outputs document says what a
   *   holding data surface's document depends on.
   * @param int $schemas
   *   The most holding data surfaces kept.
   * @param int $kept
   *   The most answers kept. With both 0 nothing is kept or hashed, and
   *   every answer is asked as it was before anything was kept.
   */
  public function __construct(
    protected readonly ContractEmitter $emitter,
    protected readonly int $schemas = self::SCHEMAS,
    protected readonly int $kept = self::ANSWERS,
  ) {
    $this->hashes = new \WeakMap();
  }

  /**
   * Answers for a schema and a written value, computing it once.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The watched place's schema.
   * @param mixed $written
   *   The value, as the check writes it.
   * @param \Closure $ask
   *   Takes the holding data surface and answers; it is called whenever
   *   no kept answer is used.
   *
   * @return bool
   *   What $ask answered for the same schema and value.
   */
  public function answer(Schema $schema, mixed $written, \Closure $ask): bool {
    $hashed = $this->schemas === 0 && $this->kept === 0 ? FALSE : $this->hashOf($schema);
    if ($hashed === FALSE) {
      return $ask(Surface::holding($schema));
    }
    [$hash, $pure] = $hashed;
    [$holding, $keeps] = $this->holding[$hash] ?? $this->held($schema, $hash, $pure);
    $holding ??= Surface::holding($schema);
    $value = $keeps ? self::valueHash($written) : NULL;
    if ($value === NULL) {
      return $ask($holding);
    }
    $key = $hash . ':' . $value;
    if (isset($this->answers[$key])) {
      return $this->answers[$key];
    }
    $answer = $ask($holding);
    if ($this->kept > 0) {
      if (count($this->answers) >= $this->kept) {
        array_shift($this->answers);
      }
      $this->answers[$key] = $answer;
    }
    return $answer;
  }

  /**
   * Forgets every holding data surface and answer kept.
   *
   * What a document or a check reads (an option list, the site) may have
   * changed.
   *
   * @see \Drupal\surface\ResourceBuild\ResourceRegistryInvalidator
   */
  public function forget(): void {
    $this->hashes = new \WeakMap();
    $this->holding = [];
    $this->answers = [];
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep(): array {
    return array_values(array_diff($this->sleepServices(), ['hashes', 'holding', 'answers']));
  }

  /**
   * {@inheritdoc}
   */
  public function __wakeup(): void {
    $this->wakeServices();
    $this->forget();
  }

  /**
   * Makes the holding data surface of a schema, keeping it where it may be.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   * @param string $hash
   *   Its hash.
   * @param bool $pure
   *   Whether every check the schema names is a pure one.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: bool}
   *   The data surface, and whether its answers may be kept.
   */
  private function held(Schema $schema, string $hash, bool $pure): array {
    $holding = Surface::holding($schema);
    $emission = Emission::detached();
    $document = $this->emitter->outputsDocument()->of($holding, $emission);
    $cacheability = $emission->cacheability;
    // A document that may change inside the request is built each time,
    // and nothing is answered from what was kept for it.
    $live = $cacheability->getCacheMaxAge() !== Cache::PERMANENT || $cacheability->getCacheContexts() !== [];
    $held = $live ? [NULL, FALSE] : [$holding, $pure && ($document === NULL || self::pure(Schemas::arrayed($document)))];
    if ($this->schemas > 0) {
      if (count($this->holding) >= $this->schemas) {
        array_shift($this->holding);
      }
      $this->holding[$hash] = $held;
    }
    return [$holding, $held[1]];
  }

  /**
   * Hashes a schema, once per schema object.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The schema.
   *
   * @return array{0: string, 1: bool}|false
   *   The hash of the schema as written, with whether it is required where
   *   it stands, and whether every check it names is pure; FALSE for a
   *   schema that holds an object hashable() refuses.
   */
  private function hashOf(Schema $schema): array|false {
    if (isset($this->hashes[$schema])) {
      return $this->hashes[$schema];
    }
    $hashed = FALSE;
    if (self::hashable($schema)) {
      $written = $schema->toDocument();
      $hashed = [hash('xxh128', serialize([$written, $schema->isRequired()])), self::pure($written)];
    }
    return $this->hashes[$schema] = $hashed;
  }

  /**
   * Answers whether a schema is all in what it writes.
   *
   * Schema::toDocument() writes a string of a translatable text and keeps
   * any other object as it is, which serialize() would not hash as the
   * schema holds it: a schema that holds one is not kept.
   *
   * @param mixed $value
   *   The schema, or a keyword's value in it.
   *
   * @return bool
   *   TRUE when every value it holds is a schema, a scalar, NULL, an
   *   array, a plain object or a markup.
   */
  private static function hashable(mixed $value): bool {
    if ($value instanceof Schema) {
      $value = $value->all();
    }
    if ($value instanceof \stdClass) {
      $value = (array) $value;
    }
    if (is_array($value)) {
      foreach ($value as $one) {
        if (!self::hashable($one)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $value === NULL || is_scalar($value) || $value instanceof MarkupInterface;
  }

  /**
   * Hashes a written value.
   *
   * @param mixed $value
   *   The value, as the check writes it.
   *
   * @return string|null
   *   Its hash; NULL for a value that holds an object or a resource.
   */
  private static function valueHash(mixed $value): ?string {
    return self::plain($value) ? hash('xxh128', serialize($value)) : NULL;
  }

  /**
   * Answers whether a value holds only scalars, NULL and arrays.
   *
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE when serialize() writes all it holds, and nothing by identity.
   */
  private static function plain(mixed $value): bool {
    if (is_array($value)) {
      foreach ($value as $one) {
        if (!self::plain($one)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $value === NULL || is_scalar($value);
  }

  /**
   * Answers whether every check a schema names is a pure one.
   *
   * Every member is read, at every depth, the checks' own options and
   * `x-surface` included.
   *
   * @param mixed $schema
   *   The schema, written or as arrays, or a part of it.
   *
   * @return bool
   *   FALSE where a `portable-check` names a check PURE_CHECKS does not,
   *   gives one a `callback`, or is not a map.
   */
  private static function pure(mixed $schema): bool {
    if ($schema instanceof \stdClass) {
      $schema = (array) $schema;
    }
    if (!is_array($schema)) {
      return TRUE;
    }
    foreach ($schema as $keyword => $member) {
      if ($keyword === PortableKeywords::CHECK) {
        $checks = $member instanceof \stdClass ? (array) $member : $member;
        if (!is_array($checks)) {
          return FALSE;
        }
        foreach ($checks as $name => $options) {
          $options = $options instanceof \stdClass ? (array) $options : $options;
          if (!in_array($name, self::PURE_CHECKS, TRUE) || !is_array($options) || array_key_exists('callback', $options)) {
            return FALSE;
          }
        }
      }
      if (!self::pure($member)) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
