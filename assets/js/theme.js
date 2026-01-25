/**
 * Theme switcher for artist portfolio
 * Supports:
 * - Light/Dark modes (light, dark, system)
 * - Gallery themes (minimal, gallery-white, darkroom, editorial, brutalist, soft)
 */

(function() {
    "use strict";

    var MODE_KEY = "painttwits_mode";      // light/dark/system
    var THEME_KEY = "painttwits_gallery";   // gallery theme name

    var GALLERY_THEMES = [
        { id: "minimal", name: "Minimal", desc: "Clean monospace" },
        { id: "gallery-white", name: "Gallery", desc: "Museum white cube" },
        { id: "darkroom", name: "Darkroom", desc: "Photo portfolio" },
        { id: "editorial", name: "Editorial", desc: "Magazine style" },
        { id: "brutalist", name: "Brutalist", desc: "Raw & bold" },
        { id: "soft", name: "Soft", desc: "Friendly & rounded" }
    ];

    // Get stored preferences
    function getStoredMode() {
        return localStorage.getItem(MODE_KEY) || "system";
    }

    function getStoredGalleryTheme() {
        return localStorage.getItem(THEME_KEY) || "minimal";
    }

    // Apply light/dark mode
    function applyMode(mode) {
        var root = document.documentElement;
        if (mode === "system") {
            root.removeAttribute("data-theme");
        } else {
            root.setAttribute("data-theme", mode);
        }
        updateModeButtons(mode);
    }

    // Apply gallery theme
    function applyGalleryTheme(theme) {
        var root = document.documentElement;
        if (theme === "minimal") {
            root.removeAttribute("data-gallery-theme");
        } else {
            root.setAttribute("data-gallery-theme", theme);
        }
        updateThemeButtons(theme);
    }

    // Update button states
    function updateModeButtons(mode) {
        document.querySelectorAll("[data-mode-btn]").forEach(function(btn) {
            btn.classList.toggle("active", btn.getAttribute("data-mode-btn") === mode);
        });
    }

    function updateThemeButtons(theme) {
        document.querySelectorAll("[data-theme-preview]").forEach(function(btn) {
            btn.classList.toggle("active", btn.getAttribute("data-theme-preview") === theme);
        });
    }

    // Set and persist mode
    function setMode(mode) {
        localStorage.setItem(MODE_KEY, mode);
        applyMode(mode);
    }

    // Set and persist gallery theme
    function setGalleryTheme(theme) {
        localStorage.setItem(THEME_KEY, theme);
        applyGalleryTheme(theme);
    }

    // Cycle through light/dark/system
    function cycleMode() {
        var current = getStoredMode();
        var next = current === "light" ? "dark" : current === "dark" ? "system" : "light";
        setMode(next);
        return next;
    }

    // Icons for mode button
    function getModeIcon(mode) {
        if (mode === "light") return "\u2600"; // sun
        if (mode === "dark") return "\u263D";  // moon
        return "\u25D0"; // half circle (system)
    }

    // Initialize
    function init() {
        var mode = getStoredMode();
        var theme = getStoredGalleryTheme();

        applyMode(mode);
        applyGalleryTheme(theme);

        // Set up mode toggle buttons
        document.querySelectorAll("[data-mode-btn]").forEach(function(btn) {
            btn.addEventListener("click", function() {
                setMode(btn.getAttribute("data-mode-btn"));
            });
        });

        // Set up gallery theme buttons
        document.querySelectorAll("[data-theme-preview]").forEach(function(btn) {
            btn.addEventListener("click", function() {
                setGalleryTheme(btn.getAttribute("data-theme-preview"));
            });
        });

        // Set up cycle button (footer toggle)
        document.querySelectorAll(".theme-cycle-btn").forEach(function(btn) {
            btn.textContent = getModeIcon(mode);
            btn.title = "Mode: " + mode;
            btn.addEventListener("click", function() {
                var newMode = cycleMode();
                btn.textContent = getModeIcon(newMode);
                btn.title = "Mode: " + newMode;
            });
        });
    }

    // Expose API globally
    window.painttwitsTheme = {
        getMode: getStoredMode,
        setMode: setMode,
        cycleMode: cycleMode,
        getModeIcon: getModeIcon,
        getGalleryTheme: getStoredGalleryTheme,
        setGalleryTheme: setGalleryTheme,
        themes: GALLERY_THEMES
    };

    // Initialize on DOM ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
