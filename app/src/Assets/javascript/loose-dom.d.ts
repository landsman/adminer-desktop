// ponytail: what a querySelector or an event hands back is typed as a bare Element or
// EventTarget, so reading .value or .close() off one is an error TypeScript cannot know is
// right. These two lines switch that off, which leaves the check to what it is for: every
// window.adminer member declared in adminer.d.ts. To type the DOM as well, delete this file and
// narrow each lookup (`/** @type {HTMLInputElement} */`) until `make qa` passes.
interface Element {
	[name: string]: any;
}
interface EventTarget {
	[name: string]: any;
}

/** Adminer's own (static/functions.js): repaints <code> blocks once a highlighter loads. */
declare let adminerHighlighter: (codes: NodeListOf<Element>) => void;
