<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

/**
 * Compares a full schema with its baseline and classifies each difference.
 *
 * The additive-diff lint. Both are full schemas (ContractEmitter::emitFull())
 * of one resource or structure in one operation: the one committed as the
 * baseline, the promise made, and the one the code emits today. Every
 * difference is named with its path and one of five effects on the
 * documents the schema accepts:
 *
 * - NARROWS: some document the baseline accepted is refused now (a key
 *   made required, a bound tightened, a choice removed, a rule added);
 * - WIDENS: every document the baseline accepted is accepted, and more
 *   (a bound widened, a choice added, `null` allowed);
 * - ADDS: a key that may be left out was added, so every document the
 *   baseline accepted is still accepted;
 * - BREAKS: neither (a key removed or renamed, a type replaced, a
 *   reference to another def, a required port);
 * - NOTE: nothing a validator reads (a label, a description, a default,
 *   a marker under `x-surface`), and one thing it does: a required key
 *   added to the inputs of an operation that acts on what is stored (an
 *   update or a read), which no document stored or sent before held, so
 *   a write supplies it where nothing is stored, a key left out keeps
 *   what is there, and the pipeline refuses one that does not by name.
 *   Added with no default to a create's inputs it NARROWS: every create
 *   an old client sends leaves it out and is refused.
 *
 * Two documents written in different versions of the contract's own wire
 * format (`x-surface.contract`, absent reading as 1) are not compared
 * keyword by keyword: every keyword may be spelled anew, and the
 * difference is one change of its own class, CONTRACT, which re-pinning
 * the baseline answers.
 *
 * For the inputs, what a client sends, NARROWS and BREAKS are breaking;
 * for the outputs, what a client reads, WIDENS and BREAKS are. A
 * breaking change passes when the version of the id that owns it (the
 * document's resource or structure, or the def the path is inside) was
 * raised, or when the baseline allows its path, and fails otherwise. A
 * difference no rule here reads is BREAKS: the lint is never more
 * lenient than it can show.
 *
 * Pure: it reads the two documents and nothing else, so an adopter's
 * test and the Drush command judge alike.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 */
final class ContractDiff {

  /**
   * Some document the baseline accepted is refused now.
   */
  public const NARROWS = 'narrows';

  /**
   * Every document the baseline accepted is accepted, and more.
   */
  public const WIDENS = 'widens';

  /**
   * A key that may be left out was added.
   */
  public const ADDS = 'adds';

  /**
   * Neither narrower nor wider: replaced.
   */
  public const BREAKS = 'breaks';

  /**
   * Nothing a validator reads.
   */
  public const NOTE = 'note';

  /**
   * The two documents are written in different versions of the contract.
   */
  public const CONTRACT = 'contract';

  /**
   * Keywords that annotate and never refuse.
   *
   * `format` is an annotation by decision
   * (docs/decisions.md#format-is-an-annotation), and a default is filled
   * by the pipeline, never by a client, so changing one changes what a
   * key left out becomes on a create, not what is accepted.
   */
  protected const ANNOTATIONS = [
    'title',
    'description',
    'default',
    'examples',
    '$comment',
    '$id',
    'deprecated',
    'format',
    'writeOnly',
  ];

  /**
   * Lower bounds: raised or added narrows.
   */
  protected const LOWER = ['minimum', 'exclusiveMinimum', 'minLength', 'minItems', 'minProperties'];

  /**
   * Upper bounds: lowered or added narrows.
   */
  protected const UPPER = ['maximum', 'exclusiveMaximum', 'maxLength', 'maxItems', 'maxProperties'];

  /**
   * Rules with no order: added narrows, removed widens, changed replaces.
   */
  protected const RULES = [
    'pattern',
    'multipleOf',
    'not',
    'uniqueItems',
    'contains',
    'propertyNames',
    'dependentRequired',
    'dependentSchemas',
    'minContains',
    'maxContains',
  ];

  /**
   * How an operation behaves, which a client acts on.
   */
  protected const FACTS = ['id', 'kind', 'creates', 'destructive', 'idempotent'];

  /**
   * The kind of the operation the documents being compared describe.
   *
   * Read from today's document, or the baseline's, when compare() starts:
   * whether a required key added asks something of every old call
   * depends on it. NULL for a structure described alone, which states
   * none.
   */
  private ?string $kind = NULL;

  /**
   * Compares one document with its baseline.
   *
   * @param array $baseline
   *   The baseline document, as decoded from JSON: `resource`,
   *   `operation`, `schema` and, when declared, `outputs` (a document of
   *   contract 1 says `surface` and `situation`).
   * @param array $current
   *   Today's document, the same way.
   * @param array<string, string> $allowed
   *   Paths a breaking change is allowed at, each with its reason.
   *
   * @return \Drupal\data_surface\Contract\ContractChange[]
   *   Every difference, judged.
   */
  public function compare(array $baseline, array $current, array $allowed = []): array {
    $surface = self::idOf($current) ?? self::idOf($baseline) ?? '';
    $prefix = (self::operationOf($current) ?? self::operationOf($baseline) ?? '') . '#';
    $was = self::contractOf($baseline);
    $is = self::contractOf($current);
    if ($was !== $is) {
      // Every keyword may be spelled anew: one change, not one per keyword.
      return [new ContractChange($surface, $surface, $prefix, sprintf('contract version %d to %d', $was, $is), self::CONTRACT, ContractChange::CONTRACT)];
    }
    $this->kind = self::kindOf($current) ?? self::kindOf($baseline);
    $found = $this->node($baseline['schema'] ?? TRUE, $current['schema'] ?? TRUE, $prefix, $surface, FALSE);
    if (isset($baseline['outputs']) && !isset($current['outputs'])) {
      $found[] = [$surface, $prefix . 'outputs', 'outputs no longer declared', self::BREAKS, TRUE];
    }
    elseif (isset($baseline['outputs'], $current['outputs'])) {
      array_push($found, ...$this->node($baseline['outputs'], $current['outputs'], $prefix . 'outputs', $surface, TRUE));
    }
    elseif (isset($current['outputs'])) {
      $found[] = [$surface, $prefix . 'outputs', 'outputs declared', self::NOTE, TRUE];
    }

    $before = $this->versions($baseline);
    $after = $this->versions($current);
    foreach ($after + $before as $owner => $ignored) {
      $was = $before[$owner] ?? 1;
      $is = $after[$owner] ?? 1;
      $at = $prefix . $this->ownerPath($owner, $surface);
      if ($is < $was) {
        $found[] = [$owner, $at, sprintf('version lowered from %d to %d', $was, $is), self::BREAKS, FALSE];
      }
      elseif ($is > $was) {
        $found[] = [$owner, $at, sprintf('version raised from %d to %d', $was, $is), self::NOTE, FALSE];
      }
    }

    $changes = [];
    foreach ($found as [$owner, $path, $change, $effect, $reading]) {
      $breaking = $effect === self::BREAKS || $effect === ($reading ? self::WIDENS : self::NARROWS);
      $status = match (TRUE) {
        !$breaking => ContractChange::COMPATIBLE,
        ($after[$owner] ?? 1) > ($before[$owner] ?? 1) => ContractChange::VERSIONED,
        isset($allowed[$path]) => ContractChange::ALLOWED,
        default => ContractChange::BREAKING,
      };
      $changes[] = new ContractChange($surface, $owner, $path, $change, $effect, $status);
    }
    return $changes;
  }

  /**
   * Reads the versions a document states.
   *
   * @param array $document
   *   The document.
   *
   * @return array<string, int>
   *   The top's version under the document's id, and each def's that is
   *   a resource's or a structure's under its id; a def that states none
   *   is at 1.
   */
  public function versions(array $document): array {
    $schema = is_array($document['schema'] ?? NULL) ? $document['schema'] : [];
    $versions = [(string) self::idOf($document) => (int) ($schema['x-surface']['version'] ?? 1)];
    foreach ($schema['$defs'] ?? [] as $key => $def) {
      if ($this->isFrame($def)) {
        $versions[$this->owner((string) $key)] = (int) ($def['x-surface']['version'] ?? 1);
      }
    }
    return $versions;
  }

  /**
   * Reads which version of the contract's wire format a document is in.
   *
   * @param array $document
   *   The document.
   *
   * @return int
   *   Its `x-surface.contract`; 1 for one that states none, as every
   *   document did before the contract had a version.
   */
  public static function contractOf(array $document): int {
    $schema = is_array($document['schema'] ?? NULL) ? $document['schema'] : [];
    return (int) ($schema['x-surface']['contract'] ?? 1);
  }

  /**
   * Reads the id of the resource or structure a document describes.
   *
   * @param array $document
   *   The document, of any contract version.
   *
   * @return string|null
   *   Its `resource`, or a contract 1 document's `surface`.
   */
  public static function idOf(array $document): ?string {
    $id = $document['resource'] ?? $document['surface'] ?? NULL;
    return is_string($id) ? $id : NULL;
  }

  /**
   * Reads the id of the operation a document describes.
   *
   * @param array $document
   *   The document, of any contract version.
   *
   * @return string|null
   *   Its `operation`, or a contract 1 document's `situation`; NULL for
   *   none.
   */
  public static function operationOf(array $document): ?string {
    $id = array_key_exists('resource', $document) ? ($document['operation'] ?? NULL) : ($document['situation'] ?? NULL);
    return is_string($id) ? $id : NULL;
  }

  /**
   * Reads the kind of the operation a document describes.
   *
   * @param array $document
   *   The document.
   *
   * @return string|null
   *   Its `x-surface.operation.kind`, `create`, `update` or `read`; NULL
   *   for one that states none, a structure described alone.
   */
  public static function kindOf(array $document): ?string {
    $kind = is_array($document['schema'] ?? NULL) ? ($document['schema']['x-surface']['operation']['kind'] ?? NULL) : NULL;
    return is_string($kind) ? $kind : NULL;
  }

  /**
   * Compares two schemas at one path.
   *
   * @param mixed $old
   *   The baseline's schema there.
   * @param mixed $new
   *   Today's.
   * @param string $path
   *   The path.
   * @param string $owner
   *   The id whose version answers for it.
   * @param bool $reading
   *   Whether it describes what a client reads (outputs).
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference: owner, path, change, effect, reading.
   */
  protected function node(mixed $old, mixed $new, string $path, string $owner, bool $reading): array {
    if ($old === $new) {
      return [];
    }
    $one = static fn (string $change, string $effect): array => [[$owner, $path, $change, $effect, $reading]];
    if ($old === FALSE) {
      return $one('allows a value where none was', self::WIDENS);
    }
    if ($new === FALSE) {
      return $one('allows no value where one was', self::NARROWS);
    }
    if ($old === TRUE && is_array($new)) {
      return $one('is narrowed where it was not', self::NARROWS);
    }
    if ($new === TRUE && is_array($old)) {
      return $one('is no longer narrowed', self::WIDENS);
    }
    if (!is_array($old) || !is_array($new)) {
      return $one('changed', self::BREAKS);
    }
    $found = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $keyword) {
      $keyword = (string) $keyword;
      $in_old = array_key_exists($keyword, $old);
      $in_new = array_key_exists($keyword, $new);
      if ($in_old && $in_new && $old[$keyword] === $new[$keyword]) {
        continue;
      }
      array_push($found, ...$this->keyword($keyword, $old, $new, $path . '/' . $keyword, $owner, $reading));
    }
    return $found;
  }

  /**
   * Compares one keyword of two schemas.
   *
   * @param string $keyword
   *   The keyword.
   * @param array $old
   *   The baseline's schema.
   * @param array $new
   *   Today's.
   * @param string $path
   *   The keyword's path.
   * @param string $owner
   *   The id whose version answers for it.
   * @param bool $reading
   *   Whether it describes what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function keyword(string $keyword, array $old, array $new, string $path, string $owner, bool $reading): array {
    $in_old = array_key_exists($keyword, $old);
    $in_new = array_key_exists($keyword, $new);
    $a = $old[$keyword] ?? NULL;
    $b = $new[$keyword] ?? NULL;
    $one = static fn (string $change, string $effect): array => [[$owner, $path, $change, $effect, $reading]];
    $by_presence = static fn (string $what): array => $one(
      $what . ($in_old ? ($in_new ? ' changed' : ' removed') : ' added'),
      $in_old ? ($in_new ? self::BREAKS : self::WIDENS) : self::NARROWS,
    );

    if (in_array($keyword, self::ANNOTATIONS, TRUE)) {
      return $one($keyword . ' changed', self::NOTE);
    }
    if (in_array($keyword, self::LOWER, TRUE) || in_array($keyword, self::UPPER, TRUE)) {
      if (!$in_old || !$in_new) {
        return $by_presence($keyword);
      }
      $raised = $b > $a;
      $narrows = in_array($keyword, self::LOWER, TRUE) ? $raised : !$raised;
      return $one(sprintf('%s %s from %s to %s', $keyword, $raised ? 'raised' : 'lowered', json_encode($a), json_encode($b)), $narrows ? self::NARROWS : self::WIDENS);
    }
    if (in_array($keyword, self::RULES, TRUE) || $keyword === 'const') {
      return $by_presence($keyword);
    }
    if ($keyword === 'anyOf' && (isset($old['x-surface']['variants']) || isset($new['x-surface']['variants']))) {
      // A key's union of its variants, beside the conditionals that state
      // each one exactly and are judged themselves: it narrows nothing.
      return $one('the union of the variants changed', self::NOTE);
    }

    return match ($keyword) {
      '$defs' => $this->defs((array) $a, (array) $b, $path, $owner, $reading),
      'x-surface' => $this->extension((array) $a, (array) $b, $path, $owner, $reading),
      'readOnly' => $one($b === TRUE ? 'read only now' : 'no longer read only', $b === TRUE ? self::NARROWS : self::WIDENS),
      'type' => $this->type($in_old ? (array) $a : NULL, $in_new ? (array) $b : NULL, $one),
      'enum' => $this->choices(array_map(static fn ($value): array => ['const' => $value], (array) $a), array_map(static fn ($value): array => ['const' => $value], (array) $b), $path, $owner, $reading),
      'oneOf', 'anyOf' => $this->alternatives((array) $a, (array) $b, $path, $owner, $reading),
      'properties' => $this->properties((array) $a, (array) $b, (array) ($new['required'] ?? []), $path, $owner, $reading),
      'required' => $this->required((array) $a, (array) $b, (array) ($old['properties'] ?? []), (array) ($new['properties'] ?? []), $path, $owner, $reading),
      'additionalProperties', 'unevaluatedProperties' => $this->node($in_old ? $a : TRUE, $in_new ? $b : TRUE, $path, $owner, $reading),
      'items', 'then', 'else' => $this->node($in_old ? $a : TRUE, $in_new ? $b : TRUE, $path, $owner, $reading),
      'allOf' => $this->allOf((array) $a, (array) $b, $old, $new, $path, $owner, $reading),
      '$ref' => $one(sprintf('refers to %s where it referred to %s', $b ?? 'nothing', $a ?? 'nothing'), self::BREAKS),
      default => $one($keyword . ' changed, which the lint does not read', self::BREAKS),
    };
  }

  /**
   * Compares the defs of a document, each under its own id.
   *
   * @param array $old
   *   The baseline's defs.
   * @param array $new
   *   Today's.
   * @param string $path
   *   The path of `$defs`.
   * @param string $surface
   *   The document's id, which answers for a named scalar shape's def.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference, owned by the def it is in when that is a resource's
   *   or a structure's, whose version answers for its keys wherever it is
   *   stored; a named scalar shape's def is owned by the document, since
   *   a shape has no version and its values are its users' keys. A def
   *   that comes or goes is a note: where it is used, the property that
   *   refers to it says what changed.
   */
  protected function defs(array $old, array $new, string $path, string $surface, bool $reading): array {
    $found = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
      $key = (string) $key;
      $owner = $this->isFrame($new[$key] ?? $old[$key]) ? $this->owner($key) : $surface;
      $at = $path . '/' . $key;
      if (!array_key_exists($key, $new)) {
        $found[] = [$owner, $at, 'def no longer used', self::NOTE, $reading];
      }
      elseif (!array_key_exists($key, $old)) {
        $found[] = [$owner, $at, 'def added', self::NOTE, $reading];
      }
      else {
        array_push($found, ...$this->node($old[$key], $new[$key], $at, $owner, $reading));
      }
    }
    return $found;
  }

  /**
   * Compares two `x-surface` keywords.
   *
   * Only what a client must act on is more than a note: a constraint the
   * server checks that no keyword states, a port a container wires, and
   * how the operation behaves. The version is judged apart.
   *
   * @param array $old
   *   The baseline's.
   * @param array $new
   *   Today's.
   * @param string $path
   *   Its path.
   * @param string $owner
   *   The id whose version answers for it.
   * @param bool $reading
   *   Whether it describes what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function extension(array $old, array $new, string $path, string $owner, bool $reading): array {
    $found = [];
    $add = static function (string $at, string $change, string $effect) use (&$found, $owner, $reading): void {
      $found[] = [$owner, $at, $change, $effect, $reading];
    };
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $name) {
      $name = (string) $name;
      $a = $old[$name] ?? NULL;
      $b = $new[$name] ?? NULL;
      $at = $path . '/' . $name;
      if ($name === 'version' || $a === $b) {
        continue;
      }
      if ($name === 'checkedOnServer') {
        $before = self::constraints((array) $a);
        $after = self::constraints((array) $b);
        foreach (array_diff($after, $before) as $constraint) {
          $add($at, sprintf('checked on the server now: %s', $constraint), self::NARROWS);
        }
        foreach (array_diff($before, $after) as $constraint) {
          $add($at, sprintf('no longer checked on the server: %s', $constraint), self::WIDENS);
        }
        if ($before === $after) {
          $add($at, 'what a constraint checked on the server says changed', self::NOTE);
        }
      }
      elseif ($name === 'ports') {
        foreach (array_unique([...array_keys((array) $a), ...array_keys((array) $b)]) as $port) {
          $was = $a[$port] ?? NULL;
          $is = $b[$port] ?? NULL;
          if ($was === $is) {
            continue;
          }
          [$change, $effect] = match (TRUE) {
            $is === NULL => ['port removed', self::BREAKS],
            $was === NULL && !empty($is['required']) => ['required port added', self::BREAKS],
            $was === NULL => ['optional port added', self::NOTE],
            default => ['port changed', self::BREAKS],
          };
          $add($at . '/' . $port, $change, $effect);
        }
      }
      elseif ($name === 'operation') {
        foreach (self::FACTS as $fact) {
          if (($a[$fact] ?? NULL) !== ($b[$fact] ?? NULL)) {
            $add($at . '/' . $fact, sprintf('the operation\'s %s changed', $fact), self::BREAKS);
          }
        }
        foreach (['label', 'description'] as $words) {
          if (($a[$words] ?? NULL) !== ($b[$words] ?? NULL)) {
            $add($at . '/' . $words, sprintf('the operation\'s %s changed', $words), self::NOTE);
          }
        }
      }
      else {
        $add($at, 'x-surface ' . $name . ' changed', self::NOTE);
      }
    }
    return $found;
  }

  /**
   * Names the constraints a `checkedOnServer` lists.
   *
   * @param array $entries
   *   Its entries, each `{constraint, message}`.
   *
   * @return string[]
   *   The constraints' ids.
   */
  protected static function constraints(array $entries): array {
    return array_map(static fn (mixed $entry): string => (string) (is_array($entry) ? ($entry['constraint'] ?? '') : $entry), $entries);
  }

  /**
   * Compares two types.
   *
   * @param array|null $old
   *   The baseline's types, NULL for none (any).
   * @param array|null $new
   *   Today's.
   * @param callable $one
   *   Makes the one difference.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   The difference.
   */
  protected function type(?array $old, ?array $new, callable $one): array {
    $said = static fn (?array $types): string => $types === NULL ? 'any' : implode(' or ', $types);
    $change = sprintf('type changed from %s to %s', $said($old), $said($new));
    return match (TRUE) {
      $old === NULL => $one($change, self::NARROWS),
      $new === NULL, array_diff($old, $new) === [] => $one($change, self::WIDENS),
      array_diff($new, $old) === [] => $one($change, self::NARROWS),
      default => $one($change, self::BREAKS),
    };
  }

  /**
   * Compares two `oneOf` or `anyOf` lists.
   *
   * @param array $old
   *   The baseline's alternatives.
   * @param array $new
   *   Today's.
   * @param string $path
   *   Their path.
   * @param string $owner
   *   The id whose version answers for them.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function alternatives(array $old, array $new, string $path, string $owner, bool $reading): array {
    if ($this->isChoices($old) && $this->isChoices($new)) {
      return $this->choices($old, $new, $path, $owner, $reading);
    }
    if (count($old) !== count($new)) {
      return [[$owner, $path, 'alternatives changed, which the lint does not read', self::BREAKS, $reading]];
    }
    $found = [];
    foreach ($old as $i => $alternative) {
      array_push($found, ...$this->node($alternative, $new[$i], $path . '/' . $i, $owner, $reading));
    }
    return $found;
  }

  /**
   * Answers whether a list of alternatives is a list of allowed values.
   *
   * @param array $alternatives
   *   The list.
   *
   * @return bool
   *   TRUE when every entry is a `const`, with a label or not.
   */
  protected function isChoices(array $alternatives): bool {
    foreach ($alternatives as $alternative) {
      if (!is_array($alternative) || !array_key_exists('const', $alternative)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Compares two lists of allowed values.
   *
   * @param array $old
   *   The baseline's, each `{const, title?}`.
   * @param array $new
   *   Today's.
   * @param string $path
   *   Their path.
   * @param string $owner
   *   The id whose version answers for them.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   One difference per value removed, added or relabeled.
   */
  protected function choices(array $old, array $new, string $path, string $owner, bool $reading): array {
    $keyed = static function (array $choices): array {
      $keyed = [];
      foreach ($choices as $choice) {
        $keyed[(string) json_encode($choice['const'])] = $choice;
      }
      return $keyed;
    };
    $old = $keyed($old);
    $new = $keyed($new);
    $found = [];
    foreach (array_diff_key($old, $new) as $value => $ignored) {
      $found[] = [$owner, $path, sprintf('choice %s removed', $value), self::NARROWS, $reading];
    }
    foreach (array_diff_key($new, $old) as $value => $ignored) {
      $found[] = [$owner, $path, sprintf('choice %s added', $value), self::WIDENS, $reading];
    }
    foreach (array_intersect_key($old, $new) as $value => $choice) {
      if ($choice !== $new[$value]) {
        $found[] = [$owner, $path, sprintf('choice %s relabeled', $value), self::NOTE, $reading];
      }
    }
    return $found;
  }

  /**
   * Compares the properties of two objects.
   *
   * @param array $old
   *   The baseline's properties.
   * @param array $new
   *   Today's.
   * @param array $required
   *   Today's required keys.
   * @param string $path
   *   The path of `properties`.
   * @param string $owner
   *   The id whose version answers for them.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function properties(array $old, array $new, array $required, string $path, string $owner, bool $reading): array {
    $found = [];
    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
      $key = (string) $key;
      $at = $path . '/' . $key;
      if (!array_key_exists($key, $new)) {
        $found[] = [$owner, $at, 'key removed', self::BREAKS, $reading];
      }
      elseif (!array_key_exists($key, $old)) {
        // Judged by the operation's kind. Nothing stored or sent before
        // held it, so an operation acting on what is stored changes no
        // document's meaning: a key left out keeps what is there, a write
        // supplies it where nothing is, and the pipeline refuses one that
        // does not in the key's own words. A create has nothing stored to
        // keep, so every create an old client sends is refused; and a
        // structure described alone states no kind, so the lint cannot
        // tell which its containers call it in, and is never more lenient
        // than it can show (each container's own document judges it in
        // that container's kind).
        // Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
        [$change, $effect] = match (TRUE) {
          !in_array($key, $required, TRUE) => ['optional key added', self::ADDS],
          $reading => ['required key added', self::NARROWS],
          is_array($new[$key]) && array_key_exists('default', $new[$key]) => [
            'required key added, with a default',
            self::NOTE,
          ],
          $this->kind === 'update' || $this->kind === 'read' => [
            sprintf('required key added: a write must now supply %s where nothing is stored', $key),
            self::NOTE,
          ],
          $this->kind === 'create' => [
            sprintf('required key added with no default: a create that leaves %s out is refused', $key),
            self::NARROWS,
          ],
          default => [
            sprintf('required key added with no default: a container that creates this structure and leaves %s out is refused', $key),
            self::NARROWS,
          ],
        };
        $found[] = [$owner, $at, $change, $effect, $reading];
      }
      else {
        array_push($found, ...$this->node($old[$key], $new[$key], $at, $owner, $reading));
      }
    }
    return $found;
  }

  /**
   * Compares the required keys of two objects that both have them.
   *
   * A key that came or went is the properties' to report.
   *
   * @param array $old
   *   The baseline's required keys.
   * @param array $new
   *   Today's.
   * @param array $was
   *   The baseline's properties.
   * @param array $is
   *   Today's.
   * @param string $path
   *   The path of `required`.
   * @param string $owner
   *   The id whose version answers for them.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function required(array $old, array $new, array $was, array $is, string $path, string $owner, bool $reading): array {
    $found = [];
    foreach (array_diff($new, $old) as $key) {
      if (array_key_exists($key, $was)) {
        $found[] = [$owner, $path, sprintf('%s made required', $key), self::NARROWS, $reading];
      }
    }
    foreach (array_diff($old, $new) as $key) {
      if (array_key_exists($key, $is)) {
        $found[] = [$owner, $path, sprintf('%s made optional', $key), self::WIDENS, $reading];
      }
    }
    return $found;
  }

  /**
   * Compares the conjuncts of two objects: conditionals and compositions.
   *
   * Each entry is matched by what it is: a conditional by its `if` (and
   * whether its object's `x-surface.rules` lists it as a declared rule), a
   * composition by its reference, the rest by its whole text. A conjunct
   * dropped only widens. One added narrows, unless it is a conditional no
   * document the baseline
   * accepted can meet, because it asks a key for a value the baseline
   * did not allow there (a new variant's), or a reference whose members
   * the properties report.
   *
   * @param array $old
   *   The baseline's `allOf`.
   * @param array $new
   *   Today's.
   * @param array $frame
   *   The baseline's object the `allOf` is on.
   * @param array $now
   *   Today's.
   * @param string $path
   *   The path of `allOf`.
   * @param string $owner
   *   The id whose version answers for them.
   * @param bool $reading
   *   Whether they describe what a client reads.
   *
   * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
   *   Each difference.
   */
  protected function allOf(array $old, array $new, array $frame, array $now, string $path, string $owner, bool $reading): array {
    $old = $this->conjuncts($old, (array) ($frame['x-surface']['rules'] ?? []));
    $new = $this->conjuncts($new, (array) ($now['x-surface']['rules'] ?? []));
    $found = [];
    foreach ($old as $key => [$i, $entry]) {
      if (!isset($new[$key])) {
        $composition = str_starts_with($key, 'ref:');
        $found[] = $composition
          ? [$owner, $path . '/' . $i, 'composition removed', self::NOTE, $reading]
          : [$owner, $path . '/' . $i, 'rule removed', self::WIDENS, $reading];
      }
    }
    foreach ($new as $key => [$i, $entry]) {
      if (isset($old[$key])) {
        array_push($found, ...$this->node($old[$key][1], $entry, $path . '/' . $i, $owner, $reading));
      }
      elseif (str_starts_with($key, 'ref:')) {
        $found[] = [$owner, $path . '/' . $i, 'composition added', self::NOTE, $reading];
      }
      elseif (is_array($entry) && is_array($entry['if'] ?? NULL) && $this->unmet($entry['if'], $frame)) {
        $found[] = [$owner, $path . '/' . $i, 'rule added for a value the baseline did not allow', self::NOTE, $reading];
      }
      else {
        $found[] = [$owner, $path . '/' . $i, 'rule added', self::NARROWS, $reading];
      }
    }
    return $found;
  }

  /**
   * Keys the entries of an `allOf` by what each is.
   *
   * @param array $entries
   *   The entries.
   * @param int[] $rules
   *   The indexes of the declared rules' conditionals among them.
   *
   * @return array<string, array{0: int, 1: mixed}>
   *   Each entry with its position, by its key.
   */
  protected function conjuncts(array $entries, array $rules = []): array {
    $keyed = [];
    foreach (array_values($entries) as $i => $entry) {
      $key = match (TRUE) {
        is_array($entry) && array_key_exists('if', $entry) => 'if:' . json_encode($entry['if']) . (in_array($i, $rules, TRUE) ? ':declared' : ''),
        is_array($entry) && isset($entry['$ref']) && count($entry) === 1 => 'ref:' . $entry['$ref'],
        default => 'is:' . json_encode($entry),
      };
      for ($n = 2, $unique = $key; isset($keyed[$unique]); $n++) {
        $unique = $key . '#' . $n;
      }
      $keyed[$unique] = [$i, $entry];
    }
    return $keyed;
  }

  /**
   * Answers whether no document the baseline accepted meets an `if`.
   *
   * @param array $if
   *   The condition.
   * @param array $frame
   *   The baseline's object it is about.
   *
   * @return bool
   *   TRUE when the condition requires a key to hold a value the baseline
   *   did not allow there, or a key the baseline did not have.
   */
  protected function unmet(array $if, array $frame): bool {
    foreach ((array) ($if['properties'] ?? []) as $key => $condition) {
      if (!in_array($key, (array) ($if['required'] ?? []), TRUE) || !is_array($condition) || !array_key_exists('const', $condition)) {
        continue;
      }
      $property = $frame['properties'][$key] ?? NULL;
      if ($property === NULL) {
        return TRUE;
      }
      $allowed = $this->allowed($property);
      if ($allowed !== NULL && !in_array($condition['const'], $allowed, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Reads the values a property allows, when it lists them.
   *
   * @param mixed $property
   *   The property's schema.
   *
   * @return array|null
   *   The values, or NULL when it does not list them.
   */
  protected function allowed(mixed $property): ?array {
    if (!is_array($property)) {
      return NULL;
    }
    if (array_key_exists('const', $property)) {
      return [$property['const']];
    }
    if (isset($property['enum'])) {
      return (array) $property['enum'];
    }
    $alternatives = $property['oneOf'] ?? $property['anyOf'] ?? NULL;
    return is_array($alternatives) && $this->isChoices($alternatives) ? array_column($alternatives, 'const') : NULL;
  }

  /**
   * Answers whether a def is a resource's or a structure's frame.
   *
   * @param mixed $def
   *   The def.
   *
   * @return bool
   *   TRUE for an object with keys of its own; FALSE for a named scalar
   *   shape's def, whose type is its value's.
   */
  protected function isFrame(mixed $def): bool {
    return is_array($def) && (in_array('object', (array) ($def['type'] ?? []), TRUE) || isset($def['properties']) || isset($def['allOf']));
  }

  /**
   * Names the id a def's key belongs to.
   *
   * @param string $key
   *   The key under `$defs`: an id, or `<id>@<n>` for a second
   *   declaration of one id.
   *
   * @return string
   *   The id.
   */
  protected function owner(string $key): string {
    return preg_replace('/@\d+$/', '', $key) ?? $key;
  }

  /**
   * Gives the path a version change is reported at.
   *
   * @param string $owner
   *   The id.
   * @param string $surface
   *   The document's id.
   *
   * @return string
   *   The pointer to where its version is stated.
   */
  protected function ownerPath(string $owner, string $surface): string {
    return $owner === $surface ? '/x-surface/version' : '/$defs/' . $owner . '/x-surface/version';
  }

}
