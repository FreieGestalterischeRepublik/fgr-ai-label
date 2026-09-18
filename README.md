# FGR AI Label

Ein Plugin der **Freien Gestalterischen Republik**.

Kennzeichnet KI-generierte oder KI-bearbeitete Bilder automatisch mit einem Logo – gemäß der [EU-Kennzeichnungspflicht für KI-Inhalte](https://digital-strategy.ec.europa.eu/de/policies/eu-icons-labelling-ai-generated-content). Das Logo wird **nicht** in die Bilddatei eingebrannt, sondern bei der Anzeige automatisch darübergelegt – egal ob das Bild in Gutenberg, ACF, Elementor oder WPBakery eingebunden wurde.

## Funktionsweise

1. **Mediathek:** Bei jedem Bild kann eine Checkbox „KI-generiert / KI-bearbeitet" aktiviert werden, plus Auswahl einer von 3 Kennzeichnungs-Arten (Basis-Symbol, Vollständig KI-generiert, Teilweise KI-modifiziert).
2. **Einstellungen:** Die 3 offiziellen EU-Icons sind fest im Plugin hinterlegt (`assets/img/`, als SVG in Schwarz und Weiß). Global werden Farbe, Position (eine der 4 Ecken), Abstand zum Rand und Icon-Höhe (max. 50 px) festgelegt.
3. **Anzeige:** Das Plugin fängt die fertige HTML-Ausgabe jeder Seite ab (Output-Buffer) und erkennt markierte Bilder anhand der WordPress-Bild-ID bzw. der Bild-URL – unabhängig davon, mit welchem Editor/Builder sie eingebunden wurden. Das Icon wird per CSS positioniert (`position: absolute`), das Originalbild bleibt unverändert.

## Icons

Die mitgelieferten Icons stammen von der offiziellen EU-Seite zur [Kennzeichnung von KI-Inhalten](https://digital-strategy.ec.europa.eu/de/policies/eu-icons-labelling-ai-generated-content) ("öffentlich zur Verfügung, von jedermann frei verwendbar"). Bereitgestellt werden pro Typ die Varianten Schwarz und Weiß (die 50%-transparenten EU-Varianten sind nicht enthalten, da die Icons ohnehin nur als kleines Badge über dem Bild schweben).

## Abdeckung nach Einbindungs-Art

| Einbindung | Funktioniert? |
|---|---|
| `<img>`-Tag (Gutenberg-Bildblock, ACF via `wp_get_attachment_image()`, Elementor-Bild-Widget, WPBakery `vc_single_image`) | ✅ |
| CSS-Hintergrundbild als Inline-Style (WPBakery Row/Column-Hintergrund, ACF-Theme-Code) | ✅ |
| Elementor „Klassik"-Hintergrundbild auf Sections/Columns/Containern (liegt standardmäßig in einer externen CSS-Datei) | ✅ (über `_elementor_data`, nur auf Einzelseiten/-beiträgen – nicht in Archiv-/Loop-Ausgaben von Elementor-Templates) |
| Elementor Diashow- oder Video-Hintergrund | ❌ (nicht abgedeckt) |
| Bild nur über eine CSS-Klasse in einer externen Stylesheet-Datei eingebunden (kein Inline-Style, kein `<img>`) | ❌ (nicht erkennbar) |

## Technisches

- **DB-Option:** `fgr_ai_label_settings` (wird bei Deinstallation gelöscht)
- **Post-Meta je Bild:** `_fgr_ai_label_enabled`, `_fgr_ai_label_type`
- **Caching:** Zuordnung „markierte Bilder → Logo" wird 1 Tag in einem Transient (`fgr_ail_map`) zwischengespeichert und bei Änderungen automatisch geleert
- **Performance:** Der Output-Buffer wird nur aktiv, wenn mindestens ein Bild markiert ist – ohne markierte Bilder entsteht kein zusätzlicher Aufwand
- **Update-Checker:** Prüft GitHub-Releases auf neue Versionen, Bibliothek in `lib/plugin-update-checker/`
- **Mindestanforderungen:** PHP 7.4+, WordPress 6.0+
- **Autor:** Freie Gestalterische Republik
