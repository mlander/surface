<?php

declare(strict_types=1);

namespace Drupal\surface\Validation;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\Contract\PortableKeywords;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Sentences;
use Drupal\surface\Pipeline\SurfacePipeline;
use Drupal\surface\Pipeline\SurfacePipelineInterface;
use Drupal\surface\SurfaceInterface;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;
use PortableSchema\Result;
use PortableSchema\ValidationError;
use PortableSchema\Validator;

/**
 * Holds values to the document a data surface serves for them.
 *
 * One run of the dialect's validator: the document is what emit() serves
 * for these values (ServedContract::schema()), held in a clone of the
 * site's registry (`surface.portable_registry`) under its `$id`, or an id
 * of its own when it has none; the data is what the pipeline writes for
 * the values (SurfacePipelineInterface::document()). Every check the
 * document names is answered by one DrupalCheckProvider for the call.
 *
 * A compiled validator is kept for the request, by the document's hash, at
 * most KEPT of them, the oldest let go first: a form rebuilt for the same
 * values, or a dry run then its commit, compiles once.
 *
 * The document a request's values are held to is emitted once for the
 * data surface, those values and the account, and kept for the request
 * as the compiled validator is, until a cache tag is invalidated
 * (forget()); a document whose cacheability says it may not be kept (a
 * list read live) is emitted each time.
 *
 * What it answers is the result as refusals (Found), each with its
 * message: a check's, the violation's own; a keyword's, the message of
 * the constraint the keyword was written from where the place holds it
 * (WRITTEN_FROM), else the document's `x-portable-messages` for the code
 * at its location (a document from another host), else the code's
 * sentence (Sentences::ofError()). A check that could not run is the
 * refusal `unchecked`: a check never counts as passed.
 *
 * A check's refusal below the place it was asked about is filed there by
 * the package, from the error's own `location`
 * (DrupalCheckProvider::error()). Every check that runs is one the
 * document names. A refusal at a secret place, or a check's about a
 * secret below its place, carries no value: its params keep
 * SECRET_PARAMS alone, and its message (but an `unchecked` one's, which
 * names the check) is one sentence that names nothing sent. A list of
 * allowed values in `params.allowed` holds at most
 * SurfacePipeline::CHOICES_SHOWN, `params.more` counting the rest.
 *
 * Decision: see
 * docs/decisions.md#validation-runs-through-the-dialects-validator.
 *
 * @internal
 */
final class PortableValidator {

  /**
   * The most compiled validators, and emitted documents, kept.
   */
  public const KEPT = 16;

  /**
   * The constraints a keyword's code is written from, by the code.
   *
   * A refusal of one of these is said in that constraint's own message
   * where the place holds it (DrupalCheckProvider::constraintMessage()).
   * A format by its name.
   */
  private const WRITTEN_FROM = [
    'minimum' => ['Range'],
    'maximum' => ['Range'],
    'minLength' => ['Length', 'NotBlank'],
    'maxLength' => ['Length'],
    'minItems' => ['Count'],
    'maxItems' => ['Count'],
    'uniqueItems' => ['UniqueItems'],
    'pattern' => ['Regex'],
    'not' => ['Regex'],
    'enum' => ['Choice', 'LabeledChoice', 'AllowedValues'],
    'email' => ['Email'],
    'uuid' => ['Uuid'],
  ];

  /**
   * The params a refusal at a secret place keeps.
   */
  public const SECRET_PARAMS = ['limit', 'expected', 'actual', 'property', 'check', 'reason'];

  /**
   * The code of a check that could not run.
   */
  public const UNCHECKED = 'unchecked';

  /**
   * The compiled validators, by the document's hash.
   *
   * @var array<string, array{0: \PortableSchema\Validator, 1: string, 2: string[], 3: \PortableSchema\Registry, 4: array}>
   */
  private array $compiled = [];

  /**
   * The documents emitted, by the data surface and the values' hash.
   *
   * @var \WeakMap<\Drupal\surface\SurfaceInterface, array<string, \PortableSchema\Builder\Schema>>
   */
  private \WeakMap $emitted;

  /**
   * Constructs a PortableValidator.
   *
   * @param \PortableSchema\Registry $registry
   *   The site's registry: the type library and what a site adds.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The emitter, which writes the document for the values.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which runs a check's constraint.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service, for the messages.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, which tells a list read from a check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the documents are emitted for.
   */
  public function __construct(
    private readonly Registry $registry,
    private readonly ContractEmitter $emitter,
    private readonly TypedDataManagerInterface $typedDataManager,
    private readonly TranslationInterface $translation,
    private readonly SurfaceOptions $options,
    private readonly AccountInterface $account,
  ) {
    $this->emitted = new \WeakMap();
  }

  /**
   * Makes the provider of one validation's checks.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, as advertised.
   * @param array $values
   *   The accepted values.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, which writes the values as the document's data.
   *
   * @return \Drupal\surface\Validation\DrupalCheckProvider
   *   The provider, for validate().
   */
  public function provider(SurfaceInterface $surface, array $values, SurfacePipelineInterface $pipeline): DrupalCheckProvider {
    return new DrupalCheckProvider($this->typedDataManager, $pipeline, $this->translation, $this->options, $surface, $values);
  }

  /**
   * Validates a data surface's accepted values, once.
   *
   * @param \Drupal\surface\Validation\DrupalCheckProvider $provider
   *   The provider of the validation (provider()), holding the data
   *   surface and its values.
   *
   * @return \Drupal\surface\Validation\Found[]
   *   What the document refuses, each at its dotted path.
   *
   * @throws \Throwable
   *   What a check threw, once the validation returned.
   */
  public function validate(DrupalCheckProvider $provider): array {
    return $this->held($this->emitted($provider->surface, $provider->values), $provider->document(), $provider);
  }

  /**
   * Holds data to a document, once.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The document.
   * @param mixed $data
   *   The data, as the dialect reads it.
   * @param \Drupal\surface\Validation\DrupalCheckProvider|null $provider
   *   The provider of its `drupal:` checks; NULL for a document with no
   *   data surface behind it, whose checks are all unchecked and whose
   *   messages come from the document and the codes' sentences.
   *
   * @return \Drupal\surface\Validation\Found[]
   *   What the document refuses, each at its dotted path.
   *
   * @throws \Throwable
   *   What a check threw, once the validation returned.
   */
  public function held(Schema $schema, mixed $data, ?DrupalCheckProvider $provider = NULL): array {
    [$validator, $id, $names, $registry, $decoded] = $this->compiled($schema);
    $nodes = new Nodes($decoded, $data);
    if ($provider !== NULL) {
      foreach ($names as $name) {
        $validator = $validator->withProvider($name, $provider);
      }
    }
    $provider?->start($nodes);
    $result = $validator->validate($data, $id);
    if ($provider?->failure() !== NULL) {
      throw $provider->failure();
    }
    if ($provider !== NULL && !self::refusedWhereUnasked($result, $provider->unasked())) {
      // The walk read a keyword otherwise than the validator: every
      // check is asked.
      $provider->start(NULL);
      $result = $validator->validate($data, $id);
      if ($provider->failure() !== NULL) {
        throw $provider->failure();
      }
    }
    return $this->found($result, $provider, $registry, $nodes);
  }

  /**
   * Forgets the documents emitted for the request.
   *
   * What they state (a build, an option list, what the account may do)
   * has changed.
   *
   * @see \Drupal\surface\ResourceBuild\ResourceRegistryInvalidator
   */
  public function forget(): void {
    $this->emitted = new \WeakMap();
  }

  /**
   * Gets the document for a data surface and its values, emitting it once.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The accepted values.
   *
   * @return \PortableSchema\Builder\Schema
   *   The document.
   */
  private function emitted(SurfaceInterface $surface, array $values): Schema {
    $hash = $this->account->id() . ':' . hash('xxh128', serialize($values));
    $kept = $this->emitted[$surface] ?? [];
    if (isset($kept[$hash])) {
      return $kept[$hash];
    }
    $served = $this->emitter->emit($surface, $values, 'surface.validate');
    if ($served->getCacheMaxAge() !== 0) {
      // A document that reads what no cache tag invalidates (a list
      // read live) is emitted again each time.
      if (count($kept) >= self::KEPT) {
        array_shift($kept);
      }
      $kept[$hash] = $served->schema();
      $this->emitted[$surface] = $kept;
    }
    return $served->schema();
  }

  /**
   * Gets the compiled validator for a document, compiling it once.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The document.
   *
   * @return array{0: \PortableSchema\Validator, 1: string, 2: string[], 3: \PortableSchema\Registry, 4: array}
   *   The validator, the document's id in it, the names of the checks it
   *   holds, the registry it was compiled from, and the document decoded.
   */
  private function compiled(Schema $schema): array {
    $encoded = (string) json_encode(Schemas::arrayed($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    $hash = hash('xxh128', $encoded);
    if (isset($this->compiled[$hash])) {
      return $this->compiled[$hash];
    }
    $object = json_decode($encoded);
    $decoded = Schemas::decoded($schema);
    $decoded = is_array($decoded) ? $decoded : [];
    $id = is_string($decoded['$id'] ?? NULL) ? $decoded['$id'] : 'urn:surface:validate:' . $hash;
    $registry = clone $this->registry;
    $registry->add($object, $id);
    $kept = [new Validator($registry), $id, self::checkNames($decoded), $registry, $decoded];
    if (count($this->compiled) >= self::KEPT) {
      array_shift($this->compiled);
    }
    return $this->compiled[$hash] = $kept;
  }

  /**
   * Answers whether every place a check was left unasked is refused there.
   *
   * @param \PortableSchema\Result $result
   *   The result.
   * @param string[] $unasked
   *   The places.
   *
   * @return bool
   *   TRUE when the validator refused each by one of the keywords
   *   Nodes::BEFORE_CHECKS names, by a `format`, or by the `anyOf` or
   *   `allOf` a composite check is written as
   *   (Nodes::refusedByComposite()).
   */
  private static function refusedWhereUnasked(Result $result, array $unasked): bool {
    $refused = [];
    foreach ($result->errors as $error) {
      if (in_array($error->code, [...Nodes::BEFORE_CHECKS, 'anyOf', 'allOf'], TRUE) || str_starts_with($error->code, 'format.')) {
        $refused[$error->instanceLocation] = TRUE;
      }
    }
    return array_diff($unasked, array_map('strval', array_keys($refused))) === [];
  }

  /**
   * Turns a result into refusals with their messages.
   *
   * @param \PortableSchema\Result $result
   *   The result.
   * @param \Drupal\surface\Validation\DrupalCheckProvider|null $provider
   *   The provider that answered its checks, if any.
   * @param \PortableSchema\Registry $registry
   *   The registry holding the document.
   * @param \Drupal\surface\Validation\Nodes $nodes
   *   The document's nodes at each place.
   *
   * @return \Drupal\surface\Validation\Found[]
   *   The refusals.
   */
  private function found(Result $result, ?DrupalCheckProvider $provider, Registry $registry, Nodes $nodes): array {
    $found = [];
    // The places a check refused.
    $checked = [];
    foreach ($result->errors as $error) {
      if (str_starts_with($error->code, PortableKeywords::CHECK_CODE . DrupalCheckProvider::PREFIX)) {
        $checked[$error->instanceLocation] = TRUE;
      }
    }
    // A key with variants is an `anyOf` of each variant and null, and its
    // value is held to the chosen one by a conditional: where that refuses
    // it, the `anyOf`'s best branch can be null, which says nothing.
    $below = [];
    foreach ($result->errors as $error) {
      for ($at = $error->instanceLocation; $at !== ''; $at = substr($at, 0, (int) strrpos($at, '/'))) {
        $below[$at] = ($below[$at] ?? 0) + 1;
      }
    }
    foreach ($result->errors as $error) {
      $location = $error->instanceLocation;
      if ($error->code === 'enum' && isset($checked[$location]) && $provider?->isListedByChecks($location)) {
        // A list read from the checks, one of which refused, is their
        // rule, said by the check; a list of its own is said beside it.
        continue;
      }
      // A key nothing declares is refused by accept(), before validation
      // (`unknown_key`); what storage holds beyond what the caller was
      // built is kept, never judged.
      if ($error->code === 'unknownProperty') {
        continue;
      }
      if ($error->code === 'type' && ($error->params['expected'] ?? NULL) === ['null'] && ($below[$location] ?? 0) > 1) {
        continue;
      }
      // A locked key's `const` is the value the operation knows; what is
      // held there is never the request's, so it is never refused.
      if ($error->code === 'const' && $nodes->readOnly($location)) {
        continue;
      }
      $found[] = $this->said($location, $error->code, $error->params, $this->message($error, $result, $provider, $registry), $provider);
    }
    foreach ($result->unchecked as $unchecked) {
      $names = $provider?->foreignAt($unchecked->instanceLocation) ?: [substr($unchecked->code, strlen(PortableKeywords::CHECK_CODE))];
      $check = implode(', ', $names);
      $message = new TranslatableMarkup('This value cannot be accepted: the check @check cannot run on this site.', ['@check' => $check], [], $this->translation);
      $params = ['check' => $check, 'reason' => $unchecked->reason];
      $found[] = $this->said($unchecked->instanceLocation, self::UNCHECKED, $params, $message, $provider);
    }
    return $found;
  }

  /**
   * Files one refusal where it belongs, with what it may carry.
   *
   * An `allowed` list holds at most SurfacePipeline::CHOICES_SHOWN
   * values, `more` counting the rest. A refusal at a secret place, or a
   * check's about a secret below its place
   * (DrupalCheckProvider::aboutSecret()), keeps SECRET_PARAMS alone and
   * says one sentence that names no value.
   *
   * @param string $location
   *   Where the validator filed it.
   * @param string $code
   *   Its code.
   * @param array<string, mixed> $params
   *   Its params.
   * @param string|\Stringable $message
   *   Its message.
   * @param \Drupal\surface\Validation\DrupalCheckProvider|null $provider
   *   The provider, which knows a secret place.
   *
   * @return \Drupal\surface\Validation\Found
   *   The refusal.
   */
  private function said(string $location, string $code, array $params, string|\Stringable $message, ?DrupalCheckProvider $provider): Found {
    $secret = $provider !== NULL && ($provider->isSecret($location) || $provider->aboutSecret($location, $code, $params));
    if (is_array($params['allowed'] ?? NULL) && count($params['allowed']) > SurfacePipeline::CHOICES_SHOWN) {
      $params['more'] = count($params['allowed']) - SurfacePipeline::CHOICES_SHOWN;
      $params['allowed'] = array_slice(array_values($params['allowed']), 0, SurfacePipeline::CHOICES_SHOWN);
    }
    if ($secret) {
      $params = array_intersect_key($params, array_flip(self::SECRET_PARAMS));
      if ($code !== self::UNCHECKED) {
        $message = new TranslatableMarkup('This value is not accepted.', [], [], $this->translation);
      }
    }
    return new Found(ResultDocument::dotted($location), $code, $params, $message);
  }

  /**
   * Says one error of the validator.
   *
   * @param \PortableSchema\ValidationError $error
   *   The error.
   * @param \PortableSchema\Result $result
   *   The result, whose annotations give a location's title and messages.
   * @param \Drupal\surface\Validation\DrupalCheckProvider|null $provider
   *   The provider that answered the checks, if any.
   * @param \PortableSchema\Registry $registry
   *   The registry holding the document, where a bound's sibling is read.
   *
   * @return string|\Stringable
   *   The message.
   */
  private function message(ValidationError $error, Result $result, ?DrupalCheckProvider $provider, Registry $registry): string|\Stringable {
    $location = $error->instanceLocation;
    if ($provider !== NULL && str_starts_with($error->code, PortableKeywords::CHECK_CODE)) {
      $said = $provider->message($location, $error->code, $error->params);
      if ($said !== NULL) {
        return $said;
      }
    }
    $code = str_starts_with($error->code, 'format.') ? substr($error->code, 7) : $error->code;
    // The constraint the keyword was written from says it, in its own
    // words. A list is stated by the constraint an options resolver reads
    // it from, whichever that is: any constraint no other code is written
    // from.
    $written = $provider?->constraintMessage($location, self::WRITTEN_FROM[$code] ?? [], $code === 'enum' ? array_merge(...array_values(self::WRITTEN_FROM)) : NULL);
    if ($written !== NULL) {
      return $written;
    }
    // A document from another host says it in its own words.
    $custom = $result->annotations($location)->messages[$error->code] ?? NULL;
    if (is_string($custom) && $custom !== '') {
      return $custom;
    }
    $property = is_string($error->params['property'] ?? NULL) ? $error->params['property'] : NULL;
    $context = [];
    if ($property !== NULL) {
      $at = $location . '/' . strtr($property, ['~' => '~0', '/' => '~1']);
      $context['label'] = $result->annotations($at)->title ?? $property;
      $context['key'] = ResultDocument::dotted($at);
    }
    if (in_array($error->code, ['minimum', 'maximum'], TRUE)) {
      // Bounded on both sides, said as one range.
      $parent = substr($error->absoluteKeywordLocation, 0, (int) strrpos($error->absoluteKeywordLocation, '/'));
      [, $node] = $registry->resolve($parent);
      if ($node instanceof \stdClass && isset($node->minimum, $node->maximum)) {
        $context['min'] = $node->minimum;
        $context['max'] = $node->maximum;
      }
    }
    return Sentences::ofError($error->code, $error->params, $context, $this->translation);
  }

  /**
   * Lists the names of every check a document holds.
   *
   * @param mixed $schema
   *   The document, decoded, or a node of it.
   *
   * @return string[]
   *   The names under every `portable-check`, once each.
   */
  private static function checkNames(mixed $schema): array {
    if (!is_array($schema)) {
      return [];
    }
    $names = is_array($schema[PortableKeywords::CHECK] ?? NULL) ? array_map('strval', array_keys($schema[PortableKeywords::CHECK])) : [];
    foreach ($schema as $keyword => $value) {
      if (is_array($value) && $keyword !== PortableKeywords::CHECK && $keyword !== ContractEmitter::EXTENSION) {
        $names = [...$names, ...self::checkNames($value)];
      }
    }
    return array_values(array_unique($names));
  }

}
