<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

/**
 * Tells a replaced form element from one a refresh left in place.
 *
 * A replaced element is a new DOM node, which does not carry what was
 * set on the old one: each element is marked before the change, and
 * whatever answers to its name afterwards without the mark was put
 * there by the response.
 */
trait ReplacedElementsTrait {

  /**
   * Marks form elements in the page, by name.
   *
   * @param string[] $names
   *   The elements' names.
   */
  protected function probe(array $names): void {
    foreach ($names as $name) {
      $this->getSession()->executeScript(sprintf(
        'document.querySelector(%s).dataset.dataSurfaceProbe = "kept";',
        json_encode('[name="' . $name . '"]'),
      ));
    }
  }

  /**
   * Marks every named element in the page.
   */
  protected function probeAll(): void {
    $this->getSession()->executeScript('document.querySelectorAll("[name]").forEach((e) => { e.dataset.dataSurfaceProbe = "kept"; });');
  }

  /**
   * Answers whether an element is still the node probe() marked.
   *
   * @param string $name
   *   The element's name.
   *
   * @return bool
   *   TRUE when it was not replaced since it was marked.
   */
  protected function stillProbed(string $name): bool {
    return $this->getSession()->evaluateScript(sprintf(
      'return (document.querySelector(%s) || {dataset: {}}).dataset.dataSurfaceProbe === "kept";',
      json_encode('[name="' . $name . '"]'),
    ));
  }

  /**
   * Lists the named elements that are not the nodes probeAll() marked.
   *
   * @return string[]
   *   Their names, sorted: replaced elements and new ones alike.
   */
  protected function replacedSinceProbe(): array {
    $names = $this->getSession()->evaluateScript('return Array.from(document.querySelectorAll("[name]")).filter((e) => e.dataset.dataSurfaceProbe !== "kept").map((e) => e.name);');
    $names = array_values(array_unique($names));
    sort($names);
    return $names;
  }

  /**
   * Gets the text of the form item an element sits in.
   *
   * @param string $name
   *   The element's name.
   *
   * @return string
   *   The form item's visible text: label, description, error.
   */
  protected function formItemText(string $name): string {
    return (string) $this->getSession()->evaluateScript(sprintf(
      'return document.querySelector(%s).closest(".js-form-item").innerText;',
      json_encode('[name="' . $name . '"]'),
    ));
  }

}
