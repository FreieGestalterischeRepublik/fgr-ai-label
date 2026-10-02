/**
 * Kennzeichnet KI-markierte Bilder zusätzlich in Lightboxen, die ihren Inhalt erst
 * per JavaScript nachladen (z.B. PhotoSwipe, das u.a. WooCommerce für die
 * Produktgalerie verwendet).
 *
 * Die normale Kennzeichnung (class-fgr-ai-label-render.php) arbeitet per
 * Output-Buffer auf der vom Server bereits ausgelieferten HTML-Seite - das
 * erreicht alle <img>-Tags und Hintergrundbilder, die beim Laden der Seite
 * existieren. Eine PhotoSwipe-Lightbox baut ihre eigenen <img>-Elemente aber
 * erst im Browser neu zusammen, sobald sie geöffnet wird (und beim Weiter-
 * blättern wiederverwendet/ausgetauscht) - diese Bilder sieht der Output-
 * Buffer nie. Deshalb hier ein schlanker MutationObserver, der genau diesen
 * Fall zusätzlich abdeckt.
 *
 * Die Positions-Werte (--fgr-ail-top/-right/-bottom/-left/-h) werden wie beim
 * normalen Bild auch als CSS-Custom-Properties gesetzt, damit dieselben
 * !important-Regeln aus frontend.css greifen (ein einfacher Inline-Style fuer
 * top/bottom/left/right wuerde von diesen !important-Regeln ueberschrieben).
 *
 * Die Werte selbst werden aber bei jeder Positionsaenderung per JS neu
 * berechnet statt (wie sonst) fest aus den Einstellungen uebernommen:
 * PhotoSwipes .pswp__zoom-wrap (der einzige positionierte Vorfahre) spannt die
 * gesamte Lightbox-Bühne auf, nicht nur die sichtbare Bildfläche - das <img>
 * selbst wird von PhotoSwipe per fester Inline-Breite/-Höhe plaziert und ist
 * je nach Bildformat/Zoomstufe meist kleiner als dieser Rahmen. Ein simples
 * "unten links" würde dadurch an der Bühnen-Kante statt an der Bild-Kante
 * landen (live beobachtet: Badge erschien unterhalb des Bildes). Deshalb wird
 * der Versatz zwischen Bild- und Wrapper-Kante bei jeder Positionsänderung
 * (Öffnen, Zoomen, Blättern, Fenstergröße) neu berechnet.
 */
( function () {
	if ( typeof window.fgrAilMap === 'undefined' ) {
		return;
	}

	var byUrl = window.fgrAilMap.byUrl || {};
	var logos = window.fgrAilMap.logos || {};
	var height = parseInt( window.fgrAilMap.height, 10 ) || 32;
	var margin = parseInt( window.fgrAilMap.margin, 10 ) || 12;
	var position = window.fgrAilMap.position || 'bottom-left';

	function normalizeUrl( url ) {
		url = url.split( '?' )[ 0 ];
		url = url.replace( /-\d+x\d+(?=\.\w+$)/, '' );
		return url.toLowerCase();
	}

	function positionBadge( badge, img, zoomWrap ) {
		var imgRect = img.getBoundingClientRect();
		var wrapRect = zoomWrap.getBoundingClientRect();

		var top = imgRect.top - wrapRect.top;
		var left = imgRect.left - wrapRect.left;
		var right = wrapRect.right - imgRect.right;
		var bottom = wrapRect.bottom - imgRect.bottom;

		var vEdge = 0 === position.indexOf( 'top' ) ? 'top' : 'bottom';
		var hEdge = -1 !== position.indexOf( 'left' ) ? 'left' : 'right';

		var style = '--fgr-ail-h:' + height + 'px;';
		style += '--fgr-ail-top:' + ( 'top' === vEdge ? ( top + margin ) + 'px' : 'auto' ) + ';';
		style += '--fgr-ail-bottom:' + ( 'bottom' === vEdge ? ( bottom + margin ) + 'px' : 'auto' ) + ';';
		style += '--fgr-ail-left:' + ( 'left' === hEdge ? ( left + margin ) + 'px' : 'auto' ) + ';';
		style += '--fgr-ail-right:' + ( 'right' === hEdge ? ( right + margin ) + 'px' : 'auto' ) + ';';

		// Nur tatsaechlich aendern, nicht bei jedem Aufruf neu setzen: der
		// MutationObserver unten beobachtet u.a. "style"-Aenderungen im
		// gesamten DOM (fuer Zoom/Pan), ein unbedingtes setAttribute hier
		// wuerde sich also selbst immer wieder neu antriggern (Endlosschleife).
		if ( badge.getAttribute( 'style' ) !== style ) {
			badge.setAttribute( 'style', style );
		}
	}

	function labelImage( img ) {
		var zoomWrap = img.closest( '.pswp__zoom-wrap' );
		if ( ! zoomWrap ) {
			return;
		}

		var existing = zoomWrap.querySelector( '.fgr-ail-badge' );
		var id = byUrl[ normalizeUrl( img.src ) ];

		// Kein (oder kein markiertes) Bild fuer diesen Slide - ein evtl. vom
		// vorherigen Slide uebrig gebliebenes Badge wieder entfernen, da
		// PhotoSwipe seine Slide-Elemente beim Weiterblaettern wiederverwendet.
		if ( ! id || ! logos[ id ] ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}

		var badge = existing;
		if ( ! badge ) {
			badge = document.createElement( 'img' );
			badge.className = 'fgr-ail-badge';
			badge.alt = 'KI-generiert';
			zoomWrap.appendChild( badge );
		}
		if ( badge.src !== logos[ id ] ) {
			badge.src = logos[ id ];
		}

		positionBadge( badge, img, zoomWrap );
	}

	function scan() {
		document.querySelectorAll( '.pswp__img[src]' ).forEach( labelImage );
	}

	function repositionOpen() {
		document.querySelectorAll( '.pswp .fgr-ail-badge' ).forEach( function ( badge ) {
			var zoomWrap = badge.closest( '.pswp__zoom-wrap' );
			var img = zoomWrap ? zoomWrap.querySelector( 'img.pswp__img' ) : null;
			if ( zoomWrap && img ) {
				positionBadge( badge, img, zoomWrap );
			}
		} );
	}

	new MutationObserver( scan ).observe( document.body, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'src', 'style' ],
	} );

	window.addEventListener( 'resize', repositionOpen );
} )();
