import { mount, unmount } from "svelte";
import AdminApp from "./AdminApp.svelte";

type Root = HTMLElement & { __webchatAdmin?: ReturnType<typeof mount> };
const roots = new Set<Root>();
function boot(): void {
  const root = document.getElementById("mautic-webchat-admin") as Root | null;
  if (!root || root.__webchatAdmin) return;
  root.__webchatAdmin = mount(AdminApp, { target: root, props: { root } });
  roots.add(root);
}
if (window.Mautic) window.Mautic.webchatAdminOnLoad = boot;
if (document.readyState === "loading")
  document.addEventListener("DOMContentLoaded", boot);
else boot();
document.addEventListener("mauticPageContentLoaded", boot);
new MutationObserver(() =>
  roots.forEach((root) => {
    if (!root.isConnected && root.__webchatAdmin) {
      void unmount(root.__webchatAdmin);
      roots.delete(root);
    }
  }),
).observe(document.documentElement, { childList: true, subtree: true });

declare global {
  interface Window {
    Mautic?: Record<string, unknown>;
  }
}
