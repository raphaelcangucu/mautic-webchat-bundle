import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { JSDOM } from "jsdom";

const source = readFileSync(
  new URL("../../Controller/PublicController.php", import.meta.url),
  "utf8",
);
const script = source
  .match(/<<<'JS'\n([\s\S]*?)\nJS;/)![1]
  .replaceAll("FRAME_URL", JSON.stringify("https://chat.example/widget/key"));
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
function fixture() {
  const dom = new JSDOM(
    '<html lang="en" class="dark"><head><title>Macro Markets</title></head><body style="font-family: ui-sans-serif, system-ui"></body></html>',
    {
      url: "https://site.example/?utm_source=meta&secret=private",
      runScripts: "outside-only",
    },
  );
  const { window } = dom;
  window.eval(script);
  const frame = window.document.querySelector("iframe")!;
  const commands: any[] = [];
  frame.contentWindow!.postMessage = (message: any) =>
    commands.push(JSON.parse(JSON.stringify(message)));
  window.dispatchEvent(
    new window.MessageEvent("message", {
      source: frame.contentWindow,
      origin: "https://chat.example",
      data: { type: "webchat.ready" },
    }),
  );
  return { dom, window, frame, commands, api: (window as any).MauticWebChat };
}
test("loader detects site font, appearance and locale; watches SPA routes and title without new frames", async () => {
  const { dom, window, commands, api } = fixture();
  try {
    assert.equal(commands[0].context.locale, "en");
    assert.equal(commands[0].context.appearance, "dark");
    assert.equal(commands[0].context.fontFamily, "ui-sans-serif, system-ui");
    assert.equal(commands[0].pageUrl, "https://site.example/");
    assert.deepEqual(commands[0].utm, { utm_source: "meta" });
    api.identify({ subject: "account:42", identityToken: "signed" });
    window.document.documentElement.lang = "es";
    window.document.documentElement.classList.remove("dark");
    window.history.pushState({}, "", "/market/nfl?token=private#price");
    window.document.title = "Bears vs Packers";
    await tick();
    const update = commands.filter((c) => c.action === "configure").at(-1);
    assert.equal(update.pageUrl, "https://site.example/market/nfl");
    assert.equal(update.pageTitle, "Bears vs Packers");
    assert.equal(update.context.locale, "es");
    assert.equal(update.context.appearance, "light");
    assert.equal(commands.filter((c) => c.action === "reset").length, 0);
    assert.equal(window.document.querySelectorAll("iframe").length, 1);
    const count = commands.length;
    window.history.replaceState({}, "", window.location.href);
    assert.equal(commands.length, count);
    window.dispatchEvent(
      new window.MessageEvent("message", {
        origin: "https://evil.example",
        data: { type: "webchat.ready" },
      }),
    );
    assert.equal(commands.length, count);
  } finally {
    api.destroy();
    dom.window.close();
  }
});
test("explicit site configuration is preserved and destroy stops observers and restores history", async () => {
  const { dom, window, commands, api } = fixture();
  try {
    api.configure({
      locale: "pt-BR",
      accountPending: true,
      appearance: "light",
    });
    window.document.documentElement.lang = "en";
    await tick();
    const update = commands.filter((c) => c.action === "configure").at(-1);
    assert.equal(update.context.locale, "pt-BR");
    assert.equal(update.context.accountPending, true);
    const wrappedHistory = window.history.pushState;
    api.destroy();
    assert.notEqual(window.history.pushState, wrappedHistory);
    const count = commands.length;
    window.document.documentElement.lang = "es";
    window.history.pushState({}, "", "/next");
    await tick();
    assert.equal(commands.length, count);
    assert.equal(window.document.querySelectorAll("iframe").length, 0);
  } finally {
    dom.window.close();
  }
});
