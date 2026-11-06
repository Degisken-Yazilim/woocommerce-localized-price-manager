/**
 * Stand-in for a stylesheet import under Jest.
 *
 * `admin.js` imports `../scss/admin.scss` so webpack can extract it, but Jest
 * has no loader for SCSS and would fail to parse the file. `moduleNameMapper`
 * routes every stylesheet import here instead, where it resolves to nothing.
 */

export default {};
