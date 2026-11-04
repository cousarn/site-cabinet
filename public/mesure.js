/* Cabinet Arnaud Cousin — mesure d'audience interne, sans cookie et sans tiers.
   Une page vue = un signal vers /api/mesure.php. Rien n'est stocké dans le navigateur.
   Le signal n'est pas envoyé si le navigateur demande à ne pas être suivi. */
(function () {
  try {
    var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
    if (dnt === '1' || dnt === 'yes') { return; }

    var utm = '';
    try { utm = new URLSearchParams(location.search).get('utm_source') || ''; } catch (e) { /* ignoré */ }

    var charge = JSON.stringify({ p: location.pathname, r: document.referrer || '', s: utm });

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/mesure.php', new Blob([charge], { type: 'text/plain;charset=UTF-8' }));
    } else {
      fetch('/api/mesure.php', { method: 'POST', body: charge, keepalive: true, credentials: 'omit' })
        .catch(function () { /* la mesure ne doit jamais gêner la navigation */ });
    }
  } catch (e) { /* la mesure ne doit jamais gêner la navigation */ }
})();
