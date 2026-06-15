import { uiLoader } from "/assets/helper-ui-bundle.js?v=0.21.90";

document.documentElement.dataset.landingJs = "loaded";

const launcherLayoutKey = "pbb.landing.launcher.layout.v1";

async function renderHelperIcons() {
  const nodes = document.querySelectorAll("[data-helper-icon]");
  if (!nodes.length) {
    return;
  }

  document.documentElement.dataset.helperIcons = "loading";
  await uiLoader.load("ui.icons");
  document.documentElement.dataset.helperIcons = "loaded";
  const iconExport = await uiLoader.get("ui.icons");
  const createIcon = typeof iconExport === "function" ? iconExport : iconExport && iconExport.createIcon;
  document.documentElement.dataset.helperIconExport = typeof iconExport;
  document.documentElement.dataset.helperCreateIcon = typeof createIcon;
  document.documentElement.dataset.helperIcons = "resolved";
  Array.prototype.forEach.call(nodes, function (node) {
    const name = node.getAttribute("data-helper-icon") || "status.info";
    try {
      node.replaceChildren(createIcon(name, { size: 18, decorative: true }));
    } catch (error) {
      document.documentElement.dataset.helperIconsError = String(error && error.message || error).slice(0, 160);
      node.textContent = "";
    }
  });
  document.documentElement.dataset.helperIcons = "rendered";
}

async function enhanceLauncherGrid() {
  const host = document.querySelector("[data-enhance='icon-grid']");
  const source = document.getElementById("launcher-items");
  if (!host || !source) {
    return false;
  }

  let items;
  try {
    items = JSON.parse(source.textContent || "[]");
  } catch (error) {
    document.documentElement.dataset.launcherGridError = String(error && error.message || error).slice(0, 160);
    return false;
  }

  if (!Array.isArray(items) || !items.length) {
    return false;
  }

  await uiLoader.load("ui.icon.grid");
  const createIconGrid = await uiLoader.get("ui.icon.grid");
  if (typeof createIconGrid !== "function") {
    document.documentElement.dataset.launcherGridError = "ui.icon.grid factory unavailable";
    return false;
  }

  document.documentElement.dataset.launcherGrid = "loading";
  let currentItems = items.map(function (item) {
    return Object.assign({}, item);
  });
  const launcher = createIconGrid(host, currentItems, {
    ariaLabel: "PBB local application launcher",
    columns: "auto",
    minTileWidth: 108,
    iconSize: 80,
    chrome: false,
    editable: true,
    autoArrange: false,
    scrollable: false,
    slots: Math.max(6, currentItems.length),
    layout: readLauncherLayout(),
    onLayoutChange: function (layout) {
      writeLauncherLayout(layout);
    },
    onActivate: function (item) {
      if (item && item.href) {
        window.location.href = item.href;
      }
    },
  });

  window.pbbLandingLauncher = launcher;
  document.documentElement.dataset.launcherGrid = "rendered";
  refreshIconGridHealth(currentItems, function (nextItems) {
    currentItems = nextItems;
    launcher.update(currentItems, { layout: launcher.getLayout() });
  });
  return true;
}

function readLauncherLayout() {
  try {
    return JSON.parse(window.localStorage.getItem(launcherLayoutKey) || "null");
  } catch (_error) {
    return null;
  }
}

function writeLauncherLayout(layout) {
  try {
    window.localStorage.setItem(launcherLayoutKey, JSON.stringify(layout));
  } catch (error) {
    document.documentElement.dataset.launcherGridPersistError = String(error && error.message || error).slice(0, 160);
  }
}

function refreshIconGridHealth(items, onUpdate) {
  if (!window.fetch) {
    return;
  }

  Promise.all(items.map(function (item) {
    const healthUrl = item && item.meta && item.meta.healthUrl;
    if (!healthUrl) {
      return Promise.resolve(Object.assign({}, item, { status: "unknown" }));
    }

    return fetch(healthUrl, { credentials: "omit", cache: "no-store" })
      .then(function (response) {
        return Object.assign({}, item, { status: response.ok ? "online" : "warning" });
      })
      .catch(function () {
        return Object.assign({}, item, { status: "offline" });
      });
  })).then(function (nextItems) {
    onUpdate(nextItems);
  }).catch(function (error) {
    document.documentElement.dataset.launcherGridHealthError = String(error && error.message || error).slice(0, 160);
  });
}

function refreshHealthBadges() {
  var cards = document.querySelectorAll("[data-health-url]");
  Array.prototype.forEach.call(cards, function (card) {
    var url = card.getAttribute("data-health-url");
    var badge = card.querySelector("[data-health-badge]");
    if (!url || !badge || !window.fetch) {
      return;
    }

    fetch(url, { credentials: "omit", cache: "no-store" })
      .then(function (response) {
        badge.textContent = "";
        badge.setAttribute("aria-label", response.ok ? "Online" : "Warning");
        badge.setAttribute("title", response.ok ? "Online" : "Warning");
        badge.className = response.ok ? "status-dot online" : "status-dot warning";
      })
      .catch(function () {
        badge.textContent = "";
        badge.setAttribute("aria-label", "Offline");
        badge.setAttribute("title", "Offline");
        badge.className = "status-dot offline";
      });
  });
}

enhanceLauncherGrid()
  .then(function (enhanced) {
    if (!enhanced) {
      renderHelperIcons().catch(function (error) {
        document.documentElement.dataset.helperIcons = "failed";
        document.documentElement.dataset.helperIconsError = String(error && error.message || error).slice(0, 160);
      });
      refreshHealthBadges();
    }
  })
  .catch(function (error) {
    document.documentElement.dataset.launcherGrid = "failed";
    document.documentElement.dataset.launcherGridError = String(error && error.message || error).slice(0, 160);
    renderHelperIcons().catch(function (iconError) {
      document.documentElement.dataset.helperIcons = "failed";
      document.documentElement.dataset.helperIconsError = String(iconError && iconError.message || iconError).slice(0, 160);
    });
    refreshHealthBadges();
  });
