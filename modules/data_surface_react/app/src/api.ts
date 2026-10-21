import type { Contract, Created, Schema, Submission, Validation, Values } from './contract';
import { assertContract, CONTRACT, ContractRefused } from './contract';

/**
 * Whether a submit sends the fingerprint the contract was loaded with.
 *
 * The server checks it only when it is sent, so the app opts in: a
 * submit after someone else saved is refused rather than overwriting
 * them. `settings.sendFingerprint: false` turns it off for one page.
 */
export const SEND_FINGERPRINT = true;

/** What the page hands the app in drupalSettings.dataSurfaceReact. */
export interface Settings {
  apiBase: string;
  /** Where the React pages are, for moving to a created thing's page. */
  pageBase?: string;
  resource: string;
  operation: string;
  parameters?: Record<string, string | number | boolean>;
  tokenUrl: string;
  mount?: string;
  /** Overrides SEND_FINGERPRINT. */
  sendFingerprint?: boolean;
}

export type Fetcher = (input: string, init?: RequestInit) => Promise<Response>;

/** The full schema: the contract for any values, with no values beside it. */
export interface FullSchema {
  resource: string;
  operation: string | null;
  label: string | null;
  schema: Schema;
  outputs?: Schema;
}

export interface Api {
  contract(): Promise<Contract>;
  /** The full schema, which reads no value: fetched once, beside the contract. */
  schema(): Promise<FullSchema>;
  refine(values: Values, stale: string[]): Promise<Contract>;
  validate(values: Values, stale: string[]): Promise<Validation>;
  submit(values: Values, stale: string[], fingerprint: string | null): Promise<Submission>;
}

/** The React page a created thing now lives at. */
export function pageOf(settings: Settings, created: Created): string {
  const base = settings.pageBase ?? settings.apiBase.replace(/\/surface-api$/, '/surface-react');
  const query = new URLSearchParams(Object.entries(created.parameters).map(([key, value]) => [key, String(value)])).toString();
  return `${base}/${encodeURIComponent(created.resource)}/${encodeURIComponent(created.operation)}${query === '' ? '' : `?${query}`}`;
}

/** The response header every answer of the five endpoints states its contract version in. */
export const CONTRACT_HEADER = 'Contract-Version';

/**
 * Refuses an answer whose header names a contract this app does not read.
 *
 * A missing header is no refusal by itself: the document it carries is
 * checked all the same.
 */
function assertHeader(response: Response): void {
  const version = response.headers.get(CONTRACT_HEADER);
  if (version !== null && version.trim() !== String(CONTRACT)) {
    throw new ContractRefused(version.trim());
  }
}

/**
 * The five endpoints of one operation.
 *
 * The operation's parameters travel in the query string of every call
 * and in the body of the POSTs; the POSTs carry the session's CSRF token,
 * fetched once from /session/token. A submit also carries the stored
 * values' fingerprint, when it is given one. Every answer that carries a
 * document (the contract, the full schema, a refine, a write's contract
 * and the operation a create now lives at) is refused unless it is of
 * contract 2, by its `Contract-Version` header and by the document.
 */
export function createApi(settings: Settings, fetcher: Fetcher): Api {
  const base = `${settings.apiBase}/${encodeURIComponent(settings.resource)}/${encodeURIComponent(settings.operation)}`;
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

  // A document's answer: its header and the document itself are contract 2.
  const readDocument = async <T extends { schema: Schema }>(response: Response): Promise<T> => {
    const body = await read<T>(response);
    assertHeader(response);
    return assertContract(body);
  };

  const post = async (endpoint: string, values: Values, stale: string[], extra: Record<string, unknown> = {}): Promise<Response> => {
    token ??= fetcher(settings.tokenUrl, { credentials: 'same-origin' }).then((response) => response.text());
    const response = await fetcher(`${base}/${endpoint}${suffix}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-Token': await token,
      },
      body: JSON.stringify({ values, stale, parameters, ...extra }),
    });
    return response;
  };

  return {
    contract: async () =>
      readDocument<Contract>(await fetcher(`${base}${suffix}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })),
    schema: async () =>
      readDocument<FullSchema>(await fetcher(`${base}/schema${suffix}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })),
    refine: async (values, stale) => readDocument<Contract>(await post('refine', values, stale)),
    validate: async (values, stale) => read<Validation>(await post('validate', values, stale)),
    submit: async (values, stale, fingerprint) => {
      const response = await post('submit', values, stale, fingerprint === null ? {} : { fingerprint });
      const submission = await read<Submission>(response);
      // A write carries the contract it rebuilt, a create where it now lives.
      if ((submission.contract ?? null) !== null || (submission.created ?? null) !== null) {
        assertHeader(response);
      }
      if ((submission.contract ?? null) !== null) {
        assertContract(submission.contract as Contract);
      }
      return submission;
    },
  };
}
