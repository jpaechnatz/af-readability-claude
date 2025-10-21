# Docker Installation ohne Composer

## Gute Nachrichten!

**Sie brauchen Composer NICHT** - alle Dependencies sind bereits im Repository enthalten (im `vendor/` Verzeichnis).

---

## Einfache Installation für Docker

### Schritt 1: In den Container einloggen

```bash
docker exec -it freshrss bash
```

(Ersetzen Sie `freshrss` mit dem Namen Ihres Containers, falls anders)

### Schritt 2: Zum Extensions-Verzeichnis wechseln

```bash
cd /usr/share/freshrss/extensions/
```

### Schritt 3: Repository klonen

```bash
git clone https://github.com/jpaechnatz/af-readability-claude.git af_readability
cd af_readability
```

### Schritt 4: Security-Branch auschecken

```bash
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh
```

### Schritt 5: Berechtigungen setzen

```bash
chown -R www-data:www-data /usr/share/freshrss/extensions/af_readability
```

### Schritt 6: Fertig!

```bash
exit  # Container verlassen
```

---

## Falls Repository bereits existiert

Wenn Sie die Extension bereits installiert haben:

```bash
docker exec -it freshrss bash
cd /usr/share/freshrss/extensions/af_readability

# Branch aktualisieren
git fetch origin
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Fertig - keine weiteren Schritte nötig!
exit
```

---

## Verifizierung

Prüfen Sie, ob die Dependencies vorhanden sind:

```bash
docker exec -it freshrss bash
ls -la /usr/share/freshrss/extensions/af_readability/vendor/
```

Sie sollten sehen:
```
autoload.php
composer/
fivefilters/
league/
masterminds/
psr/
```

---

## Aktivierung in FreshRSS

1. **FreshRSS öffnen** im Browser
2. **Einstellungen → Erweiterungen** (oder **Konfiguration → Erweiterungen**)
3. **"Af_Readability"** aktivieren
4. **Speichern**
5. Auf das **Zahnrad-Symbol** klicken
6. **Feeds auswählen**, für die Sie die Extension nutzen möchten
7. **Speichern**

---

## Testen

1. Einen Feed aktualisieren
2. Artikel öffnen - sollten jetzt vollständigen Inhalt zeigen
3. Logs prüfen:

```bash
docker exec -it freshrss bash
tail -f /var/www/FreshRSS/data/users/_/log.txt
# oder
tail -f /var/www/FreshRSS/data/users/ihr-username/log.txt
```

---

## Häufige Probleme

### Extension erscheint nicht in der Liste

**Problem:** Af_Readability wird nicht angezeigt

**Lösung:**
```bash
docker exec -it freshrss bash

# Prüfen, ob Verzeichnis existiert
ls -la /usr/share/freshrss/extensions/af_readability

# Prüfen, ob extension.php existiert
ls -la /usr/share/freshrss/extensions/af_readability/extension.php

# Berechtigungen prüfen und korrigieren
chown -R www-data:www-data /usr/share/freshrss/extensions/af_readability
chmod -R 755 /usr/share/freshrss/extensions/af_readability
```

### Verzeichnisname falsch

**Wichtig:** Der Ordner muss `af_readability` heißen (mit Unterstrich, nicht Bindestrich!)

```bash
# Falls falsch benannt:
docker exec -it freshrss bash
cd /usr/share/freshrss/extensions/
mv af-readability af_readability  # umbenennen
```

### PHP-Fehler "Class not found"

Prüfen Sie, ob vendor/ vorhanden ist:

```bash
docker exec -it freshrss bash
ls /usr/share/freshrss/extensions/af_readability/vendor/autoload.php
```

Falls die Datei fehlt:
```bash
cd /usr/share/freshrss/extensions/af_readability
git status
# Sollte zeigen, dass Sie auf dem richtigen Branch sind

# Falls vendor/ fehlt, nochmal auschecken:
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh -- vendor/
```

---

## Alternative: Ohne Git im Container

Falls Git nicht im Container verfügbar ist, können Sie die Dateien von außen kopieren:

### Option A: Volume Mount nutzen

Falls FreshRSS mit Volumes läuft:

```bash
# Auf dem Host-System:
git clone https://github.com/jpaechnatz/af-readability-claude.git
cd af-readability-claude
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Finden Sie das Volume:
docker volume inspect freshrss_extensions
# oder
docker inspect freshrss | grep -A 5 Mounts

# Kopieren ins Volume (Pfad anpassen!)
sudo cp -r . /var/lib/docker/volumes/freshrss_extensions/_data/af_readability/
```

### Option B: Docker cp nutzen

```bash
# Auf dem Host-System:
git clone https://github.com/jpaechnatz/af-readability-claude.git af_readability
cd af_readability
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# In Container kopieren:
docker cp . freshrss:/usr/share/freshrss/extensions/af_readability/

# Berechtigungen setzen:
docker exec freshrss chown -R www-data:www-data /usr/share/freshrss/extensions/af_readability
```

---

## Sicherheits-Features testen

### SSRF-Schutz prüfen

Die Extension blockiert jetzt automatisch:
- `localhost` und `127.0.0.1`
- Private IP-Adressen (`192.168.x.x`, `10.x.x.x`)
- Nicht-HTTP(S) Protokolle

Im Log sollten Sie sehen:
```
af-readability: Blocked unsafe URL
```

### Timeouts prüfen

- Langsame Seiten: Timeout nach 30 Sekunden
- Nicht erreichbare Server: Timeout nach 10 Sekunden

### Größenlimit prüfen

Seiten über 500KB werden abgelehnt.

---

## Docker-Compose Beispiel

Falls Sie docker-compose nutzen:

```yaml
version: "3"
services:
  freshrss:
    image: freshrss/freshrss:latest
    volumes:
      - ./af_readability:/usr/share/freshrss/extensions/af_readability:ro
    # ... weitere Konfiguration
```

Dann:
```bash
# Auf dem Host:
git clone https://github.com/jpaechnatz/af-readability-claude.git af_readability
cd af_readability
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Container neustarten:
docker-compose restart freshrss
```

---

## Zusammenfassung

✅ **Kein Composer nötig** - Dependencies sind bereits dabei
✅ **Einfaches Git-Checkout** im Container reicht aus
✅ **Berechtigungen setzen** nicht vergessen
✅ **In FreshRSS aktivieren** und testen

---

## Support

Falls Probleme auftreten:

1. **Logs prüfen** (siehe oben)
2. **TESTING_GUIDE.md** lesen für Details
3. **Issue erstellen** mit:
   - Docker-Image Version
   - Fehlerlog
   - Schritte zum Reproduzieren

Viel Erfolg beim Testen!
