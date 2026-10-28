/**
 * @file
 * Tells Prism, before it loads, to highlight only when asked.
 *
 * Prism reads `Prism.manual` as it loads; without it, it would highlight
 * every block on DOMContentLoaded as well as when highlight.js asks.
 */
window.Prism = window.Prism || {};
window.Prism.manual = true;
