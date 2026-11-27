# Pravidla repozitáře Dabing

## Git a ověření

- `main` je stabilní větev, `develop` je integrační větev. Novou práci zakládej
  z ověřeného `origin/develop` na větvi `feature/*` bez převzetí upstreamu.
- Komituj po ucelených změnách. Pushuj přesnou referenci pracovní větve.
  Po kontrole se pracovní větev integruje do `develop` přes squash. Integrace
  a změny `main` vyžadují samostatné zadání. Nepřepisuj cizí commity ani ochrany.
- Všechny příkazy Git spouštěj mimo sandbox. Před zápisem a pushem ověř složku,
  větev, HEAD, vzdálený repozitář a příslušné reference.
- Kvalifikuj přesný commit v hostované CI. Když CI neexistuje nebo nebyla
  dostupná, uveď chybějící pokrytí. Interaktivní a environmentální ověření řeš
  ve vhodném lokálním prostředí. Při předání zkontroluj diff, metadata, stav
  větví a odděleně popiš ruční ověření.

## Kód a trvalá dokumentace

- PHP 8.1+, čtyři mezery, `declare(strict_types=1);` v každém PHP souboru.
  Framework-free JavaScript a CSS, bez potřeby Composeru nebo Node buildu.
- Dodržuj MVC. Controller obsluhuje HTTP, autorizaci, CSRF a připravuje view
  model. Service vlastní doménovou politiku, orchestrace a práci se soubory.
  Model vlastní PDO, SQL a mapování řádků. View vlastní prezentaci.
- Nové zdrojové soubory potřebují komentář s poli Project, Repository, File,
  Module Type, Purpose, Responsibilities, Author (Rudolf Klusal) a License.
  Cesta File je cesta v repozitáři.
- Každá třída i pojmenovaná funkce nebo metoda potřebuje popis účelu. PHPDoc
  a JSDoc popisují všechny parametry, konkrétní tvary kolekcí a všechny
  návratové varianty včetně void. Nativní typy PHP jsou povinné tam, kde je
  jazyk umí vyjádřit. Parametry PHP callbacků mají také nativní typy.
- Pokud projekt někdy použije Python, zakázané jsou `from __future__ import
  annotations` a importy uvnitř funkcí a metod. Třídy, metody a funkce mají
  typované docstringy ve stylu Google.
- Číselnou politiku definuj pojmenovanou konstantou nebo konfigurační hodnotou.
  Připoj Type, Units, Scope, Consumers, Rationale, hodnotu a její důvod.
- Zachovávej komentáře a důvody fallbacků. Dokumentuj chování, formáty,
  migrace a provozní dopad spolu se změnou. Vyhýbej se oslabení ověření.
- Preferuj čitelné přirozené zalomení okolo 80 znaků. Jednotlivé součásti
  rozděl podle skutečných odpovědností. Hlavičky a doky neslouží místo typů.
- Drž instalaci nezávislou na interním umístění webového adresáře. Zabraň
  přímému přístupu k údaji pro instalaci, konfiguraci, originálům nahrávek
  a Git metadatům. Přístup k projektu ověřuj u každé akce a přehrávání.
- Rozhraní navrhuj vždy velmi kompaktně. V přehledech používej husté tabulky
  nebo řádky s jedním záznamem na řádek. Běžné údaje, přehrávače a akce drž
  vedle sebe, kde se vejdou. Používej malé zavedené ikony s přístupným
  názvem a tooltipem. Formuláře otevírej u jejich ovladače.
- Odstraň nadbytečné svislé mezery, výplňové popisky, dekorativní mezititulky
  a velké karty pro jednotlivé záznamy. Na malé šířce zalamuj společné
  prvky a zachovej čitelnost i pohodlné cíle pro dotyk. Při kontrole
  desktopu a mobilu ověř hustotu řádků a viditelnost otevřených panelů.
- Ovládací prvky používají vlastní Theme tokeny, utlumené průsvitné plochy,
  jasnou hierarchii akcí, čitelný focus a pohodlné mobilní cíle. Kontextový
  panel kotvi k jeho ovladači a umožni zavření přes Escape i kliknutí mimo.
  Každou změnu UI ověř na desktopu i v úzkém viewportu.
