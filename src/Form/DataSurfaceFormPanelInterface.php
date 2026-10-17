<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\ResourceBuild\OperationRoute;

/**
 * Something shown inside an operation form's data surface, rebuilt with it.
 *
 * Named by a route's optional `_data_surface_panel` default, as a service
 * id or a class, the way the cosmetic layer is. DataSurfaceOperationForm
 * places what it returns inside the data surface container, so the AJAX
 * rebuild a refinement triggers replaces it together with the elements:
 * a panel describing the data surface as it stands changes the moment an
 * answer does.
 *
 * Read only. A panel holds no input and is never extracted; what it
 * returns is rendered, not read.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceOperationForm
 * @see \Drupal\data_surface_tool\ContractPanel
 */
interface DataSurfaceFormPanelInterface {

  /**
   * Builds the panel.
   *
   * @param \Drupal\data_surface\ResourceBuild\OperationRoute $served
   *   The resource class, the operation and the context the form serves.
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The resource as built in that context, before any value arrived.
   * @param array $values
   *   The values the form's elements are built with: what is stored,
   *   overlaid with the answers of a rebuild in progress. Refining the
   *   data surface by them gives the data surface the elements show.
   *
   * @return array
   *   A render array.
   */
  public function buildPanel(OperationRoute $served, DataSurfaceInterface $declared, array $values): array;

}
