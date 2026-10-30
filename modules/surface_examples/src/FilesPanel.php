<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Lists the files an example is made of, on each of its pages.
 *
 * Each file with its path from the repository's root, linked on GitHub,
 * what it is, and its lines of code; a short one is shown whole below its
 * line, collapsed, as "Your first resource" shows every file whole. The
 * list is Series', the same one the landing page counts and the README's
 * Files tables are held to.
 *
 * @see \Drupal\surface_examples\Series
 */
final class FilesPanel {

  use StringTranslationTrait;

  /**
   * The Prism language of a file shown whole, by its extension.
   *
   * Only languages the surface/prism library loads; a file of any
   * other extension is shown as plain text.
   */
  protected const LANGUAGES = [
    'php' => 'php',
    'install' => 'php',
    'module' => 'php',
    'inc' => 'php',
    'yml' => 'yaml',
    'yaml' => 'yaml',
    'twig' => 'twig',
    'json' => 'json',
  ];

  /**
   * Constructs a FilesPanel.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Builds the panel for one example.
   *
   * @param int $number
   *   The example.
   *
   * @return array
   *   A render array: an open details element holding the list.
   */
  public function build(int $number): array {
    $example = Series::examples()[$number];
    $branch = Series::branch();
    $build = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('The files of example @number', ['@number' => $number]),
      // The contract panel's own class, for its spacing and type sizes.
      '#attributes' => ['class' => ['surface-contract-panel', 'surface-files-panel']],
      '#attached' => ['library' => ['surface_tool/contract_panel']],
    ];
    if ($example['files'] === []) {
      $build['none'] = [
        '#markup' => '<p>' . $this->t('Example @number adds no file of its own: it is made from the files of example @from.', [
          '@number' => $number,
          '@from' => $example['from'] ?? $number - 1,
        ]) . '</p>',
      ];
      return $build;
    }
    $items = [];
    $total = 0;
    foreach ($example['files'] as $file => $role) {
      [$path, $key] = Series::split($file);
      $lines = Series::lines($file);
      $total += $lines;
      $item = [
        '#wrapper_attributes' => ['data-file' => $file],
        'link' => [
          '#type' => 'link',
          '#title' => ['#markup' => '<code>' . Html::escape($path) . '</code>'],
          '#url' => Url::fromUri(Series::url($file, $branch)),
        ],
        'role' => [
          '#markup' => ($key === NULL ? '' : ' ' . $this->t('entry <code>@key</code>', ['@key' => $key])) . ': ' . $role . '. ' . $this->formatPlural($lines, '1 line of code.', '@count lines of code.'),
        ],
      ];
      if (Series::whole($file)) {
        $item['whole'] = [
          '#type' => 'details',
          '#open' => FALSE,
          '#title' => $this->t('@file, whole', ['@file' => basename($path) . ($key === NULL ? '' : ', ' . $key)]),
          'text' => [
            '#type' => 'surface_code',
            '#code' => Series::text($file),
            '#language' => self::LANGUAGES[pathinfo($path, PATHINFO_EXTENSION)] ?? NULL,
          ],
        ];
      }
      $items[] = $item;
    }
    $build['note'] = [
      '#markup' => '<p>' . $this->t('@count files, @lines lines of code in all, counted without blank lines or comments, a PHP class from its first attribute down. Each path links to the file on GitHub, on the @branch branch; a file of fewer than @whole lines of code is shown whole below its line, collapsed.', [
        '@count' => count($items),
        '@lines' => $total,
        '@branch' => $branch,
        '@whole' => Series::WHOLE_BELOW,
      ]) . '</p>',
    ];
    $build['files'] = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['surface-files']],
    ];
    return $build;
  }

}
