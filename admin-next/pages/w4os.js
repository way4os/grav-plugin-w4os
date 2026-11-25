/**
 * admin-next/pages/w4os.js: the page of the w4os plugin in Grav's admin (admin2, its plugin pages: the tag it gives,
 * window.__GRAV_PAGE_TAG): the way back to the admin of the grid, its links from the page's definition (onApiPluginPageInfo).
 *
 * @package    w4os/grav-plugin-w4os
 * @license    AGPLv3
 */
(() => {
	const tag = window.__GRAV_PAGE_TAG;
	if (!tag || customElements.get(tag)) {
		return;
	}
	const api = (window.__GRAV_API_SERVER_URL || "") + (window.__GRAV_API_PREFIX || "/api/v1");

	customElements.define(
		tag,
		class extends HTMLElement {
			async connectedCallback() {
				let links = [];
				try {
					const response = await fetch(api + "/gpm/plugins/w4os/page", { headers: { Authorization: "Bearer " + (window.__GRAV_API_TOKEN || "") } });
					const body = await response.json();
					links = (body.data ?? body).links ?? [];
				} catch (error) {
					links = [];
				}
				const list = document.createElement("div");
				list.className = "flex flex-wrap gap-3 p-6";
				links.forEach((link) => {
					const a = document.createElement("a");
					const icon = document.createElement("i");
					a.href = link.url;
					a.className = "inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90";
					icon.className = "fa-solid " + (link.icon || "fa-arrow-right");
					icon.setAttribute("aria-hidden", "true");
					a.append(icon, " " + link.label);
					list.append(a);
				});
				this.replaceChildren(list);
			}
		},
	);
})();
