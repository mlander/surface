<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormPanelInterface;
use Drupal\data_surface\ResourceBuild\OperationRoute;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * The two panels under an example's form: the contract, and today.
 *
 * Named by the examples' routes in `_data_surface_panel` as
 * `data_surface_examples.panels`. An operation form takes one panel, so
 * this is one: the contract panel it is handed, unchanged, and below it
 * what config schema alone can say about the same settings, compared
 * key by key with the full schema the resource emits, the one that reads
 * no value. The form replaces both on every rebuild; only the contract
 * panel has anything new to say then.
 *
 * @see \Drupal\data_surface_tool\ContractPanel
 * @see \Drupal\data_surface_examples\ConfigSchemaPanel
 */
final class ExamplePanels implements DataSurfaceFormPanelInterface {

  /**
   * The key the contract panel is placed at.
   */
  public const CONTRACT = 'contract_panel';

  /**
   * The key the panel of what config schema says is placed at.
   */
  public const TODAY = 'today_panel';

  /**
   * Constructs ExamplePanels.
   *
   * @param \Drupal\data_surface\Form\DataSurfaceFormPanelInterface $contractPanel
   *   The panel showing the resource's contract.
   * @param \Drupal\data_surface_examples\ConfigSchemaPanel $configSchemaPanel
   *   The panel showing what config schema can say.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\data_surface\ResourceBuild\ResourceRegistry $registry
   *   What discovery found, for the resource's id and its storage.
   */
  public function __construct(
    protected readonly DataSurfaceFormPanelInterface $contractPanel,
    protected readonly ConfigSchemaPanel $configSchemaPanel,
    protected readonly ContractEmitter $emitter,
    protected readonly ResourceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildPanel(OperationRoute $served, DataSurfaceInterface $declared, array $values): array {
    $contract_panel = $this->contractPanel->buildPanel($served, $declared, $values);
    $panels = [
      // Last in the form's 'surface' container, where the contract panel put
      // itself.
      '#weight' => $contract_panel['#weight'] ?? 0,
      self::CONTRACT => ['#weight' => 0] + $contract_panel,
    ];
    $definition = $this->registry->getDefinition($served->resource);
    $storage = (string) $definition->storage;
    if (is_a($storage, ConfigObjectStorage::class, TRUE)) {
      // The full schema the contract panel shows, asked for again rather
      // than read back out of its render array: static against static.
      $full = $this->emitter->emitFull($declared, $definition->id, $served->operation->id, $served->operation->label);
      $panels[self::TODAY] = ['#weight' => 1] + $this->configSchemaPanel->build($storage::CONFIG, $full->document['schema']);
    }
    return $panels;
  }

}
