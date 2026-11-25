(() => {
    "use strict";

    const root = document.documentElement;
    const darkPreference = window.matchMedia("(prefers-color-scheme: dark)");
    const applyTheme = () => {
        let saved = "auto";
        try { saved = localStorage.getItem("uc-theme") || "auto"; } catch (_) { /* System preference remains available. */ }
        root.dataset.theme = saved === "dark" || (saved !== "light" && darkPreference.matches) ? "dark" : "light";
    };
    applyTheme();
    darkPreference.addEventListener?.("change", applyTheme);
    window.addEventListener("storage", event => {
        if (event.key === "uc-theme") applyTheme();
    });

    const toc = document.querySelector(".guide-toc");
    const mobile = window.matchMedia("(max-width: 900px)");
    const links = [...document.querySelectorAll('.guide-toc nav a[href^="#step-"]')];
    const steps = [...document.querySelectorAll(".guide-step")];
    const status = document.querySelector("[data-current-step]");
    const syncToc = () => { if (toc) toc.open = !mobile.matches; };
    syncToc();
    mobile.addEventListener?.("change", syncToc);

    const selectStep = id => {
        const index = steps.findIndex(step => step.id === id);
        if (index < 0) return;
        links.forEach(link => {
            if (link.hash === `#${id}`) link.setAttribute("aria-current", "step");
            else link.removeAttribute("aria-current");
        });
        if (status) status.textContent = `当前第 ${index + 1} 步，共 ${steps.length} 步`;
    };
    links.forEach(link => {
        link.addEventListener("click", event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            if (mobile.matches && toc) toc.open = false;
            selectStep(link.hash.slice(1));
        });
    });
    const fromHash = () => selectStep(location.hash.slice(1));
    window.addEventListener("hashchange", fromHash);
    fromHash();

    if ("IntersectionObserver" in window) {
        const visible = new Set();
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) visible.add(entry.target);
                else visible.delete(entry.target);
            });
            const current = [...visible].sort((a, b) => a.getBoundingClientRect().top - b.getBoundingClientRect().top)[0];
            if (current) selectStep(current.id);
        }, { rootMargin: "-145px 0px -45% 0px", threshold: 0 });
        steps.forEach(step => observer.observe(step));
    }

    const viewer = document.querySelector(".guide-viewer");
    if (!viewer || typeof viewer.showModal !== "function") return;
    const image = viewer.querySelector("img");
    const title = viewer.querySelector("h2");
    let opener = null;
    document.querySelectorAll("[data-guide-image]").forEach(link => {
        link.addEventListener("click", event => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            opener = link;
            const original = link.querySelector("img");
            image.src = link.href;
            image.alt = original?.alt || "操作示意图";
            title.textContent = link.getAttribute("aria-label")?.replace(/^放大/, "") || "操作示意图";
            document.body.classList.add("viewer-open");
            viewer.showModal();
        });
    });
    viewer.querySelector("[data-close-viewer]").addEventListener("click", () => viewer.close());
    viewer.addEventListener("click", event => {
        if (event.target !== viewer) return;
        const bounds = viewer.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) viewer.close();
    });
    viewer.addEventListener("close", () => {
        document.body.classList.remove("viewer-open");
        image.removeAttribute("src");
        opener?.focus({ preventScroll: true });
    });
})();
