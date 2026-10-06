<script lang="ts">
  import {
    themes,
    colorLabels,
    defaultPresentation,
    resolveTheme,
    cssVariables,
    contrast,
    safeLogo,
    type Presentation,
    type Variant,
    type ThemeKey,
    type Options,
    type Palette,
  } from "../widget/presentation";
  import { copy, type Locale } from "../widget/i18n";
  export let value: Presentation = defaultPresentation();
  export let legacyColor = "#4e5ba6";
  let variant: Variant = "light";
  let locale: Locale = "pt";
  $: value = value?.version ? value : defaultPresentation();
  $: theme = resolveTheme(value, { appearance: variant }, legacyColor);
  $: preview = resolveTheme(
    {
      ...value,
      overrides: {
        ...value.overrides,
        [value.theme]: {
          ...value.overrides?.[value.theme],
          options: {
            ...value.overrides?.[value.theme]?.options,
            appearance: variant,
          },
        },
      },
    },
    {},
    legacyColor,
  );
  $: c = copy[locale];
  const fields = ["name", "email", "phone"] as const;
  const palettes = Object.keys(colorLabels) as (keyof Palette)[];
  function option(key: keyof Options, next: string | number | boolean) {
    const saved = value.overrides?.[value.theme] || {};
    value = {
      ...value,
      overrides: {
        ...value.overrides,
        [value.theme]: { ...saved, options: { ...saved.options, [key]: next } },
      },
    };
  }
  function color(key: keyof Palette, next?: string) {
    if (next && !/^#[0-9a-f]{6}$/i.test(next)) return;
    const saved = value.overrides?.[value.theme] || {};
    const palette = { ...saved[variant] };
    if (next) palette[key] = next;
    else delete palette[key];
    value = {
      ...value,
      overrides: {
        ...value.overrides,
        [value.theme]: { ...saved, [variant]: palette },
      },
    };
  }
  function reset() {
    const overrides = { ...value.overrides };
    delete overrides[value.theme];
    value = { ...value, overrides };
  }
  function translation(key: "greeting" | "offline", next: string) {
    value = {
      ...value,
      translations: {
        ...value.translations,
        [locale]: { ...value.translations?.[locale], [key]: next },
      },
    };
  }
</script>

<section class="theme-editor">
  <h4>Aparência do atendimento</h4>
  <p>
    Escolha um modelo. Todos permitem personalização, separada por tema claro e
    escuro.
  </p>
  <div class="theme-cards">
    {#each Object.entries(themes) as [key, model]}
      <button
        type="button"
        class:chosen={value.theme === key}
        aria-pressed={value.theme === key}
        on:click={() => (value = { ...value, theme: key as ThemeKey })}
      >
        <span
          class="sample"
          style={`background:${model.light.surface};border-top:4px solid ${model.light.primary}`}
          ><i style={`background:${model.light.accent}`}></i><b
            style={`background:${model.light.primary}`}
          ></b></span
        >
        <strong>{model.name}</strong><small>{model.description}</small>
      </button>
    {/each}
  </div>
  <div class="editor-columns">
    <div class="controls">
      <div class="pair">
        <label
          >Nome da marca<input
            class="form-control"
            maxlength="120"
            bind:value={value.brandName}
            placeholder="Usar nome do site"
          /></label
        ><label
          >Logo da marca (HTTPS)<input
            class="form-control"
            type="url"
            bind:value={value.logoUrl}
            placeholder="https://…"
          /></label
        >
      </div>
      <div class="pair">
        <label
          >Modo de cor<select
            class="form-control"
            value={theme.options.appearance}
            on:change={(e) => option("appearance", e.currentTarget.value)}
            ><option value="auto">Acompanhar o site</option><option
              value="light">Sempre claro</option
            ><option value="dark">Sempre escuro</option></select
          ></label
        >
        <label
          >Editar cores da versão<select
            class="form-control"
            bind:value={variant}
            ><option value="light">Clara</option><option value="dark"
              >Escura</option
            ></select
          ></label
        >
      </div>
      <div class="colors">
        {#each palettes as key}<div class="color">
            <label for={`color-${key}`}>{colorLabels[key]}</label><input
              id={`color-${key}`}
              type="color"
              value={preview.colors[key]}
              on:input={(e) => color(key, e.currentTarget.value)}
            /><input
              aria-label={`${colorLabels[key]} em hexadecimal`}
              maxlength="7"
              value={preview.colors[key]}
              on:change={(e) => color(key, e.currentTarget.value)}
            /><button
              type="button"
              title={`Restaurar ${colorLabels[key]}`}
              aria-label={`Restaurar ${colorLabels[key]}`}
              on:click={() => color(key)}>↺</button
            >
          </div>{/each}
      </div>
      <small class="contrast"
        >Contraste: texto {contrast(
          preview.colors.text,
          preview.colors.surface,
        ).toFixed(1)}:1 · botão {contrast(
          preview.colors.buttonText,
          preview.colors.primary,
        ).toFixed(1)}:1. Referência: 4,5:1.</small
      >
      <div class="pair">
        {#each [{ key: "header", label: "Cabeçalho", values: [["surface", "Discreto"], ["filled", "Colorido"], ["minimal", "Mínimo"]] }, { key: "logo", label: "Identificação", values: [["site", "Logo do site"], ["initials", "Inicial da marca"], ["url", "URL configurada"]] }, { key: "font", label: "Fonte", values: [["site", "Fonte do site"], ["system", "Sistema"], ["arial", "Arial"], ["georgia", "Georgia"]] }, { key: "density", label: "Espaçamento", values: [["comfortable", "Confortável"], ["compact", "Compacto"]] }, { key: "position", label: "Posição", values: [["right", "Direita"], ["left", "Esquerda"]] }, { key: "shadow", label: "Sombra", values: [["regular", "Padrão"], ["soft", "Suave"], ["none", "Sem sombra"]] }, { key: "formLayout", label: "Layout do formulário", values: [["vertical", "Uma coluna"], ["grid", "Duas colunas quando couber"]] }] as item}<label
            >{item.label}<select
              class="form-control"
              value={theme.options[item.key as keyof Options]}
              on:change={(e) =>
                option(item.key as keyof Options, e.currentTarget.value)}
              >{#each item.values as [key, label]}<option value={key}
                  >{label}</option
                >{/each}</select
            ></label
          >{/each}
        {#each [{ key: "radius", label: "Cantos do painel", min: 0, max: 24 }, { key: "controlRadius", label: "Cantos dos campos", min: 0, max: 16 }, { key: "controlHeight", label: "Altura dos campos", min: 40, max: 56 }, { key: "width", label: "Largura do chat", min: 320, max: 480 }] as item}<label
            >{item.label} · {theme.options[item.key as keyof Options]}px<input
              type="range"
              min={item.min}
              max={item.max}
              value={theme.options[item.key as keyof Options]}
              on:input={(e) =>
                option(
                  item.key as keyof Options,
                  Number(e.currentTarget.value),
                )}
            /></label
          >{/each}
      </div>
      <label class="check"
        ><input
          type="checkbox"
          checked={theme.options.showFooter}
          on:change={(e) => option("showFooter", e.currentTarget.checked)}
        /> Mostrar assinatura da marca</label
      >
      <button type="button" class="btn btn-default" on:click={reset}
        >Restaurar este tema</button
      >
      <h4>Dados de visitantes</h4>
      <p>Contas reconhecidas pelo servidor entram direto na conversa.</p>
      <div class="pair">
        {#each fields as field}<label
            >{copy.pt[field]}<select
              class="form-control"
              value={value.fields?.[field] || "optional"}
              on:change={(e) =>
                (value = {
                  ...value,
                  fields: {
                    ...value.fields,
                    [field]: e.currentTarget.value as
                      | "required"
                      | "optional"
                      | "hidden",
                  },
                })}
              ><option value="required">Obrigatório</option><option
                value="optional">Opcional</option
              ><option value="hidden">Oculto</option></select
            ></label
          >{/each}
      </div>
      <h4>Mensagens por idioma</h4>
      <label
        >Idioma<select class="form-control" bind:value={locale}
          ><option value="pt">Português</option><option value="en"
            >English</option
          ><option value="es">Español</option></select
        ></label
      >
      <label
        >Boas-vindas<textarea
          class="form-control"
          maxlength="500"
          value={value.translations?.[locale]?.greeting || ""}
          placeholder={c.greeting}
          on:input={(e) => translation("greeting", e.currentTarget.value)}
        ></textarea></label
      >
      <label
        >Mensagem quando desconectado<textarea
          class="form-control"
          maxlength="500"
          value={value.translations?.[locale]?.offline || ""}
          placeholder={c.offline}
          on:input={(e) => translation("offline", e.currentTarget.value)}
        ></textarea></label
      >
    </div>
    <aside>
      <strong>Prévia · {variant === "light" ? "clara" : "escura"}</strong>
      <div
        class="chat-preview"
        class:filled={preview.options.header === "filled"}
        class:minimal={preview.options.header === "minimal"}
        style={`${cssVariables(preview)};max-width:${preview.options.width}px`}
      >
        <header>
          {#if preview.options.logo !== "initials" && safeLogo(value.logoUrl)}<img
              src={safeLogo(value.logoUrl)}
              alt=""
            />{:else}<span class="mark"
              >{(value.brandName || "Marca").slice(0, 1)}</span
            >{/if}
          <div>
            <b>{value.brandName || "Sua marca"}</b><small>{c.ready}</small>
          </div>
          <span>−</span>
        </header>
        <div class="welcome">
          <h3>{value.translations?.[locale]?.greeting || c.greeting}</h3>
          <p>{c.sub}</p>
          <div
            class="preview-form"
            class:grid={preview.options.formLayout === "grid" &&
              preview.options.width > 410}
          >
            {#each fields as field}{#if value.fields?.[field] !== "hidden"}<label
                  >{c[field]}
                  {value.fields?.[field] !== "required"
                    ? `(${c.optional})`
                    : ""}<input
                    placeholder={c[`${field}Placeholder`]}
                    readonly
                  /></label
                >{/if}{/each}<button type="button">{c.start}</button>
          </div>
          <small>{c.privacy}</small>
        </div>
        {#if preview.options.showFooter}<footer>
            {c.footer}
            {value.brandName || "Sua marca"}
          </footer>{/if}
      </div>
    </aside>
  </div>
</section>

<style>
  .theme-editor {
    min-width: 0;
    padding: 18px 0;
    border-top: 1px solid #ddd;
  }
  .theme-editor p,
  .theme-editor small {
    color: #687083;
  }
  .theme-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin: 16px 0;
  }
  .theme-cards button {
    border: 1px solid #ddd;
    border-radius: 8px;
    background: white;
    text-align: left;
    padding: 12px;
    min-width: 0;
  }
  .theme-cards .chosen {
    outline: 2px solid #4e5ba6;
  }
  .theme-cards strong,
  .theme-cards small {
    display: block;
  }
  .sample {
    display: block;
    height: 62px;
    border-radius: 5px;
    border: 1px solid #ddd;
    padding: 8px;
    margin-bottom: 8px;
  }
  .sample i {
    display: block;
    height: 12px;
    width: 65%;
    border-radius: 3px;
  }
  .sample b {
    display: block;
    height: 12px;
    width: 45%;
    border-radius: 3px;
    margin: 6px 0 0 auto;
  }
  .editor-columns {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
    gap: 22px;
  }
  .controls,
  aside {
    min-width: 0;
  }
  .pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin: 12px 0;
  }
  label {
    display: block;
    min-width: 0;
    font-weight: 600;
    margin-bottom: 8px;
  }
  input[type="range"] {
    width: 100%;
    margin-top: 9px;
  }
  textarea {
    margin-top: 6px;
  }
  .colors {
    display: grid;
    gap: 6px;
  }
  .color {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 32px 78px 26px;
    gap: 6px;
    align-items: center;
  }
  .color label {
    margin: 0;
  }
  .color input {
    width: 100%;
    min-width: 0;
    height: 30px;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 3px;
  }
  .color button {
    border: 0;
    background: transparent;
  }
  .contrast {
    display: block;
    margin: 10px 0;
  }
  .check {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  aside {
    position: sticky;
    top: 20px;
    align-self: start;
  }
  aside > strong {
    display: block;
    margin-bottom: 10px;
  }
  .chat-preview {
    border: 1px solid var(--wc-divider);
    border-radius: var(--wc-radius);
    box-shadow: var(--wc-shadow);
    color: var(--wc-text);
    font-family: var(--wc-font);
    background: var(--wc-surface);
    overflow: hidden;
  }
  .chat-preview header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px;
    background: var(--wc-surface);
    border-bottom: 1px solid var(--wc-divider);
    color: var(--wc-title);
  }
  .chat-preview header > span:last-child {
    margin-left: auto;
  }
  .chat-preview header small {
    display: block;
    font-weight: 400;
    color: var(--wc-muted);
  }
  .filled header {
    background: var(--wc-accent);
    color: var(--wc-button-text);
  }
  .filled header small {
    color: inherit;
  }
  .minimal header {
    border-top: 3px solid var(--wc-accent);
  }
  .mark,
  .chat-preview img {
    width: 32px;
    height: 32px;
    object-fit: contain;
    border-radius: 6px;
  }
  .mark {
    display: grid;
    place-items: center;
    background: var(--wc-agent-bubble);
    color: var(--wc-accent);
    font-weight: 700;
  }
  .welcome {
    padding: var(--wc-spacing);
  }
  .welcome h3 {
    margin: 0 0 8px;
    color: var(--wc-title);
    font-size: 20px;
  }
  .welcome p,
  .welcome > small {
    color: var(--wc-muted);
    font-size: 13px;
  }
  .preview-form {
    display: grid;
    gap: 12px;
    margin: 20px 0;
  }
  .preview-form.grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .preview-form input {
    display: block;
    width: 100%;
    min-width: 0;
    height: var(--wc-control-height);
    border: 1px solid var(--wc-divider);
    border-radius: var(--wc-control-radius);
    background: var(--wc-background);
    color: var(--wc-text);
    font: inherit;
    margin-top: 5px;
    padding: 0 10px;
  }
  .preview-form button {
    grid-column: 1/-1;
    height: var(--wc-control-height);
    border: 0;
    border-radius: var(--wc-control-radius);
    background: var(--wc-accent);
    color: var(--wc-button-text);
    font-weight: 600;
  }
  footer {
    padding: 10px;
    text-align: center;
    font-size: 11px;
    color: var(--wc-muted);
  }
  @media (max-width: 1000px) {
    .editor-columns {
      grid-template-columns: minmax(0, 1fr);
    }
    aside {
      position: static;
    }
    .chat-preview {
      margin: auto;
    }
  }
  @media (max-width: 480px) {
    .theme-cards,
    .pair {
      grid-template-columns: 1fr;
    }
    .preview-form.grid {
      grid-template-columns: 1fr;
    }
  }
</style>
