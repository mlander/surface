<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Form\SurfaceFormPanelInterface;
use Drupal\surface\ResourceBuild\OperationRoute;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * The panels under an example's form: the contract, and today.
 *
 * Named by the examples' routes in `_surface_panel` as
 * `surface_examples.panels`. An operation form takes one panel, so
 * this is one: the contract panel it is handed, unchanged, and below it
 * what config schema alone can say about the same settings, compared
 * key by key with the full schema the resource emits, the one that reads
 * no value. Above them, for examples 7 to 12 and 14 only, what the
 * example shows (ConceptPanel), and on every example's page the files it
 * is made of (FilesPanel), between that and the contract. The form
 * replaces them on every rebuild; only the contract panel has anything
 * new to say then.
 *
 * @see \Drupal\surface\Form\ContractPanel
 * @see \Drupal\surface_examples\ConfigSchemaPanel
 * @see \Drupal\surface_examples\FilesPanel
 */
final class ExamplePanels implements SurfaceFormPanelInterface {

  /**
   * The key the contract panel is placed at.
   */
  public const CONTRACT = 'contract_panel';

  /**
   * The key the panel of what config schema says is placed at.
   */
  public const TODAY = 'today_panel';

  /**
   * The key the panel saying what examples 7 to 12 and 14 show is placed at.
   */
  public const CONCEPT = 'concept_panel';

  /**
   * The key the panel of the example's files is placed at.
   */
  public const FILES = 'files_panel';

  /**
   * Constructs ExamplePanels.
   *
   * @param \Drupal\surface\Form\SurfaceFormPanelInterface $contractPanel
   *   The panel showing the resource's contract.
   * @param \Drupal\surface_examples\ConfigSchemaPanel $configSchemaPanel
   *   The panel showing what config schema can say.
   * @param \Drupal\surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for the resource's id and its storage.
   * @param \Drupal\surface\ResourceBuild\ResourcesInterface $resources
   *   The build step, for the full schema built for the account alone.
   * @param \Drupal\surface_examples\ConceptPanel $conceptPanel
   *   The panel saying what examples 7 to 12 and 14 show.
   * @param \Drupal\surface_examples\FilesPanel $filesPanel
   *   The panel listing the files an example is made of.
   */
  public function __construct(
    protected readonly SurfaceFormPanelInterface $contractPanel,
    protected readonly ConfigSchemaPanel $configSchemaPanel,
    protected readonly ContractEmitter $emitter,
    protected readonly ResourceRegistry $registry,
    protected readonly ResourcesInterface $resources,
    protected readonly ConceptPanel $conceptPanel,
    protected readonly FilesPanel $filesPanel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildPanel(OperationRoute $served, SurfaceInterface $declared, array $values): array {
    $contract_panel = $this->contractPanel->buildPanel($served, $declared, $values);
    $panels = [
      // Last in the form's 'surface' container, where the contract panel put
      // itself.
      '#weight' => $contract_panel['#weight'] ?? 0,
      self::CONTRACT => ['#weight' => 0] + $contract_panel,
    ];
    // Examples 7 to 12 and 14 say what they show first; 1 to 3 have
    // nothing more.
    $concept = $this->conceptPanel->build($served, $declared, $values);
    if ($concept !== NULL) {
      $panels[self::CONCEPT] = ['#weight' => -1] + $concept;
    }
    $definition = $this->registry->getDefinition($served->resource);
    // The example whose files list the resource's class.
    $number = $definition->class === NULL ? NULL : Series::numberOf(Series::relative((string) (new \ReflectionClass($definition->class))->getFileName()));
    if ($number !== NULL) {
      $panels[self::FILES] = ['#weight' => -0.5] + $this->filesPanel->build($number);
    }
    $storage = (string) $definition->storage;
    if (is_a($storage, ConfigObjectStorage::class, TRUE)) {
      // The full schema the contract panel shows, asked for again rather
      // than read back out of its render array: static against static.
      $full = $this->emitter->emitFull($this->resources->buildStatic($served->resource, $served->context), $definition->id, $served->operation->id, $served->operation->label, operation: $served->operation->facts());
      $panels[self::TODAY] = ['#weight' => 1] + $this->configSchemaPanel->build($storage::CONFIG, $full->document['schema']);
    }
    return $panels;
  }

}
