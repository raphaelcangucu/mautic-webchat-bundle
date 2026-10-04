import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
  plugins: [svelte()],
  build: {
    emptyOutDir: true,
    outDir: "Assets/dist",
    minify: "oxc",
    sourcemap: true,
    lib: { entry: "Frontend/admin/main.ts", name: "MauticWebChatAdmin", formats: ["iife"], fileName: () => "admin-app.js" },
    rollupOptions: { output: { assetFileNames: (info) => info.names?.some((name) => name.endsWith(".css")) ? "admin-app.css" : "[name][extname]" } }
  }
});
