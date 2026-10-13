<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\data_surface\Contract\ContractEmitter;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\Form\DataSurfaceFormPanelInterface;
use Drupal\data_surface\SurfaceBuild\SituationRoute;
use Drupal\data_surface\SurfaceBuild\SurfaceRegistry;
use Drupal\data_surface_examples\Target\RegistrationTarget;

/**
 * The two panels under an example's form: the contract, and today.
 *
 * Named by the examples' routes in `_data_surface_panel` as
 * `data_surface_examples.panels`. A situation form takes one panel, so
 * this is one: the contract panel it is handed, unchanged, and below it
 * what config schema alone can say about the same settings, compared
 * key by key with the full schema the surface emits, the one that reads
 * no value. The form replaces both on every rebuild; only the contract
 * panel has anything new to say then.
 *
 * @see \Drupal\data_surface_tool\SurfaceContractPanel
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
   *   The panel showing the surface's contract.
   * @param \Drupal\data_surface_examples\ConfigSchemaPanel $configSchemaPanel
   *   The panel showing what config schema can say.
   * @param \Drupal\data_surface\Contract\ContractEmitter $emitter
   *   The main module's contract emitter.
   * @param \Drupal\data_surface\SurfaceBuild\SurfaceRegistry $registry
   *   What discovery found, for the surface's id and its target.
   */
  public function __construct(
    protected readonly DataSurfaceFormPanelInterface $contractPanel,
    protected readonly ConfigSchemaPanel $configSchemaPanel,
    protected readonly ContractEmitter $emitter,
    protected readonly SurfaceRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildPanel(SituationRoute $served, DataSurfaceInterface $declared, array $values): array {
    $contract_panel = $this->contractPanel->buildPanel($served, $declared, $values);
    $panels = [
      // Last in the surface container, where the contract panel put
      // itself.
      '#weight' => $contract_panel['#weight'] ?? 0,
      self::CONTRACT => ['#weight' => 0] + $contract_panel,
    ];
    $definition = $this->registry->getDefinition($served->surface);
    $target = (string) $definition->target;
    if (is_a($target, RegistrationTarget::class, TRUE)) {
      // The full schema the contract panel shows, asked for again rather
      // than read back out of its render array: static against static.
      $full = $this->emitter->emitFull($declared, $definition->id, $served->situation->id, $served->situation->label);
      $panels[self::TODAY] = ['#weight' => 1] + $this->configSchemaPanel->build($target::CONFIG, $full->document['schema']);
    }
    return $panels;
  }

}
