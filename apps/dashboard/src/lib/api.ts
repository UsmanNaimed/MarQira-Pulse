import axios, { AxiosError } from 'axios';

// Base URL for the API. In production this is https://api.marqira.com; in local
// dev it is left empty so calls go through the Vite proxy (same-origin), which
// keeps the Sanctum session cookie first-party.
const baseURL = import.meta.env.VITE_API_BASE_URL || '';

export const api = axios.create({
  baseURL,
  withCredentials: true, // send/receive the Sanctum session + XSRF cookies
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  // Read the XSRF token Laravel sets and echo it back in the header it expects.
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
});

let csrfPrimed = false;

/**
 * Prime the CSRF cookie. Sanctum requires a GET to /sanctum/csrf-cookie before
 * the first state-changing request so the XSRF-TOKEN cookie is set.
 */
export async function ensureCsrfCookie(): Promise<void> {
  if (csrfPrimed) return;
  await api.get('/sanctum/csrf-cookie');
  csrfPrimed = true;
}

export interface ApiErrorShape {
  message: string;
  errors?: Record<string, string[]>;
  status?: number;
}

/**
 * Normalise an axios error into a predictable shape for the UI.
 */
export function toApiError(err: unknown): ApiErrorShape {
  const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]>; error?: string }>;
  const status = axiosErr.response?.status;
  const data = axiosErr.response?.data;

  let message = 'Something went wrong. Please try again.';
  if (data?.message) message = data.message;
  else if (data?.error) message = data.error;
  else if (status === 401) message = 'Your session has expired. Please sign in again.';
  else if (status === 429) message = 'Too many requests. Please slow down and try again.';
  else if (axiosErr.message) message = axiosErr.message;

  return { message, errors: data?.errors, status };
}
