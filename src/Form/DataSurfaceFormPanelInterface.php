<?php

declare(strict_types=1);

namespace Drupal\data_surface\Form;

use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\SurfaceBuild\SituationRoute;

/**
 * Something shown inside a situation form's surface, rebuilt with it.
 *
 * Named by a route's optional `_data_surface_panel` default, as a service
 * id or a class, the way the cosmetic layer is. DataSurfaceSituationForm
 * places what it returns inside the surface container, so the AJAX
 * rebuild a refinement triggers replaces it together with the elements:
 * a panel describing the surface as it stands changes the moment an
 * answer does.
 *
 * Read only. A panel holds no input and is never extracted; what it
 * returns is rendered, not read.
 *
 * @see \Drupal\data_surface\Form\DataSurfaceSituationForm
 * @see \Drupal\data_surface_tool\SurfaceContractPanel
 */
interface DataSurfaceFormPanelInterface {

  /**
   * Builds the panel.
   *
   * @param \Drupal\data_surface\SurfaceBuild\SituationRoute $served
   *   The surface class, the situation and the context the form serves.
   * @param \Drupal\data_surface\DataSurfaceInterface $declared
   *   The surface as built in that context, before any value arrived.
   * @param array $values
   *   The values the form's elements are built with: what is stored,
   *   overlaid with the answers of a rebuild in progress. Refining the
   *   surface by them gives the surface the elements show.
   *
   * @return array
   *   A render array.
   */
  public function buildPanel(SituationRoute $served, DataSurfaceInterface $declared, array $values): array;

}
