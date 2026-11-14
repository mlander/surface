<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\HandCorpus;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\surface\Form\SurfaceFormatterTrait;
use Drupal\surface\SurfaceInterface;

/**
 * A field formatter's settings summary over a hand-built surface.
 */
final class HandFormatter {

  use SurfaceFormatterTrait;
  use StringTranslationTrait;

  /**
   * Constructs the formatter.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The surface its settings are.
   * @param array<string, mixed> $settings
   *   Its settings.
   */
  public function __construct(protected readonly SurfaceInterface $surface, protected readonly array $settings) {
  }

  /**
   * The field the formatter is for, which the summary never reads.
   */
  protected ?FieldDefinitionInterface $fieldDefinition = NULL;

  /**
   * Gets the formatter's plugin id, which the summary never reads.
   *
   * @return string
   *   The id.
   */
  public function getPluginId(): string {
    return 'hand';
  }

  /**
   * {@inheritdoc}
   */
  public function getSurface(): SurfaceInterface {
    return $this->surface;
  }

  /**
   * Gets the settings.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  public function getSettings(): array {
    return $this->settings;
  }

}
