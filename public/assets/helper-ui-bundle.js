import { uiLoader } from "/vendor/helpers.pbb.ph/js/ui/ui.loader.js?v=0.21.90";

uiLoader.setPreferBundles(true);

if (typeof window !== "undefined") {
  window.uiLoader = uiLoader;
}

export { uiLoader };
