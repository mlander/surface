<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Values;

use Drupal\surface\Pipeline\Accepting;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\Pipeline\TypeMismatchException;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Pipeline\Validating;
use Drupal\surface\Pipeline\VariantMismatchException;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\InputDefinitions;

/**
 * Records what the pipeline makes of one input, for a value baseline.
 *
 * What record() records for one case, in this order:
 * - `accept`: what accept() answers, every value with its PHP type ('1'
 *   and 1 differ, and so does the order of a map's keys). When it
 *   throws, the exception's class, message, path and what it names.
 * - `violations`: for a refusal accept() throws as an unknown key, a type
 *   mismatch or a variant mismatch, the violations a door sends for it
 *   (Accepting::unknownKeyViolations(), typeMismatchViolations() and
 *   variantMismatchViolations()).
 * - `outbound stored`: what a door serves of the stored values, as a
 *   read serves them.
 * - `notes` and `unmatched`: the notes submit() hands on, and the secrets
 *   it refuses with, as that accept() collected them.
 * - For an accepted case only: `kept` (what would be written, the case's
 *   input as what was sent), `document` (the validator's input),
 *   `outbound` (what a door hands a caller) and `validate` (each
 *   violation's key, path, code, parameters and message).
 *
 * record() with $creates records a write that creates the thing, as
 * submit() runs one over a storage that creates, with nothing stored:
 * accept() and validate() are told it creates, and `kept` is what
 * submit() writes for a create, kept() with no input. It also records
 * `kept, nothing stored`: kept() with the input, as a write that does not
 * create runs it over a storage that holds nothing. It records no
 * `outbound stored`.
 *
 * `kept` and `outbound` are recorded as AS_ACCEPTED when they are what
 * accept() answered, value for value. `document` is recorded as
 * AS_ACCEPTED when flat() makes the same tokens of it as of the accepted
 * values, and whole otherwise; a map the document holds as an object is
 * a token of its own, so a document of objects is recorded whole.
 * A violation is one token: its record as a JSON list, its double quotes
 * written as apostrophes, under its key, path and code.
 * Per data surface, secrets() records Outbound::secrets() with no
 * closure and with the one the tool door passes.
 *
 * A text is recorded as its rendering in the current language and as its
 * class, so a message made lazy where it was a string, or the reverse,
 * is seen. A value that JSON cannot write throws, and is never recorded
 * in part. Nothing here writes: it never calls submit(), prepare(),
 * commit() or outputs(), and never touches storage.
 *
 * The verbs accept() and validate() read what they collected from the
 * pipeline's own Accepting and Validating, which accepting() and
 * validating() find; each throws, naming the pipeline's class, when the
 * pipeline holds none.
 *
 * flat() turns a record into one token per place, so two records are
 * compared place by place and a fixture holds only where a case differs
 * from the base case.
 */
final class ValueRecorder {

  /**
   * What a verb is recorded as when it answers what accept() answered.
   */
  public const AS_ACCEPTED = '= accept';

  /**
   * How a value is written as JSON here.
   */
  protected const JSON = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

  /**
   * What validate() answered, by the data surface, values and stored values.
   *
   * The verb validate() is a function of the three, and many cases accept
   * to the same values, so each is asked once. forget() starts it again.
   *
   * @var array<string, mixed>
   */
  protected array $validated = [];

  /**
   * The pipeline's accept verb.
   */
  protected readonly Accepting $accepting;

  /**
   * The pipeline's validate verb.
   */
  protected readonly Validating $validating;

  /**
   * Constructs a recorder for a pipeline.
   *
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline whose verbs are recorded.
   *
   * @throws \LogicException
   *   When the pipeline holds no Accepting or no Validating.
   */
  public function __construct(
    protected readonly SurfacePipelineInterface $pipeline,
  ) {
    $this->accepting = static::held($pipeline, 'accepting', Accepting::class);
    $this->validating = static::held($pipeline, 'validating', Validating::class);
  }

  /**
   * Forgets what validate() answered, before another data surface.
   */
  public function forget(): void {
    $this->validated = [];
  }

  /**
   * Records one case.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array<string, mixed> $input
   *   What is sent.
   * @param array<string, mixed> $current
   *   What storage holds; ignored for a create, over which nothing is.
   * @param bool $creates
   *   TRUE to record a write that creates the thing.
   *
   * @return array<string, mixed>
   *   The record, by verb.
   */
  public function record(SurfaceInterface $surface, array $input, array $current, bool $creates = FALSE): array {
    $current = $creates ? [] : $current;
    $record = [];
    $stored = $creates ? NULL : static::guarded(fn (): mixed => $this->pipeline->outbound($surface, $current));
    try {
      $values = $creates ? $this->accepting->accept($surface, $input, [], TRUE) : $this->pipeline->accept($surface, $input, $current);
    }
    catch (\Throwable $thrown) {
      $record['accept'] = static::thrown($thrown);
      $refused = match (TRUE) {
        $thrown instanceof UnknownKeysException => fn (): mixed => static::violations($this->accepting->unknownKeyViolations($thrown)),
        $thrown instanceof TypeMismatchException => fn (): mixed => static::violations($this->accepting->typeMismatchViolations($thrown)),
        $thrown instanceof VariantMismatchException => fn (): mixed => static::violations($this->accepting->variantMismatchViolations($thrown)),
        default => NULL,
      };
      if ($refused !== NULL) {
        $record['violations'] = static::guarded($refused);
      }
      return $creates ? $record : $record + ['outbound stored' => $stored];
    }
    $record['accept'] = $values;
    $record += $creates ? [] : ['outbound stored' => $stored];
    $record['notes'] = static::violations($this->accepting->notTaken());
    $record['unmatched'] = static::violations($this->accepting->unmatched());
    $kept = static::guarded(fn (): mixed => $this->pipeline->kept($surface, $values, $current, $creates ? NULL : $input));
    $record['kept'] = $kept === $values ? self::AS_ACCEPTED : $kept;
    if ($creates) {
      $over = static::guarded(fn (): mixed => $this->pipeline->kept($surface, $values, [], $input));
      $record['kept, nothing stored'] = $over === $values ? self::AS_ACCEPTED : $over;
    }
    $document = static::guarded(fn (): mixed => $this->pipeline->document($surface, $values));
    $record['document'] = static::flat($document) === static::flat($values) ? self::AS_ACCEPTED : $document;
    $outbound = static::guarded(fn (): mixed => $this->pipeline->outbound($surface, $values));
    $record['outbound'] = $outbound === $values ? self::AS_ACCEPTED : $outbound;
    $asked = hash('xxh128', serialize([spl_object_id($surface), $values, $current, $creates]));
    $record['validate'] = $this->validated[$asked] ??= static::guarded(fn (): mixed => static::violations($this->validating->validate($surface, $values, $current, $creates)));
    return $record;
  }

  /**
   * Records a data surface's secrets, as the pipeline and the tool find them.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param \Drupal\surface\Tool\InputDefinitions $tool
   *   The tool door's input definitions, whose closure asks only the
   *   variants a listing states.
   *
   * @return array<string, mixed>
   *   Outbound::secrets() with no closure (`pipeline`) and with the tool
   *   door's (`tool`).
   */
  public static function secrets(SurfaceInterface $surface, InputDefinitions $tool): array {
    return [
      'pipeline' => static::guarded(static fn (): mixed => Outbound::secrets($surface)),
      'tool' => static::guarded(static fn (): mixed => $tool->secrets($surface)),
    ];
  }

  /**
   * Turns a record into one token per place, in record order.
   *
   * A string is `t` and the string. Any other scalar is `s` and its JSON,
   * a float with its zero fraction. A list is `l` and its length. A PHP
   * map is `a` and its keys in order. An object of no other kind (a
   * document's map) is `o` and its keys. Any other object is `x`, its
   * class and its rendering. A float that is not finite is `f` and its
   * PHP spelling. Each member below is a place of its own, under the JSON
   * pointer of its key.
   *
   * @param mixed $value
   *   The record, or a part of it.
   * @param string $pointer
   *   Its place.
   * @param array<string, string> $flat
   *   The tokens so far; gains these.
   *
   * @return array<string, string>
   *   The tokens, by place.
   *
   * @throws \JsonException
   *   When a value cannot be written as JSON.
   */
  public static function flat(mixed $value, string $pointer = '', array $flat = []): array {
    $members = [];
    if ($value instanceof \stdClass) {
      $members = get_object_vars($value);
      $flat[$pointer] = 'o' . json_encode(array_map('strval', array_keys($members)), self::JSON);
    }
    elseif (is_array($value)) {
      $members = $value;
      $flat[$pointer] = array_is_list($value) ? 'l' . count($value) : 'a' . json_encode(array_keys($value), self::JSON);
    }
    elseif (is_object($value)) {
      $flat[$pointer] = 'x' . get_class($value) . ($value instanceof \Stringable ? ':' . $value : '');
    }
    elseif (is_float($value) && !is_finite($value)) {
      $flat[$pointer] = 'f' . var_export($value, TRUE);
    }
    elseif (is_string($value)) {
      $flat[$pointer] = 't' . $value;
    }
    else {
      $flat[$pointer] = 's' . json_encode($value, self::JSON);
    }
    foreach ($members as $key => $member) {
      $flat = static::flat($member, $pointer . '/' . strtr((string) $key, ['~' => '~0', '/' => '~1']), $flat);
    }
    return $flat;
  }

  /**
   * Records violations, each by what it is about.
   *
   * Keyed by the violation's key, path and code (and a count after a
   * second of the same), in the order said, so a case that adds one
   * violation differs from the base case there alone.
   *
   * @param iterable<\Drupal\surface\Pipeline\Violation> $violations
   *   The violations.
   *
   * @return array<string, string>
   *   Each violation's record, as JSON whose double quotes are written as
   *   apostrophes.
   *
   * @throws \JsonException
   *   When a violation cannot be written as JSON.
   */
  protected static function violations(iterable $violations): array {
    $said = [];
    foreach ($violations as $violation) {
      $id = trim("$violation->key $violation->path $violation->code");
      for ($n = 2, $at = $id; isset($said[$at]); $n++) {
        $at = "$id #$n";
      }
      // JSON with every apostrophe escaped and its double quotes written as
      // apostrophes, which reads back exactly and is not escaped again
      // where the token is written into a fixture.
      $said[$at] = strtr(json_encode(static::plain(static::violation($violation)), self::JSON | JSON_HEX_APOS), ['"' => "'"]);
    }
    return $said;
  }

  /**
   * Records a violation, less what its key in violations() says.
   *
   * @param \Drupal\surface\Pipeline\Violation $violation
   *   The violation.
   *
   * @return list<mixed>
   *   Its parameters, its message, the short name of the message's class
   *   ('string' for a string), and, for a stale violation or one that
   *   carries what is stored, whether it is stale and what is stored.
   */
  protected static function violation(Violation $violation): array {
    $class = is_object($violation->message) ? get_class($violation->message) : 'string';
    $record = [
      $violation->params,
      (string) $violation->message,
      substr($class, (int) strrpos('\\' . $class, '\\')),
    ];
    return $violation->stale || $violation->stored !== NULL ? [...$record, $violation->stale, $violation->stored] : $record;
  }

  /**
   * Writes every object in a value as its class and rendering.
   *
   * @param mixed $value
   *   The value.
   *
   * @return mixed
   *   The value, each object a string naming its class and, when it is
   *   one, its text.
   */
  protected static function plain(mixed $value): mixed {
    if (is_array($value)) {
      return array_map(static::plain(...), $value);
    }
    if (is_object($value)) {
      return get_class($value) . ($value instanceof \Stringable ? ': ' . $value : '');
    }
    return $value;
  }

  /**
   * Records what a verb threw.
   *
   * @param \Throwable $thrown
   *   What it threw.
   *
   * @return array<string, mixed>
   *   Its class and message, and the path and names a pipeline refusal
   *   carries.
   */
  protected static function thrown(\Throwable $thrown): array {
    $record = ['thrown' => get_class($thrown), 'message' => $thrown->getMessage()];
    if ($thrown instanceof UnknownKeysException) {
      $record += ['path' => $thrown->getPath(), 'keys' => $thrown->getKeys(), 'declared' => $thrown->getDeclared()];
    }
    elseif ($thrown instanceof TypeMismatchException) {
      $record += [
        'path' => $thrown->getPath(),
        'expected' => $thrown->getExpected(),
        'actual' => $thrown->getActual(),
        'nullable' => $thrown->isNullable(),
      ];
    }
    elseif ($thrown instanceof VariantMismatchException) {
      $record += [
        'path' => $thrown->getPath(),
        'by' => $thrown->getBy(),
        'chosen' => $thrown->getChosen(),
        'owners' => $thrown->getOwners(),
      ];
    }
    return $record;
  }

  /**
   * Runs a verb, recording what it throws in place of what it answers.
   *
   * @param \Closure(): mixed $verb
   *   The verb.
   *
   * @return mixed
   *   What it answers, or the record of what it threw.
   *
   * @throws \JsonException
   *   When the recorder could not write what the verb answered as JSON:
   *   a fault of the record, not an answer of the verb.
   */
  protected static function guarded(\Closure $verb): mixed {
    try {
      return $verb();
    }
    catch (\JsonException $thrown) {
      throw $thrown;
    }
    catch (\Throwable $thrown) {
      return static::thrown($thrown);
    }
  }

  /**
   * Gets one verb a pipeline holds, to read what it collected.
   *
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline.
   * @param string $property
   *   The property that holds the verb.
   * @param class-string<T> $class
   *   The verb's class.
   *
   * @return T
   *   The verb.
   *
   * @throws \LogicException
   *   When the pipeline holds no such property, or it holds another class.
   *
   * @template T of object
   */
  protected static function held(SurfacePipelineInterface $pipeline, string $property, string $class): object {
    $reflection = new \ReflectionObject($pipeline);
    $verb = $reflection->hasProperty($property) ? $reflection->getProperty($property)->getValue($pipeline) : NULL;
    if (!$verb instanceof $class) {
      throw new \LogicException(sprintf('The value recorder reads the %s a pipeline holds in its $%s property, and %s holds none: give the recorder another way to reach it.', $class, $property, get_class($pipeline)));
    }
    return $verb;
  }

}
