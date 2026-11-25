/**
 * admin-next/pages/w4os.js: the page of the w4os plugin in Grav's admin (admin2, its plugin pages: the tag it gives,
 * window.__GRAV_PAGE_TAG): it leads at once to the settings of the site in the admin of the grid (the `url` of the page's definition,
 * onApiPluginPageInfo), its link shown meanwhile.
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
				let page = {};
				try {
					const response = await fetch(api + "/gpm/plugins/w4os/page", { headers: { Authorization: "Bearer " + (window.__GRAV_API_TOKEN || "") } });
					const body = await response.json();
					page = body.data ?? body;
				} catch (error) {
					page = {};
				}
				const url = page.url || "/w4os/settings";
				const link = document.createElement("a");
				link.href = url;
				link.className = "inline-flex m-6 text-primary underline";
				link.textContent = page.label || url;
				this.replaceChildren(link);
				window.location.assign(url);
			}
		},
	);
})();
