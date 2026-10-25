// ponytail: what a querySelector hands back is typed as a bare Element, so reading .value or
// .close() off one is an error TypeScript cannot know is right. This switches that off, which
// leaves the check to what it is for: every window.adminer member declared in adminer.d.ts.
// Element only — never EventTarget, which Window extends: loosening that let any window.<name>
// through, an undeclared one included. To type the DOM as well, delete this and narrow each
// lookup (`/** @type {HTMLInputElement} */`) until `make qa` passes.
interface Element {
	[name: string]: any;
}

/** Adminer's own (static/functions.js): repaints <code> blocks once a highlighter loads. */
declare let adminerHighlighter: (codes: NodeListOf<Element>) => void;
