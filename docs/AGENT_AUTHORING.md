# Povinný autorský kontrakt

Tento dokument rozvádí [pravidla repozitáře](../AGENTS.md). Platí pro aplikaci,
skripty, testy a další udržovaný zdrojový kód Dabingu. Nenahrazuje doménové
pokyny, bezpečnostní a autorizační pravidla, schéma databáze ani smlouvu pro
uživatelské rozhraní.

## Před změnou

Než začneš psát, ověř rozsah požadavku a pracovní větev, vyhledej současného
vlastníka chování a přečti instrukce pro dotčenou oblast. Najdi existující
deklaraci nebo příklad, který odpovídá místním typům a stylu. Zvaž všechny
volající, datové toky, přístupové kontroly, transakce, soubory a vykreslené
pohledy. Nezakládej paralelní vlastníka jen proto, že je lokální úprava
snazší.

U změny schématu přezkoumej migraci, omezení, zpětnou kompatibilitu starých
řádků a pořadí nasazení kódu a databáze. U chráněné akce ověř oprávnění při
každém požadavku a před citlivou mutací znovu podle autoritativního stavu.
U souborového zápisu nebo přehrávání zachovej ověření cesty a oprávnění na
místě, které skutečně provádí operaci. Změna UI patří pod kompaktní pravidla
a povinné desktopové i úzké vizuální ověření z root `AGENTS.md`.

## Vlastnictví odpovědností MVC

Nové nebo významně přeuspořádané doménové chování používá tok
`Controller -> Service -> Model`. Controller připraví data pro View odděleně
od tohoto toku.

- **Controller** vlastní HTTP vstup a odpověď, načtení aktuální identity,
  autorizaci, CSRF a kontrolu vstupů. Připraví konkrétní, typovaný view model.
- **Service** vlastní doménovou politiku, koordinaci operací, transakční
  postup a práci se soubory či externími službami. Rozhoduje na základě
  sémantických vstupů a vrací doménový výsledek nebo známé selhání.
- **Model** vlastní PDO, SQL, vazby parametrů, transakčně relevantní dotazy,
  mapování řádků a perzistenci. Nečte HTTP globály ani nevykresluje HTML.
- **View** vlastní pouze prezentaci připravených dat. Neprovádí SQL, nedohledává
  doménová oprávnění a nevytváří skryté servisní operace.

Předávej významové hodnoty, například ID projektu, identitu aktéra a konkrétní
postavu. Nepředávej fragmenty SQL ani neprováděj SQL v controlleru nebo šabloně.
V Dabingu může například `StudioController` zpracovat požadavek a připravit
data, `StudioService` vynutit pravidla přístupu k projektu a
`StudioModel` načíst nebo uložit řádky. Šablona `project.php` pouze zobrazí
připravená data. Jednoduchý existující kód nemusí být násilně rozdělen do
prázdných vrstev, ale nová významná odpovědnost má správného vlastníka.

Pro souběžné mutace použij transakci a vhodný zámek podle současného schématu.
Ověř pod zámkem podmínky, které se mohou mezitím změnit, jako je přístup,
existence rodičovského záznamu a číslo dalšího pokusu. Opakování požadavku
nesmí vytvořit duplikát ani obejít nově odebrané oprávnění. Změna grantů,
odstranění rodiče, opakování uploadu a současný požadavek se musí chovat podle
jednoznačné databázové autority, ne podle zastaralých dat v HTML.

## Deklarace, typy a dokumentace

Každá nová nebo změněná pojmenovaná funkce či metoda má bezprostředně před
deklarací PHPDoc/JSDoc s účelem, všemi explicitními parametry a skutečnými
variantami návratu. Změna těla může změnit smlouvu stejně jako změna signatury.
Aktualizuj obojí v témže kroku. Popiš také nepovinné, výchozí, nullable a
variadické parametry. Nativní typy jsou samostatný požadavek a dokumentaci
nenahrazují.

Pro kolekci `array` uveď konkrétní tvar položek či polí, nikoli holé „array“.
Pro objekt uveď konkrétní třídu nebo rozhraní. Popiš `void`, `null`, chybu,
false či jiné skutečné návratové větve podle smlouvy volajícího. Každá třída,
včetně anonymní, má účelový popis. Konstruktor a destruktor mají dokumentovaný
výsledek, i když PHP nepovoluje jejich nativní návratový typ.

Anonymní funkce, closure, arrow funkce a callback uložený do proměnné nepotřebují
vlastní docstring. Výjimka se týká pouze dokumentačního bloku: každý explicitní
parametr PHP callbacku stále potřebuje nativní typ. Pojmenované deklarace
vnořené dovnitř callbacku se dokumentují běžně. Nevytvářej pomocnou funkci jen
kvůli dokumentaci a nevkládej PHPDoc do argumentů volání.

Nový zdrojový soubor začíná nativním komentářem. Obsahuje tato pole.

- `Project`, `Repository` a skutečnou cestu `File` v repozitáři.
- `Module Type`, `Purpose` a konkrétní `Responsibilities`.
- `Author: Rudolf Klusal` a `License` podle skutečné licence repozitáře.

Cestu v kopírované hlavičce vždy oprav. Nový PHP soubor dále obsahuje
`declare(strict_types=1);` a používá čtyři mezery.

PHPDoc/JSDoc patří bezprostředně nad pojmenovanou deklaraci. Python používá
docstring jako první příkaz deklarace, s typovanými sekcemi `Args:` a
`Returns:`. Nativní typy Python signatury jsou povinné nezávisle na docstringu.
Nevymýšlej `mixed`, obecný `Object`, prázdný popis ani nesprávné zúžení typu,
jen aby kontrola prošla.

## Číselné politiky a limity

Každou novou či významně změněnou provozní číselnou volbu pojmenuj konstantou
nebo konfigurační hodnotou. Patří sem timeouty, čekání, opakování, limity počtu,
velikosti, hloubky, stránkování a výchozí hodnoty. Připoj účelový komentář
s následujícími šesti položkami.

1. typ (`Type:` nebo `@var`/`@type`),
2. jednotky (`Units:`),
3. rozsah platnosti (`Scope:`),
4. konzumenty (`Consumers:`),
5. odůvodnění (`Rationale:`),
6. skutečnou hodnotu a důvod její volby.

Komentář patří k vlastníkovi politiky. Hlavička souboru jej nenahrazuje.
U strukturálních literálů, jako je index, délka oddělovače nebo verze formátu,
stručně vysvětli jejich lokální význam. Nevydávej je za konfigurovatelnou
politiku. Testy a nástroje dodržují stejné zásady jako runtime.

## Čitelnost

Preferuj přirozené zalomení kolem 80 znaků. Rozděluj dlouhé argumenty,
asociativní struktury a seznamy po jedné položce na řádek. Seskupuj logické
podmínky podle významu a odděluj samostatné kroky. Nad 100 znaků aktivně
zvaž čitelnější rozdělení. Jde o doporučení, ne o důvod měnit chování, typy,
komentáře nebo dlouhé významové řetězce. Zachovej zaměření diffu a ručně
zkontroluj skutečně uložené řádky, ne pouze zalomení editoru.

## Kompatibilita, fallbacky a nasazení

Při změně chování spolu se zdrojovým kódem uprav trvalou dokumentaci formátů,
migrací, provozních předpokladů a dopadu na uložená data. Zachovej existující
komentáře vysvětlující důvody rozhodnutí. Fallback neměň ani neodstraň bez
záznamu jeho chráněného chování, vlastníka, podporovaného prostředí a důkazu,
že je bezpečné jej ukončit. Pokud podpora zůstává potřebná, řekni to výslovně.

Při změně schématu zvaž stávající záznamy, nullable vazby, unikátní klíče,
kaskády a pořadí aplikace migrace vůči nové verzi kódu. Zpětnou kompatibilitu
neodvozuj z čísla verze. U významných regresí a nasazovacích rizik zaznamenej
dostupné důkazy nebo výslovně uveď, že používání či cílové prostředí není
ověřeno. Úspěch syntaktické kontroly sám nepotvrzuje provozní kompatibilitu.

## Python

Pokud se do repozitáře přidá Python, zákaz platí pro celý přijatý zdrojový strom.

- nikdy nepoužívej `from __future__ import annotations`.
- nikdy nevkládej import do funkce, metody, async deklarace ani jejich vnořeného
  bloku. Všechny importy, včetně volitelných, platformních a typovacích, patří
  na úroveň modulu.
- zákaz neobcházej dynamickým importem ani jiným ekvivalentem.
- typuj třídy, metody a funkce a připojuj jejich Google-style docstringy.

Při cyklické závislosti přesuň sdílenou odpovědnost do vhodného nezávislého
modulu místo lokálního importu.
