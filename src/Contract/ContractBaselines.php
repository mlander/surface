<?php

declare(strict_types=1);

namespace Drupal\data_surface\Contract;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\ResourcesInterface;

/**
 * Emits, reads, writes and compares the contract baselines of modules.
 *
 * A baseline is a committed file per resource or structure,
 * `tests/fixtures/contracts/<id>.json` in the module that declares it:
 * the full schema of each of its operations that needs no subject (or,
 * for one that declares no operation, the one it is described in alone),
 * for the account that asks, as the promise made to every client that
 * read it. ContractDiff judges today's emission against it. Each records
 * the module that declares it, so one directory may hold several modules'
 * baselines and a run naming some of them compares and writes only
 * theirs, and the version of the contract's wire format it was written
 * in (`contract`), so a baseline from before the wire moved is re-pinned,
 * not compared keyword by keyword.
 *
 * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
 */
final class ContractBaselines {

  /**
   * Where a module keeps its baselines, from its own directory.
   */
  public const DIRECTORY = 'tests/fixtures/contracts';

  /**
   * The context a declaration with no operation of its own is described in.
   *
   * A plugin host's verb, which is no declared operation: what a host
   * builds a structure in.
   */
  public const ALONE = 'configure';

  /**
   * Constructs a ContractBaselines.
   *
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   The registry.
   * @param \Drupal\data_surface\ResourceBuild\ResourcesInterface $resources
   *   The build step.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The emitter.
   * @param \Drupal\Core\Extension\ModuleExtensionList $modules
   *   The module list, for where a module's baselines live.
   */
  public function __construct(
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ContractEmitter $emitter,
    protected readonly ModuleExtensionList $modules,
  ) {}

  /**
   * Emits the baselines of every resource and structure some modules declare.
   *
   * A derived one is left out: its keys are the site's, not the code's.
   * An operation that cannot be built without a subject is left out too:
   * its full schema is the one a tool's static definition describes, and
   * nothing here names a subject.
   *
   * @param string[] $modules
   *   The modules.
   *
   * @return array<string, array{resource: string, module: string, contract: int, documents: list<array>}>
   *   Each baseline, by id: the module declaring it, the contract version
   *   its documents are written in and its documents as emitted.
   */
  public function emit(array $modules): array {
    $baselines = [];
    foreach ($this->registry->getDefinitions() as $definition) {
      if ($definition->isDerived() || !in_array($definition->module, $modules, TRUE)) {
        continue;
      }
      $name = $definition->name();
      $documents = [];
      $operations = $this->registry->getOperations($name);
      if ($operations === []) {
        $documents[] = $this->emitter->emitFull($this->resources->buildStatic($name, new OperationContext(self::ALONE)), $definition->id)->document;
      }
      foreach ($operations as $operation) {
        if (!$operation->needsNothing()) {
          continue;
        }
        $context = $this->resources->operation($name, $operation->id);
        $documents[] = $this->emitter->emitFull($this->resources->buildStatic($name, $context), $definition->id, $operation->id, $operation->label, operation: $operation->facts())->document;
      }
      if ($documents !== []) {
        $baselines[$definition->id] = [
          'resource' => $definition->id,
          'module' => $definition->module,
          'contract' => ContractEmitter::CONTRACT,
          'documents' => $documents,
        ];
      }
    }
    ksort($baselines);
    return $baselines;
  }

  /**
   * Gives the directory a module's baselines are kept in.
   *
   * @param string $module
   *   The module.
   *
   * @return string
   *   The directory, relative to the Drupal root.
   */
  public function directory(string $module): string {
    return $this->modules->getPath($module) . '/' . self::DIRECTORY;
  }

  /**
   * Reads every baseline in a directory.
   *
   * @param string $directory
   *   The directory.
   *
   * @return array<string, array>
   *   Each baseline, by the id its file is named for.
   */
  public function read(string $directory): array {
    $baselines = [];
    foreach (glob($directory . '/*.json') ?: [] as $file) {
      $decoded = json_decode((string) file_get_contents($file), TRUE);
      if (!is_array($decoded)) {
        throw new \UnexpectedValueException(sprintf('%s is not a contract baseline: it does not decode as JSON.', $file));
      }
      $baselines[basename($file, '.json')] = $decoded;
    }
    ksort($baselines);
    return $baselines;
  }

  /**
   * Names the module a baseline belongs to.
   *
   * The module it records; for one written before baselines recorded it,
   * the module declaring a resource or structure of its id today; NULL
   * when neither says.
   *
   * @param string $id
   *   The id its file is named for.
   * @param array $baseline
   *   The baseline.
   *
   * @return string|null
   *   The module, or NULL.
   */
  public function moduleOf(string $id, array $baseline): ?string {
    if (is_string($baseline['module'] ?? NULL) && $baseline['module'] !== '') {
      return $baseline['module'];
    }
    foreach ($this->registry->getDefinitions() as $definition) {
      if ($definition->id === $id && !$definition->isDerived()) {
        return $definition->module;
      }
    }
    return NULL;
  }

  /**
   * Keeps the baselines of some modules, and those that name none.
   *
   * A baseline whose module is not listed is another module's promise in
   * a shared directory: it is not compared, and never written or removed.
   * One whose module is unknown (written before baselines recorded it,
   * for a declaration no longer there) is kept as theirs, since nothing
   * says it is another's.
   *
   * @param array<string, array> $baselines
   *   The baselines, by id.
   * @param string[] $modules
   *   The modules.
   *
   * @return array<string, array>
   *   The baselines of those modules, by id.
   */
  public function ofModules(array $baselines, array $modules): array {
    $kept = [];
    foreach ($baselines as $id => $baseline) {
      $module = $this->moduleOf((string) $id, $baseline);
      if ($module === NULL || in_array($module, $modules, TRUE)) {
        $kept[$id] = $baseline;
      }
    }
    return $kept;
  }

  /**
   * Writes one baseline.
   *
   * @param string $directory
   *   The directory.
   * @param array $baseline
   *   The baseline: `resource`, `module`, `contract`, `documents` and,
   *   when any, `allowed`.
   */
  public function write(string $directory, array $baseline): void {
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException(sprintf('%s could not be made.', $directory));
    }
    if (($baseline['allowed'] ?? []) === []) {
      unset($baseline['allowed']);
    }
    file_put_contents($directory . '/' . ($baseline['resource'] ?? $baseline['surface']) . '.json', json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
  }

  /**
   * Compares baselines with today's emission.
   *
   * @param array<string, array> $baselines
   *   The committed baselines, by id.
   * @param array<string, array> $current
   *   Today's, by id, as emit() answers them.
   *
   * @return \Drupal\data_surface\Contract\ContractChange[]
   *   Every difference, judged, in id order. A declaration or a document
   *   no longer emitted breaks; one with no baseline yet fails, unpinned,
   *   until writing the baselines pins it. Baselines written in another
   *   version of the contract are not compared: one change says so, once
   *   for each version they move from, its document and owner `*` (every
   *   such baseline), and fails until writing the baselines re-pins them.
   */
  public function compare(array $baselines, array $current): array {
    $diff = new ContractDiff();
    $current = self::decoded($current);
    $changes = [];
    $moved = [];
    $ids = array_unique([...array_keys($baselines), ...array_keys($current)]);
    sort($ids);
    foreach ($ids as $id) {
      $id = (string) $id;
      if (isset($baselines[$id], $current[$id]) && self::contractOf($baselines[$id]) !== self::contractOf($current[$id])) {
        // Decision: see docs/decisions.md#contract-2.
        $moved[self::contractOf($baselines[$id])][] = $id;
        continue;
      }
      $allowed = array_column($baselines[$id]['allowed'] ?? [], 'reason', 'path');
      if (!isset($current[$id])) {
        $changes[] = new ContractChange($id, $id, '#', 'no longer declared', ContractDiff::BREAKS, isset($allowed['#']) ? ContractChange::ALLOWED : ContractChange::BREAKING);
        continue;
      }
      if (!isset($baselines[$id])) {
        $changes[] = new ContractChange($id, $id, '#', 'no baseline yet', ContractDiff::NOTE, ContractChange::UNPINNED);
        continue;
      }
      $before = self::byOperation($baselines[$id]['documents'] ?? []);
      $after = self::byOperation($current[$id]['documents']);
      foreach ($before + $after as $operation => $ignored) {
        if (!isset($after[$operation])) {
          $changes[] = new ContractChange($id, $id, $operation . '#', 'operation no longer described', ContractDiff::BREAKS, isset($allowed[$operation . '#']) ? ContractChange::ALLOWED : ContractChange::BREAKING);
        }
        elseif (!isset($before[$operation])) {
          $changes[] = new ContractChange($id, $id, $operation . '#', 'operation added', ContractDiff::NOTE, ContractChange::COMPATIBLE);
        }
        else {
          array_push($changes, ...$diff->compare($before[$operation], $after[$operation], $allowed));
        }
      }
    }
    ksort($moved);
    foreach ($moved as $from => $ignored) {
      $changes[] = new ContractChange('*', '*', '#', sprintf('contract version %d to %d', $from, ContractEmitter::CONTRACT), ContractDiff::CONTRACT, ContractChange::CONTRACT);
    }
    return $changes;
  }

  /**
   * Reads which version of the contract a baseline was written in.
   *
   * @param array $baseline
   *   The baseline.
   *
   * @return int
   *   Its `contract`, else its first document's, else 1: a baseline
   *   written before the contract had a version.
   */
  protected static function contractOf(array $baseline): int {
    if (is_int($baseline['contract'] ?? NULL)) {
      return $baseline['contract'];
    }
    $first = $baseline['documents'][0] ?? NULL;
    return is_array($first) ? ContractDiff::contractOf($first) : 1;
  }

  /**
   * Keys documents by the operation each describes.
   *
   * @param array $documents
   *   The documents.
   *
   * @return array<string, array>
   *   Each document, by its operation, the empty string for none.
   */
  protected static function byOperation(array $documents): array {
    $keyed = [];
    foreach ($documents as $document) {
      $keyed[(string) ContractDiff::operationOf($document)] = $document;
    }
    return $keyed;
  }

  /**
   * Reads a value back as a JSON decoder would.
   *
   * @param array $value
   *   The value.
   *
   * @return array
   *   The same, through JSON: strings for markup, arrays for objects.
   */
  protected static function decoded(array $value): array {
    return json_decode(json_encode($value, JSON_THROW_ON_ERROR), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
