/**
 * @file
 * Client-side hole-punch for the evening program status banner.
 *
 * The page is cached whole; this fetches the live banner fragment and injects
 * it into the reserved placeholder. The request date is computed in
 * America/Toronto (not the browser's timezone), so at local midnight the URL
 * rolls to a new day, the edge cache misses, and the banner resets to
 * "not updated today" — no LiteSpeed purge needed.
 */
((Drupal, once) => {
  /**
   * Today's date (YYYY-MM-DD) in the club's timezone, regardless of visitor TZ.
   */
  function torontoDate() {
    // en-CA formats as YYYY-MM-DD, which is exactly the endpoint's ?d= format.
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: 'America/Toronto',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date());
  }

  Drupal.behaviors.piccEveningStatus = {
    attach(context) {
      once(
        'picc-evening-status',
        '[data-evening-status-endpoint]',
        context,
      ).forEach((mount) => {
        const base = mount.getAttribute('data-evening-status-endpoint');
        if (!base) {
          return;
        }
        const sep = base.indexOf('?') === -1 ? '?' : '&';
        const url = `${base + sep}d=${torontoDate()}`;

        fetch(url, {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
          .then((response) => (response.ok ? response.text() : Promise.reject()))
          .then((html) => {
            mount.innerHTML = html;
            // The fragment was rendered at the endpoint URL, so the editor edit
            // link (admin only) carries a `destination` back to that bare
            // endpoint. Point it at the current page instead, so saving the
            // block returns the editor to where they actually are.
            mount.querySelectorAll('a[href]').forEach((link) => {
              try {
                const href = new URL(link.getAttribute('href'), window.location.origin);
                if (href.searchParams.has('destination')) {
                  href.searchParams.set('destination', window.location.pathname);
                  link.setAttribute('href', href.pathname + href.search);
                }
              } catch (e) {
                // Leave the link untouched if the href can't be parsed.
              }
            });
            mount.classList.add('is-loaded');
            // Let other behaviours process anything inside the injected markup.
            Drupal.attachBehaviors(mount);
          })
          .catch(() => {
            // Fail quietly: the reserved placeholder stays empty rather than
            // showing a broken or stale banner.
          });
      });
    },
  };
})(Drupal, once);
