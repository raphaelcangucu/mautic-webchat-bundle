import { mount } from "svelte";
import WidgetApp from "./WidgetApp.svelte";

const root = document.getElementById("mautic-webchat-widget");
if (root) mount(WidgetApp, { target: root, props: { root } });
