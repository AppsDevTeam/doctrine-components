# Opravy nalezené při psaní testovací sady

Přehled všeho, co se opravovalo, proč, a co se naopak vědomě nechalo. Detail k `postFetch` je
zvlášť v [`postfetch-fix.md`](postfetch-fix.md).

Všechno našly testy v `tests/`. Každá oprava je ověřená na PHP 8.4 i 8.5, na SQLite i MySQL 8.0,
s nejnovějšími i nejnižšími povolenými závislostmi, a navíc nasazením do reálného projektu.

## Souhrn

| # | co | soubor | druh změny |
|---|---|---|---|
| 1 | `postFetch` byl od v3.2 mrtvý kód | `QueryObject.php` | oprava chyby |
| 2 | `fetchOne()` volal postFetch dvakrát | `QueryObject.php` | oprava chyby |
| 3 | `null` jako klíč pole v `byId()` a `fetchField()` | `QueryObject.php` | PHP 8.5 deprecation |
| 4 | `setAccessible()` v `mapToDTO()` | `QueryObject.php` | PHP 8.5 deprecation |
| 5 | `validateFieldNames()` v `by()` nekontroloval nic | `QueryObject.php` | oprava chyby, **mění chování** |
| 6 | `orById()` se zahodil při kombinaci s `byId()` | `QueryObject.php` | oprava chyby, **mění chování** |
| 7 | vlastní `entityAlias` rozbil `byId()`, `orById()`, `fetchField()` | `QueryObject.php` | oprava chyby |
| 8 | `fetchPairs($value, null)` hodil nejasný `Error` | `QueryObject.php` | jasná chybová hláška |
| 9 | `byIsActive()` a `disableIsActiveFilter()` spolu nefungovaly | `IsActiveFilterTrait.php` + `by()` | oprava chyby, **rozšiřuje API** |
| 10 | `require` povoloval DBAL 3, se kterým `src/Logging/*` nejde načíst | `composer.json` | **zužuje podporu** |

Tři změny mění chování a jedna zužuje podporované verze. Detaily a odůvodnění níž.

---

## 1 a 2. postFetch

Popsané zvlášť v [`postfetch-fix.md`](postfetch-fix.md), včetně toho, proč si toho roky nikdo nevšiml
(nikdo z projektů `addPostFetch()` nevolá a symptomem není špatný výsledek, jen víc dotazů).

Ve zkratce: přejmenování `IEntity` → `Entities\Entity` v commitu `a337461` neaktualizovalo referenci
v `QueryObject.php`, `instanceof` na nedeklarovanou třídu vrací `false` bez chyby, takže
`doPostFetch()` vždycky skončil na prvním `return`. Za tím se skrývaly další tři chyby
(`ClassMetadataInfo`, `mappedBy` přes `ArrayAccess`, `PARTIAL`), které se projevily až po odemčení kódu.

## 3. `null` jako klíč pole

Na PHP 8.5 je `$array[null]` deprecated. Objevovalo se to na dvou místech: `byId()` s entitou bez ID
nebo s `null` v poli, a `fetchField()` nad nullable sloupcem.

`byId()` je teď přepsaný tak, aby `null` přeskočil, ale **filtr se pořád aplikuje**. To je podstatné:

```php
$this->byIdFilter ??= [];          // filtr je "zapnutý" i když je pole prázdné
if (($_id = $this->resolveId($item)) !== null) {
    $this->byIdFilter[$_id] = $_id;
}
```

`byIdFilter` je `?array` a `null` znamená „filtr se neaplikuje", `[]` znamená „aplikuje se
a nic nematchne" (`id IN (NULL)`). Kdyby se `null` jen přeskočilo bez toho `??= []`, tak by
`byId(new Author())` (entita bez ID) přestala filtrovat a vrátila **všechno** místo ničeho.
To by byla bezpečnostní regrese, takže na to je test.

Efektivní SQL výsledek je proti stavu před opravou identický, protože `NULL` v `IN (...)` nikdy
nematchne. Změnil se jen obsah parametru: `byId([1, null])` posílá `[1]` místo `[1, null]`.

`byId()` navíc už nepoužívá `count($id)` na iterable, takže funguje i s generátorem (dřív by
`count()` na `Generator` hodilo `TypeError`).

`fetchField()` má jen doplněný `?? ''`, takže klíč pro `NULL` hodnotu je `''` stejně jako dřív,
jen bez deprecation.

## 4. `setAccessible()`

Nemá od PHP 8.1 žádný efekt a v 8.5 je deprecated. Package vyžaduje `php: >=8.4`, takže odstranění
je bezpečné bez jakékoli podmínky. Odstraněné ze `mapToDTO()` a ze `doPostFetch()`.

## 5. `validateFieldNames()` nekontroloval nic

```php
// před
foreach ($fields as $_name => $_order) {
    if (explode('.', $_name)[0] === $this->entityAlias) { throw ... }
}
```

Iterovalo se přes **klíče**. U `orderBy(['name' => 'ASC'])` jsou klíče jména sloupců, takže tam to
fungovalo. U `by(['name', 'email'])` jsou to ale číselné indexy, takže kontrola nikdy nic nenašla.

`by('e.name', 'x')` proto neskončil hláškou „Do not use entity alias in field names", ale vygeneroval
nesmyslné `LEFT JOIN e.e e` a spadl až na `QueryException` z Doctriny.

Teď se iteruje přes hodnoty a `orderBy()` posílá `array_keys($field)`.

**Mění chování:** `by('e.cokoli')` teď hodí `Exception` s jasnou hláškou místo `QueryException`.
Zkontroloval jsem všech 7 lokálních projektů, `by()` s prefixem entity aliasu nikdo nepoužívá.

## 6. `orById()` se zahodil při kombinaci s `byId()`

Ty dva bloky byly ve špatném pořadí:

```php
// před: orById se testoval na existenci WHERE dřív, než ho byId přidalo
if ($this->orByIdFilter && $qb->getDQLPart('where')) { $qb->orWhere(...); }
if ($this->byIdFilter !== null) { $qb->andWhere(...); }
```

`byId(1)->orById(3)` tedy vrátilo jen záznam 1. Po prohození vrací 1 i 3.

Podmínka `$qb->getDQLPart('where')` **zůstává**, takže `orById(3)` jako jediný filtr je pořád no-op.
To je záměr a je to logicky správně: dotaz bez podmínek už vrací všechno, takže „všechno NEBO id 3"
je zase všechno. Je na to test, aby to někdo omylem „neopravil".

## 7. Vlastní `entityAlias`

`byId()`, `orById()` a `fetchField()` měly alias `'e'` napevno, takže query object s přepsaným
`$entityAlias` generoval nevalidní DQL (`SELECT a FROM Author a WHERE e.id IN (...)`). Nahrazeno
za `$this->entityAlias`.

`doPostFetch()` si staví vlastní query buildery a `'e'` v nich je jeho vlastní alias, ten zůstává.

## 8. `fetchPairs($value, null)`

Hodilo to `Error: Call to undefined method ...::get()`, protože se skládal getter z prázdného jména.
Teď je na začátku jasná kontrola:

```php
if ($key === null) {
    throw new Exception('Parameter "$key" is required, there is nothing to key the result by.');
}
```

Signatura `?string $key` zůstává, protože ji předepisuje `QueryObjectInterface`. Kdybyste chtěli,
aby `null` znamenalo „vrať seznam bez klíčů" (jako Nette Database), je to funkční rozhodnutí, ne
oprava chyby, takže jsem to nedělal.

## 9. `byIsActive()` a `disableIsActiveFilter()`

`byIsActive()` používal `by()`, které filtr registruje pod **číselný** klíč, zatímco
`disableIsActiveFilter()` mazal klíč `'isActiveFilter'`. Nikdy se tedy netrefily.

`by()` má proto nově čtvrtý nepovinný parametr:

```php
public function by(array|string $column, mixed $value = null, QueryObjectByMode $mode = QueryObjectByMode::AUTO, ?string $filterKey = null): static
```

S `$filterKey` se filtr registruje pod tímto klíčem a jde ho vypnout přes `disableFilter()`.
Bez něj se chová přesně jako dřív. Trait to používá:

```php
return $this->by('isActive', $isActive, QueryObjectByMode::AUTO, IsActiveFilter::IS_ACTIVE_FILTER);
```

**Rozšiřuje API:** parametr je přidaný i do `QueryObjectInterface`. Přidání nepovinného parametru
do rozhraní je formálně BC break pro cizí implementace toho rozhraní. `QueryObject` je jediná
implementace ve všech projektech, takže reálně to nikoho nezasáhne.

**Vedlejší efekt:** opakované `byIsActive()` už nestohuje podmínky, druhé volání to první přepíše
(protože jde o stejný klíč). To je žádoucí, dřív `byIsActive(false)` po defaultním `byIsActive(true)`
vytvořilo `isActive = true AND isActive = false`, což nikdy nic nevrátilo.

## 10. `require` povoloval DBAL 3

```diff
-"doctrine/orm": "^2.18|^3.0",
+"doctrine/orm": "^3.3",
+"doctrine/dbal": "^4.0",
```

`src/Logging/*` má signatury DBAL 4 (`bindValue(..., ParameterType $type): void`,
`beginTransaction(): void`). ORM 2 i ORM 3.0–3.2 přitom táhnou DBAL 3, a s ním se ty třídy ani
nenačtou:

```
Fatal error: Declaration of ADT\DoctrineComponents\Logging\Statement::bindValue(...)
must be compatible with AbstractStatementMiddleware::bindValue($param, $value, $type = ...)
```

Projevilo by se to při prvním zapnutém Tracy panelu. `^3.3` je ověřeně nejnižší ORM, které DBAL 4
připouští (`--prefer-lowest` s tímto `require` vyřeší ORM 3.3.0 + DBAL 4.2.1).

**Zužuje podporu:** ORM 2 už není podporované. Chce to bump minor verze a poznámku do changelogu.
Všech 7 lokálních projektů už na ORM 3.6 a DBAL 4 běží, takže je to nezasáhne.

---

## Co se vědomě NEopravilo

### Deduplikace joinů podle aliasu

Původně jsem to měl za chybu: `getJoinFilterKey()` ignoroval všechny parametry kromě aliasu, takže
druhý join se stejným aliasem se tiše zahodil. Napsal jsem opravu, která na konflikt hodí výjimku.

**Pak jsem to vrátil**, protože jsem si to ověřil na `sobit-pokladna-api` a je to load-bearing:

```php
class ReportSettlementOrganizerGridQuery extends OrderItemQuery
{
    public function getEntityClass(): string { $this->entityAlias = 'e'; return Account::class; }

    public function init(): void
    {
        $this->filter[] = function (QueryBuilder $qb): void {
            $this->leftJoin($qb, 'e.orders', '_order');   // <- zaregistruje alias PRVNÍ
            ...
        };
        parent::init();
    }
}
```

Rodič `OrderItemQuery` má šest `by*()` metod, každá dělá `innerJoin($qb, 'e.order', '_order')`
a pak se odkazuje na `_order.branch`, `_order.date` a podobně. Ty joiny se dnes díky deduplikaci
podle aliasu **tiše přeskočí** a `_order` zůstane ukazovat na `Account.orders`. Potomek tím vědomě
přesměrovává všechny dědené joiny a podmínky na jinou relaci.

Výjimka na konfliktu by tyhle grid dotazy položila. Chování je tedy schválně zachované a v kódu
je u `commonJoin()` komentář, aby to někdo „neopravil" znovu. Testy v `JoinTest` ten override
mechanismus popisují, aby byl vidět jako záměr, a je zdokumentovaný i v README.

Statická kontrola všech 7 projektů (skript hledá alias použitý pro dvě různé relace v jedné třídě
včetně rodičů) našla tenhle vzor ve 4 třídách `sobit-pokladna-api`; v ostatních projektech jsou
duplicitní aliasy vždy v různých třídách, kde se nepotkají.

### Statický stav v `BaseListener`

`private static int $transactionsStartedCount` a `private static bool $possibleChangesChecked` jsou
sdílené mezi všemi instancemi listenerů. Vypadá to jako smell, ale je to konzistentní se svým účelem:
transakce je jedna na spojení a flush cyklus je jeden, takže několik listenerů se má koordinovat.
Předělání na instanční stav by tu koordinaci rozbilo. Nechávám a testy současné chování popisují.

### Zakomentované bloky kontrol

V `createQueryBuilder()` (`$forbiddenDQLParts`) a ve `fetch()` (kontrola `hasModifiedColumns`) jsou
zaparkované zakomentované kontroly. Nejsou to chyby a smazat cizí vědomě odložený kód bez zeptání
mi nepřišlo správné.

### `count()` a duplikáty z joinů

`count()` používá `COUNT(e.id)` bez `DISTINCT`, takže join na `*_TO_MANY` nafoukne výsledek.
Není to chyba, `getCountExpr()` je dokumentovaný extension point pro `COUNT(DISTINCT e.id)`.
Testy obojí chování pokrývají.

---

## Ověření

**Vlastní sada:** 452 testů, zeleně ve všech kombinacích:

| | PHP 8.4 | PHP 8.5 |
|---|---|---|
| SQLite, nejnovější závislosti | ✔ | ✔ |
| MySQL 8.0, nejnovější závislosti | ✔ | ✔ |
| SQLite, `--prefer-lowest` (ORM 3.3.0 / DBAL 4.2.1) | ✔ | ✔ |
| MySQL 8.0, `--prefer-lowest` | ✔ | ✔ |

Pokrytí `src`: 98 % řádků, 98 % metod.

CI je nastavená striktně (`failOnRisky`, `failOnWarning`, `failOnNotice`, `failOnDeprecation`)
**bez baseline** — po opravách už ve `src/` žádná deprecation nezbyla. Jediná výjimka je
`ignoreIndirectDeprecations`, která odfiltruje deprecations pocházející z `vendor/`.

**Reálný projekt (prevozpenez, ORM 3.6.7 / DBAL 4.4.3, PHP 8.5):** opravený `src/` nasazený do
`vendor/` projektu, pak

- PHPStan level 3 nad `app` + `tests`: **19 chyb před i po, diff prázdný** (všechny pre-existující)
- Codeception Unit suite: **OK (309 testů, 1535 asercí)**

Vendor projektu potom vrácený do původního stavu, ověřeno `diff -r`.

**Statická kontrola rizikových změn napříč všemi 7 projekty:**

- `by()` s prefixem entity aliasu (bod 5): 0 výskytů
- alias použitý pro dvě různé relace v jedné query třídě: viz sekce o deduplikaci joinů

## Co je pořád otevřené

- Test suite pokrývá jen SQLite a MySQL, ne PostgreSQL ani MariaDB.
- Neběží mutation testing, takže 98 % pokrytí `QueryObject` znamená „řádky se vykonaly", ne že
  jsou asertace úplné.
- Nad samotným packagem neběží statická analýza. Přidání PHPStanu do CI by byl logický další krok.
