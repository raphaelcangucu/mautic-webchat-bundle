import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
  plugins: [svelte()],
  build: {
    emptyOutDir: false,
    outDir: "Assets/dist",
    minify: "oxc",
    sourcemap: true,
    lib: { entry: "Frontend/widget/main.ts", name: "MauticWebChatWidget", formats: ["iife"], fileName: () => "widget-app.js" },
    rollupOptions: { output: { assetFileNames: (info) => info.names?.some((name) => name.endsWith(".css")) ? "widget-app.css" : "[name][extname]" } }
  }
});
