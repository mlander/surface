import type { Contract, Validation, Values } from './contract';

/** What the page hands the app in drupalSettings.dataSurfaceReact. */
export interface Settings {
  apiBase: string;
  surface: string;
  situation: string;
  parameters?: Record<string, string | number | boolean>;
  tokenUrl: string;
  mount?: string;
}

export type Fetcher = (input: string, init?: RequestInit) => Promise<Response>;

export interface Api {
  contract(): Promise<Contract>;
  refine(values: Values, stale: string[]): Promise<Contract>;
  validate(values: Values, stale: string[]): Promise<Validation>;
}

/**
 * The three endpoints of one situation.
 *
 * The situation's parameters travel in the query string of every call
 * and in the body of the POSTs; the POSTs carry the session's CSRF token,
 * fetched once from /session/token.
 */
export function createApi(settings: Settings, fetcher: Fetcher): Api {
  const base = `${settings.apiBase}/${encodeURIComponent(settings.surface)}/${encodeURIComponent(settings.situation)}`;
  const parameters = settings.parameters ?? {};
  const query = new URLSearchParams(Object.entries(parameters).map(([key, value]) => [key, String(value)])).toString();
  const suffix = query === '' ? '' : `?${query}`;
  let token: Promise<string> | null = null;

  const read = async <T>(response: Response): Promise<T> => {
    if (!response.ok) {
      throw new Error(`The server answered ${response.status} ${response.statusText}`.trim());
    }
    return (await response.json()) as T;
  };

  const post = async <T>(endpoint: string, values: Values, stale: string[]): Promise<T> => {
    token ??= fetcher(settings.tokenUrl, { credentials: 'same-origin' }).then((response) => response.text());
    const response = await fetcher(`${base}/${endpoint}${suffix}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': await token,
      },
      body: JSON.stringify({ values, stale, parameters }),
    });
    return read<T>(response);
  };

  return {
    contract: async () =>
      read<Contract>(await fetcher(`${base}${suffix}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })),
    refine: (values, stale) => post<Contract>('refine', values, stale),
    validate: (values, stale) => post<Validation>('validate', values, stale),
  };
}
