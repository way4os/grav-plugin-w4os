/**
 * js/menus.js: the pages of an entry of the admin menu in the top bar of the site (w4os/admin-menu.html.twig) beside it, to its right,
 * to its left only where the window has no room on the right. Quark2's own method for its menus (its site.js): the panels measured
 * while hidden, up front, again when the window or its fonts change; the one that would cross the edge turned over (w4os-sub-left).
 *
 * @package    w4os/grav-plugin-w4os
 * @license    AGPLv3
 */
(() => {
	const EDGE_GAP = 8;
	const panels = document.querySelectorAll(".w4os-has-sub > .w4os-user-sublist");
	if (!panels.length) {
		return;
	}
	const fit = () => {
		const width = document.documentElement.clientWidth;
		panels.forEach((panel) => panel.parentNode.classList.remove("w4os-sub-left"));
		panels.forEach((panel) => panel.getBoundingClientRect().right > width - EDGE_GAP && panel.parentNode.classList.add("w4os-sub-left"));
	};
	let timer;
	window.addEventListener("resize", () => {
		clearTimeout(timer);
		timer = setTimeout(fit, 100);
	});
	fit();
	document.fonts?.ready.then(fit);
})();
