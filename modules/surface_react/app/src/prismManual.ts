// Prism reads Prism.manual as it loads, so this module is imported before
// it: Prism then never highlights the page by itself, only what
// highlight.ts asks for, and never touches the DOM React renders.
const scope = window as unknown as { Prism?: { manual?: boolean } };
scope.Prism = scope.Prism ?? {};
scope.Prism.manual = true;

export {};
