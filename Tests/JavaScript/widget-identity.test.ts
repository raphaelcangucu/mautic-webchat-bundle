import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { JSDOM } from "jsdom";

const script = readFileSync(
  new URL("../../Assets/dist/widget-app.js", import.meta.url),
  "utf8",
);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const config = {
  name: "Macro Markets",
  greeting: "Olá",
  offline_message: "",
  accent_color: "#22c55e",
  require_name: true,
  require_email: true,
};
async function fixture() {
  const dom = new JSDOM(
    '<div id="mautic-webchat-widget" data-public-key="pub_test"></div>',
    {
      url: "https://chat.example/chat/widget/pub_test",
      runScripts: "outside-only",
      pretendToBeVisual: true,
    },
  );
  const window = dom.window as any;
  const root = window.document.getElementById("mautic-webchat-widget");
  root.dataset.config = JSON.stringify(config);
  const posts: any[] = [];
  const requests: any[] = [];
  window.postMessage = (message: any) => posts.push(message);
  window.fetch = async (url: string, options: any) => {
    requests.push({ url, body: JSON.parse(options?.body || "{}") });
    return { ok: false, json: async () => ({ code: "identity_invalid" }) };
  };
  window.eval(script);
  await tick();
  const command = async (data: any) => {
    window.dispatchEvent(
      new window.MessageEvent("message", {
        source: window,
        origin: "https://site.example",
        data,
      }),
    );
    await tick();
  };
  await command({
    type: "webchat.bootstrap",
    context: { accountActive: true, accountPending: false, locale: "en" },
    user: { subject: "macro:42", identityToken: "expired-signed-token" },
  });
  return { dom, window, posts, requests, command };
}
test("opening from the iframe launcher renews account identity before starting a session", async () => {
  const f = await fixture();
  try {
    f.window.document.querySelector("button.launcher").click();
    await tick();
    assert.equal(
      f.requests.length,
      0,
      "opening must not submit the stale credential before the host can refresh it",
    );
    assert.equal(
      f.posts.filter((p: any) => p.type === "webchat.identity.refresh").length,
      1,
    );
    await f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "fresh-signed-token" },
    });
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].body.identity_token, "fresh-signed-token");
  } finally {
    f.dom.window.close();
  }
});
test("a renewed identity arriving during an old failed start is not lost", async () => {
  const f = await fixture();
  try {
    let finish!: (data: any) => void;
    f.window.fetch = (url: string, options: any) => {
      f.requests.push({ url, body: JSON.parse(options.body) });
      return new Promise((resolve) => {
        finish = resolve;
      });
    };
    await f.command({ type: "webchat.command", action: "open" });
    await f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "first-fresh-token" },
    });
    assert.equal(f.requests.length, 1);
    await f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "renewed-token" },
    });
    finish({ ok: false, json: async () => ({ code: "identity_invalid" }) });
    await tick();
    await tick();
    assert.equal(
      f.requests.length,
      2,
      "the renewed credential should start once the stale request settles",
    );
    assert.equal(f.requests[1].body.identity_token, "renewed-token");
  } finally {
    f.dom.window.close();
  }
});

test("identity rejection renews once, then manual retry requests a new proof instead of resending it", async () => {
  const f = await fixture();
  const refreshes = () =>
    f.posts.filter((p: any) => p.type === "webchat.identity.refresh").length;
  const identify = () =>
    f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "same-second-signed-token" },
    });
  try {
    await f.command({ type: "webchat.command", action: "open" });
    assert.equal(refreshes(), 1);
    await identify();
    await tick();
    assert.equal(refreshes(), 2, "a rejected proof gets one automatic renewal");
    await identify();
    await tick();
    assert.equal(f.requests.length, 2);
    assert.equal(refreshes(), 2, "a persistent rejection must not loop");
    assert.match(
      f.window.document.body.textContent,
      /Could not verify your account/,
    );
    f.window.document.querySelector(".welcome .primary").click();
    await tick();
    assert.equal(refreshes(), 3);
    assert.equal(
      f.requests.length,
      2,
      "retry must wait for the host, not reuse the rejected proof",
    );
    assert.doesNotMatch(
      f.window.document.body.textContent,
      /Could not verify your account/,
    );
  } finally {
    f.dom.window.close();
  }
});

test("a renewal with an identical same-second token still retries after an in-flight start", async () => {
  const f = await fixture();
  try {
    let finish!: (data: any) => void;
    f.window.fetch = (url: string, options: any) => {
      f.requests.push({ url, body: JSON.parse(options.body) });
      return new Promise((resolve) => {
        finish = resolve;
      });
    };
    await f.command({ type: "webchat.command", action: "open" });
    const identify = {
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "same-signed-token" },
    };
    await f.command(identify);
    await f.command(identify);
    finish({ ok: false, json: async () => ({ code: "identity_invalid" }) });
    await tick();
    await tick();
    assert.equal(f.requests.length, 2);
  } finally {
    f.dom.window.close();
  }
});

test("a host identity failure offers retry and never falls back to an anonymous account", async () => {
  const f = await fixture();
  try {
    await f.command({ type: "webchat.command", action: "open" });
    await f.command({
      type: "webchat.command",
      action: "configure",
      context: {
        accountActive: true,
        accountPending: false,
        accountError: true,
      },
    });
    assert.equal(f.requests.length, 0);
    assert.equal(
      f.window.document.querySelectorAll(".welcome input").length,
      0,
    );
    assert.match(
      f.window.document.body.textContent,
      /Could not verify your account/,
    );
    f.window.document.querySelector(".welcome .primary").click();
    await tick();
    assert.equal(
      f.posts.filter((p: any) => p.type === "webchat.identity.refresh").length,
      2,
    );
    assert.equal(f.requests.length, 0);
  } finally {
    f.dom.window.close();
  }
});

test("after two hours, an expired stream waits for renewed identity and preserves history and an unsent draft", async () => {
  const f = await fixture();
  const streams: any[] = [];
  class FakeStream {
    onopen: any;
    onmessage: any;
    onerror: any;
    closed = false;
    constructor(readonly url: string) {
      streams.push(this);
    }
    close() {
      this.closed = true;
    }
  }
  f.window.EventSource = FakeStream;
  const data = (token: string) => ({
    session: "a".repeat(32),
    session_token: "resume-token",
    widget: config,
    realtime: {
      token,
      url: "https://chat.example/chat/realtime",
      expires_at: new Date(f.window.Date.now() + 3600000).toISOString(),
    },
    messages: [
      {
        id: 1,
        client_id: "history_1",
        body: "Previous conversation",
        direction: "visitor",
        status: "sent",
        timestamp: "2026-10-07T00:00:00Z",
      },
    ],
  });
  let fail = false;
  f.window.fetch = async (_url: string, options: any) => {
    f.requests.push(JSON.parse(options.body));
    return {
      ok: !fail,
      json: async () =>
        fail ? { code: "identity_invalid" } : data("new-scoped-token"),
    };
  };
  try {
    await f.command({ type: "webchat.command", action: "open" });
    await f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "first-token" },
    });
    streams[0].onopen();
    await tick();
    const input = f.window.document.querySelector("textarea");
    input.value = "Not sent yet";
    input.dispatchEvent(new f.window.Event("input", { bubbles: true }));
    await tick();
    fail = true;
    const openedAt = f.window.Date.now();
    f.window.Date.now = () => openedAt + 2 * 3600000;
    streams[0].onerror();
    await tick();
    assert.equal(
      streams.length,
      1,
      "failed renewal must not reconnect with the expired credential",
    );
    assert.equal(streams[0].closed, true);
    assert.match(f.window.document.body.textContent, /Previous conversation/);
    assert.equal(input.value, "Not sent yet");
    assert.equal(
      f.posts.filter((p: any) => p.type === "webchat.identity.refresh").length,
      2,
    );
    fail = false;
    await f.command({
      type: "webchat.command",
      action: "identify",
      user: { subject: "macro:42", identityToken: "renewed-token" },
    });
    assert.equal(streams.length, 2);
    assert.match(streams[1].url, /new-scoped-token/);
    assert.equal(f.requests.at(-1).identity_token, "renewed-token");
    assert.equal(input.value, "Not sent yet");
    assert.equal(f.window.document.querySelectorAll(".bubble").length, 1);
  } finally {
    f.window.close();
  }
});
