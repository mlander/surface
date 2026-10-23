/**
 * @file
 * Swagger UI over the site's OpenAPI document, its "try it out" sent with
 * the logged-in session.
 *
 * Every POST and PATCH carries the CSRF token /session/token answers,
 * fetched once; a write carries, as If-Match, the last ETag an answer for
 * the same path carried, so a write after someone else's is refused with
 * 412 as the React page's is.
 */
((Drupal, drupalSettings, once) => {
  const tags = new Map();
  let token = null;

  // The path a request or answer is for, without its query string: a
  // write and the read it follows share it.
  const pathOf = (url) => new URL(url, window.location.href).pathname;

  Drupal.behaviors.dataSurfaceOpenApiDocs = {
    attach(context) {
      once('data-surface-openapi-docs', '#data-surface-openapi-docs', context).forEach((element) => {
        const settings = drupalSettings.dataSurfaceOpenApi;
        window.SwaggerUIBundle({
          url: settings.document,
          domNode: element,
          deepLinking: true,
          tryItOutEnabled: false,
          requestInterceptor: async (request) => {
            const method = (request.method || 'GET').toUpperCase();
            if (method === 'POST' || method === 'PATCH') {
              token ??= fetch(settings.token, { credentials: 'same-origin' }).then((response) => response.text());
              request.headers['X-CSRF-Token'] = await token;
              const tag = tags.get(pathOf(request.url));
              if (tag !== undefined && request.headers['If-Match'] === undefined) {
                request.headers['If-Match'] = tag;
              }
            }
            request.credentials = 'same-origin';
            return request;
          },
          responseInterceptor: (response) => {
            const tag = response.headers && (response.headers.etag || response.headers.ETag);
            if (tag) {
              tags.set(pathOf(response.url), tag);
            }
            return response;
          },
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
