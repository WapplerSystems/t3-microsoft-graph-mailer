# Microsoft Entra ID — App Registration für TYPO3 Mailer

Diese Anleitung führt dich durch die Schritte im Microsoft-Tenant, die nötig
sind, damit TYPO3 über Microsoft Graph Mails verschicken darf. Du brauchst
hierfür einen Tenant-Administrator-Account (oder jemanden, der die Schritte
für dich ausführt).

Am Ende hast du **vier Werte**, die im TYPO3-Backend unter
*System → OAuth Services* eingetragen werden:

| Wert            | Wofür                                                          |
|-----------------|----------------------------------------------------------------|
| Tenant ID       | Identifiziert deinen Microsoft-365-Tenant                      |
| Client ID       | Identifiziert die App-Registrierung („Application (client) ID")|
| Client Secret   | Authentifiziert die App gegenüber Microsoft                    |
| Sender UPN      | Mailbox-Adresse, von der gesendet wird (z. B. `noreply@…`)     |

---

## 1. App-Registrierung anlegen

1. Browser → <https://entra.microsoft.com>
2. Linke Seitenleiste: **Applications → App registrations**
3. Oben: **+ New registration**
4. Werte eintragen:

   | Feld                                | Wert                                    |
   |-------------------------------------|-----------------------------------------|
   | **Name**                            | `TYPO3 Mailer – <deine-domain>`         |
   | **Supported account types**         | *Accounts in this organizational directory only (single tenant)* |
   | **Redirect URI (optional)**         | *leer lassen* — App-only Flow, kein User-Login |

5. **Register** klicken.

Du landest auf der „Overview"-Seite der neuen App.

### ⇒ Hier zwei Werte abgreifen:

- **Application (client) ID** → das ist deine **Client ID**
- **Directory (tenant) ID** → das ist deine **Tenant ID**

(Beides per Klick auf das kleine Copy-Icon kopieren.)

---

## 2. API-Berechtigung „Mail.Send" hinzufügen

1. In der App linke Seitenleiste: **API permissions**
2. **+ Add a permission**
3. Auf der rechten Seite: **Microsoft Graph** auswählen
4. **Application permissions** (NICHT *Delegated permissions* — App-only Flow!)
5. Suchfeld: `Mail.Send`. Häkchen setzen. **Add permissions** klicken.
6. Zurück in der API-Permissions-Liste — die Spalte „Status" zeigt jetzt
   `Not granted for <tenant>` mit einem orangenen Warnsymbol.
7. **„Grant admin consent for <tenant>"**-Button oben klicken. Bestätigen.
   Status wird grün: `Granted for <tenant>`.

> ⚠️ **Ohne Admin-Consent funktioniert gar nichts.** Microsoft gibt dann
> einen Token aus, aber `sendMail` antwortet mit `403 Forbidden`.

---

## 3. Client Secret erzeugen

1. Linke Seitenleiste: **Certificates & secrets → Client secrets**
2. **+ New client secret**
3. Werte:

   | Feld          | Wert                                    |
   |---------------|-----------------------------------------|
   | **Description** | `TYPO3 Mailer`                        |
   | **Expires**     | *24 months* (oder firmenintern üblicher Zeitraum) |

4. **Add** klicken.

### ⇒ JETZT den Wert kopieren

Microsoft zeigt zwei Spalten:

- **Value** — das ist das Client Secret. **Nur ein einziges Mal sichtbar!**
- **Secret ID** — Verwaltungs-ID, NICHT der Wert. Brauchst du nicht.

Den **Value** sofort in einen Passwort-Manager (oder direkt ins
TYPO3-BE-Modul) kopieren. Sobald du die Seite verlässt, ist der Wert
unwiederbringlich weg und du müsstest ein neues Secret erzeugen.

---

## 4. App auf eine einzige Mailbox einschränken

Ohne diesen Schritt kann die App von **jeder** Mailbox im Tenant senden —
das ist ein Sicherheitsrisiko, das du nicht haben willst. Microsoft bietet
dafür die *Application Access Policy* in Exchange Online.

Voraussetzung: **Exchange Online PowerShell V3** lokal installiert:
```powershell
Install-Module -Name ExchangeOnlineManagement -Force
```

Dann:

```powershell
Connect-ExchangeOnline

# 1. Sicherheitsgruppe anlegen, deren einziges Mitglied die Absender-Mailbox ist:
New-DistributionGroup `
    -Name "TYPO3MailerAllowedSenders" `
    -Type Security `
    -Members noreply@yourdomain.com

# 2. App auf diese Gruppe einschränken:
New-ApplicationAccessPolicy `
    -AppId <CLIENT-ID-aus-Schritt-1> `
    -PolicyScopeGroupId TYPO3MailerAllowedSenders@yourdomain.com `
    -AccessRight RestrictAccess `
    -Description "TYPO3 Mailer restricted to noreply mailbox"

# 3. Verifizieren (sollte die neue Policy listen):
Get-ApplicationAccessPolicy
```

> ℹ️ Falls die Absender-Mailbox `noreply@yourdomain.com` noch nicht
> existiert: vorher eine Mailbox mit Exchange-Online-Plan-1-Lizenz
> anlegen, oder eine bestehende Service-Mailbox verwenden.

### ⇒ Hier den vierten Wert abgreifen:

- **Sender UPN** = die Mailbox-Adresse aus dem `-Members`-Parameter
  (z. B. `noreply@yourdomain.com`)

---

## 5. Zusammenfassung — was du jetzt hast

| Wert            | Beispiel                                      |
|-----------------|-----------------------------------------------|
| Tenant ID       | `12345678-1234-1234-1234-123456789abc`        |
| Client ID       | `abcdef01-2345-6789-abcd-ef0123456789`        |
| Client Secret   | `xYz~ABCdefGHIjklMNOpqr.STUvw1234567890.Q`    |
| Sender UPN      | `noreply@yourdomain.com`                      |

---

## 6. Eintragen in TYPO3

Im TYPO3-Backend zu **System → OAuth Services → Clients → Neu** wechseln und
folgende Felder ausfüllen:

| Feld            | Wert                                                                                              |
|-----------------|---------------------------------------------------------------------------------------------------|
| Provider        | `microsoft_graph`                                                                                 |
| Active          | ✅                                                                                                |
| Client ID       | *Client ID*                                                                                       |
| Client Secret   | *Client Secret Value*                                                                             |
| Scopes          | `https://graph.microsoft.com/.default`                                                            |
| Metadata        | `{"tenant_id":"<Tenant ID>","sender_upn":"<Sender UPN>"}`                                         |

Speichern. Erster Test von der CLI:

```bash
typo3 microsoft-graph-mailer:test deine@adresse.example.com
```

Erfolg:

```
[OK] Test mail accepted by Microsoft Graph for delivery to deine@adresse.example.com.
```

Fehler? Der Output enthält die Microsoft `request-id` — bei einem
Support-Ticket immer mitschicken.

---

## Troubleshooting

| Symptom                                                                                | Ursache                                                                                       |
|----------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| `400 Bad Request: AADSTS900023` beim Token-Holen                                       | Tenant ID falsch — prüfe die GUID auf der Overview-Seite der App                              |
| `401 Unauthorized: AADSTS7000215`                                                      | Client Secret falsch oder abgelaufen — in Azure ein neues Secret erzeugen                     |
| `403 Forbidden: ErrorAccessDenied: Access is denied. Check credentials and try again.` | Admin-Consent für `Mail.Send` fehlt — Schritt 2 wiederholen                                   |
| `403 Forbidden: ErrorAccessDenied: Send mail to specified recipient is not allowed.`   | Application Access Policy blockiert — Sender UPN ist nicht in der Allow-Gruppe                |
| `404 Not Found: MailboxNotEnabledForRESTAPI`                                           | Sender UPN hat keine Exchange-Online-Lizenz — Mailbox lizenzieren                             |
| `429 Too Many Requests`                                                                | Graph-Throttling, Microsoft erlaubt max. 10.000 Mails/24h pro App pro Mailbox — `Retry-After`-Header beachten |

---

## Sicherheits-Hinweise

- **Client Secret rotieren**: spätestens vor dem in Azure eingestellten
  Ablaufdatum. Setze dir eine Erinnerung 1 Monat vorher.
- **Niemals committen**: Client Secret gehört nicht ins Git-Repo, nicht in
  `LocalConfiguration.php`, nicht in `.env`. Es lebt verschlüsselt in
  `tx_oauthsvc_client.client_secret` (sodium-XSalsa20-Poly1305).
- **Audit-Logs**: Microsoft logged jeden `sendMail`-Aufruf der App. Im
  *Microsoft Purview Compliance Portal → Audit*-Modul kannst du nachsehen,
  von wo aus deine App in den letzten 90 Tagen Mails verschickt hat.
