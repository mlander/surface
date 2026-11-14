<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Form\NarrowsDocumentsInterface;
use Drupal\surface\Pipeline\Accepting;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\TypeMismatchException;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Pipeline\Validating;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\SurfaceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records what the pipeline and the documents make of the hand corpus.
 *
 * One record per row, by the row's name: `<form> | <stored> | <case>`
 * for a case run with nothing stored (`none`) or with what the surface
 * stores (`stored`), and documents beside them. A case's record
 * holds what accept() answers (or what it threw, and the violations a
 * door sends for a refusal), the notes and the secrets a collection item
 * would erase (unmatched()), what validate() answers for the
 * accepted values and for the raw input, for a create when nothing is
 * stored, and what kept() keeps. A document row holds the document:
 * emit() over what is stored, with and without widget hints, emit() over
 * each case's accepted values, each case's narrowed document with its
 * values and what it discarded, emit() over nothing, and emitFull() with
 * and without hints.
 *
 * Every violation is recorded whole: its key, path, code, parameters,
 * whether it is stale and what is stored, and its message as the source
 * string, its arguments and options, and the rendered text, at every
 * depth. Nothing here writes: it never submits, prepares or commits.
 */
final class HandCorpusRecorder {

  /**
   * How a record is written as JSON.
   */
  public const JSON = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

  /**
   * The pipeline's accept verb, for the notes and the door's violations.
   */
  protected readonly Accepting $accepting;

  /**
   * The pipeline's validate verb, for the raw input and a create.
   */
  protected readonly Validating $validating;

  /**
   * Constructs a recorder.
   *
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The document emitter.
   * @param \Drupal\surface\Form\NarrowsDocumentsInterface $narrowing
   *   What narrows a document for an edit in progress.
   */
  public function __construct(
    protected readonly SurfacePipelineInterface $pipeline,
    protected readonly ContractEmitter $emitter,
    protected readonly NarrowsDocumentsInterface $narrowing,
  ) {
    $reflection = new \ReflectionObject($pipeline);
    $accepting = $reflection->getProperty('accepting')->getValue($pipeline);
    $validating = $reflection->getProperty('validating')->getValue($pipeline);
    if (!$accepting instanceof Accepting || !$validating instanceof Validating) {
      throw new \LogicException('The hand corpus reads the Accepting and Validating the pipeline holds.');
    }
    $this->accepting = $accepting;
    $this->validating = $validating;
  }

  /**
   * Records every row of one surface of the corpus.
   *
   * @param string $name
   *   One of HandSurfaces::NAMES.
   *
   * @return \Generator<string, array<string, mixed>>
   *   Each row's record, by the row's name.
   */
  public function rows(string $name): \Generator {
    [$surface, $stored, $cases, $alternates] = HandSurfaces::build($name);
    $forms = ['plain' => [$surface, $stored, FALSE]] + $alternates;
    foreach ($forms as $form => [$each, $held, $stored_only]) {
      $currents = $stored_only ? ['stored' => $held] : ['none' => [], 'stored' => $held];
      foreach ($currents as $current_name => $current) {
        foreach (['nothing' => []] + $cases as $case => $input) {
          yield "$form | $current_name | $case" => $this->values($each, $input, $current, $current_name === 'none');
        }
        yield "$form | $current_name | doc full" => static::guarded(fn (): array => $this->emitter->emit($each, $current, 'hand', 'op')->document);
        yield "$form | $current_name | doc hints" => static::guarded(fn (): array => $this->emitter->emit($each, $current, 'hand', 'op', NULL, TRUE)->document);
        foreach ($cases as $case => $input) {
          yield "$form | $current_name | doc case $case" => static::guarded(fn (): array => $this->emitter->emit($each, $this->pipeline->accept($each, $input, $current), 'hand', 'op', NULL, TRUE)->document);
          yield "$form | $current_name | narrowed $case" => static::guarded(function () use ($each, $current, $input): array {
            $narrowed = $this->narrowing->narrow($each, $current, $input);
            return [
              'document' => $narrowed->contract()->document,
              'values' => $narrowed->values,
              'discarded' => $narrowed->discarded,
            ];
          });
        }
      }
      if (!$stored_only) {
        yield "$form | doc empty" => static::guarded(fn (): array => $this->emitter->emit($each, [], 'hand', 'op')->document);
        yield "$form | doc emitFull" => static::guarded(fn (): array => $this->emitter->emitFull($each, 'hand', 'op', 'Op label')->document);
        yield "$form | doc emitFull hints" => static::guarded(fn (): array => $this->emitter->emitFull($each, 'hand', 'op', 'Op label', TRUE)->document);
      }
    }
  }

  /**
   * Records one case.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $input
   *   What is sent.
   * @param array<string, mixed> $current
   *   What storage holds.
   * @param bool $creates
   *   Whether to record a create as well: nothing is stored.
   *
   * @return array<string, mixed>
   *   The record, by verb.
   */
  protected function values(SurfaceInterface $surface, array $input, array $current, bool $creates): array {
    $record = [];
    $values = NULL;
    try {
      $values = $this->pipeline->accept($surface, $input, $current);
      $record['accept'] = static::dump($values);
    }
    catch (\Throwable $thrown) {
      $record['accept'] = static::thrown($thrown);
      $refused = match (TRUE) {
        $thrown instanceof UnknownKeysException => fn (): iterable => $this->accepting->unknownKeyViolations($thrown),
        $thrown instanceof TypeMismatchException => fn (): iterable => $this->accepting->typeMismatchViolations($thrown),
        $thrown instanceof VariantMismatchException => fn (): iterable => $this->accepting->variantMismatchViolations($thrown),
        default => NULL,
      };
      if ($refused !== NULL) {
        $record['refused'] = static::guarded($refused);
      }
    }
    if ($values !== NULL) {
      $record['notes'] = static::guarded(fn (): iterable => $this->accepting->notTaken());
      $record['unmatched'] = static::guarded(fn (): iterable => $this->accepting->unmatched());
      $record['validate'] = static::guarded(fn (): iterable => $this->validating->validate($surface, $values, $current));
      $record['kept'] = static::guarded(fn (): mixed => $this->pipeline->kept($surface, $values, $current, $input));
    }
    // The validator on the raw input, which accept() would have shaped.
    $record['validate raw'] = static::guarded(fn (): iterable => $this->validating->validate($surface, $input, $current));
    if ($creates) {
      $record['validate creates'] = static::guarded(fn (): iterable => $this->validating->validate($surface, $values ?? $input, [], TRUE));
    }
    return $record;
  }

  /**
   * Gives the digest a fixture holds for a record.
   *
   * @param mixed $record
   *   The record.
   *
   * @return string
   *   Twelve hexadecimal digits.
   */
  public static function digest(mixed $record): string {
    return substr(hash('xxh128', json_encode($record, self::JSON)), 0, 12);
  }

  /**
   * Writes a value as plain data: every object as its class and members.
   *
   * A translatable text is its source string, arguments, options and
   * rendering; a definition is its class and data type; a map the
   * document holds as an object is `@object`; any other object is its
   * class, each member but a service (a set of violations its refusals
   * and its stale values alike), and its rendering where it has one.
   *
   * @param mixed $value
   *   The value.
   * @param int $depth
   *   How deep the walk is.
   *
   * @return mixed
   *   The plain data.
   */
  public static function dump(mixed $value, int $depth = 0): mixed {
    if ($depth > 12) {
      return '@deeper';
    }
    if (is_array($value)) {
      $plain = [];
      foreach ($value as $key => $member) {
        $plain[$key] = static::dump($member, $depth + 1);
      }
      return $plain;
    }
    if ($value instanceof \stdClass) {
      return ['@object' => static::dump(get_object_vars($value), $depth + 1)];
    }
    if ($value instanceof TranslatableMarkup) {
      return [
        '@source' => $value->getUntranslatedString(),
        'arguments' => static::dump($value->getArguments(), $depth + 1),
        'options' => static::dump($value->getOptions(), $depth + 1),
        'text' => (string) $value,
      ];
    }
    if ($value instanceof \Closure) {
      return '@closure';
    }
    if ($value instanceof DataDefinitionInterface) {
      return ['@class' => get_class($value), 'type' => $value->getDataType()];
    }
    if (is_object($value)) {
      $plain = ['@class' => get_class($value)];
      foreach ((array) $value as $key => $member) {
        $key = (string) preg_replace('/^\0[^\0]*\0/', '', (string) $key);
        $service = $member instanceof TranslationInterface || $member instanceof ContainerInterface;
        if ($service || in_array($key, ['stringTranslation', 'translationManager'], TRUE)) {
          continue;
        }
        $plain[$key] = static::dump($member, $depth + 1);
      }
      if ($value instanceof \Stringable) {
        $plain['text'] = static::guarded(static fn (): string => (string) $value);
      }
      return $plain;
    }
    if (is_float($value) && !is_finite($value)) {
      return 'float ' . var_export($value, TRUE);
    }
    return $value;
  }

  /**
   * Runs a verb, recording what it answers or what it threw.
   *
   * @param \Closure(): mixed $verb
   *   The verb.
   *
   * @return mixed
   *   What it answers, as plain data, or what it threw.
   */
  protected static function guarded(\Closure $verb): mixed {
    try {
      return static::dump($verb());
    }
    catch (\Throwable $thrown) {
      return static::thrown($thrown);
    }
  }

  /**
   * Records what was thrown, with no file path or line in its message.
   *
   * @param \Throwable $thrown
   *   What was thrown.
   *
   * @return array<string, string>
   *   Its class and message.
   */
  protected static function thrown(\Throwable $thrown): array {
    $message = (string) preg_replace(['#, called in \S+ on line \d+#', '# in \S+\.php(:\d+| on line \d+)#'], '', $thrown->getMessage());
    return ['thrown' => get_class($thrown), 'message' => $message];
  }

}
