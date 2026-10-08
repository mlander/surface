import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import type { Settings } from './api';
import { SurfaceForm } from './SurfaceForm';
import './app.css';

interface Behavior {
  attach: (context: Document | Element, settings?: { dataSurfaceReact?: Settings }) => void;
}

declare global {
  interface Window {
    Drupal?: { behaviors: Record<string, Behavior> };
    drupalSettings?: { dataSurfaceReact?: Settings };
  }
}

/** Mounts the form into the element the page rendered for it, once. */
function attach(context: Document | Element, settings?: { dataSurfaceReact?: Settings }): void {
  const served = settings?.dataSurfaceReact;
  if (served === undefined) {
    return;
  }
  const element = context.querySelector<HTMLElement>(`#${served.mount ?? 'data-surface-react'}`);
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
  window.Drupal.behaviors.dataSurfaceReact = { attach };
}
else {
  document.addEventListener('DOMContentLoaded', () => attach(document, window.drupalSettings));
}
