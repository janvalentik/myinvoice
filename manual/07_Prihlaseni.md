# 7. Přihlášení a uživatelský profil

## 7.1 Přihlášení

![Přihlašovací obrazovka](img/04_login.webp)

Zadej e-mail + heslo a klikni **Přihlásit**. Po přihlášení tě systém pustí na
[Přehled (dashboard)](08_Prehled.md).

| Pole | Význam |
|---|---|
| E-mail | Login zadaný při registraci |
| Heslo | Heslo zadané při registraci |
| Zapomenuté heslo? | Odkaz na obnovu — viz § 7.4 |

## 7.2 Brute-force ochrana

Po **5 neúspěšných pokusech** během 5 minut z jedné IP se objeví **CAPTCHA**
(Cloudflare Turnstile). Po **10 selháních** během 15 minut se IP zablokuje na
15 minut. Po **30 selháních za hodinu** je lockout 24 hodin a uživateli na
e-mail přijde upozornění.

> 🛈 Pokud heslo zapomeneš a omylem se 5× spletl, CAPTCHA se objeví — vyřeš ji
> a pokračuj. Pokud se zablokuješ, počkej 15 minut nebo požádej admina, aby ti
> heslo resetoval z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 7.3 Dvoufaktorové ověření (TOTP / 2FA)

Pokud máš pro účet aktivované 2FA, po zadání hesla tě systém vyzve k
6-cifernému kódu z autentikátoru. Detailní popis aktivace, použití záložních
kódů a řešení ztráty telefonu — viz [39. Bezpečnost — § 37.2](39_Bezpecnost.md).

## 7.4 Zapomenuté heslo

Klikni na **Zapomenuté heslo?** pod přihlášením. Zadej e-mail, na který přijde
odkaz pro nastavení nového hesla (platnost 1 hodina).

![Reset hesla](img/04_reset.webp)

Pokud e-mail nepřišel:

- Zkontroluj spam.
- Ověř s adminem, že systém má nakonfigurované SMTP (`cfg.php` → `smtp.*`).
- V krajním případě admin nastaví heslo z CLI: `php api/bin/set-password.php tvuj@email.cz`.

## 7.5 Můj profil

V pravém horním rohu klikni na své jméno → **Můj profil**.

![Můj profil](img/04_profil.webp)

Můžeš si změnit:

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI + activity log |
| Jazyk | `cs` (čeština) nebo `en` (angličtina) — UI + e-mailové šablony |
| Heslo | Změna stávajícího hesla (vyžaduje původní) |
| 2FA | Aktivovat / deaktivovat (vyžaduje heslo + ověření TOTP kódem) |

## 7.6 Odhlášení

V pravém horním rohu klikni **Odhlásit**. Session se zruší okamžitě i na
serveru. Pokud nezmáčkneš odhlásit a jen zavřeš okno, session vyprší **za 30
dní**.
