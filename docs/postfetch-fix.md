# Oprava postFetch (doPostFetch)

Detail k tomu, proč byl `postFetch` od verze **v3.2** nefunkční a co bylo potřeba opravit.
Souhrn všech oprav z tohoto kola je v [`fixes.md`](fixes.md).

## TL;DR

`QueryObject::doPostFetch()` od v3.2 vždycky skončil na prvním `return` a nikdy nic nepředfetchoval.
Bylo to **tiché** — nešlo o špatné výsledky, jen o víc dotazů, takže se to neprojevilo jako bug.
Odemčení kódu pak odhalilo další tři chyby, které se nikdy nemohly projevit.

## Proč to bylo rozbité

Commit `a337461` (27. 9. 2025, „Adds base entity with identifier trait") přejmenoval rozhraní:

```
src/IEntity.php  ->  src/Entities/Entity.php
interface IEntity -> interface Entity
```

Ten commit sáhl na tři soubory a `QueryObject.php` mezi nimi nebyl. Zůstala v něm tedy viset
reference na starý název — v `use` na řádku 5 a hlavně ve výkonném kódu:

```php
if (!is_object($firstRootEntity) || !($firstRootEntity instanceof IEntity)) {
    return;
}
```

Klíčové je, že **`instanceof` na nedeklarovanou třídu vrátí `false` a nic nenahlásí** — ani nespustí
autoloader, ani nezapíše nic do `error_get_last()`. Proto se to neprojevilo ani při `error_reporting=-1`.
Od té doby každé volání `doPostFetch()` skončilo o dva řádky dál.

### Kdy se to dostalo do release

| verze | `src/IEntity.php` | stav |
|---|---|---|
| do v3.1 | existuje | postFetch funguje |
| **v3.2 a novější** | neexistuje | **postFetch nedělá nic** |

## Proč si to nikdo nevšiml

Dva důvody a oba jsou podstatné:

1. **Není to chyba správnosti, ale výkonu.** Když prefetch neproběhne, Doctrine kolekce dolazy
   normálně lazy-loadem. Výsledky jsou identické, jen se udělá víc dotazů. Nikdo tedy nemá důvod
   podat bug report.

2. **Nikdo tu metodu nevolá.** Kontrola všech lokálních projektů:

   | projekt | verze packagu | `addPostFetch` v kódu projektu |
   |---|---|---|
   | agelplus | v3.2.6 | 0 |
   | paydroid-web | v3.3.2 | 0 |
   | prevozpenez | v3.3.3 | 0 |
   | sandbox_web | v3.3.3 | 0 |
   | sobitecr | v3.3.2 | 0 |
   | sobit-pokladna-api | v3.3.4 | 0 |
   | tms-new | v3.3.2 | 0 |

   Ani žádný jiný `adt/*` package ho nevolá. Projekty přitom `QueryObject` aktivně dědí, jen
   používají jiné části.

## Co bylo opravené

Všechno v `src/QueryObject/QueryObject.php`. Pořadí není náhodné — každá další chyba se objevila až
po opravě té předchozí, protože kód za `return`em byl kompletně nedosažitelný a nikdy se nespustil.

### 1. Špatná reference na rozhraní

```diff
-use ADT\DoctrineComponents\IEntity;
+use ADT\DoctrineComponents\Entities\Entity;

-if (!is_object($firstRootEntity) || !($firstRootEntity instanceof IEntity)) {
+if (!is_object($firstRootEntity) || !($firstRootEntity instanceof Entity)) {
```

Plus tři docbloky. Tohle je ta vlastní příčina.

### 2. `ClassMetadataInfo` v ORM 3 neexistuje (7 výskytů)

Po opravě bodu 1 kód poprvé došel dál a spadl:

```
Fatal error: Class "Doctrine\ORM\Mapping\ClassMetadataInfo" not found
```

`ClassMetadataInfo` byla v ORM 3 odstraněná. Konstanty `TO_ONE`, `TO_MANY`, `ONE_TO_MANY`
a `MANY_TO_MANY` jsou dostupné na `ClassMetadata`, a to i v ORM 2 (dědí je), takže náhrada je
kompatibilní s oběma:

```diff
-Doctrine\ORM\Mapping\ClassMetadataInfo::TO_ONE
+Doctrine\ORM\Mapping\ClassMetadata::TO_ONE
```

### 3. `mappedBy` na owning side MANY_TO_MANY v ORM 3 hodí výjimku

```
OutOfRangeException: Unknown property "mappedBy" on class ManyToManyOwningSideMapping
```

V ORM 2 bylo `$association` obyčejné pole, takže `$association['mappedBy']` vrátilo na owning side
`null` a `?:` propadlo na `inversedBy`. V ORM 3 je to objekt `AssociationMapping` s `ArrayAccess`,
který na nedeklarovanou property **hodí výjimku** místo `null`:

```diff
-$propertyName = $association['mappedBy'] ?: $association['inversedBy'];
+$propertyName = ($association['mappedBy'] ?? null) ?: ($association['inversedBy'] ?? null);
```

Operátor `??` volá nejdřív `offsetExists()`, takže k výjimce nedojde. Na poli (ORM 2) funguje stejně.
Tohle je zrádné, protože se to projeví jen u MANY_TO_MANY z owning side — ONE_TO_MANY prošlo.

### 4. `PARTIAL` byl v ORM 3.0 odstraněný

```
[Syntax Error] line 0, col 16: Error: Expected T_FROM, got '.'
```

Řádek se selectem IDček TO_ONE asociací používal `PARTIAL`, který ORM 3.0 vůbec nezná (parser ho
nemá). Alias `e_id` se přitom nikde nečte — dál se pracuje jen s `$row['id_' . $i]` z `addSelect`.
Stačí tedy obyčejný select:

```diff
-->select('PARTIAL e.{id} AS e_id')
+->select('e.id')
```

Tohle je zároveň jediná změna, která by teoreticky mohla ovlivnit tvar výsledku, proto:
`getScalarResult()` vrací pro `e.id` klíč `id`, který se nepoužívá, a `IDENTITY(...) AS id_N`
aliasy zůstávají nedotčené.

### 5. `setAccessible()` je od PHP 8.5 deprecated (3 výskyty)

`ReflectionProperty::setAccessible()` nemá od PHP 8.1 žádný efekt a v 8.5 je deprecated. V `doPostFetch`
to dosud nevadilo (kód byl mrtvý), po opravě by to začalo hlásit deprecation. Package vyžaduje
`php: >=8.4`, takže odstranění je bezpečné bez podmínek.

### 6. `fetchOne()` volal postFetch dvakrát

Souvisejicí, ale nezávislá chyba. `fetchOne()` volá `fetch()`, které postFetch spustí samo, a pak ho
volal ještě jednou:

```diff
 if ($strict && count($result) > 1) {
     throw new NonUniqueResultException();
 }
-
-$this->postFetch(new ArrayIterator($result));

 return $result[0];
```

Změřeno přepsáním `doPostFetch()` v potomkovi (není `final`, `postFetch()` ho volá přes `static::`):

| | před opravou | po opravě |
|---|---|---|
| `fetch()` | 1× | 1× |
| `fetchOne()` | 2× | 1× |
| `fetchOneOrNull()` | 2× | 1× |

Dokud byl postFetch mrtvý, nic to nestálo. Bez téhle opravy by ale po opravě bodů 1–4 každé
`fetchOne()` prohnalo prefetch dvakrát, tedy dvojnásobek dotazů — projevilo by se to jako
výkonnostní regrese až po nasazení.

## Že to funguje

Prefetch teď reálně šetří dotazy (měřeno přes `SqlLogger`, 5 autorů ve fixtures):

| scénář | bez postFetch | s postFetch |
|---|---|---|
| iterace přes `$author->getBooks()` (ONE_TO_MANY) | 6 dotazů | **2** |
| iterace přes `$author->getPublisher()` (MANY_TO_ONE) | 4 dotazy | **3** |

Kolekce jsou po `fetch()` označené jako inicializované a obsahují správné entity (kontrolováno
i obsahově, ne jen počtem), včetně MANY_TO_MANY, kde `doPostFetch` dělá extra mapping dotaz.
`addPostFetch('neexistujiciPole')` teď správně hodí výjimku, která byla dosud nedosažitelná.

Pokryto v `tests/QueryObject/PostFetchTest.php` (23 testů). Coverage `QueryObject` vyskočil
z 75 % na 98 % řádků, protože 101 dosud mrtvých řádků teď testy skutečně prochází.

## Riziko pro existující projekty

**Pro projekt, který `addPostFetch()` nevolá, je chování bit za bit stejné.** Není to domněnka,
plyne to z toho, že veškerý změněný kód leží za touhle branou:

```php
final public function postFetch(Iterator $iterator): void
{
    if (empty($this->postFetch)) {
        return;      // <- bez addPostFetch() se dál nikdy nedostane
    }
    ...
}
```

Všech 5 změn v `doPostFetch()` je za tímto `return`em. Šestá změna (odstranění dvojího volání)
jen ruší volání, které by stejně na tomhle `return`u skončilo.

Pro projekt, který `addPostFetch()` **volá**, se změní tohle: začne se prefetchovat (méně dotazů)
a neplatné jméno pole začne hlásit výjimku místo tichého ignorování.

### Ověřeno

- **Vlastní sada:** zeleně na PHP 8.4 i 8.5, na SQLite i MySQL 8.0, s nejnovějšími i s nejnižšími
  povolenými závislostmi (ORM 3.3.0 / DBAL 4.2.1). Kombinace s nejnižšími je důležitá, protože
  právě na ní se projevil bod 4.
- **Reálný projekt (prevozpenez):** opravený `src/` nasazený do `vendor/` projektu, pak
  - PHPStan (level 3, `app` + `tests`): **19 chyb před i po záměně, diff prázdný** — všechny
    pre-existující a nesouvisející,
  - Codeception Unit suite: **OK (309 testů, 1535 asercí)**.

  Vendor projektu byl potom vrácen do původního stavu (ověřeno `diff -r`).
