
## Browser automation note
When taking screenshots of the front-end theme (DaisyUI), Codex sees large empty spaces that don't exist for real users. This is likely related to how the sticky nav/header is rendered. Always scroll significantly further than expected to find content, and use JavaScript (`document.body.innerText`) to verify page content when screenshots appear empty. This issue only affects the site theme, not the admin theme.
