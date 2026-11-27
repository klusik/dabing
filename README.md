# Dabing

Dabing je webové studio pro společné nahrávání audioknih. Autor nahraje scénář
ve formátu JSON a pozve další hlasy. Každý člen projektu může uložit svůj
záběr ke každé replice. Autor vybírá záběry pro souvislý poslech ve studiu.

## Instalace

Potřebujete PHP 8.1 nebo novější s rozšířeními PDO MySQL, mbstring a fileinfo,
prázdnou databázi MariaDB nebo MySQL, Apache 2.4 s `mod_rewrite` a povolené
zpracování `.htaccess`. Pro nahrávání mikrofonem otevřete web přes HTTPS nebo
na místním `localhost`. Nahrávání probíhá přes MediaRecorder v prohlížeči.

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

Maximální povolenou velikost uploadu nastavujte v konfiguraci PHP na serveru.
Pro celý limit zvuku nastavte alespoň `upload_max_filesize = 32M` a
`post_max_size = 36M`. Vyžaduje-li hosting nižší hodnotu, uplatní se tento
nižší limit.

Místní instalaci lze omezit na loopback vytvořením prázdného souboru
`storage/local-only`. Při jeho přítomnosti aplikace přijímá požadavky na
přihlášení a projekty pouze ze stejného počítače.

## Scénář a spolupráce

Nahrajte UTF-8 JSON se strukturou verze 1. Například

```json
{
  "version": 1,
  "title": "Cesta domů",
  "chapters": [
    {
      "title": "Příjezd",
      "lines": [
        {"character": "Vypravěč", "text": "Vlak zastavil jako každý čtvrtek."},
        {"character": "Anna", "text": "Jsme doma?"}
      ]
    }
  ]
}
```

Pořadí v polích je pořadí v audioknize. Vyžaduje se nejméně jedna kapitola a
jedna replika v každé kapitole. Scénář má limit 2 MiB, 200 kapitol a celkem
1 000 replik. Titul knihy a kapitoly může mít 200 znaků, postava 100 znaků
a replika 10 000 znaků. Ukázku lze stáhnout i přímo ze studia.

Vlastník si v knize vytvoří jednorázový odkaz pro spolupracovníka. Příjemce se
přihlásí nebo si vytvoří účet a pozvánku potvrdí. První záběr se pro repliku
použije automaticky. Když je záběrů více, vlastník zvolí záběr pro přehrávání.
Poslech přehraje zvolené repliky ve scénářovém pořadí a přeskočí prázdná místa.

Nahraný záběr lze před uložením přehrát. Záznam z mikrofonu má limit tří minut.
Soubor můžete také vybrat ze zařízení. Studio přijímá WebM, Ogg, MP4/M4A a WAV
do 32 MiB na repliku. Přehratelnost konkrétního kodeku závisí na prohlížeči.

## Vývoj

`main` je stabilní větev a výchozí větev na GitHubu. Z ověřeného
`origin/develop` zakládejte `feature/*`, komitujte po ucelených změnách a
pushujte přesnou pracovní větev. Po kvalifikaci a schválení se změna integruje
do `develop` squashem. Návazná stabilní verze je samostatný krok.

Pravidla pro kód a ověření jsou v [AGENTS.md](AGENTS.md). Pro základní kontroly
v GitHub Actions slouží workflow Basic, jehož příkazy spravuje
`scripts/audit.php`.
