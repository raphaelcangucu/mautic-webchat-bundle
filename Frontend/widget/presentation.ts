export type ThemeKey = "classic" | "macro" | "essential";
export type Variant = "light" | "dark";
export type FieldPolicy = "required" | "optional" | "hidden";
export interface Options {
  appearance: "auto" | Variant;
  header: "filled" | "surface" | "minimal";
  logo: "site" | "initials" | "url";
  font: "site" | "system" | "arial" | "georgia";
  density: "comfortable" | "compact";
  radius: number;
  controlRadius: number;
  controlHeight: number;
  width: number;
  position: "left" | "right";
  shadow: "none" | "soft" | "regular";
  formLayout: "vertical" | "grid";
  showFooter: boolean;
}
export type Palette = Record<
  | "primary"
  | "buttonText"
  | "surface"
  | "background"
  | "title"
  | "text"
  | "muted"
  | "divider"
  | "accent",
  string
>;
export interface Presentation {
  version: 1;
  theme: ThemeKey;
  brandName?: string;
  logoUrl?: string;
  overrides: Partial<
    Record<
      ThemeKey,
      {
        options?: Partial<Options>;
        light?: Partial<Palette>;
        dark?: Partial<Palette>;
      }
    >
  >;
  fields?: Partial<Record<"name" | "email" | "phone", FieldPolicy>>;
  translations?: Partial<
    Record<"pt" | "en" | "es", { greeting?: string; offline?: string }>
  >;
}
export interface SiteContext {
  locale?: string;
  appearance?: Variant;
  brandName?: string;
  logoUrl?: string;
  palettes?: Partial<Record<Variant, Partial<Palette>>>;
}
export const themes: Record<
  ThemeKey,
  Options & { name: string; description: string; light: Palette; dark: Palette }
> = {
  classic: {
    name: "Clássico",
    description: "O visual atual do widget, com cabeçalho colorido.",
    appearance: "light",
    header: "filled",
    logo: "initials",
    font: "site",
    density: "comfortable",
    radius: 16,
    controlRadius: 10,
    controlHeight: 44,
    width: 400,
    position: "right",
    shadow: "regular",
    formLayout: "vertical",
    showFooter: true,
    light: {
      primary: "#4E5BA6",
      buttonText: "#FFFFFF",
      surface: "#FFFFFF",
      background: "#FFFFFF",
      title: "#171B24",
      text: "#374151",
      muted: "#6B7280",
      divider: "#DFE2EA",
      accent: "#F0F1F7",
    },
    dark: {
      primary: "#A5B4FC",
      buttonText: "#171B24",
      surface: "#1C2030",
      background: "#151827",
      title: "#F4F5FF",
      text: "#D4D7E5",
      muted: "#A0A7BE",
      divider: "#363C55",
      accent: "#2B3045",
    },
  },
  macro: {
    name: "Macro Markets",
    description: "Marca e cores do site, com tema claro e escuro.",
    appearance: "auto",
    header: "surface",
    logo: "site",
    font: "site",
    density: "comfortable",
    radius: 12,
    controlRadius: 6,
    controlHeight: 44,
    width: 380,
    position: "right",
    shadow: "regular",
    formLayout: "vertical",
    showFooter: true,
    light: {
      primary: "#22C55E",
      buttonText: "#1F2937",
      surface: "#FFFFFF",
      background: "#F9FAFB",
      title: "#111827",
      text: "#1F2937",
      muted: "#6B7280",
      divider: "#E2E5EC",
      accent: "#F3F5F8",
    },
    dark: {
      primary: "#22C55E",
      buttonText: "#1F2937",
      surface: "#1B1E25",
      background: "#111318",
      title: "#F2F4F8",
      text: "#C5C8D4",
      muted: "#949AAA",
      divider: "#3A3F4A",
      accent: "#262B35",
    },
  },
  essential: {
    name: "Essencial",
    description: "Visual neutro e compacto, com destaque azul.",
    appearance: "auto",
    header: "minimal",
    logo: "site",
    font: "system",
    density: "compact",
    radius: 10,
    controlRadius: 6,
    controlHeight: 44,
    width: 360,
    position: "right",
    shadow: "soft",
    formLayout: "vertical",
    showFooter: true,
    light: {
      primary: "#2563EB",
      buttonText: "#FFFFFF",
      surface: "#FFFFFF",
      background: "#F8FAFC",
      title: "#0F172A",
      text: "#334155",
      muted: "#64748B",
      divider: "#E2E8F0",
      accent: "#F1F5F9",
    },
    dark: {
      primary: "#60A5FA",
      buttonText: "#0F172A",
      surface: "#111C30",
      background: "#0F172A",
      title: "#F8FAFC",
      text: "#E2E8F0",
      muted: "#94A3B8",
      divider: "#334155",
      accent: "#1E293B",
    },
  },
};
export const fonts = {
  site: "ui-sans-serif,system-ui,sans-serif",
  system: '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
  arial: "Arial,Helvetica,sans-serif",
  georgia: 'Georgia,"Times New Roman",serif',
};
export const colorLabels = {
  primary: "Cor de ação",
  buttonText: "Texto do botão",
  surface: "Fundo do painel",
  background: "Fundo dos campos",
  title: "Títulos",
  text: "Texto principal",
  muted: "Texto secundário",
  divider: "Bordas",
  accent: "Mensagens da equipe",
};

export const defaultPresentation = (): Presentation => ({
  version: 1,
  theme: "classic",
  overrides: {},
});
export function resolveTheme(
  p: Presentation = defaultPresentation(),
  site: SiteContext = {},
  legacyColor = "#4e5ba6",
) {
  const id = Object.hasOwn(themes, p.theme) ? p.theme : "classic";
  const base = themes[id];
  const saved = p.overrides?.[id] || {};
  const options = { ...base, ...saved.options };
  const variant: Variant =
    options.appearance === "auto"
      ? site.appearance === "dark"
        ? "dark"
        : "light"
      : options.appearance;
  const palette = { ...base[variant] };
  if (id === "classic" && /^#[0-9a-f]{6}$/i.test(legacyColor))
    palette.primary = legacyColor;
  if (id === "macro")
    Object.assign(palette, safePalette(site.palettes?.[variant]));
  Object.assign(palette, safePalette(saved[variant]));
  return { id, options, variant, colors: palette };
}
export function safePalette(input?: Partial<Palette>): Partial<Palette> {
  return Object.fromEntries(
    Object.keys(colorLabels)
      .filter((key) =>
        /^#[0-9a-f]{6}$/i.test(String(input?.[key as keyof Palette] || "")),
      )
      .map((key) => [key, input![key as keyof Palette]]),
  );
}
export function safeLogo(value: string | undefined): string {
  try {
    const url = new URL(value || "");
    return url.protocol === "https:" && !url.username && !url.password
      ? url.href
      : "";
  } catch {
    return "";
  }
}
export function cssVariables(theme: ReturnType<typeof resolveTheme>): string {
  const { options: o, colors: c } = theme;
  const shadows = {
    none: "none",
    soft: "0 8px 24px #0000001f",
    regular: "0 24px 70px #00000033",
  };
  const vars: Record<string, string> = {
    accent: c.primary,
    "button-text": c.buttonText,
    surface: c.surface,
    background: c.background,
    title: c.title,
    text: c.text,
    muted: c.muted,
    divider: c.divider,
    "agent-bubble": c.accent,
    radius: `${o.radius}px`,
    "control-radius": `${o.controlRadius}px`,
    "control-height": `${Math.max(44, o.controlHeight)}px`,
    font: fonts[o.font],
    spacing: o.density === "compact" ? "16px" : "24px",
    shadow: shadows[o.shadow],
  };
  return Object.entries(vars)
    .map(([k, v]) => `--wc-${k}:${v}`)
    .join(";");
}
export function contrast(foreground: string, background: string): number {
  const luminance = (hex: string) => {
    const rgb = [1, 3, 5]
      .map((n) => parseInt(hex.slice(n, n + 2), 16) / 255)
      .map((v) => (v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
  };
  const a = luminance(foreground),
    b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
