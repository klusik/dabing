# Dabing

Dabing je webové studio pro společné nahrávání audioknih. Autor nahraje scénář
ve formátu JSON a pozve spolupracovníky. Každý pokus se uloží zvlášť. Autor volí
záběry pro souvislý poslech a členové si mohou jednotlivé soubory stáhnout.

## Instalace

Potřebujete PHP 8.1 nebo novější s rozšířeními PDO MySQL, mbstring a fileinfo,
prázdnou databázi MariaDB nebo MySQL, Apache 2.4 s `mod_rewrite` a povolené
zpracování `.htaccess`. Pro mikrofon otevřete web přes HTTPS nebo na
`localhost`. Aplikace zachytí zvuk v prohlížeči pomocí MediaRecorder.

1. Zkopírujte zdrojové soubory včetně `.htaccess` do webového adresáře a
   zpřístupněte aplikaci pro zápis do `storage`. Nekopírujte Git metadata ani
   dřívější provozní soubory.
2. Ověřte, že adresy pod `storage/` a `database/` vracejí 403 nebo 404.
3. Otevřete veřejnou adresu Dabingu. Průvodce požádá o server, název
   databáze, databázový účet a heslo, port (výchozí `3306`) a počáteční účet
   s uživatelským jménem, e-mailem a heslem. Zadejte účet databáze, který smí
   vytvářet tabulky.
4. Po dokončení se přihlaste vytvořeným účtem. Přístup do databáze je uložený
   v chráněném `storage/config.php`. Pro zálohu uchovávejte databázi a neveřejný
   adresář `storage`, zejména `storage/recordings/`.

Na WEDOSu použijte server, název databáze a jeden databázový účet z administrace
hostingu. Tento účet musí mít oprávnění vytvářet tabulky. Nahrajte obsah složky
`dabing/` z ověřeného ZIP balíčku do `www/subdom/dabing`, včetně `.htaccess`,
a otevřete `https://dabing.klusik.cz/`. Starší instalace může mít v
`storage/config.php` nastavení `base_url`. Nová instalace používá relativní
odkazy a veřejnou adresu nevyžaduje.

- [Dokumentace databází WEDOS](https://kb.vedos.cz/webhosting-databaze/)
- [Dokumentace konfigurace PHP WEDOS](https://kb.vedos.cz/webhosting-php/)

Import scénáře má strop 20 MB (20 000 000 bajtů). Při nahrávání souboru
aplikace zohlední také `upload_max_filesize` a `post_max_size` s rezervou
pro formulář. U výběru souboru se zobrazí výsledný limit pro daný web.

### Aktualizace z verze 0.1.1

Před aktualizací zálohujte databázi a úložiště a přihlaste se do verze 0.1.1
dosavadním správcovským účtem. Otevřete **Aktualizace** a nainstalujte vydání
0.2.0. Updater aplikuje čekající migraci před výměnou aplikačních souborů.
Pokud běžný databázový účet nemůže měnit schéma, zadejte účet s oprávněním DDL
do pole **Databázová oprávnění**.

Migrace nastaví stávající účty jako aktivní a jejich verzi relace na 0.
Starší relace s platným otiskem hesla se proto zachovají. Každá následná
deaktivace účtu nebo správcovský reset hesla zvýší verzi relace a zneplatní
všechny starší relace, i pokud je účet znovu aktivován před jejich použitím.

## Scénář

U importu otevřete `?` a použijte ukázku nebo zkopírujte pokyny. Vlastní
soubor uložte jako UTF-8 JSON. Verze 2 obsahuje souvislé číslování všech
nahrávacích jednotek od jedné napříč kapitolami.

```json
{
  "version": 2,
  "title": "O pejskovi",
  "chapters": [
    {
      "title": "Začátek",
      "lines": [
        {
          "number": 1,
          "character": "Vypravěč",
          "text": "Pejsek vyšel do zahrady. V trávě ležela červená čepice."
        },
        {"number": 2, "character": "Pejsek", "text": "Kdo ji tu asi nechal?"}
      ]
    }
  ]
}
```

Objekt v `lines` označuje jednu nahrávací jednotku. Text může být věta nebo
celý odstavec pro jednoho čtenáře. Kapitoly i repliky uvádějte v pořadí
poslechu. Hodnotu `number` zapisujte jako celé číslo od jedné, například
`1`. Čísla se zvyšují i při přechodu do další kapitoly. Aplikace u formátu
verze 1 přiřadí stejná čísla podle pořadí v polích.

Vyžaduje se nejméně jedna kapitola a jedna replika v každé kapitole.
Scénář má limit 200 kapitol a celkem 9 999 replik. Titul knihy a kapitoly
může mít 200 znaků, postava 100 znaků a replika 10 000 znaků.

## Záznamy a poslech

Správce vytváří počáteční administrátorský účet pouze při instalaci. Další
účty vznikají přijetím e-mailové pozvánky. Administrátor v nabídce **Uživatelé**
vidí účty podle e-mailu, aktivní stav a obsazení po knihách. Může změnit
přiřazené postavy, deaktivovat nebo znovu aktivovat účet, resetovat heslo a
odstranit účet. Deaktivace i správcovský reset hesla zvyšují verzi relace;
všechny dřívější relace tak zůstanou neplatné i po opětovné aktivaci účtu.
Administrátor nemůže deaktivovat ani odstranit svůj vlastní účet.

Správce odešle e-mailovou pozvánku z knihy a může předem vybrat několik
postav. Jednorázový odkaz platí 24 hodin a e-mail uvádí čas konce platnosti
v českém čase. Odkaz používá veřejný protokol, doménu, port a cestu, na níž
správce pozvánku odeslal. Otevření odkazu zobrazí náhled. Nový příjemce
vyplní jméno a heslo, e-mail je předvyplněný. Dabing ho po potvrzení přihlásí.
Stávajícího uživatele vyberte v poli **Účet příjemce** a zvolte postavy.
Nové role se k jeho obsazení rovnou přidají a Dabing pošle informační e-mail
s odkazem na knihu. Příjemce již odeslané pozvánky ji může potvrdit po
přihlášení stejným e-mailem.

Na nevyřízené pozvánce lze použít **Zrušit** nebo **Poslat znovu**. Opětovné
odeslání nahradí původní odkaz a spustí novou 24hodinovou lhůtu. Chybu odeslání
zobrazí panel pozvánek, odkud lze doručení zopakovat.

Každou postavu může nahrávat více lidí a jeden člověk může mít několik postav.
Předem vybrané role se přidělí při přijetí pozvánky. Správce může obsazení
měnit kliknutím na buňku v matici **Obsazení** nebo ve správě uživatelů.
Kniha se spolupracovníkovi zobrazí po přiřazení první role. Má přístup k celému
scénáři i všem pokusům. Nahrávat může vlastní role a filtr **Moje repliky** mu
ukáže jejich text. Po odebrání poslední role ztratí přístup od dalšího požadavku.
Správce může otevřít každou knihu a nahrávat všechny repliky.

Odstranění účtu smaže jeho přiřazení postav. Záznamy a knihy zůstanou zachované;
jejich odkazy na uživatele se nastaví na prázdnou hodnotu. Dabing nemaže ani
nepřesouvá soubory nahrávek a nemění vybraný záběr.

Každá replika má vlastní číslování pokusů sdílené
všemi mluvčími. Replika má nejvýše 999 pokusů. U názvu knihy *O pejskovi* mají například první dva pokusy
pro první repliku názvy `o_pejskovi_0001_001.mp3` a
`o_pejskovi_0001_002.mp3`. Jednotlivé názvy jsou uvedené u nahrávek a
použijí se také při stažení do počítače.

Správce může odstranit libovolný pokus. Spolupracovník s přístupem ke knize
může odstranit svůj vlastní pokus. Při odstranění
vybraného pokusu se vybere nejnižší zbývající číslo. Číslování již uložených
pokusů zůstává. Nový pokus dostane číslo za nejvyšším dosavadním.

Při mazání se soubor nejprve přesune do neveřejného adresáře
`storage/recordings/pending`. Pokud databázová transakce skončí chybou, soubor
tam zůstane a přihlášeným členům je dál dostupný k poslechu i opakovanému
mazání. Po potvrzeném smazání aplikace soubor odstraní. Když se odstranění
nezdaří, soubor zůstane v tomto adresáři a omezená údržba ho zkusí znovu při
některém dalším úspěšném mazání. Údržba smaže jen soubor, na který už databáze
neodkazuje.

První záběr se použije automaticky a správce může vybrat jiný. Poslech
přehraje vybrané repliky ve scénářovém pořadí a přeskočí prázdná místa.
Při nahrávání bliká REC a ukazatel reaguje na úroveň mikrofonu. Po stisknutí
Stop se záznam v prohlížeči rovnou převede na MP3 a uloží. Stejně se zpracuje
vybraný zvukový soubor. Po chybě zůstane zvuk v otevřené kartě pro další
pokus o uložení. Pokud server při prvním požadavku uložil záběr, opakování
vrátí tentýž záběr.

Záznam nebo převod pro jeden pokus má limit tří minut. Zdroj a licence jsou
v dokumentaci enkodéru. [lamejs](docs/third-party/lamejs-notice.md)

Heslo se mění v nastavení účtu po zadání současného hesla. Po úspěšné
změně zůstane aktuální relace přihlášená. Ostatní relace stejného účtu
budou vyžadovat nové přihlášení.

## Export ZIP

Ovládací prvek ZIP v horní liště knihy stáhne všechny její uložené pokusy
v pořadí replik a pokusů. Soubory jsou přímo v kořeni archivu a zachovají si
názvy jednotlivých stažení, například `o_pejskovi_0001_001.mp3`.
Správce stáhne například archiv `o_pejskovi_nahravky.zip`.

Export vyžaduje rozšíření PHP `zip`. Server připraví archiv v neveřejném
úložišti a po odeslání jej odstraní. Při přípravě potřebuje volné místo
pro velikost archivu.

## Pošta pro pozvánky

Zkopírujte `config/mail.example.php` do neveřejného `storage/mail.php`.
Nastavte `from` na adresu odesílatele na vaší doméně. Dabing sestaví odkaz
z ověřené veřejné adresy aktuálního požadavku. Interní cesta přepsaná Apachem
nemění podadresář v odkazu. Na WEDOSu používá PHP `mail()` s odesílatelem pro
návratovou cestu. [Nastavení pošty WEDOS](https://kb.vedos.cz/maily-blokovani/)

V Laragonu lze použít Mailpit. Volitelné klíče `smtp_host` a `smtp_port`
nastavují přímé SMTP v PHP na Windows. Je-li nastavený `sendmail_path`, Dabing
použije nakonfigurovaný příkaz a kontroluje jeho návratový kód. Výchozí místní
Mailpit přijímá poštu na portu 1025 a zobrazuje ji na portu 8025.

## Aktualizace

Hlavní správce otevře ikonu **Aktualizace** v horní liště. Stránka zobrazí nainstalovanou
verzi a případné novější stabilní vydání z GitHubu. Po potvrzení stáhne ZIP,
ověří jeho SHA-256, provede čekající DB migrace a nasadí aplikační soubory.
Provozní instalace ukládá konfiguraci a nahrávky v chráněném adresáři
`storage`. Pro updater potřebuje PHP rozšíření `curl` a `zip` a právo
zápisu do adresáře aplikace.

Kořenový `index.php` zůstává stabilním spouštěčem, proměnlivý dispatch je v
`app/Bootstrap.php`. Pokud vydání změní i `index.php` a Windows Apache jej
právě používá, updater změnu odmítne a vrátí ostatní soubory. Proveďte změnu
ručně v režimu údržby: zastavte Apache, nahraďte `index.php` verzí
`dabing/index.php` z ověřeného ZIP balíčku, Apache znovu spusťte a aktualizaci
opakujte.

Pokud běžný DB účet nemůže měnit schéma, zadejte databázový účet s právy
pro danou migraci v části **Databázová oprávnění**. Aplikace jej použije jen
v tomto požadavku a připojí se ke stejnému serveru a databázi.

### Balík vydání

Z ověřené revize sestavte balík mimo pracovní kopii.

```sh
php scripts/build-update.php 0.2.0 ../dabing-0.2.0.zip
```

Balík `dabing-0.2.0.zip` patří jako asset ke stabilnímu vydání s přesným tagem
`0.2.0` v `klusik/dabing`. Updater přijímá také značku `v` před verzí.
Příkazy po schválení vydání:

```sh
git tag 0.2.0
git push origin refs/tags/0.2.0
```
Verze balíku musí odpovídat tagu a GitHub musí k assetu uvádět kontrolní součet
SHA-256. Archiv má jediný kořen
`dabing/`.
Na WEDOSu nasazujte obsah složky podle umístění uvedeného výše.

Migrace jsou soubory `database/migrations/0001_nazev.php`, které vracejí
seznam SQL příkazů. Probíhají v pořadí názvů souborů. Dokončenou migraci
identifikuje název a SHA-256 v tabulce `dabing_schema_migrations`.
Další změnu zapište do nového souboru. SQL musí být opakovatelné a
kompatibilní s dosavadními soubory, protože DDL může potvrdit změny před
dokončením aktualizace.

Pokud se nasazení nepodaří dokončit ani vrátit, Dabing zůstane v údržbě.
`storage/update-recovery.json` obsahuje inventář původních a nových souborů
a klíč pracovního adresáře pod `storage/update-work/`. Obnovte původní
soubory z jeho `backup/`, odstraňte soubory vzniklé jen přerušenou
aktualizací a pak odeberte značku údržby. Aplikované DB migrace eviduje
databáze.

## Vývoj

`main` je stabilní větev a výchozí větev na GitHubu. Z ověřeného
`origin/develop` zakládejte `feature/*`, komitujte po ucelených změnách a
pushujte přesnou pracovní větev. Po kvalifikaci a schválení se změna integruje
do `develop` squashem. Návazná stabilní verze je samostatný krok.

Pravidla pro kód a ověření jsou v [AGENTS.md](AGENTS.md). Pro základní kontroly
v GitHub Actions slouží workflow Basic, jehož příkazy spravuje
`scripts/audit.php`.
