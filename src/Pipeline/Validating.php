<?php

declare(strict_types=1);

namespace Drupal\surface\Pipeline;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\Computed;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\DefinitionTypedData;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Entry;
use Drupal\surface\Collection;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraint;
use Drupal\surface\Plugin\Validation\Constraint\JsonSchemaConstraintValidator;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * The validate verb of the pipeline: accepted values held to the rules.
 *
 * What SurfacePipelineInterface::validate() does: each level refined
 * against its values and judged by typed data, each child, variant and
 * collection item in its own frame; each refusal filed under its key with
 * the path below it, naming what it depends on and what is allowed; a
 * key that does not apply, a key required inside a plain map, a computed
 * key held to the value computed, and the stale rule.
 *
 * It holds the frame of the level being validated, where that level sits
 * in the values a caller sends, so a refusal names a key by the path a
 * caller sends it at.
 *
 * Decision: see docs/decisions.md#the-pipeline-is-read-by-verb.
 *
 * @internal
 *
 * @see \Drupal\surface\Pipeline\SurfacePipeline
 * @see docs/semantics.md
 */
final class Validating {

  use StringTranslationTrait;

  /**
   * Where the level being validated sits in the values a caller sends.
   *
   * The dotted prefix of the level's keys; each port it reads mapped to
   * the full path of the key it reads; and the levels around it, each by
   * its prefix, the outermost first. So a refusal names a key it depends
   * on by the path a caller sends it at, and by the label of what holds
   * it. Set by validateFramed() for each child and put back after it.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @var array{0: string, 1: array<string, string>, 2: array<string, \Drupal\surface\SurfaceInterface>}
   */
  protected array $frame = ['', [], []];

  /**
   * Constructs the validate verb.
   *
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, used to run the data surface's constraints.
   * @param \Drupal\surface\Options\SurfaceOptions $options
   *   The options service, asked whether a refused value is among the
   *   ones its key offers (the stale rule), and for the values a refusal
   *   names: the list a generated select renders.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, for the sentences a refusal says.
   * @param \Drupal\surface\Pipeline\Accepting $accepting
   *   The accept verb, which reads a computed key's value as accepted and
   *   names the keys it is computed from.
   * @param \Drupal\surface\Pipeline\SurfacePipelineInterface $pipeline
   *   The pipeline, whose document() a schema read into a frame is held
   *   to.
   */
  public function __construct(
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly SurfaceOptions $options,
    TranslationInterface $string_translation,
    protected readonly Accepting $accepting,
    protected readonly SurfacePipelineInterface $pipeline,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Validates a data surface's values at the top.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface, as advertised.
   * @param array $values
   *   The accepted values.
   * @param array $current
   *   The stored values, in input layout.
   *
   * @return \Drupal\surface\Pipeline\ViolationSet
   *   The violations, any stale entries held to one side.
   *
   * @see \Drupal\surface\Pipeline\SurfacePipelineInterface::validate()
   */
  public function validate(SurfaceInterface $surface, array $values, array $current = []): ViolationSet {
    $this->frame = ['', [], []];
    return new ViolationSet($this->validateLevel($surface, $values, $current));
  }

  /**
   * Names the sources in what a storage refused of the values at the top.
   *
   * @param \Drupal\surface\Pipeline\Violation[] $violations
   *   The storage's violations, filed under the top level's keys.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface describing the top level.
   * @param array $values
   *   The accepted values of the top level, which tell a key computed
   *   unless sent that was computed from one that was sent.
   * @param array $current
   *   What the top level holds in storage.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The same, each of a computed key naming its sources.
   */
  public function namingSourcesAtTop(array $violations, SurfaceInterface $surface, array $values = [], array $current = []): array {
    $this->frame = ['', [], []];
    return $this->namingSources($violations, $surface, $values, $current);
  }

  /**
   * Validates a child's level in its own frame, then puts the frame back.
   *
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child.
   * @param array $values
   *   Its values.
   * @param array $current
   *   What it holds in storage.
   * @param string $at
   *   Its dotted path below the current level: the key, and for an item
   *   its delta.
   * @param array<string, string> $reads
   *   Its ports, each mapped to the path in the current level's frame of
   *   the key it reads.
   * @param \Drupal\surface\SurfaceInterface $level
   *   The current level, refined, which holds the child.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The violations, filed under the child's own keys.
   */
  protected function validateFramed(SurfaceInterface $child, array $values, array $current, string $at, array $reads, SurfaceInterface $level): array {
    $outer = $this->frame;
    [$prefix, $ports, $levels] = $outer;
    $resolved = [];
    foreach ($reads as $port => $path) {
      // A path through a port of this frame (an entry of a map of keys
      // from the site, reading a sibling map's entry) continues where that
      // port reads.
      [$first, $rest] = array_pad(explode('.', $path, 2), 2, NULL);
      $resolved[(string) $port] = $ports[$path] ?? (isset($ports[$first]) && $rest !== NULL ? $ports[$first] . '.' . $rest : $prefix . $path);
    }
    $this->frame = [$prefix . $at . '.', $resolved, $levels + [$prefix => $level]];
    try {
      return $this->validateLevel($child, $values, $current);
    }
    finally {
      $this->frame = $outer;
    }
  }

  /**
   * Validates one data surface's values, at the top or inside a child.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface describing this level.
   * @param array $values
   *   The accepted values of this level.
   * @param array $current
   *   What this level holds in storage, for the stale rule.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The violations, filed under this level's keys.
   */
  protected function validateLevel(SurfaceInterface $surface, array $values, array $current): array {
    $refined = $surface->refine($values);
    $errors = [];
    foreach ($refined->getDefinitions()->entries() as $name => $entry) {
      $name = (string) $name;
      $definition = $entry->definition;
      $value = $values[$name] ?? NULL;
      $computed = $entry->locked ? NULL : Computed::of($definition);
      // A key computed unless sent holds either: nothing to refuse.
      $refusal = $computed === NULL || $computed->takesSent() ? NULL : $this->notComputed($surface, $entry, $computed, $values, $current);
      if ($refusal !== NULL) {
        // Decision: see
        // docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
        $errors[] = $refusal;
        continue;
      }
      if (DoesNotApply::marks($definition)) {
        // Nothing about a key that does not apply is required or held to
        // a rule; a value sent for it other than what it holds is refused.
        // Decision: see docs/decisions.md#a-key-that-does-not-apply.
        if (array_key_exists($name, $values) && ValueState::isConfigured($value) && !(array_key_exists($name, $current) && $current[$name] == $value)) {
          $errors[] = new Violation($name, '', $this->doesNotApply($surface, $refined, $name, '', $values), Violation::DOES_NOT_APPLY);
        }
        continue;
      }
      $ruled_out = DoesNotApply::inside($definition);
      if ($ruled_out !== []) {
        foreach ($ruled_out as $path) {
          $parents = explode('.', $path);
          $held = is_array($value) ? NestedArray::getValue($value, $parents) : NULL;
          $stored = is_array($current[$name] ?? NULL) && NestedArray::keyExists($current[$name], $parents) ? NestedArray::getValue($current[$name], $parents) : NULL;
          if (ValueState::isConfigured($held) && $held != $stored) {
            $errors[] = new Violation($name, $path, $this->doesNotApply($surface, $refined, $name, $path, $values), Violation::DOES_NOT_APPLY);
          }
        }
        $definition = static::withoutRuledOut($definition);
        $value = is_array($value) ? static::valueWithout($value, $ruled_out) : $value;
      }
      // Decision: see docs/decisions.md#required-inside-a-plain-map.
      $missing = $entry->attachment === NULL && $entry->variants === NULL
        ? $this->missingInMap($definition, is_array($value) ? $value : [])
        : [];
      if (!ValueState::isConfigured($value)) {
        // Not configured: nothing to hold to a constraint, and for a
        // required key the data surface's own message rather than whichever
        // type-specific one a constraint would have produced. A key that
        // was never set is this case whether it is required or not, and
        // it is never stale: there is no value to keep.
        if ($definition->isRequired()) {
          $errors[] = new Violation($name, '', $this->isRequired($surface, $refined, $name, '', $definition->getLabel() ?? $name, $values), Violation::REQUIRED);
        }
        elseif (DefinitionMetadata::isAlwaysHeld($definition)) {
          // Null clears where clearing is possible; here the storage
          // holds a value whatever is sent, so null is no answer.
          // Decision: see docs/decisions.md#a-key-its-storage-always-holds.
          $errors[] = new Violation($name, '', $this->cannotBeCleared($definition, $name), Violation::CANNOT_CLEAR);
        }
        foreach ($missing as $path => $label) {
          $errors[] = new Violation($name, $path, $this->isRequired($surface, $refined, $name, $path, $label, $values), Violation::REQUIRED);
        }
        continue;
      }
      if ($entry->list !== NULL) {
        $errors = array_merge($errors, $this->validateCollection($entry, $entry->list, $value, $current[$name] ?? NULL, $refined));
        continue;
      }
      $child = $entry->childFor($values);
      if ($child !== NULL) {
        $errors = array_merge($errors, $this->validateChild($entry, $child, $value, $current[$name] ?? [], $values, $refined));
        continue;
      }
      if ($entry->variants !== NULL) {
        // A key with variants holding a value with no variant to read it
        // by. When the
        // deciding key holds a value that names none, that key's own
        // Choice says so; when it holds nothing, this does.
        if (!ValueState::isConfigured($values[$entry->variants->by] ?? NULL)) {
          $errors[] = new Violation($name, '', $this->t('@label depends on @by, which holds no value.', [
            '@label' => $definition->getLabel() ?? $name,
            '@by' => Named::key($refined->getDefinition($entry->variants->by)?->getLabel() ?? $entry->variants->by, $this->frame[0] . $entry->variants->by),
          ]), Violation::NO_VARIANT);
        }
        continue;
      }
      if (($definition instanceof ListDataDefinition || $definition instanceof MapDataDefinition) && is_array($value) && $definition->isRequired()) {
        // Required means configured, and the empty list is: what all
        // checkboxes unchecked, or every row removed, says. So is a map
        // whose keys hold nothing, whose required keys each say so in
        // their own words ($missing), as a child's do. Core's NotNull
        // reads an empty list, or a map of nothing, as null, so it is not
        // asked here; the data surface asked whether the key holds a
        // value, above.
        // Decision: see docs/decisions.md#required-means-must-hold-a-value.
        $definition = (clone $definition)->setRequired(FALSE);
      }
      $typed_data = DefinitionTypedData::create($this->typedDataManager, $definition, $value, $name);
      $refusals = [];
      $choices = [];
      foreach ($typed_data->validate() as $violation) {
        $path = (string) $violation->getPropertyPath();
        if (isset($missing[$path])) {
          // Said below, in the data surface's own words.
          continue;
        }
        // The message stays the object the constraint built. Flattening
        // it here would render its placeholders once, as plain text, and
        // whatever reads the violation afterwards would escape that text
        // a second time; a form error, a tool result and a log line each
        // render it themselves, at their own boundary. A Symfony
        // placeholder core's translator leaves in it is filled in then.
        if ($violation->getConstraint() instanceof Choice) {
          // A value not on a list: the refusal names the list.
          $choices[count($refusals)] = TRUE;
        }
        $refusals[] = Violation::fromConstraint($name, $path, $violation);
      }
      foreach ($missing as $path => $label) {
        // What a rule made required is said below, with the rest.
        $refusals[] = new Violation($name, $path, $this->t('@label is required.', ['@label' => $label]), Violation::REQUIRED);
      }
      if ($refusals !== [] && $this->isStale($definition, $value, $name, $current, $refined->getDefinitions()->dependencies($name), $values)) {
        // The whole key is reported as stale and not re-judged. What
        // else its constraints would say is about a value this run is
        // not changing and could not have chosen — it is what storage
        // holds — and saying it would read as a list of things to fix
        // where there is exactly one: choose again.
        $errors[] = $this->staleViolation($definition, $value, $name);
        continue;
      }
      $errors = array_merge($errors, $this->sayingWhatIsAllowed($refusals, $surface, $refined, $name, $values, $choices));
    }
    foreach ($refined->getDefinitions()->frameConstraints() as $schema) {
      // What a schema read into this frame says of the object as a whole,
      // which no key and no rule could say, held to the values as accepted.
      // Decision: see
      // docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
      $verdict = JsonSchemaConstraintValidator::verdict($this->pipeline->document($refined, $values), $schema);
      if ($verdict === NULL) {
        continue;
      }
      [$code, $said] = $verdict;
      $errors[] = $code === JsonSchemaConstraint::UNRESOLVED_ERROR
        ? new Violation('', '', $this->t('These values cannot be checked here: the schema they were read from refers to @reference, which is outside it.', ['@reference' => $said]), Violation::UNRESOLVED)
        : new Violation('', '', $this->t('These values do not hold to the schema they were read from: @error', ['@error' => $said]), Violation::NOT_VALID);
    }
    return $this->namingSources($errors, $surface, $values, $current);
  }

  /**
   * Refuses a value a computed key holds other than the one computed.
   *
   * What the key holds for these values is computed again, as accept()
   * computed it: a pure function of what its sources hold, or what is
   * stored where it is not computed again.
   *
   * Decision: see
   * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface the key belongs to.
   * @param \Drupal\surface\Entry $entry
   *   The computed key.
   * @param \Drupal\surface\Computed $computed
   *   What it is computed from.
   * @param array $values
   *   The accepted values of its level.
   * @param array $current
   *   What its level holds in storage.
   *
   * @return \Drupal\surface\Pipeline\Violation|null
   *   The refusal, or NULL when it holds the value computed.
   */
  protected function notComputed(SurfaceInterface $surface, Entry $entry, Computed $computed, array $values, array $current): ?Violation {
    $name = $entry->name;
    $held = $computed->value($name, $values, $current, $entry->definition);
    try {
      $held = $this->accepting->acceptValue($entry->definition, $held, $held, $name);
    }
    catch (\InvalidArgumentException) {
      // Compared as computed.
    }
    if (Accepting::sameValue($values[$name] ?? NULL, $held)) {
      return NULL;
    }
    return new Violation($name, '', $this->accepting->computedFrom($entry->definition->getLabel() ?? $name, $this->accepting->sources($surface, $computed, $this->frame[0]), 'refusal'), Violation::COMPUTED);
  }

  /**
   * Names the sources in each refusal of a computed key nobody sent.
   *
   * A refusal of the value a computed key holds (its pattern, a storage's
   * rule) is about a value the caller did not send: a sentence after it
   * names the keys it is computed from, the ones the caller can change.
   * While one of those is refused itself, the computed key's refusals are
   * left out: they follow from it, and fixing it fixes them. A key
   * computed unless sent is named so only where it holds the value its
   * sources give, and its sentence says a value may be sent instead; one
   * holding another value holds the caller's own, and its refusals stand
   * as they are.
   *
   * Decision: see
   * docs/decisions.md#a-computed-key-is-a-pure-function-of-its-sources.
   *
   * @param \Drupal\surface\Pipeline\Violation[] $violations
   *   The violations of one level, filed under its keys.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface describing the level.
   * @param array $values
   *   The accepted values of the level.
   * @param array $current
   *   What the level holds in storage.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The same, each of a computed key naming its sources.
   */
  protected function namingSources(array $violations, SurfaceInterface $surface, array $values = [], array $current = []): array {
    $refused = [];
    foreach ($violations as $violation) {
      $refused[$violation->key] = TRUE;
    }
    $named = [];
    foreach ($violations as $violation) {
      $definition = $violation->key === '' ? NULL : $surface->getDefinition($violation->key);
      $computed = Computed::of($definition);
      // A key computed unless sent holding a value its sources do not give
      // holds the caller's own: its refusals stand as they are.
      if ($computed === NULL || $violation->code === Violation::COMPUTED || ($computed->takesSent() && !$this->holdsComputed($violation->key, $definition, $computed, $values, $current))) {
        $named[] = $violation;
        continue;
      }
      if (array_intersect_key($refused, array_flip($computed->from)) !== []) {
        continue;
      }
      $after = $this->accepting->computedFrom($definition->getLabel() ?? $violation->key, $this->accepting->sources($surface, $computed, $this->frame[0]), $computed->takesSent() ? 'unless' : 'after');
      $named[] = $violation->saying(new Sentences($violation->message, $after));
    }
    return $named;
  }

  /**
   * Answers whether a key computed unless sent holds what its sources give.
   *
   * @param string $key
   *   The key.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   Its definition.
   * @param \Drupal\surface\Computed $computed
   *   What it is computed from.
   * @param array $values
   *   The accepted values of its level.
   * @param array $current
   *   What its level holds in storage.
   *
   * @return bool
   *   TRUE when it holds the value computed, or what is stored where it is
   *   not computed again: no value of the caller's own.
   */
  protected function holdsComputed(string $key, DataDefinitionInterface $definition, Computed $computed, array $values, array $current): bool {
    if ($values === []) {
      return FALSE;
    }
    $held = $computed->value($key, $values, $current, $definition);
    try {
      $held = $this->accepting->acceptValue($definition, $held, $held, $key);
    }
    catch (\InvalidArgumentException) {
      // Compared as computed.
    }
    return Accepting::sameValue($values[$key] ?? NULL, $held);
  }

  /**
   * Says in each refusal of a key what is allowed for the values sent.
   *
   * One sentence per refusal, so a caller fixes the key in one step:
   *
   * - A refusal a refiner caused, such as too few stewards for the
   *   capacity given, is about a value the caller may not have touched:
   *   the key it names is the one to fix, but the reason is a sibling.
   *   When the refiners changed the refused key's constraints for these
   *   values, a second sentence names each key they watch and what it
   *   holds, as a key that does not apply is said ("This value should be
   *   3 or more. This applies when Capacity is 120."). A refusal by a
   *   rule the key declared itself says no condition. A key an alter mounted is
   *   refined one property at a time, so its refusals are matched to the
   *   property each is about.
   * - A value not on its key's list is followed by a sentence listing
   *   what may be chosen, as refined for these values ("... Choose Main
   *   hall or East room." for a form; for a machine, what to send with
   *   its label beside it, "... Choose riverside_main (Main hall) or
   *   riverside_east (East room)."), capped at CHOICES_SHOWN.
   *
   * A key and a value these sentences name are Named placeholders, so
   * each renders for whoever reads it: by label for a form, by path and
   * by what is sent for a machine (Sentences::machine()).
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\surface\Pipeline\Violation[] $refusals
   *   The key's refusals.
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param array $values
   *   The values of the key's level.
   * @param array<int, true> $choices
   *   The refusals, by index, that a list of allowed values made.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The refusals, each saying what is allowed where that applies.
   */
  protected function sayingWhatIsAllowed(array $refusals, SurfaceInterface $surface, SurfaceInterface $refined, string $name, array $values, array $choices): array {
    $entry = $refined->getDefinitions()->entry($name);
    if ($refusals === [] || $entry === NULL) {
      return $refusals;
    }
    $targets = $entry->dependencies === [] ? [] : ($entry->paths === [] ? ['' => $entry->dependencies] : $entry->paths);
    foreach ($refusals as $index => $refusal) {
      $conditions = [];
      foreach ($targets as $path => $dependencies) {
        $path = (string) $path;
        if ($path !== '' && $refusal->path !== $path && !str_starts_with($refusal->path, $path . '.')) {
          continue;
        }
        $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
        $declared = static::definitionAt($surface, $parents);
        $narrowed = static::definitionAt($refined, $parents);
        if ($declared === NULL || $narrowed === NULL || json_encode($declared->getConstraints()) === json_encode($narrowed->getConstraints())) {
          continue;
        }
        $conditions = $this->conditions($refined, $dependencies, $values, $entry);
        break;
      }
      $allowed = isset($choices[$index]) ? $this->choices($refined, $name, $refusal->path) : NULL;
      if ($conditions === [] && $allowed === NULL) {
        continue;
      }
      // A required property a rule made required says so in the one
      // sentence a required key does.
      $message = $refusal->message;
      if ($allowed === NULL && $message instanceof TranslatableMarkup && $message->getUntranslatedString() === '@label is required.') {
        $refusals[$index] = $refusal->saying($this->requiredWhen($message->getArguments()['@label'], $conditions));
        continue;
      }
      // The constraint's message is kept whole, and what this adds is said
      // in whole sentences of its own beside it.
      $sentences = [$refusal->message];
      if ($conditions !== []) {
        $sentences[] = $this->appliesWhen($conditions);
      }
      if ($allowed !== NULL) {
        $sentences[] = $allowed;
      }
      $refusals[$index] = $refusal->saying(new Sentences(...$sentences));
    }
    return $refusals;
  }

  /**
   * Says what each watched key holds, as the condition of a refusal.
   *
   * Each condition is one whole clause a translator sees entire, its
   * kind in the source ("@key is empty", "@key is on"), never a key and
   * a bare word joined. It describes what the refiners were handed: a
   * watched value its own key refused was handed as nothing, so it is
   * said as empty, with the value that was refused. A key only a declared
   * rule reads, and only by whether it holds a value (`present()`,
   * `absent()`), is said as set or empty, never by what it holds: the
   * rule asked nothing else, and "when Email is digest@example.com" reads
   * as though only that address counted.
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The data surface, refined against the values.
   * @param string[] $dependencies
   *   The watched keys, a mounted key by its dotted path, a port by its
   *   name.
   * @param array $values
   *   The values of the level.
   * @param \Drupal\surface\Entry|null $entry
   *   The refused key, whose declared rules say which watched keys are
   *   read for presence alone.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   "Venue is Riverside Hall", one per watched key, an option by its
   *   label; "Email is set" for a key read for presence alone; "Event
   *   license is empty (EV-2048 was refused)" for a refused one.
   */
  protected function conditions(SurfaceInterface $refined, array $dependencies, array $values, ?Entry $entry = NULL): array {
    $conditions = [];
    $presence = $entry?->presenceDependencies() ?? [];
    $withheld = $refined->withheldValues();
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    foreach ($dependencies as $dependency) {
      $at = explode('.', $dependency);
      $watched = static::definitionAt($refined, $at);
      $arguments = ['@key' => $this->conditionKey($refined, $dependency)];
      if (array_key_exists($dependency, $withheld)) {
        // What the rule was handed: nothing, since its own key refused it.
        // Decision: see
        // docs/decisions.md#a-refiner-never-sees-an-invalid-sibling.
        $refused = $withheld[$dependency];
        $arguments['@value'] = is_bool($refused) ? (int) $refused : $refused;
        $conditions[] = is_scalar($refused)
          ? $this->t('@key is empty (@value was refused)', $arguments, $context)
          : $this->t('@key is empty (what was sent was refused)', $arguments, $context);
        continue;
      }
      $held = NestedArray::getValue($values, $at) ?? $refined->getPortValues()[$dependency] ?? NULL;
      $set = $watched === NULL || !is_scalar($held) ? NULL : $this->options->resolve($watched);
      $labeled = $set !== NULL && isset($set->options[$held]);
      if ($labeled || (is_scalar($held) && !is_bool($held))) {
        $arguments['@value'] = $labeled ? Named::value($set->options[$held], (string) $held) : (string) $held;
      }
      $conditions[] = match (TRUE) {
        !ValueState::isConfigured($held) => $this->t('@key is empty', $arguments, $context),
        in_array($dependency, $presence, TRUE) => $this->t('@key is set', $arguments, $context),
        isset($arguments['@value']) => $this->t('@key is @value', $arguments, $context),
        $held === TRUE => $this->t('@key is on', $arguments, $context),
        $held === FALSE => $this->t('@key is off', $arguments, $context),
        default => $this->t('@key holds its current value', $arguments, $context),
      };
    }
    return $conditions;
  }

  /**
   * Names a key a condition reads, the same for a person and a machine.
   *
   * By its label; a key held by another (a key an alter mounted, a
   * property of a map, a key of a child, a variant or an item) by the
   * label of what holds it, then its own, "Managed key: Key", the item of
   * a list by the list's label; by its JSON Pointer only where it has no
   * label. A port is named as the key it reads. The two labels are set
   * side by side outside translation, as a heading's trail is: each is a
   * name, and no word joins them.
   *
   * Decision: see docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The level, refined against the values.
   * @param string $dependency
   *   The key, by its dotted path in the level, or a port by its name.
   *
   * @return string|\Stringable
   *   Its name.
   */
  protected function conditionKey(SurfaceInterface $refined, string $dependency): string|\Stringable {
    [$prefix, $ports] = $this->frame;
    $full = $ports[$dependency] ?? $prefix . $dependency;
    $label = static::labelOf(static::definitionAt($refined, explode('.', $dependency)));
    if ($label === NULL) {
      return ResultDocument::pointer($full);
    }
    $holder = explode('.', $full);
    array_pop($holder);
    // An item has no label of its own: the list holding it names it.
    while ($holder !== [] && ctype_digit((string) end($holder))) {
      array_pop($holder);
    }
    $parent = $holder === [] ? NULL : $this->labelAt($refined, implode('.', $holder));
    return $parent === NULL ? $label : new FormattableMarkup('@parent: @key', ['@parent' => $parent, '@key' => $label]);
  }

  /**
   * Reads the label of a key anywhere in the values, from its full path.
   *
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The current level, refined.
   * @param string $path
   *   The key's dotted path from the top.
   *
   * @return string|\Stringable|null
   *   Its label, or NULL where it has none or nothing declares it.
   */
  protected function labelAt(SurfaceInterface $refined, string $path): string|\Stringable|null {
    [$prefix, , $levels] = $this->frame;
    $levels[$prefix] = $refined;
    // The innermost level the path lies inside.
    foreach (array_reverse($levels, TRUE) as $at => $level) {
      $at = (string) $at;
      if ($at === '' || str_starts_with($path, $at)) {
        return static::labelOf(static::definitionAt($level, explode('.', substr($path, strlen($at)))));
      }
    }
    return NULL;
  }

  /**
   * Reads a definition's label, an empty one as none.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface|null $definition
   *   The definition.
   *
   * @return string|\Stringable|null
   *   The label, or NULL.
   */
  protected static function labelOf(?DataDefinitionInterface $definition): string|\Stringable|null {
    $label = $definition?->getLabel();
    return $label === NULL || (string) $label === '' ? NULL : $label;
  }

  /**
   * Says when a refusal applies, in one sentence.
   *
   * One source per count of conditions, so no condition is ever joined
   * to another by a translated word: one, two, and more, which is plural
   * by its count.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $conditions
   *   The conditions, at least one.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "This applies when Room is Main hall and Event license is empty."
   */
  protected function appliesWhen(array $conditions): TranslatableMarkup {
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    $arguments = static::conditionArguments($conditions);
    return match (count($conditions)) {
      1 => $this->t('This applies when @condition.', $arguments, $context),
      2 => $this->t('This applies when @first and @second.', $arguments, $context),
      default => $this->formatPlural(count($conditions), 'This applies when this holds: @conditions.', 'This applies when all @count of these hold: @conditions.', $arguments, $context),
    };
  }

  /**
   * Hands conditions to the sentence for their count.
   *
   * One is `@condition`, two are `@first` and `@second`, and three or
   * more are `@conditions`, whole clauses set in a row with semicolons
   * outside translation.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $conditions
   *   The conditions, at least one.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   The placeholders.
   */
  protected static function conditionArguments(array $conditions): array {
    return match (count($conditions)) {
      1 => ['@condition' => $conditions[0]],
      2 => ['@first' => $conditions[0], '@second' => $conditions[1]],
      default => ['@conditions' => implode('; ', array_map('strval', $conditions))],
    };
  }

  /**
   * Lists what a refused key may hold, as refined for the values sent.
   *
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The data surface, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The refused property below it, or ''.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   "Choose Main hall or East room." for a form, "Choose riverside_main
   *   (Main hall) or riverside_east (East room)." for a machine, at most
   *   CHOICES_SHOWN of them and how many more; "Nothing may be
   *   chosen." for an empty list; NULL when the key offers no list.
   */
  protected function choices(SurfaceInterface $refined, string $name, string $path): ?TranslatableMarkup {
    $definition = $path === '' ? NULL : static::definitionAt($refined, [$name, ...explode('.', $path)]);
    $definition ??= $refined->getDefinition($name);
    $set = $definition === NULL ? NULL : $this->options->resolve($definition);
    if ($set === NULL) {
      return NULL;
    }
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    if ($set->options === []) {
      return $this->t('Nothing may be chosen.', [], $context);
    }
    // Each by its label for a form; for a machine, what is sent, its
    // label beside it.
    $choices = [];
    foreach ($set->options as $value => $label) {
      $choices[] = Named::value($label, (string) $value);
    }
    $shown = array_slice($choices, 0, SurfacePipeline::CHOICES_SHOWN);
    $more = count($choices) - count($shown);
    if ($more > 0) {
      return $this->formatPlural($more, 'Choose @choices or 1 more.', 'Choose @choices or one of @count more.', ['@choices' => new NamedList(...$shown)], $context);
    }
    $last = array_pop($shown);
    return $shown === []
      ? $this->t('Choose @choice.', ['@choice' => $last], $context)
      : $this->t('Choose @choices or @last.', ['@choices' => new NamedList(...$shown), '@last' => $last], $context);
  }

  /**
   * Says that a key its storage always holds cannot be cleared.
   *
   * Names the default, an option by its label, so the caller knows what
   * the key holds when it starts, and that leaving it out keeps it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   * @param string $name
   *   Its name, when it has no label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The refusal.
   */
  protected function cannotBeCleared(DataDefinitionInterface $definition, string $name): TranslatableMarkup {
    $label = $definition->getLabel() ?? $name;
    $default = DefinitionMetadata::hasDefaultValue($definition) ? DefinitionMetadata::getDefaultValue($definition) : NULL;
    if (!is_scalar($default)) {
      return $this->t('@label cannot be cleared: it always holds a value. Leave it out to keep what it holds.', ['@label' => $label]);
    }
    $set = $this->options->resolve($definition);
    $value = is_bool($default) ? (int) $default : $default;
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    if (is_bool($default) && !($set !== NULL && isset($set->options[$value]))) {
      return $default
        ? $this->t('@label cannot be cleared: it always holds a value, on by default. Leave it out to keep what it holds.', ['@label' => $label], $context)
        : $this->t('@label cannot be cleared: it always holds a value, off by default. Leave it out to keep what it holds.', ['@label' => $label], $context);
    }
    $shown = $set !== NULL && isset($set->options[$value])
      ? Named::value($set->options[$value], (string) $value)
      : (string) $default;
    return $this->t('@label cannot be cleared: it always holds a value, @default by default. Leave it out to keep what it holds.', [
      '@label' => $label,
      '@default' => $shown,
    ]);
  }

  /**
   * Says that a key is required, and, when a rule made it so, why.
   *
   * A key declared required says so alone ("Phone is required."). One a
   * refiner or a declared rule made required for these values goes on
   * to name each key the rule watches and what it holds, as a refused
   * constraint does: "Phone is required when Maximum visitors is 80." A
   * port is named by the container's key it reads.
   *
   * Decision: see
   * docs/decisions.md#a-refusal-names-what-it-depends-on.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The property path below it, for a required property of a map; ''
   *   for the key itself.
   * @param string|\Stringable $label
   *   The label of what is required.
   * @param array $values
   *   The values of the key's level.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The refusal.
   */
  protected function isRequired(SurfaceInterface $surface, SurfaceInterface $refined, string $name, string $path, string|\Stringable $label, array $values): TranslatableMarkup {
    $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
    $declared = static::definitionAt($surface, $parents);
    $entry = $refined->getDefinitions()->entry($name);
    $dependencies = $path === '' ? ($entry->dependencies ?? []) : ($entry->paths[$path] ?? $entry->dependencies ?? []);
    if ($entry?->variants !== NULL) {
      $dependencies = array_values(array_diff($dependencies, [$entry->variants->by]));
    }
    $conditions = $declared === NULL || $declared->isRequired() ? [] : $this->conditions($refined, $dependencies, $values, $entry);
    return $this->requiredWhen($label, $conditions);
  }

  /**
   * Says that a key is required, under the conditions that made it so.
   *
   * @param string|\Stringable $label
   *   The label of what is required.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $conditions
   *   The conditions; none for a key required by its own declaration.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "Phone is required when Maximum visitors is 80.", one source per
   *   count of conditions.
   */
  protected function requiredWhen(string|\Stringable $label, array $conditions): TranslatableMarkup {
    if ($conditions === []) {
      return $this->t('@label is required.', ['@label' => $label]);
    }
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    $arguments = ['@label' => $label] + static::conditionArguments($conditions);
    return match (count($conditions)) {
      1 => $this->t('@label is required when @condition.', $arguments, $context),
      2 => $this->t('@label is required when @first and @second.', $arguments, $context),
      default => $this->formatPlural(count($conditions), '@label is required when this holds: @conditions.', '@label is required when all @count of these hold: @conditions.', $arguments, $context),
    };
  }

  /**
   * Says that a key does not apply, and what it depends on.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface as declared.
   * @param \Drupal\surface\SurfaceInterface $refined
   *   The same, refined against the values.
   * @param string $name
   *   The key.
   * @param string $path
   *   The property path below it, for a key an alter mounted; '' for the
   *   key itself.
   * @param array $values
   *   The values of the key's level.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   "Send day does not apply when Frequency is Monthly."
   */
  protected function doesNotApply(SurfaceInterface $surface, SurfaceInterface $refined, string $name, string $path, array $values): TranslatableMarkup {
    $parents = $path === '' ? [$name] : [$name, ...explode('.', $path)];
    $entry = $refined->getDefinitions()->entry($name);
    $dependencies = $path === '' ? ($entry->dependencies ?? []) : ($entry->paths[$path] ?? $entry->dependencies ?? []);
    if ($entry?->variants !== NULL) {
      // What chooses a variant is not what rules the key out.
      $dependencies = array_values(array_diff($dependencies, [$entry->variants->by]));
    }
    $label = static::definitionAt($surface, $parents)?->getLabel() ?? end($parents);
    $conditions = $this->conditions($refined, $dependencies, $values, $entry);
    if ($conditions === []) {
      return $this->t('@label does not apply.', ['@label' => $label]);
    }
    $context = ['context' => SurfacePipeline::REFUSAL_CONTEXT];
    $arguments = ['@label' => $label] + static::conditionArguments($conditions);
    return match (count($conditions)) {
      1 => $this->t('@label does not apply when @condition.', $arguments, $context),
      2 => $this->t('@label does not apply when @first and @second.', $arguments, $context),
      default => $this->formatPlural(count($conditions), '@label does not apply when this holds: @conditions.', '@label does not apply when all @count of these hold: @conditions.', $arguments, $context),
    };
  }

  /**
   * Copies a map's definition without the properties ruled out.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The map, refined.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   A copy holding only what applies.
   */
  protected static function withoutRuledOut(DataDefinitionInterface $definition): DataDefinitionInterface {
    if (!$definition instanceof MapDataDefinition) {
      return $definition;
    }
    $copy = clone $definition;
    foreach ($copy->getPropertyDefinitions() as $property => $property_definition) {
      $copy->setPropertyDefinition((string) $property, DoesNotApply::marks($property_definition) ? NULL : static::withoutRuledOut($property_definition));
    }
    return $copy;
  }

  /**
   * Copies a map's value without the properties at some paths.
   *
   * @param array $value
   *   The map.
   * @param string[] $paths
   *   Dotted paths below it.
   *
   * @return array
   *   The map less those properties.
   */
  protected static function valueWithout(array $value, array $paths): array {
    foreach ($paths as $path) {
      NestedArray::unsetValue($value, explode('.', $path));
    }
    return $value;
  }

  /**
   * Finds the definition at a key, or at a property path inside it.
   *
   * A port is read as the container's key it is wired to. A delta below
   * a list names its item, so a refused item's choices are its item's, as
   * a scalar's are its own.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string[] $parents
   *   The key, then the property names or deltas below it; or a port.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The definition, or NULL when the path names none.
   */
  public static function definitionAt(SurfaceInterface $surface, array $parents): ?DataDefinitionInterface {
    if (count($parents) === 1 && $surface->getDefinition((string) $parents[0]) === NULL) {
      // A port: the container's key it reads.
      return $surface->getPortDefinition((string) $parents[0]);
    }
    $definition = $surface->getDefinition((string) array_shift($parents));
    foreach ($parents as $property) {
      $definition = match (TRUE) {
        $definition instanceof ListDataDefinitionInterface => ctype_digit((string) $property) ? $definition->getItemDefinition() : NULL,
        $definition instanceof ComplexDataDefinitionInterface => $definition->getPropertyDefinition($property),
        default => NULL,
      };
    }
    return $definition;
  }

  /**
   * Finds the required properties of a plain map that hold no value.
   *
   * A plain map is not a child, so no child judges its keys; without
   * this, a required property inside one would be held only to typed
   * data's NotNull, which an empty string passes and an absent map never
   * reaches. The data surface's rule for a key, applied to the map's own
   * properties: required and not configured is refused, with the same
   * message. A mounted key an alter made required is the case it is for.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param array $value
   *   The key's value, or an empty array when it holds none.
   * @param string $prefix
   *   The property path so far.
   *
   * @return array<string, string|\Stringable>
   *   The labels of the missing properties, keyed by property path.
   */
  protected function missingInMap(DataDefinitionInterface $definition, array $value, string $prefix = ''): array {
    if (!$definition instanceof ComplexDataDefinitionInterface || $definition instanceof ListDataDefinitionInterface) {
      return [];
    }
    $missing = [];
    foreach ($definition->getPropertyDefinitions() as $property => $property_definition) {
      $path = $prefix . $property;
      $held = $value[$property] ?? NULL;
      if ($property_definition instanceof ComplexDataDefinitionInterface) {
        $missing += $this->missingInMap($property_definition, is_array($held) ? $held : [], $path . '.');
        continue;
      }
      if ($property_definition->isRequired() && !ValueState::isConfigured($held)) {
        $missing[$path] = $property_definition->getLabel() ?? $property;
      }
    }
    return $missing;
  }

  /**
   * Validates a child's value through the built child describing it.
   *
   * The child judges its own keys by its own rules — the required
   * message, the stale rule, its refiners, in its own frame — and every
   * violation it reports is filed back under the parent's key with the
   * child's key at the front of its path, so `columns` inside
   * `presentation_settings` reads as `presentation_settings.columns` to
   * whoever reads the set. Dotted paths into a child exist here and in
   * emitted schemas, and nowhere a refiner could name them.
   *
   * @param \Drupal\surface\Entry $entry
   *   The attached key or key with variants.
   * @param \Drupal\surface\SurfaceInterface $child
   *   The child answering for it, as advertised.
   * @param mixed $value
   *   The key's value, configured.
   * @param mixed $current
   *   What storage holds for the key.
   * @param array $values
   *   The values of the key's own level, for the variants' deciding key.
   * @param \Drupal\surface\SurfaceInterface $level
   *   The key's own level, refined.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The violations, filed under the key.
   */
  protected function validateChild(Entry $entry, SurfaceInterface $child, mixed $value, mixed $current, array $values, SurfaceInterface $level): array {
    $name = $entry->name;
    if (!is_array($value)) {
      return [
        new Violation($name, '', $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'map',
          '@actual' => get_debug_type($value),
        ]), Violation::TYPE_MISMATCH),
      ];
    }
    $errors = [];
    // The ports the child reads, each by the path in this level's frame
    // of the key it reads, for what its refusals name.
    $reads = $entry->attachment->reads ?? [];
    if ($entry->variants !== NULL) {
      $chosen = (string) $entry->variants->chosen($values[$entry->variants->by] ?? NULL);
      $reads = isset($entry->variants->variants[$chosen]) ? $entry->variants->variants[$chosen]->reads : [];
      $strangers = $entry->variants->strangers($chosen, $value);
      $owners = $entry->variants->owners($strangers, $chosen);
      foreach ($strangers as $stranger) {
        $errors[] = isset($owners[$stranger])
          ? new Violation($name, $stranger, $this->accepting->variantMismatchMessage($name . '.' . $stranger, $entry->variants->by, $chosen, $owners[$stranger]), Violation::WRONG_VARIANT)
          : new Violation($name, $stranger, $this->t('Unknown key @key.', ['@key' => $name . '.' . $stranger]), Violation::UNKNOWN_KEY);
      }
      // Judged as the variant would judge it on its own: the keys it
      // declares, and nothing it does not.
      $value = array_diff_key($value, array_flip($strangers));
    }
    foreach ($this->validateFramed($child, $value, is_array($current) ? $current : [], $name, $reads, $level) as $violation) {
      $errors[] = $violation->at($name, $violation->fullPath());
    }
    return $errors;
  }

  /**
   * Validates a collection: the list as the parent's, each item as the child's.
   *
   * The list's own constraints, its Count above all, are the parent's,
   * read off the key as the parent's refiners left it, and are said about
   * the list itself. Then every item is judged by the child in its own
   * frame, its own refiners run against that item alone, the stale rule
   * against what is stored at the same position, and every violation it
   * reports is filed under the list's key with the item's delta at the
   * front of its path: `tags.2.target_id`.
   *
   * Decision: see docs/decisions.md#count-is-the-parents.
   *
   * @param \Drupal\surface\Entry $entry
   *   The collection, refined.
   * @param \Drupal\surface\Collection $list
   *   The child every item is.
   * @param mixed $value
   *   The list, configured.
   * @param mixed $current
   *   What storage holds for it.
   * @param \Drupal\surface\SurfaceInterface $level
   *   The key's own level, refined.
   *
   * @return \Drupal\surface\Pipeline\Violation[]
   *   The violations, filed under the key.
   */
  protected function validateCollection(Entry $entry, Collection $list, mixed $value, mixed $current, SurfaceInterface $level): array {
    $name = $entry->name;
    if (!is_array($value)) {
      return [
        new Violation($name, '', $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'list',
          '@actual' => get_debug_type($value),
        ]), Violation::TYPE_MISMATCH),
      ];
    }
    $items = array_values($value);
    // The list's own rules, apart from its items, which the child judges:
    // a list of anything, carrying the list's constraints.
    $own = ListDataDefinition::create('any');
    $own->setLabel($entry->definition->getLabel());
    foreach ($entry->definition->getConstraints() as $constraint => $options) {
      $own->addConstraint((string) $constraint, $options);
    }
    $errors = [];
    foreach (DefinitionTypedData::create($this->typedDataManager, $own, $items, $name)->validate() as $violation) {
      $errors[] = Violation::fromConstraint($name, (string) $violation->getPropertyPath(), $violation);
    }
    $stored = is_array($current) ? array_values($current) : [];
    $child = $list->child();
    foreach ($items as $delta => $item) {
      if (!is_array($item)) {
        $errors[] = new Violation($name, (string) $delta, $this->t('This value must be of type @expected, @actual given.', [
          '@expected' => 'map',
          '@actual' => get_debug_type($item),
        ]), Violation::TYPE_MISMATCH);
        continue;
      }
      $held = is_array($stored[$delta] ?? NULL) ? $stored[$delta] : [];
      foreach ($this->validateFramed($child, $item, $held, $name . '.' . $delta, $list->item->reads, $level) as $violation) {
        $errors[] = $violation->at($name, $delta . '.' . $violation->fullPath());
      }
    }
    return $errors;
  }

  /**
   * Answers whether a refused value is a stale reference.
   *
   * Four things have to be true at once, and each of them rules out a
   * case that is not stale:
   *
   * - The value is exactly what storage holds for that key. A value that
   *   differs was chosen by whoever sent it, so it is refused however
   *   far outside the list it falls. This is the whole line between
   *   "re-choose this" and "that is not a valid answer".
   * - Every key it refines against also holds exactly what storage
   *   holds. A run that moves a dependency is what narrowed the list
   *   away from the stored value — a venue changed, and the room stored
   *   under the old one is not among the new one's — so the refusal is
   *   about this run's own answers, not about the site, and it blocks.
   *   Stale is for what the site did; this is what the caller did.
   * - The key offers a list of values at all, read from the options
   *   service, which is the same list a generated select renders. A key
   *   with no list has no membership to fall outside of; whatever its
   *   constraints refused, they refused on their own terms.
   * - The value is not in that list. If it is, the refusal came from
   *   some other constraint — a length, a range — and that is an
   *   ordinary refusal of a value the key still offers.
   *
   * Lists are deliberately not covered. A multiple select's items are
   * each members of the same set, so a stale item would have to be
   * stashed and warned about per item, with a partial keep that neither
   * the widget nor this rule has a shape for. A list whose items went
   * stale is refused as it always was.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The refined definition, which is where the narrowed list lives.
   * @param mixed $value
   *   The value that was refused.
   * @param string $name
   *   The key.
   * @param array $current
   *   The stored values, in input layout.
   * @param string[] $dependencies
   *   The keys the key refines against.
   * @param array $values
   *   The values of this run, at the key's own level.
   *
   * @return bool
   *   TRUE when the refusal is a stale reference.
   */
  protected function isStale(DataDefinitionInterface $definition, mixed $value, string $name, array $current, array $dependencies, array $values): bool {
    if ($definition instanceof ListDataDefinitionInterface) {
      return FALSE;
    }
    if (!array_key_exists($name, $current) || $current[$name] !== $value) {
      return FALSE;
    }
    foreach ($dependencies as $dependency) {
      // A key an alter mounted is named by its dotted path.
      $parents = explode('.', $dependency);
      if (NestedArray::getValue($values, $parents) !== NestedArray::getValue($current, $parents)) {
        return FALSE;
      }
    }
    $set = $this->options->resolve($definition);
    return $set !== NULL && !$set->allows($value);
  }

  /**
   * Builds the entry reporting one stale reference.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The refined definition, for its label.
   * @param mixed $value
   *   The stale value, which the message names so that whoever reads it
   *   knows what is about to be kept.
   * @param string $name
   *   The key.
   *
   * @return \Drupal\surface\Pipeline\Violation
   *   The stale entry.
   */
  protected function staleViolation(DataDefinitionInterface $definition, mixed $value, string $name): Violation {
    return new Violation($name, '', $this->t('@label keeps the value @value, which is no longer available. Choose a new one when you can.', [
      '@label' => $definition->getLabel() ?? $name,
      '@value' => (string) $value,
    ]), Violation::STALE, TRUE, DefinitionMetadata::isSecret($definition) ? NULL : $value);
  }

}
