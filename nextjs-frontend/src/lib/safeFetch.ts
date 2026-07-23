/**
 * safeFetch – a thin wrapper around fetch that:
 *   1. Passes credentials: "include" by default
 *   2. Guards against non-JSON responses (e.g. PHP HTML error pages)
 *      and returns null instead of throwing a SyntaxError
 *   3. Logs a descriptive error with the raw response snippet
 *
 * @param url      - The endpoint to fetch
 * @param options  - Optional RequestInit overrides (method, body, headers…)
 * @param tag      - A short label used in console errors for easy diagnosis
 * @returns        Parsed JSON object, or null on any failure
 */
export async function safeFetch<T = unknown>(
  url: string,
  options: RequestInit = {},
  tag = "safeFetch"
): Promise<T | null> {
  try {
    const res = await fetch(url, {
      credentials: "include",
      ...options,
    });

    // Redirect to login on auth failure (except when already on login page or checking auth)
    if (res.status === 401 || res.status === 403) {
      if (
        typeof window !== "undefined" &&
        window.location.pathname !== "/login" &&
        !url.includes("auth_check.php")
      ) {
        window.location.href = "/login";
      }
      return null;
    }

    // Guard: only parse if the server says it's JSON
    const contentType = res.headers.get("content-type") ?? "";
    if (!contentType.includes("application/json")) {
      const text = await res.text();
      console.error(
        `[${tag}] Expected JSON but received non-JSON response (status ${res.status}):\n`,
        text.slice(0, 400)
      );
      return null;
    }

    let text = "";
    try {
      text = await res.text();
      const data: T = JSON.parse(text);
      return data;
    } catch (err) {
      console.error(`[${tag}] Expected JSON but failed to parse. Raw response snippet (status ${res.status}):\n`, text.slice(0, 400));
      return null;
    }
  } catch (err) {
    console.error(`[${tag}] Network error:`, err);
    return null;
  }
}
