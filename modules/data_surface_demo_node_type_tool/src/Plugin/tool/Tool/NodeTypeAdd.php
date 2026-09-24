<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type_tool\Plugin\tool\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Pipeline\DataSurfaceResult;
use Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider;
use Drupal\data_surface_tool\SurfaceProviderToolBase;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\OutputDefinition;

/**
 * Creates a content type through the content type surface.
 *
 * Read it for what it does not say. No key of a content type appears
 * here, and nothing any other module adds to one: the values input is
 * the provider's surface for the add operation, converted as the
 * instance is asked for its inputs, and execution is the provider's
 * access answer, its target, and one pipeline call. A module that mounts
 * a setting on the content type surface is therefore advertised,
 * validated and stored by this tool without this file changing.
 *
 * @see \Drupal\data_surface_tool\SurfaceProviderToolBase
 * @see \Drupal\data_surface_demo_node_type\NodeTypeSurfaceProvider
 */
#[Tool(
  id: 'data_surface:node_type_add',
  label: new TranslatableMarkup('Add content type'),
  description: new TranslatableMarkup('Creates a content type. The values input is the content type surface itself: every setting it takes, including those other modules add, with its label, its meaning and the values it allows.'),
  operation: ToolOperation::Write,
  input_definitions: [
    SurfaceProviderToolBase::VALUES => new MapInputDefinition(
      label: new TranslatableMarkup('Content type values'),
      description: new TranslatableMarkup('The settings of the new content type, as the content type surface describes them.'),
      required: TRUE,
    ),
    SurfaceProviderToolBase::DRY_RUN => new InputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Dry run'),
      description: new TranslatableMarkup('Accept, validate and prepare the values without creating anything.'),
      required: FALSE,
      default_value: FALSE,
    ),
  ],
  output_definitions: [
    SurfaceProviderToolBase::VALUES => new OutputDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Accepted values'),
      description: new TranslatableMarkup('The values as the surface accepted them, with every declared default filled in.'),
    ),
    SurfaceProviderToolBase::COMMITTED => new OutputDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Created'),
      description: new TranslatableMarkup('Whether the content type was created; false for a dry run.'),
    ),
  ],
)]
final class NodeTypeAdd extends SurfaceProviderToolBase {

  /**
   * {@inheritdoc}
   */
  protected static function providerService(): string {
    return 'data_surface_demo_node_type.provider';
  }

  /**
   * {@inheritdoc}
   */
  protected function operation(): string {
    return NodeTypeSurfaceProvider::OPERATION_ADD;
  }

  /**
   * {@inheritdoc}
   */
  protected function resultMessage(DataSurfaceResult $result): TranslatableMarkup {
    return $result->committed
      ? $this->t('The content type was created.')
      : $this->t('Dry run: the values were accepted and prepared, and no content type was created.');
  }

}
