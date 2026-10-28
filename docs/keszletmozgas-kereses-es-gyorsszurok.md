# Készletmozgások: keresés és gyors dátumszűrők

## Mozgásazonosító keresése

A lista keresője a terméknév, SKU és hivatkozás mellett a megjelenített `MOZ-000046` azonosítót is felismeri. A kis- és nagybetű nem számít, a `MOZ-46` alak is használható. A szám pontos rekordazonosítóként kereshető, így a `MOZ-1` nem adja vissza a 10-es mozgást pusztán annak azonosítója alapján. A túl nagy számokat nem alakítjuk túlcsorduló egész értékké.

A több keresőszó továbbra is ÉS kapcsolatban áll: a `MOZ-000046 csavar` keresésnél mindkét feltételnek teljesülnie kell. A terméknévben, SKU-ban és hivatkozásban lévő `%` és `_` karakterek literálisak. A lista és a PDF ugyanazt a lekérdezést használja.

## Termékjavaslatok a felső keresőben

Gépelés közben a kereső legfeljebb nyolc termékjavaslatot mutat, teljes névvel és külön cikkszámmal. A javaslatok több szóra, kis- és nagybetűtől és ékezetektől függetlenül szűrődnek a névben és SKU-ban. A javaslatok a már betöltött termékkatalógusból származnak, nem csak az aktuális lap mozgásaiból.

Kattintás vagy nyílbillentyűvel kijelölt javaslaton Enter pontos termékazonosító-szűrést alkalmaz, kiüríti a szöveges keresést, és az első oldalra lép. A többi szűrő aktuális értéke, a rendezés és az oldalméret megmarad. Az azonos nevű, eltérő SKU-val rendelkező termékeket így is megkülönbözteti.

Kijelölés nélkül az Enter a szöveges keresést küldi be, akkor is, ha vannak javaslatok. A találat nélküli szöveg, bizonylatszám és MOZ-azonosító is kereshető. Escape, Tab vagy a keresőn kívüli kattintás bezárja a javaslatokat. Üres keresőnél nincs lenyíló lista. Az akadálymentes állapotot combobox/listbox/option szerepek és aktív opcióazonosító jelzik.

A javaslatokkal történő választás ugyanazt a `product_id` szűrést használja, mint a részletes szűrőpanel; nincs új végpont vagy adatbázis-módosítás. Külön PHP-teszt ellenőrzi az azonos nevű termékek megkülönböztetését a listában és PDF-ben. Négy böngészőteszt vizsgálja az egér- és billentyűzetes kiválasztást, a szűrők megőrzését, az ékezet nélküli több szavas javaslatokat, a szabad szöveges keresést, a lista bezárását, a nyolctételes korlátot és a mobilnézetet.

## Gyors dátumszűrők

A Szűrők panelen négy időszak választható:

- Ma: az aktuális naptári nap.
- Elmúlt 7 nap: a mai nap és az azt megelőző 6 nap.
- Elmúlt 30 nap: a mai nap és az azt megelőző 29 nap.
- Ez a hónap: az aktuális hónap első napjától az utolsóig.

A dátumok az alkalmazás konfigurált időzónájában képződnek, a meglévő dátumszűréssel összhangban. A zárónap teljes egészében beletartozik a tartományba. A hivatkozások konkrét `date_from` és `date_to` értékeket tartalmaznak, így az export vagy a listaállapot visszaállítása nem számolja át később az időszakot.

A gyorsszűrő a már alkalmazott többi szűrőt, rendezést és oldalméretet megőrzi; az oldalszámot elhagyja, így az első oldal nyílik meg. A dátummezők továbbra is kézzel módosíthatók. Az aktuális tartománnyal egyező gyorsszűrőt kiemelés és `aria-current` jelzi.

Normál kattintásnál a gyorsszűrő a mezők aktuális értékeivel küldi be a keresőűrlapot, így a még nem alkalmazott módosítások sem vesznek el. A termékválasztó érvényességi ellenőrzése ekkor is működik. Új lapra nyitásnál a hivatkozás a legutóbb alkalmazott szűrésekkel használható.

Üres találatoknál a lista külön, a képernyő szélességéhez igazodó üzenetet jelenít meg. Ez mobilon sem igényli a széles táblázat oldalirányú görgetését.

## Termék mozgástörténete

A mozgás részletein a „Termék mozgásai” hivatkozás kizárólag az adott termékre szűrt listát nyitja meg. A korábbi dátum-, irány- vagy keresési feltételek nem korlátozzák ezt a teljes történetet. A külön „Készletmozgások” visszahivatkozás változatlanul az eredeti listaállapothoz vezet.

## Ellenőrzés

17 új PHP-teszteset ellenőrzi az azonosító keresését (pontos egyezés, normalizálás, összetett keresés, túl nagy érték, literális helyettesítő karakter), a négy időszak dátumhatárait, a megőrzött szűrőket, az oldalszám elhagyását, a lista/PDF egyezést, a teljes terméktörténetet és az azonos nevű termékek pontos szűrését. Külön esetek vizsgálják a szökőnapot, az évváltást és a konkrét dátumokkal megőrzött visszatérési állapotot. Három böngészőteszt végigjárja az új hivatkozásokat, az azonosító keresését, a dátummezőket, az aktív állapotot, az egyedi dátumra váltást, valamint a még nem alkalmazott szűrők és a validáció megőrzését mobilnézetben. A felső kereső termékjavaslatait további négy böngészőteszt ellenőrzi.

```powershell
php artisan test
npm run build
npm run test:browser
```

Ehhez a változtatáshoz új adatbázis-migráció és seederfuttatás nem szükséges.
