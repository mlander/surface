/**
 * @file
 * Redoc over the site's OpenAPI document: a reference to read, with no
 * call sent from it.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.dataSurfaceOpenApiReference = {
    attach(context) {
      once('data-surface-openapi-reference', '#data-surface-openapi-reference', context).forEach((element) => {
        window.Redoc.init(drupalSettings.dataSurfaceOpenApi.document, { hideDownloadButton: false }, element);
      });
    },
  };
})(Drupal, drupalSettings, once);
