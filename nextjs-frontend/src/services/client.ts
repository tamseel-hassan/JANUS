import { safeFetch } from "@/lib/safeFetch";

/**
 * Core HTTP Client wrapping safeFetch for type-safe API requests
 */
export const apiClient = {
  get: <T>(
    url: string,
    paramsOrTag?: Record<string, any> | string,
    tag = "apiClient.get"
  ): Promise<T | null> => {
    let requestUrl = url;
    let actualTag = tag;

    if (typeof paramsOrTag === "string") {
      actualTag = paramsOrTag;
    } else if (paramsOrTag && typeof paramsOrTag === "object") {
      const searchParams = new URLSearchParams();
      Object.entries(paramsOrTag).forEach(([key, val]) => {
        if (val !== undefined && val !== null && val !== "") {
          searchParams.append(key, String(val));
        }
      });
      const queryString = searchParams.toString();
      if (queryString) {
        requestUrl += (url.includes("?") ? "&" : "?") + queryString;
      }
    }

    return safeFetch<T>(requestUrl, { method: "GET" }, actualTag);
  },

  post: <T>(
    url: string,
    data?: any,
    tag = "apiClient.post"
  ): Promise<T | null> => {
    return safeFetch<T>(
      url,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: data !== undefined ? JSON.stringify(data) : undefined,
      },
      tag
    );
  },

  postFormData: <T>(
    url: string,
    formData: FormData,
    tag = "apiClient.postFormData"
  ): Promise<T | null> => {
    return safeFetch<T>(
      url,
      {
        method: "POST",
        body: formData,
      },
      tag
    );
  },
};
