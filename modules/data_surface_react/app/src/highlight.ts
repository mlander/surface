import './prismManual';
import Prism from 'prismjs/components/prism-core';
import 'prismjs/components/prism-json';

/**
 * Highlights JSON text as Prism's markup: every part of the text escaped,
 * each token in a span. Its colors are the Prism theme Drupal attaches to
 * the page (data_surface/prism_theme), the one the PHP panels use.
 */
export function highlightJson(text: string): string {
  return Prism.highlight(text, Prism.languages.json, 'json');
}
