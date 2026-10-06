import assert from "node:assert/strict";
import test from "node:test";
import {
  resolveTheme,
  defaultPresentation,
  safeLogo,
  cssVariables,
  contrast,
  type Presentation,
} from "../../Frontend/widget/presentation";
import { copy, normalizeLocale, apiError } from "../../Frontend/widget/i18n";
test("existing widgets retain their own accent and classic light appearance", () => {
  const t = resolveTheme(undefined, { appearance: "dark" }, "#008844");
  assert.equal(t.id, "classic");
  assert.equal(t.variant, "light");
  assert.equal(t.colors.primary, "#008844");
});
test("classic dark buttons remain readable with inherited legacy accents and retain explicit text overrides", () => {
  const p: Presentation = {
    ...defaultPresentation(),
    overrides: { classic: { options: { appearance: "dark" } } },
  };
  for (const accent of ["#4e5ba6", "#ffffff", "#000000", "#008844"]) {
    const t = resolveTheme(p, {}, accent);
    assert.equal(t.colors.primary, accent);
    assert.ok(contrast(t.colors.buttonText, accent) >= 4.5);
  }
  p.overrides.classic!.dark = { buttonText: "#ffff00" };
  assert.equal(resolveTheme(p).colors.buttonText, "#ffff00");
});
test("manual theme overrides win over site tokens without affecting other themes or variants", () => {
  const p: Presentation = {
    ...defaultPresentation(),
    theme: "macro",
    overrides: {
      macro: { dark: { primary: "#16a34a" }, options: { font: "arial" } },
    },
  };
  const site = {
    appearance: "dark" as const,
    palettes: { dark: { primary: "#22c55e", surface: "#121212" } },
  };
  const t = resolveTheme(p, site);
  assert.equal(t.colors.primary, "#16a34a");
  assert.equal(t.colors.surface, "#121212");
  assert.equal(t.options.font, "arial");
  assert.equal(
    resolveTheme({ ...p, theme: "classic" }, site).colors.primary,
    "#4e5ba6",
  );
  assert.equal(
    resolveTheme(p, { appearance: "light" }).colors.primary,
    "#22C55E",
  );
  assert.match(cssVariables(t), /--wc-font:Arial/);
});
test("untrusted palettes and image URLs cannot insert CSS or active content", () => {
  const t = resolveTheme(
    { ...defaultPresentation(), theme: "macro" },
    {
      palettes: {
        light: { primary: "red; background:url(javascript:alert(1))" },
      },
    },
  );
  assert.equal(t.colors.primary, "#22C55E");
  for (const url of [
    "javascript:alert(1)",
    "http://example.com/a.png",
    "https://user:secret@example.com/a.png",
    "data:text/html,hi",
  ])
    assert.equal(safeLogo(url), "");
  assert.equal(
    safeLogo("https://example.com/logo.svg"),
    "https://example.com/logo.svg",
  );
});
test("all locales have matching keys and errors stay in the selected language", () => {
  assert.deepEqual(Object.keys(copy.pt).sort(), Object.keys(copy.en).sort());
  assert.deepEqual(Object.keys(copy.pt).sort(), Object.keys(copy.es).sort());
  assert.equal(normalizeLocale("pt-BR"), "pt");
  assert.equal(apiError("identity_invalid", "en"), copy.en.identityError);
  assert.equal(apiError("email_invalid", "es"), copy.es.emailError);
  assert.equal(contrast("#000000", "#ffffff"), 21);
});
test("solid chat bubbles and launcher retain readable text with low-contrast site tokens", () => {
  const p: Presentation = { ...defaultPresentation(), theme: "macro" };
  for (const appearance of ["light", "dark"] as const) {
    const t = resolveTheme(p, {
      appearance,
      palettes: { [appearance]: { primary: "#19C45B", buttonText: "#169C49" } },
    });
    assert.equal(t.colors.primary, "#19C45B");
    assert.ok(contrast(t.colors.buttonText, t.colors.primary) >= 4.5);
    assert.match(
      cssVariables(t),
      new RegExp(`--wc-button-text:${t.colors.buttonText}`),
    );
  }
  p.overrides.macro = {
    light: {
      primary: "#FFFFFF",
      buttonText: "#FFFFFF",
      text: "#FFFFFF",
      title: "#FFFFFF",
    },
  };
  assert.equal(resolveTheme(p).colors.buttonText, "#000000");
});
test("site font and compact text scale are inherited safely, with configurable overrides", () => {
  const p: Presentation = { ...defaultPresentation(), theme: "macro" };
  const font = 'ui-sans-serif, system-ui, "Apple Color Emoji"';
  const t = resolveTheme(p, { fontFamily: font });
  assert.equal(t.fontFamily, font);
  assert.equal(t.options.fontSize, 14);
  assert.match(cssVariables(t), /--wc-heading-size:18px/);
  p.overrides.macro = { options: { font: "arial", fontSize: 99 } };
  const manual = resolveTheme(p, { fontFamily: font });
  assert.match(manual.fontFamily, /Arial/);
  assert.equal(manual.options.fontSize, 18);
  assert.doesNotMatch(
    cssVariables(
      resolveTheme(
        { ...p, overrides: {} },
        { fontFamily: "system-ui; background:url(evil)" },
      ),
    ),
    /url\(evil\)/,
  );
});
