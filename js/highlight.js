/**
 * @file
 * Highlights each code block the data_surface_code element renders.
 *
 * Once per block, so a block that arrives over AJAX is highlighted when
 * it arrives and a block already highlighted is left alone. When Prism
 * did not load (the CDN unreachable, or a file refused for its integrity
 * hash), the blocks stay plain text.
 */
((Drupal, once) => {
  Drupal.behaviors.dataSurfaceHighlight = {
    attach(context) {
      const { Prism } = window;
      if (!Prism || typeof Prism.highlightElement !== 'function') {
        return;
      }
      once('data-surface-highlight', 'pre > code[class*="language-"]', context).forEach((code) => {
        Prism.highlightElement(code);
      });
    },
  };
})(Drupal, once);
