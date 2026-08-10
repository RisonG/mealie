import { describe, expect, test, vi, beforeEach, afterEach } from "vitest";
import { getTokenExpiryMs, resetAuth, useAuthBackend } from "./use-auth-backend";

function base64url(input: unknown): string {
  const base64 = Buffer.from(JSON.stringify(input)).toString("base64");
  return base64.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function makeToken(claims: Record<string, unknown>): string {
  const header = base64url({ alg: "HS256", typ: "JWT" });
  const payload = base64url(claims);
  return `${header}.${payload}.signature`;
}

describe("getTokenExpiryMs", () => {
  test("decodes the exp claim (seconds) into milliseconds", () => {
    const expSeconds = 1_700_000_000;
    const token = makeToken({ sub: "user-1", exp: expSeconds });

    expect(getTokenExpiryMs(token)).toBe(expSeconds * 1000);
  });

  test("returns null when the token has no payload segment", () => {
    expect(getTokenExpiryMs("not-a-jwt")).toBeNull();
  });

  test("returns null when the payload isn't valid base64/JSON", () => {
    expect(getTokenExpiryMs("header.%%%not-base64%%%.signature")).toBeNull();
  });

  test("returns null when the payload has no exp claim", () => {
    const token = makeToken({ sub: "user-1" });
    expect(getTokenExpiryMs(token)).toBeNull();
  });
});

describe("useAuthBackend refresh scheduling", () => {
  let cookieRef: { value: string | null };
  let axiosGet: ReturnType<typeof vi.fn>;
  let routerPush: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    cookieRef = { value: null };
    axiosGet = vi.fn();
    routerPush = vi.fn();

    vi.stubGlobal("useNuxtApp", () => ({
      $axios: { get: axiosGet },
      $appInfo: { production: false, tokenTime: 48 },
    }));
    vi.stubGlobal("useCookie", () => cookieRef);
    vi.stubGlobal("useRouter", () => ({ push: routerPush }));
    vi.stubGlobal("useRuntimeConfig", () => ({ public: { AUTH_TOKEN: "mealie.token" } }));
  });

  afterEach(() => {
    resetAuth();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  test("schedules a proactive refresh before the token expires and repeats after each refresh", async () => {
    const lifetimeMs = 20 * 60 * 1000; // 20 minutes
    const marginMs = 5 * 60 * 1000; // matches REFRESH_MARGIN_MS in use-auth-backend.ts
    const now = Date.now();

    cookieRef.value = makeToken({ sub: "user-1", exp: (now + lifetimeMs) / 1000 });
    axiosGet.mockImplementation((url: string) => {
      if (url === "/api/users/self") return Promise.resolve({ data: { id: "user-1" } });
      if (url === "/api/auth/refresh") {
        const nextExp = (Date.now() + lifetimeMs) / 1000;
        cookieRef.value = makeToken({ sub: "user-1", exp: nextExp });
        return Promise.resolve({ data: { access_token: cookieRef.value } });
      }
      throw new Error(`unexpected url: ${url}`);
    });

    const auth = useAuthBackend();
    await auth.getSession();

    expect(axiosGet).toHaveBeenCalledTimes(1);
    expect(axiosGet).toHaveBeenCalledWith("/api/users/self");

    // Just before the scheduled refresh (lifetime - margin) fires, nothing happens yet.
    await vi.advanceTimersByTimeAsync(lifetimeMs - marginMs - 1);
    expect(axiosGet).toHaveBeenCalledTimes(1);

    // Once the margin is reached, the refresh fires and a new session fetch follows.
    await vi.advanceTimersByTimeAsync(1);
    expect(axiosGet).toHaveBeenCalledWith("/api/auth/refresh");
    expect(axiosGet).toHaveBeenCalledWith("/api/users/self");
    expect(axiosGet).toHaveBeenCalledTimes(3);

    // The refresh loop reschedules itself around the new token's expiry.
    await vi.advanceTimersByTimeAsync(lifetimeMs - marginMs);
    expect(axiosGet).toHaveBeenCalledTimes(5);
  });

  test("does not schedule a refresh when there is no token", async () => {
    axiosGet.mockResolvedValue({ data: { id: "user-1" } });

    const auth = useAuthBackend();
    await auth.getSession();

    expect(axiosGet).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(60 * 60 * 1000);
    expect(axiosGet).not.toHaveBeenCalled();
  });

  test("clears the scheduled refresh on sign out", async () => {
    const lifetimeMs = 20 * 60 * 1000;
    const now = Date.now();
    cookieRef.value = makeToken({ sub: "user-1", exp: (now + lifetimeMs) / 1000 });

    axiosGet.mockImplementation((url: string) => {
      if (url === "/api/users/self") return Promise.resolve({ data: { id: "user-1" } });
      throw new Error(`unexpected url: ${url}`);
    });

    const auth = useAuthBackend();
    await auth.getSession();
    expect(axiosGet).toHaveBeenCalledTimes(1);

    resetAuth();
    cookieRef.value = null;

    await vi.advanceTimersByTimeAsync(lifetimeMs);
    expect(axiosGet).toHaveBeenCalledTimes(1);
  });
});
