import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import type { Settings } from './api';
import { SurfaceForm } from './SurfaceForm';
import './app.css';

interface Behavior {
  attach: (context: Document | Element, settings?: { surfaceReact?: Settings }) => void;
}

declare global {
  interface Window {
    Drupal?: { behaviors: Record<string, Behavior> };
    drupalSettings?: { surfaceReact?: Settings };
  }
}

/** Mounts the form into the element the page rendered for it, once. */
function attach(context: Document | Element, settings?: { surfaceReact?: Settings }): void {
  const served = settings?.surfaceReact;
  if (served === undefined) {
    return;
  }
  const element = context.querySelector<HTMLElement>(`#${served.mount ?? 'surface-react'}`);
  if (element === null || element.dataset.dsrMounted === 'true') {
    return;
  }
  element.dataset.dsrMounted = 'true';
  createRoot(element).render(
    <StrictMode>
      <SurfaceForm settings={served} />
    </StrictMode>,
  );
}

if (window.Drupal?.behaviors) {
  window.Drupal.behaviors.surfaceReact = { attach };
}
else {
  document.addEventListener('DOMContentLoaded', () => attach(document, window.drupalSettings));
}
