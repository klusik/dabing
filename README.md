# Dabing

Dabing je webové studio pro společné nahrávání audioknih. Autor nahraje scénář
ve formátu JSON a pozve spolupracovníky. Každý pokus se uloží zvlášť. Autor volí
záběry pro souvislý poslech a členové si mohou jednotlivé soubory stáhnout.

## Instalace

Potřebujete PHP 8.1 nebo novější s rozšířeními PDO MySQL, mbstring a fileinfo,
prázdnou databázi MariaDB nebo MySQL, Apache 2.4 s `mod_rewrite` a povolené
zpracování `.htaccess`. Pro mikrofon otevřete web přes HTTPS nebo na
`localhost`. Aplikace zachytí zvuk v prohlížeči pomocí MediaRecorder.

1. Zkopírujte zdrojové soubory včetně `.htaccess` do adresáře, který webserver
   obsluhuje pro zvolenou veřejnou adresu. Pro zápis aplikace zpřístupněte
   adresář `storage`. Git metadata ani dřívější provozní soubory nekopírujte.
2. Ověřte, že adresy pod `storage/` a `database/` vracejí 403 nebo 404.
   Vygenerujte instalační klíč příkazem `php scripts/create-install-key.php`.
   Při nasazení přes FTP vytvořte soubor `storage/install.key` s jedním řádkem
   náhodného klíče. Můžete použít 64 náhodných hexadecimálních znaků.
3. Otevřete veřejnou adresu Dabingu. Průvodce požádá o klíč, přístup do
   databáze a údaje správce. Pokud účet webu smí pouze pracovat s daty,
   vyplňte v průvodci ještě účet s právy k vytvoření tabulek.
4. Po dokončení se přihlaste účtem správce. Provozní údaje databáze zůstávají
   v `storage/config.php`. Pro zálohu uchovávejte databázi a neveřejný adresář
   `storage`, zejména `storage/recordings/`.

Na WEDOSu použijte server a název databáze z administrace hostingu. Účet
**web** patří do provozních údajů a účet **admin** poslouží k vytvoření tabulek.
Průvodce ukládá pouze provozní údaje. Odkazy a přesměrování fungují relativně
k veřejné adrese v kořenu domény i pod veřejnou cestou.

- [Dokumentace databází WEDOS](https://kb.vedos.cz/webhosting-databaze/)
- [Dokumentace konfigurace PHP WEDOS](https://kb.vedos.cz/webhosting-php/)

Import scénáře má strop 20 MB (20 000 000 bajtů). Při nahrávání souboru
aplikace zohlední také `upload_max_filesize` a `post_max_size` s rezervou
pro formulář. U výběru souboru se zobrazí výsledný limit pro daný web.

### Aktualizace z první verze studia

Před aktualizací zálohujte databázi a úložiště. Pozastavte nahrávání a z kořene
repozitáře spusťte `php scripts/migrate_recording_attempts.php DDL_USER`.
Heslo databázového účtu s právy ke změně schématu zadejte přes stdin.
Migrace doplní pořadí dřívějších pokusů a zachová jejich formáty i výběr pro
poslech. Potom aplikujte opakovatelná schémata database/studio.sql a
database/email-invites.sql účtem s právy ke změně schématu. První příkaz
můžete bezpečně spustit opakovaně.

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

Vlastník pozve čtenáře e-mailem. Jednorázový kód z pozvánky patří jedné knize
a konkrétní adrese. Při potvrzení si nový uživatel nastaví jméno a heslo.
Uživatel s existujícím účtem se přihlásí a potvrdí pozvánku. Po přijetí může
vlastník v nabídce Obsazení přiřadit čtenáře k postavám. Nepřiřazenou repliku
může nahrát člen knihy. Přiřazenou repliku nahrává vybraný čtenář nebo autor.
Změna obsazení zachová všechny uložené pokusy.

Každá replika má vlastní číslování pokusů sdílené
všemi mluvčími. Replika má nejvýše 999 pokusů. U názvu knihy *O pejskovi* mají například první dva pokusy
pro první repliku názvy `o_pejskovi_0001_001.mp3` a
`o_pejskovi_0001_002.mp3`. Jednotlivé názvy jsou uvedené u nahrávek a
použijí se také při stažení do počítače.

Autor knihy může odstranit libovolný pokus. Spolupracovník může odstranit jen
svůj vlastní pokus, i když už má postavu přiřazenou někdo jiný. Při odstranění
vybraného pokusu se vybere nejnižší zbývající číslo. Číslování již uložených
pokusů zůstává. Nový pokus dostane číslo za nejvyšším dosavadním.

Při mazání se soubor nejprve přesune do neveřejného adresáře
`storage/recordings/pending`. Pokud databázová transakce skončí chybou, soubor
tam zůstane a přihlášeným členům je dál dostupný k poslechu i opakovanému
mazání. Po potvrzeném smazání aplikace soubor odstraní. Když se odstranění
nezdaří, soubor zůstane v tomto adresáři a omezená údržba ho zkusí znovu při
některém dalším úspěšném mazání. Údržba smaže jen soubor, na který už databáze
neodkazuje.

První záběr se použije automaticky a vlastník může vybrat jiný. Poslech
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
Členové knihy dostanou například archiv `o_pejskovi_nahravky.zip`.

Export vyžaduje rozšíření PHP `zip`. Server připraví archiv v neveřejném
úložišti a po odeslání jej odstraní. Při přípravě potřebuje volné místo
pro velikost archivu.

## Pošta pro pozvánky

Zkopírujte `config/mail.example.php` do neveřejného `storage/mail.php`.
Nastavte `base_url` na veřejnou HTTPS adresu instalace a `from` na adresu
odesílatele na vaší doméně. Na WEDOSu využívá aplikace PHP mail() s adresou
odesílatele pro návratovou cestu. [Nastavení pošty WEDOS](https://kb.vedos.cz/maily-blokovani/)

V Laragonu lze použít Mailpit a veřejnou adresu `http://localhost/dabing/`.
Volitelné klíče `smtp_host` a `smtp_port` nastavují přímé SMTP v PHP na
Windows. Je-li nastavený `sendmail_path`, transport vybírá tento příkaz.
Výchozí místní Mailpit přijímá zkušební poštu na portu 1025 a ukazuje ji
v rozhraní na portu 8025.

## Vývoj

`main` je stabilní větev a výchozí větev na GitHubu. Z ověřeného
`origin/develop` zakládejte `feature/*`, komitujte po ucelených změnách a
pushujte přesnou pracovní větev. Po kvalifikaci a schválení se změna integruje
do `develop` squashem. Návazná stabilní verze je samostatný krok.

Pravidla pro kód a ověření jsou v [AGENTS.md](AGENTS.md). Pro základní kontroly
v GitHub Actions slouží workflow Basic, jehož příkazy spravuje
`scripts/audit.php`.
